<?php
/**
 * CS Meta Sync — Meta Pixel (browser-side).
 *
 * Injects the Meta Pixel base code and fires standard e-commerce events
 * on WooCommerce storefront pages.
 *
 * @package CS_Meta_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CS_Meta_Pixel {

    /** @var string Pixel ID */
    private $pixel_id;

    public function __construct() {
        $this->pixel_id = CS_Meta_Sync::get_option( 'pixel_id' );

        if ( empty( $this->pixel_id ) || '1' !== CS_Meta_Sync::get_option( 'enable_pixel' ) ) {
            return;
        }

        // Do not run in admin.
        if ( is_admin() ) {
            return;
        }

        add_action( 'wp_head', array( $this, 'inject_base_pixel' ), 1 );
        add_action( 'wp_footer', array( $this, 'inject_event_scripts' ), 50 );
    }

    /**
     * Inject the Meta Pixel base code into <head>.
     */
    public function inject_base_pixel() {
        $advanced_matching = $this->get_advanced_matching_params();
        $am_json           = ! empty( $advanced_matching ) ? wp_json_encode( $advanced_matching ) : '{}';
        ?>
<!-- Meta Pixel Code — CS Meta Sync -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');

fbq('init', '<?php echo esc_js( $this->pixel_id ); ?>', <?php echo $am_json; ?>);
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=<?php echo esc_attr( $this->pixel_id ); ?>&ev=PageView&noscript=1"
/></noscript>
<!-- End Meta Pixel Code -->
        <?php
    }

    /**
     * Inject e-commerce event scripts in the footer.
     */
    public function inject_event_scripts() {
        if ( ! function_exists( 'is_product' ) ) {
            return;
        }

        // ViewContent — single product page.
        if ( is_product() ) {
            global $product;
            if ( $product instanceof WC_Product ) {
                $event_id = $this->generate_event_id();
                $params   = array(
                    'content_name' => $product->get_name(),
                    'content_ids'  => array( (string) $product->get_id() ),
                    'content_type' => 'product',
                    'value'        => (float) $product->get_price(),
                    'currency'     => get_woocommerce_currency(),
                );
                $this->fire_event( 'ViewContent', $params, $event_id );
            }
        }

        // InitiateCheckout — checkout page.
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
            $cart       = WC()->cart;
            $content_ids = array();
            $num_items   = 0;
            if ( $cart ) {
                foreach ( $cart->get_cart() as $item ) {
                    $content_ids[] = (string) $item['product_id'];
                    $num_items    += $item['quantity'];
                }
            }
            $event_id = $this->generate_event_id();
            $params   = array(
                'content_ids'  => $content_ids,
                'content_type' => 'product',
                'value'        => (float) ( $cart ? $cart->get_total( 'edit' ) : 0 ),
                'currency'     => get_woocommerce_currency(),
                'num_items'    => $num_items,
            );
            $this->fire_event( 'InitiateCheckout', $params, $event_id );
        }

        // Purchase — order received / thank-you page.
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            global $wp;
            $order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
            if ( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( $order && ! $order->get_meta( '_cs_meta_pixel_tracked' ) ) {
                    $content_ids = array();
                    $num_items   = 0;
                    foreach ( $order->get_items() as $item ) {
                        $content_ids[] = (string) $item->get_product_id();
                        $num_items    += $item->get_quantity();
                    }
                    $event_id = $this->generate_event_id();
                    $params   = array(
                        'content_ids'  => $content_ids,
                        'content_type' => 'product',
                        'value'        => (float) $order->get_total(),
                        'currency'     => $order->get_currency(),
                        'num_items'    => $num_items,
                    );
                    $this->fire_event( 'Purchase', $params, $event_id );

                    // Save event_id for CAPI deduplication & prevent double-tracking.
                    $order->update_meta_data( '_cs_meta_pixel_tracked', '1' );
                    $order->update_meta_data( '_cs_meta_purchase_event_id', $event_id );
                    $order->save();
                }
            }
        }

        // AddToCart — handled via JS on AJAX add-to-cart buttons.
        $this->inject_add_to_cart_js();
    }

    /**
     * Inject JavaScript for AddToCart event tracking (handles AJAX add-to-cart).
     */
    private function inject_add_to_cart_js() {
        if ( ! function_exists( 'is_shop' ) ) {
            return;
        }
        ?>
<script>
(function() {
    if (typeof jQuery === 'undefined' || typeof fbq === 'undefined') return;

    // AJAX add to cart (archive pages).
    jQuery(document.body).on('added_to_cart', function(e, fragments, cart_hash, $button) {
        var productId = $button.data('product_id') || '';
        var productName = $button.data('product_name') || '';
        var productPrice = $button.data('product_price') || 0;
        fbq('track', 'AddToCart', {
            content_ids: [String(productId)],
            content_type: 'product',
            value: parseFloat(productPrice),
            currency: '<?php echo esc_js( get_woocommerce_currency() ); ?>'
        });
    });

    // Single product page add to cart.
    jQuery('form.cart').on('submit', function() {
        var $form = jQuery(this);
        var productId = $form.find('button[name="add-to-cart"]').val() || $form.find('input[name="add-to-cart"]').val();
        <?php
        if ( is_product() ) {
            global $product;
            if ( $product instanceof WC_Product ) {
                printf(
                    "fbq('track', 'AddToCart', { content_ids: ['%s'], content_type: 'product', value: %s, currency: '%s' });",
                    esc_js( $product->get_id() ),
                    esc_js( $product->get_price() ),
                    esc_js( get_woocommerce_currency() )
                );
            }
        }
        ?>
    });
})();
</script>
        <?php
    }

    /**
     * Output a fbq('track', ...) call with eventID for deduplication.
     */
    private function fire_event( $event_name, $params, $event_id ) {
        printf(
            "<script>fbq('track', '%s', %s, { eventID: '%s' });</script>\n",
            esc_js( $event_name ),
            wp_json_encode( $params ),
            esc_js( $event_id )
        );

        // Store event_id in session for CAPI deduplication.
        if ( function_exists( 'WC' ) && WC()->session ) {
            $key = 'cs_meta_eid_' . strtolower( $event_name );
            WC()->session->set( $key, $event_id );
        }
    }

    /**
     * Get advanced matching parameters (hashed user data) if available.
     */
    private function get_advanced_matching_params() {
        $params = array();

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user->user_email ) {
                $params['em'] = hash( 'sha256', strtolower( trim( $user->user_email ) ) );
            }
            if ( $user->first_name ) {
                $params['fn'] = hash( 'sha256', strtolower( trim( $user->first_name ) ) );
            }
            if ( $user->last_name ) {
                $params['ln'] = hash( 'sha256', strtolower( trim( $user->last_name ) ) );
            }
        }

        return $params;
    }

    /**
     * Generate a unique event ID for deduplication between Pixel and CAPI.
     */
    private function generate_event_id() {
        return 'cs_' . wp_generate_uuid4();
    }
}
