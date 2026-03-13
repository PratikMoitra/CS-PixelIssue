<?php
/**
 * CS Meta Sync — Product Catalog Sync.
 *
 * Handles syncing WooCommerce products to Meta Commerce Manager catalog
 * via the Graph API items_batch endpoint.
 *
 * @package CS_Meta_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class CS_Meta_Catalog_Sync
{

    /** Maximum items per batch API call. */
    const BATCH_SIZE = 4999;

    public function __construct()
    {
        // Real-time sync on product save.
        add_action('woocommerce_update_product', array($this, 'on_product_save'), 10, 2);
        add_action('save_post_product', array($this, 'on_product_save_post'), 20, 3);

        // Delete from catalog when product is trashed.
        add_action('wp_trash_post', array($this, 'on_product_trash'));

        // AJAX handler for manual sync.
        add_action('wp_ajax_cs_meta_sync_now', array($this, 'ajax_sync_now'));
    }

    /**
     * Sync all published WooCommerce products to Meta catalog.
     *
     * @return array Log data with counts and status.
     */
    public function sync_all_products()
    {
        $catalog_id = CS_Meta_Sync::get_option('catalog_id');
        $token = CS_Meta_Sync::get_option('graph_api_token');

        if (empty($catalog_id) || empty($token)) {
            return array(
                'time' => current_time('mysql'),
                'total' => 0,
                'success' => 0,
                'errors' => 0,
                'message' => __('Missing Catalog ID or Graph API Token.', 'cs-meta-sync'),
            );
        }

        // Query all published simple & variable products.
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'type' => array('simple', 'variable'),
            'return' => 'objects',
        );
        $products = wc_get_products($args);

        if (empty($products)) {
            $log = array(
                'time' => current_time('mysql'),
                'total' => 0,
                'success' => 0,
                'errors' => 0,
                'message' => __('No published products found.', 'cs-meta-sync'),
            );
            update_option('cs_meta_sync_last_log', $log);
            return $log;
        }

        // Build batch request items (deduplicate by retailer_id).
        $requests = array();
        $seen_ids = array();
        $skipped  = 0;
        foreach ($products as $product) {
            // Skip invalid products.
            $product_id = $product->get_id();
            if (empty($product_id) || 0 === $product_id) {
                $skipped++;
                continue;
            }

            // Skip product types that shouldn't be in the catalog.
            $product_type = $product->get_type();
            if (!in_array($product_type, array('simple', 'variable'), true)) {
                $skipped++;
                continue;
            }

            $retailer_id = $this->get_retailer_id($product_id);
            if (isset($seen_ids[$retailer_id])) {
                $skipped++;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[CS Meta Sync] Skipped duplicate retailer_id: ' . $retailer_id . ' (product #' . $product_id . ')');
                }
                continue;
            }
            $seen_ids[$retailer_id] = true;

            $data = $this->map_product($product);
            if ($data) {
                $requests[] = array(
                    'method' => 'UPDATE',
                    'retailer_id' => $retailer_id,
                    'data' => $data,
                );
            }
        }

        if ($skipped > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CS Meta Sync] Skipped ' . $skipped . ' products (duplicates or invalid).');
        }

        // Send in batches.
        $total_sent = count($requests);
        $success = 0;
        $errors = 0;
        $messages = array();

        $batches = array_chunk($requests, self::BATCH_SIZE);
        foreach ($batches as $batch) {
            $result = $this->send_batch($catalog_id, $token, $batch);
            if (is_wp_error($result)) {
                $errors += count($batch);
                $messages[] = $result->get_error_message();
            } else {
                $success += count($batch);
                if (!empty($result['validation_status'])) {
                    foreach ($result['validation_status'] as $status) {
                        if (!empty($status['errors'])) {
                            $errors++;
                            $success--;
                            $messages[] = wp_json_encode($status['errors']);
                        }
                    }
                }
            }
        }

        $log = array(
            'time' => current_time('mysql'),
            'total' => $total_sent,
            'success' => $success,
            'errors' => $errors,
            'message' => implode(' | ', array_slice($messages, 0, 5)),
        );

        update_option('cs_meta_sync_last_log', $log);
        return $log;
    }

    /**
     * Sync a single product by ID.
     *
     * @param int $product_id
     * @return array|WP_Error
     */
    public function sync_single_product($product_id)
    {
        if ('1' !== CS_Meta_Sync::get_option('enable_catalog')) {
            return new WP_Error('disabled', 'Catalog sync is disabled.');
        }

        $catalog_id = CS_Meta_Sync::get_option('catalog_id');
        $token = CS_Meta_Sync::get_option('graph_api_token');

        if (empty($catalog_id) || empty($token)) {
            return new WP_Error('config', 'Missing Catalog ID or Graph API Token.');
        }

        $product = wc_get_product($product_id);
        if (!$product || 'publish' !== $product->get_status()) {
            return new WP_Error('not_found', 'Product not found or not published.');
        }

        $data = $this->map_product($product);
        if (!$data) {
            return new WP_Error('mapping', 'Product data could not be mapped.');
        }

        $requests = array(
            array(
                'method' => 'UPDATE',
                'retailer_id' => $this->get_retailer_id($product->get_id()),
                'data' => $data,
            ),
        );

        return $this->send_batch($catalog_id, $token, $requests);
    }

    /**
     * Delete a product from the Meta catalog.
     *
     * @param int $product_id
     * @return array|WP_Error
     */
    public function delete_product($product_id)
    {
        if ('1' !== CS_Meta_Sync::get_option('enable_catalog')) {
            return new WP_Error('disabled', 'Catalog sync is disabled.');
        }

        $catalog_id = CS_Meta_Sync::get_option('catalog_id');
        $token = CS_Meta_Sync::get_option('graph_api_token');

        if (empty($catalog_id) || empty($token)) {
            return new WP_Error('config', 'Missing Catalog ID or Graph API Token.');
        }

        $requests = array(
            array(
                'method' => 'DELETE',
                'retailer_id' => $this->get_retailer_id($product_id),
            ),
        );

        return $this->send_batch($catalog_id, $token, $requests);
    }

    /**
     * Map a WC_Product to Meta catalog item data.
     *
     * @param WC_Product $product
     * @return array|null
     */
    public function map_product($product)
    {
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

        if (empty($image_url)) {
            // Meta requires an image — use a placeholder or skip.
            $image_url = wc_placeholder_img_src('woocommerce_single');
        }

        // Meta requires HTTPS image URLs.
        $image_url = str_replace('http://', 'https://', $image_url);

        $price = $product->get_regular_price();
        if (empty($price)) {
            $price = $product->get_price();
        }

        if (empty($price)) {
            return null; // Cannot submit without a price.
        }

        $currency = get_woocommerce_currency();

        // Availability mapping.
        $stock_status = $product->get_stock_status();
        $availability = 'in stock';
        if ('outofstock' === $stock_status) {
            $availability = 'out of stock';
        } elseif ('onbackorder' === $stock_status) {
            $availability = 'available for order';
        }

        // Description — strip tags and limit length.
        $description = $product->get_short_description();
        if (empty($description)) {
            $description = $product->get_description();
        }
        $description = wp_strip_all_tags($description);
        if (strlen($description) > 5000) {
            $description = substr($description, 0, 4997) . '...';
        }
        if (empty($description)) {
            $description = $product->get_name();
        }

        // Brand — try to get from a custom attribute or fall back to site name.
        $brand = $product->get_attribute('brand');
        if (empty($brand)) {
            $brand = get_bloginfo('name');
        }

        // Product URL — ensure it's a full absolute URL.
        $link = $product->get_permalink();
        $link = str_replace('http://', 'https://', $link);

        // Build the product data — ALL values must be strings for Meta's API.
        $data = array(
            'id'           => $this->get_retailer_id($product->get_id()),
            'title'        => (string) $product->get_name(),
            'description'  => (string) $description,
            'availability' => (string) $availability,
            'condition'    => 'new',
            'price'        => (string) $this->format_price($price, $currency),
            'link'         => (string) $link,
            'image_link'   => (string) $image_url,
            'brand'        => (string) $brand,
        );

        // Sale price.
        $sale_price = $product->get_sale_price();
        if (!empty($sale_price)) {
            $data['sale_price'] = (string) $this->format_price($sale_price, $currency);
        }

        // Additional images.
        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            $additional = array();
            foreach (array_slice($gallery_ids, 0, 10) as $gid) {
                $url = wp_get_attachment_url($gid);
                if ($url) {
                    $additional[] = str_replace('http://', 'https://', $url);
                }
            }
            if (!empty($additional)) {
                $data['additional_image_link'] = implode(',', $additional);
            }
        }

        // Categories.
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            $data['product_type'] = (string) $terms[0]->name;
        }

        // SKU as MPN if present.
        $sku = $product->get_sku();
        if (!empty($sku)) {
            $data['mpn'] = (string) $sku;
        }

        // Debug: log the first product's data for troubleshooting.
        static $logged_first = false;
        if (!$logged_first && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CS Meta Sync] Sample product data: ' . wp_json_encode($data));
            $logged_first = true;
        }

        return $data;
    }

    /**
     * Format price as "100.00 USD" (Meta's expected format).
     */
    private function format_price($amount, $currency)
    {
        return number_format((float) $amount, 2, '.', '') . ' ' . $currency;
    }

    /**
     * Generate a consistent retailer_id for a product.
     * Uses 'wc_' prefix to avoid conflicts with other integrations.
     *
     * @param int $product_id
     * @return string
     */
    private function get_retailer_id($product_id)
    {
        return 'wc_' . (string) $product_id;
    }

    /**
     * Send a batch request to the Meta Graph API.
     *
     * @param string $catalog_id
     * @param string $token
     * @param array  $requests
     * @return array|WP_Error
     */
    private function send_batch($catalog_id, $token, $requests)
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/items_batch',
            CS_META_SYNC_GRAPH_API_VERSION,
            $catalog_id
        );

        $body = array(
            'access_token' => $token,
            'item_type' => 'PRODUCT_ITEM',
            'requests' => wp_json_encode($requests),
        );

        $response = wp_remote_post($url, array(
            'timeout' => 120,
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown API error (HTTP ' . $code . ')';
            return new WP_Error('api_error', $error_msg);
        }

        return $data;
    }

    /**
     * Hook: WooCommerce product updated.
     */
    public function on_product_save($product_id, $product = null)
    {
        // Prevent infinite loops.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        $this->sync_single_product($product_id);
    }

    /**
     * Hook: WordPress save_post_product.
     */
    public function on_product_save_post($post_id, $post, $update)
    {
        if (!$update || 'publish' !== $post->post_status) {
            return;
        }
        // The woocommerce_update_product hook already handles this in most cases,
        // but this catches direct wp_insert_post calls.
        $this->sync_single_product($post_id);
    }

    /**
     * Hook: product trashed → delete from Meta.
     */
    public function on_product_trash($post_id)
    {
        if ('product' !== get_post_type($post_id)) {
            return;
        }
        $this->delete_product($post_id);
    }

    /**
     * AJAX handler for manual sync.
     */
    public function ajax_sync_now()
    {
        check_ajax_referer('cs_meta_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Unauthorized.', 'cs-meta-sync'));
        }

        $log = $this->sync_all_products();
        wp_send_json_success($log);
    }
}
