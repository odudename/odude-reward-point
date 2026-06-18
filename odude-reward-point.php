<?php
/**
 * Plugin Name:       ODude Reward Point
 * Plugin URI:        https://point.odude.com/
 * Description:       Bridges WordPress and WooCommerce with the ODude Reward Point Universal Loyalty Ledger.
 * Version:           1.0.1
 * Author:            ODude Developers
 * Author URI:        https://odude.com/
 * License:           GPL-2.0+
 * Text Domain:       odude-reward-point
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants
define( 'ODUDE_REWARD_POINT_VERSION', '1.0.1' );
define( 'ODUDE_REWARD_POINT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ODUDE_REWARD_POINT_URL', plugin_dir_url( __FILE__ ) );
define( 'ODUDE_REWARD_POINT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function odude_reward_point_activate() {
    // Set default settings if not already set
    if ( false === get_option( 'odude_reward_point_api_url' ) ) {
        update_option( 'odude_reward_point_api_url', 'https://point.odude.com/api/v1' );
    }
    if ( false === get_option( 'odude_reward_point_connection_status' ) ) {
        update_option( 'odude_reward_point_connection_status', 'disconnected' );
    }
    if ( false === get_option( 'odude_reward_point_wp_settings' ) ) {
        update_option( 'odude_reward_point_wp_settings', [
            'enable_wp_rewards'   => 'no',
            'points_registration' => 50,
            'points_comment'      => 5,
            'max_comment_daily'   => 3,
            'points_daily_login'  => 10,
        ] );
    }
    if ( false === get_option( 'odude_reward_point_wc_settings' ) ) {
        update_option( 'odude_reward_point_wc_settings', [
            'enable_earning'         => 'no',
            'points_earning_type'    => 'remote',
            'earning_ratio'          => 1.0,
            'points_trigger_status'  => 'wc-completed',
            'exclude_tax_shipping'   => 'no',
            'enable_redemption'      => 'no',
            'redemption_ratio'       => 100,
            'min_order_subtotal'     => 0,
            'max_redemption_percent' => 50,
            'max_redemption_points'  => 1000,
        ] );
    }

    // Initialize transient for stats to prevent empty dashboard on first load
    set_transient( 'odude_reward_point_admin_stats', false, 1 );
}
register_activation_hook( __FILE__, 'odude_reward_point_activate' );

/**
 * The code that runs during plugin deactivation.
 */
function odude_reward_point_deactivate() {
    // Delete WooCommerce Webhook to prevent leaving dead endpoints in WooCommerce
    if ( file_exists( ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-webhook.php' ) ) {
        require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-webhook.php';
        ODude_Reward_Point_Webhook_Manager::delete_webhook();
    }
    
    // Purge cached admin statistics
    delete_transient( 'odude_reward_point_admin_stats' );
}
register_deactivation_hook( __FILE__, 'odude_reward_point_deactivate' );

/**
 * Declare HPOS Compatibility for WooCommerce.
 */
function odude_reward_point_declare_hpos_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ODUDE_REWARD_POINT_BASENAME, true );
    }
}
add_action( 'before_woocommerce_init', 'odude_reward_point_declare_hpos_compatibility' );

/**
 * Include the core class to start the plugin
 */
require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-core.php';

/**
 * Begins execution of the plugin.
 */
function odude_reward_point_run() {
    $plugin = new ODude_Reward_Point_Core();
    $plugin->run();
}
odude_reward_point_run();
