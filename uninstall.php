<?php
/**
 * ODude Reward Point - Uninstall Cleanup Script
 *
 * This file is run automatically when the plugin is deleted from the WordPress admin dashboard.
 * It removes all configuration options, transients, and user metadata associated with the plugin.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Uninstall cleanup function to prevent global scope variable leakage.
 */
function odude_reward_point_uninstall() {
    // 1. Delete Options
    $odude_reward_point_options = [
        'odude_reward_point_api_url',
        'odude_reward_point_secret_key',
        'odude_reward_point_connection_status',
        'odude_reward_point_provider_profile',
        'odude_reward_point_wp_settings',
        'odude_reward_point_wc_settings',
        'odude_reward_point_wc_webhook_award_id',
        'odude_reward_point_wc_webhook_redeem_id',
        'odude_reward_point_wc_webhook_id',
    ];

    foreach ( $odude_reward_point_options as $option ) {
        delete_option( $option );
    }

    // 2. Delete Transients
    delete_transient( 'odude_reward_point_admin_stats' );

    // 3. Clean up user metadata associated with the plugin (using WP metadata API instead of direct SQL query)
    $odude_reward_point_meta_keys = [
        '_odude_reward_point_last_login_reward',
        '_odude_reward_point_points_balance',
        '_odude_reward_point_points_last_updated',
        '_odude_reward_point_customer_history',
        '_odude_reward_point_customer_history_updated',
    ];

    foreach ( $odude_reward_point_meta_keys as $meta_key ) {
        delete_metadata( 'user', 0, $meta_key, '', true );
    }
}

odude_reward_point_uninstall();
