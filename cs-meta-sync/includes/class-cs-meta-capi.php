<?php
/**
 * CS Meta Sync — Conversions API (server-side).
 *
 * Sends e-commerce events to Meta server-side via the Conversions API
 * for improved match quality and ad-blocker resilience.
 *
 * @package CS_Meta_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CS_Meta_CAPI {

    /** @var string Pixel / Dataset ID */
    private $pixel_id;

    /** @var string Conversions API access token */
    private $access_token;

    /** @var bool Test mode */
    private $test_mode;

    /** @var string Test event code */
    private $test_event_code;

    public function __construct() {
        $this->pixel_id        = CS_Meta_Sync::get_option( 'pixel_id' );
        $this->access_token    = CS_Meta_Sync::get_option( 'capi_access_token' );
        $this->test_mode       = '1' === CS_Meta_Sync::get_option( 'test_mode' );
        $this->test_event_code = CS_Meta_Sync::get_option( 'test_event_code', '' );

        if ( empty( $this->pixel_id ) || empty( $this->access_token ) || '1' !== CS_Meta_Sync::get_option( 'enable_capi' ) ) {
            return;
        }

        // Do not run in admin or during AJAX (Pixel handles AddToCart in browser).
        if ( is_admin() ) {
            return;
        }

        // Server-side events hooked into WooCommerce actions.
        add_action( 'woocommerce_after_single_product', array( $this, 'track_view_content' ) );
        add_action( 'woocommerce_add_to_cart', array( $this, 'track_add_to_cart' ), 10, 6 );
        add_action( 'woocommerce_before_checkout_form', array( $this, 'track_initiate_checkout' ) );
        add_action( 'woocommerce_thankyou', array( $this, 'track_purchase' ), 10, 1 );
    }

    /**
     * Track ViewContent event.
     */
    public function track_view_content() {
        global $product;
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $event_id = $this->get_dedup_event_id( 'viewcontent' );

        $this->send_event( 'ViewContent', array(
            'content_name' => $product->get_name(),
            'content_ids'  => array( (string) $product->get_id() ),
            'content_type' => 'product',
            'value'        => (float) $product->get_price(),
            'currency'     => get_woocommerce_currency(),
        ), $event_id );
    }

    /**
     * Track AddToCart event.
     */
    public function track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $event_id = $this->generate_event_id();

        $this->send_event( 'AddToCart', array(
            'content_ids'  => array( (string) $product_id ),
            'content_type' => 'product',
            'value'        => (float) $product->get_price() * $quantity,
            'currency'     => get_woocommerce_currency(),
            'num_items'    => $quantity,
        ), $event_id );
    }

    /**
     * Track InitiateCheckout event.
     */
    public function track_initiate_checkout() {
        $cart = WC()->cart;
        if ( ! $cart ) {
            return;
        }

        $content_ids = array();
        $num_items   = 0;
        foreach ( $cart->get_cart() as $item ) {
            $content_ids[] = (string) $item['product_id'];
            $num_items    += $item['quantity'];
        }

        $event_id = $this->get_dedup_event_id( 'initiatecheckout' );

        $this->send_event( 'InitiateCheckout', array(
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'value'        => (float) $cart->get_total( 'edit' ),
            'currency'     => get_woocommerce_currency(),
            'num_items'    => $num_items,
        ), $event_id );
    }

    /**
     * Track Purchase event.
     */
    public function track_purchase( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Prevent double-tracking.
        if ( $order->get_meta( '_cs_meta_capi_tracked' ) ) {
            return;
        }

        $content_ids = array();
        $num_items   = 0;
        $contents    = array();
        foreach ( $order->get_items() as $item ) {
            $pid           = (string) $item->get_product_id();
            $qty           = $item->get_quantity();
            $content_ids[] = $pid;
            $num_items    += $qty;
            $contents[]    = array(
                'id'       => $pid,
                'quantity' => $qty,
                'item_price' => (float) $item->get_total() / max( $qty, 1 ),
            );
        }

        // Try to reuse the Pixel's event_id for deduplication.
        $event_id = $order->get_meta( '_cs_meta_purchase_event_id' );
        if ( empty( $event_id ) ) {
            $event_id = $this->generate_event_id();
        }

        $this->send_event( 'Purchase', array(
            'content_ids'  => $content_ids,
            'content_type' => 'product',
            'contents'     => $contents,
            'value'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
            'num_items'    => $num_items,
            'order_id'     => (string) $order_id,
        ), $event_id, $order );

        $order->update_meta_data( '_cs_meta_capi_tracked', '1' );
        $order->save();
    }

    /**
     * Send an event to the Conversions API.
     *
     * @param string          $event_name  Standard event name.
     * @param array           $custom_data Custom data parameters.
     * @param string          $event_id    Unique event ID for dedup.
     * @param WC_Order|null   $order       Optional order for user data.
     */
    private function send_event( $event_name, $custom_data, $event_id, $order = null ) {
        $user_data = $this->build_user_data( $order );

        $event = array(
            'event_name'  => $event_name,
            'event_time'  => time(),
            'event_id'    => $event_id,
            'action_source' => 'website',
            'event_source_url' => $this->get_current_url(),
            'user_data'   => $user_data,
            'custom_data' => $custom_data,
        );

        $body = array(
            'data'         => wp_json_encode( array( $event ) ),
            'access_token' => $this->access_token,
        );

        // Add test event code if in test mode.
        if ( $this->test_mode && ! empty( $this->test_event_code ) ) {
            $body['test_event_code'] = $this->test_event_code;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/events',
            CS_META_SYNC_GRAPH_API_VERSION,
            $this->pixel_id
        );

        // Fire asynchronously to avoid blocking page load.
        wp_remote_post( $url, array(
            'timeout'   => 5,
            'blocking'  => false,
            'body'      => $body,
        ) );

        // Debug logging.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[CS Meta Sync] CAPI %s sent | event_id: %s | test_mode: %s',
                $event_name,
                $event_id,
                $this->test_mode ? 'yes' : 'no'
            ) );
        }
    }

    /**
     * Build the user_data object with hashed PII.
     *
     * @param WC_Order|null $order
     * @return array
     */
    private function build_user_data( $order = null ) {
        $data = array();

        // IP address.
        $ip = $this->get_client_ip();
        if ( $ip ) {
            $data['client_ip_address'] = $ip;
        }

        // User agent.
        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $data['client_user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        // Facebook cookies (fbc, fbp).
        if ( ! empty( $_COOKIE['_fbc'] ) ) {
            $data['fbc'] = sanitize_text_field( $_COOKIE['_fbc'] );
        }
        if ( ! empty( $_COOKIE['_fbp'] ) ) {
            $data['fbp'] = sanitize_text_field( $_COOKIE['_fbp'] );
        }

        // User data from order (most accurate for Purchase events).
        if ( $order instanceof WC_Order ) {
            $email = $order->get_billing_email();
            $phone = $order->get_billing_phone();
            $fn    = $order->get_billing_first_name();
            $ln    = $order->get_billing_last_name();
            $city  = $order->get_billing_city();
            $state = $order->get_billing_state();
            $zip   = $order->get_billing_postcode();
            $country = $order->get_billing_country();
        } elseif ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $email = $user->user_email;
            $phone = get_user_meta( $user->ID, 'billing_phone', true );
            $fn    = $user->first_name;
            $ln    = $user->last_name;
            $city  = get_user_meta( $user->ID, 'billing_city', true );
            $state = get_user_meta( $user->ID, 'billing_state', true );
            $zip   = get_user_meta( $user->ID, 'billing_postcode', true );
            $country = get_user_meta( $user->ID, 'billing_country', true );
        } else {
            $email = $phone = $fn = $ln = $city = $state = $zip = $country = '';
        }

        // Hash and assign — Meta requires SHA-256 hashed values (lowercase, trimmed).
        if ( ! empty( $email ) ) {
            $data['em'] = array( $this->hash_value( $email ) );
        }
        if ( ! empty( $phone ) ) {
            // Normalize phone: remove spaces, dashes, parentheses.
            $phone = preg_replace( '/[^0-9+]/', '', $phone );
            $data['ph'] = array( $this->hash_value( $phone ) );
        }
        if ( ! empty( $fn ) ) {
            $data['fn'] = array( $this->hash_value( $fn ) );
        }
        if ( ! empty( $ln ) ) {
            $data['ln'] = array( $this->hash_value( $ln ) );
        }
        if ( ! empty( $city ) ) {
            $data['ct'] = array( $this->hash_value( $city ) );
        }
        if ( ! empty( $state ) ) {
            $data['st'] = array( $this->hash_value( $state ) );
        }
        if ( ! empty( $zip ) ) {
            $data['zp'] = array( $this->hash_value( $zip ) );
        }
        if ( ! empty( $country ) ) {
            $data['country'] = array( $this->hash_value( $country ) );
        }

        return $data;
    }

    /**
     * SHA-256 hash a value for Meta privacy-safe matching.
     */
    private function hash_value( $value ) {
        return hash( 'sha256', strtolower( trim( $value ) ) );
    }

    /**
     * Get the client's IP address.
     */
    private function get_client_ip() {
        $headers = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' );
        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
                $ip  = trim( $ips[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * Get the current page URL.
     */
    private function get_current_url() {
        if ( isset( $_SERVER['HTTP_HOST'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
            $protocol = is_ssl() ? 'https' : 'http';
            return $protocol . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return home_url();
    }

    /**
     * Try to get a matching event ID from the Pixel (for deduplication).
     */
    private function get_dedup_event_id( $event_key ) {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $eid = WC()->session->get( 'cs_meta_eid_' . $event_key );
            if ( $eid ) {
                return $eid;
            }
        }
        return $this->generate_event_id();
    }

    /**
     * Generate a unique event ID.
     */
    private function generate_event_id() {
        return 'cs_' . wp_generate_uuid4();
    }
}
