<?php
/**
 * Plugin Name:       Universal Reward Points Ledger
 * Plugin URI:        https://point.odude.com/
 * Description:       Bridges WordPress and WooCommerce with the ODude Reward Point Universal Loyalty Ledger.
 * Version:           1.0.0
 * Author:            ODude Developers
 * Author URI:        https://point.odude.com/
 * License:           GPL-2.0+
 * Text Domain:       universal-reward
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants
define( 'UNIVERSAL_REWARD_VERSION', '1.0.0' );
define( 'UNIVERSAL_REWARD_PATH', plugin_dir_path( __FILE__ ) );
define( 'UNIVERSAL_REWARD_URL', plugin_dir_url( __FILE__ ) );
define( 'UNIVERSAL_REWARD_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function universal_reward_activate() {
    // Set default settings if not already set
    if ( false === get_option( 'universal_reward_api_url' ) ) {
        update_option( 'universal_reward_api_url', 'https://point.odude.com/api/v1' );
    }
    if ( false === get_option( 'universal_reward_connection_status' ) ) {
        update_option( 'universal_reward_connection_status', 'disconnected' );
    }
    if ( false === get_option( 'universal_reward_wp_settings' ) ) {
        update_option( 'universal_reward_wp_settings', [
            'enable_wp_rewards'   => 'no',
            'points_registration' => 50,
            'points_comment'      => 5,
            'max_comment_daily'   => 3,
            'points_daily_login'  => 10,
        ] );
    }
    if ( false === get_option( 'universal_reward_wc_settings' ) ) {
        update_option( 'universal_reward_wc_settings', [
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
    set_transient( 'universal_reward_admin_stats', false, 1 );
}
register_activation_hook( __FILE__, 'universal_reward_activate' );

/**
 * The code that runs during plugin deactivation.
 */
function universal_reward_deactivate() {
    // Delete WooCommerce Webhook to prevent leaving dead endpoints in WooCommerce
    if ( file_exists( UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-webhook.php' ) ) {
        require_once UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-webhook.php';
        Universal_Reward_Webhook_Manager::delete_webhook();
    }
    
    // Purge cached admin statistics
    delete_transient( 'universal_reward_admin_stats' );
}
register_deactivation_hook( __FILE__, 'universal_reward_deactivate' );

/**
 * Declare HPOS Compatibility for WooCommerce.
 */
function universal_reward_declare_hpos_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UNIVERSAL_REWARD_BASENAME, true );
    }
}
add_action( 'before_woocommerce_init', 'universal_reward_declare_hpos_compatibility' );

/**
 * Include the core class to start the plugin
 */
require_once UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-core.php';

/**
 * Begins execution of the plugin.
 */
function universal_reward_run() {
    $plugin = new Universal_Reward_Core();
    $plugin->run();
}
universal_reward_run();
