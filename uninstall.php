<?php
/**
 * Universal Reward Points Ledger - Uninstall Cleanup Script
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
function universal_reward_uninstall() {
    // 1. Delete Options
    $universal_reward_options = [
        'universal_reward_api_url',
        'universal_reward_secret_key',
        'universal_reward_connection_status',
        'universal_reward_provider_profile',
        'universal_reward_wp_settings',
        'universal_reward_wc_settings',
        'universal_reward_wc_webhook_award_id',
        'universal_reward_wc_webhook_redeem_id',
        'universal_reward_wc_webhook_id',
    ];

    foreach ( $universal_reward_options as $option ) {
        delete_option( $option );
    }

    // 2. Delete Transients
    delete_transient( 'universal_reward_admin_stats' );

    // 3. Clean up user metadata associated with the plugin (using WP metadata API instead of direct SQL query)
    $universal_reward_meta_keys = [
        '_universal_reward_last_login_reward',
        '_universal_reward_points_balance',
        '_universal_reward_points_last_updated',
        '_universal_reward_customer_history',
        '_universal_reward_customer_history_updated',
    ];

    foreach ( $universal_reward_meta_keys as $meta_key ) {
        delete_metadata( 'user', 0, $meta_key, '', true );
    }
}

universal_reward_uninstall();
