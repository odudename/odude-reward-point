<?php
/**
 * API Client for ODude Reward Point Ledger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Universal_Reward_API_Client {

    private $api_url;
    private $secret_key;

    public function __construct() {
        $this->api_url    = rtrim( get_option( 'universal_reward_api_url', 'https://point.odude.com/api/v1' ), '/' );
        $this->secret_key = get_option( 'universal_reward_secret_key', '' );
    }

    /**
     * Get authorization headers
     */
    private function get_headers( $key = '' ) {
        $auth_key = ! empty( $key ) ? $key : $this->secret_key;
        return [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $auth_key,
        ];
    }

    /**
     * Scrub sensitive merchant credentials from logging payloads
     */
    private function scrub_sensitive_data( $data ) {
        if ( empty( $data ) ) {
            return $data;
        }

        $serialized = wp_json_encode( $data );
        if ( ! $serialized ) {
            return $data;
        }

        // Mask exact secret key matches
        if ( ! empty( $this->secret_key ) ) {
            $serialized = str_replace( $this->secret_key, '********', $serialized );
        }

        // Mask query string patterns
        $serialized = preg_replace( '/secret_key=[a-zA-Z0-9_\\-]+/', 'secret_key=********', $serialized );

        // Mask Authorization Bearer tokens in headers
        $serialized = preg_replace( '/Bearer\\s+[a-zA-Z0-9_\\-]+/i', 'Bearer ********', $serialized );

        return json_decode( $serialized, true );
    }

    /**
     * Helper to log debug messages if enabled using standard error_log
     */
    private function log( $message, $data = [] ) {
        $settings = get_option( 'universal_reward_wp_settings', [] );
        if ( ! empty( $settings['enable_debug_logging'] ) && $settings['enable_debug_logging'] === 'yes' ) {
            $scrubbed_data = $this->scrub_sensitive_data( $data );
            $log_entry = sprintf(
                '[UniversalReward] %s: %s',
                $message,
                wp_json_encode( $scrubbed_data )
            );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $log_entry );
        }
    }

    /**
     * 1. Verify Secret Key & Fetch Profile
     */
    public function verify_key( $temp_key = '' ) {
        $key = ! empty( $temp_key ) ? $temp_key : $this->secret_key;
        $url = add_query_arg( 'secret_key', $key, $this->api_url . '/verify' );

        $this->log( 'Verifying Key', [ 'url' => $url ] );

        $response = wp_remote_get( $url, [
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Verification Error', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->log( 'Verification Response', $body );

        return $body;
    }

    /**
     * 2. Award Points
     */
    public function award_points( $email, $phone, $amount, $deserved_point = null, $remarks = '' ) {
        $url = $this->api_url . '/award';

        $body = [
            'email'        => $email,
            'phone'        => $phone,
            'amount'       => floatval( $amount ),
            'website_name' => get_bloginfo( 'name' ),
        ];

        if ( $deserved_point !== null ) {
            $body['deserved_point'] = intval( $deserved_point );
        }

        $this->log( 'Awarding Points Request', [ 'url' => $url, 'body' => $body ] );

        $response = wp_remote_post( $url, [
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Awarding Points Error', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->log( 'Awarding Points Response', $res_body );

        return $res_body;
    }

    /**
     * 3. Get Customer Balance
     */
    public function get_customer_balance( $identifier ) {
        $url = $this->api_url . '/customer';
        $params = [
            'query'      => $identifier,
            'secret_key' => $this->secret_key,
        ];

        $request_url = add_query_arg( $params, $url );
        $this->log( 'Fetching Customer Balance', [ 'url' => $request_url ] );

        $response = wp_remote_get( $request_url, [
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Customer Balance Error', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->log( 'Customer Balance Response', $body );

        return $body;
    }

    /**
     * 4. Redeem Points
     */
    public function redeem_points( $identifier, $points, $remarks ) {
        $url = $this->api_url . '/redeem';

        $body = [
            'identifier' => $identifier,
            'points'     => intval( $points ),
            'remarks'    => $remarks,
            'website'    => wp_parse_url( home_url(), PHP_URL_HOST ),
        ];

        $this->log( 'Redeeming Points Request', [ 'url' => $url, 'body' => $body ] );

        $response = wp_remote_post( $url, [
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Redeeming Points Error', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->log( 'Redeeming Points Response', $res_body );

        return $res_body;
    }

    /**
     * 5. Get Provider Stats
     */
    public function get_provider_stats() {
        $url = add_query_arg( 'secret_key', $this->secret_key, $this->api_url . '/stats' );

        $this->log( 'Fetching Provider Stats', [ 'url' => $url ] );

        $response = wp_remote_get( $url, [
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Provider Stats Error', [ 'error' => $response->get_error_message() ] );
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->log( 'Provider Stats Response', $body );

        return $body;
    }
}
