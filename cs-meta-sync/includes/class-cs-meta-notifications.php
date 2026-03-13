<?php
/**
 * CS Meta Sync — Webhook & Telegram Notifications.
 *
 * Sends sync result notifications after every sync (manual or scheduled).
 *
 * @package CS_Meta_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CS_Meta_Notifications {

    /**
     * Send sync notifications (webhook + Telegram).
     *
     * @param array $log The sync log data.
     */
    public static function send( $log ) {
        $webhook_url       = CS_Meta_Sync::get_option( 'webhook_url' );
        $telegram_bot      = CS_Meta_Sync::get_option( 'telegram_bot_token' );
        $telegram_chat_id  = CS_Meta_Sync::get_option( 'telegram_chat_id' );

        // --- Webhook ---
        if ( ! empty( $webhook_url ) ) {
            self::send_webhook( $webhook_url, $log );
        }

        // --- Telegram ---
        if ( ! empty( $telegram_bot ) && ! empty( $telegram_chat_id ) ) {
            self::send_telegram( $telegram_bot, $telegram_chat_id, $log );
        }
    }

    /**
     * Send a POST request to the webhook URL with the full sync log as JSON.
     *
     * @param string $url  Webhook endpoint.
     * @param array  $log  Sync log data.
     */
    private static function send_webhook( $url, $log ) {
        // Strip large data (verbose items) to keep payload reasonable.
        $payload = $log;
        unset( $payload['items'] ); // Remove per-product verbose data.

        $payload['site_url']  = get_site_url();
        $payload['site_name'] = get_bloginfo( 'name' );
        $payload['plugin']    = 'CS Meta Sync';
        $payload['timestamp'] = current_time( 'c' ); // ISO 8601.

        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[CS Meta Sync] Webhook error: ' . $response->get_error_message() );
        }
    }

    /**
     * Send a message to a Telegram channel/group via Bot API.
     *
     * @param string $bot_token Bot API token.
     * @param string $chat_id   Chat/channel ID.
     * @param array  $log       Sync log data.
     */
    private static function send_telegram( $bot_token, $chat_id, $log ) {
        $sync_type = isset( $log['sync_type'] ) && 'manual' === $log['sync_type']
            ? '🔧 Manual'
            : '⏰ Scheduled';

        $site_name = get_bloginfo( 'name' );

        // Build the message.
        $lines   = array();
        $lines[] = "📦 *{$site_name} — Catalog Sync*";
        $lines[] = "";
        $lines[] = "🏷 *Type:* {$sync_type}";
        $lines[] = "🕐 *Time:* " . ( $log['time'] ?? 'N/A' );
        $lines[] = "";
        $lines[] = "📊 *Products*";
        $lines[] = "• Sent: `{$log['total']}`";
        $lines[] = "• Succeeded: `{$log['success']}`";
        $lines[] = "• Errors: `{$log['errors']}`";

        if ( ! empty( $log['skipped'] ) ) {
            $lines[] = "• Skipped: `{$log['skipped']}`";
        }

        // Sets summary.
        if ( ! empty( $log['sets'] ) ) {
            $created  = 0;
            $existing = 0;
            $failed   = 0;

            foreach ( $log['sets'] as $set ) {
                if ( 'created' === $set['status'] ) {
                    $created++;
                } elseif ( 'error' === $set['status'] ) {
                    $failed++;
                } else {
                    $existing++;
                }
            }

            $lines[] = "";
            $lines[] = "🏷 *Product Sets*";
            $lines[] = "• Total: `" . count( $log['sets'] ) . "`";

            if ( $created > 0 ) {
                $lines[] = "• Created: `{$created}`";
            }
            if ( $existing > 0 ) {
                $lines[] = "• Existing: `{$existing}`";
            }
            if ( $failed > 0 ) {
                $lines[] = "• Failed: `{$failed}`";
            }
        }

        // Error details.
        if ( ! empty( $log['message'] ) ) {
            $lines[] = "";
            $lines[] = "⚠️ *Details:* " . $log['message'];
        }

        $status_emoji = ( (int) $log['errors'] > 0 ) ? '⚠️' : '✅';
        $lines[] = "";
        $lines[] = "{$status_emoji} Sync complete.";

        $message = implode( "\n", $lines );

        // Telegram API — sendMessage.
        $url = sprintf(
            'https://api.telegram.org/bot%s/sendMessage',
            $bot_token
        );

        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'body'    => array(
                'chat_id'    => $chat_id,
                'text'       => $message,
                'parse_mode' => 'Markdown',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[CS Meta Sync] Telegram error: ' . $response->get_error_message() );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 300 ) {
                error_log( '[CS Meta Sync] Telegram API error (HTTP ' . $code . '): ' . wp_remote_retrieve_body( $response ) );
            }
        }
    }
}
