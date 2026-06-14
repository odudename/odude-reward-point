<?php
/**
 * Main Loader & Core Cache Manager for universal-reward
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Universal_Reward_Core {

    public function __construct() {
        $this->load_dependencies();
    }

    /**
     * Include all plugin components
     */
    private function load_dependencies() {
        require_once UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-api.php';
        require_once UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-webhook.php';
        require_once UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-wp.php';
        require_once UNIVERSAL_REWARD_PATH . 'public/class-universal-reward-public.php';

        if ( class_exists( 'WooCommerce' ) ) {
            require_once UNIVERSAL_REWARD_PATH . 'includes/class-universal-reward-wc.php';
        }

        if ( is_admin() ) {
            require_once UNIVERSAL_REWARD_PATH . 'admin/class-universal-reward-admin.php';
        }
    }

    /**
     * Run actions and register hooks
     */
    public function run() {
        // Instantiate controllers
        new Universal_Reward_WP();
        new Universal_Reward_Public();

        if ( class_exists( 'WooCommerce' ) ) {
            new Universal_Reward_WC();
        }

        if ( is_admin() ) {
            new Universal_Reward_Admin();
        }

        // Scripts enqueuing
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /**
     * Enqueue public scripts and styles
     */
    public function enqueue_public_assets() {
        // Load only at WooCommerce Checkout page or where shortcode might render
        wp_enqueue_style( 'universal-reward-public-css', UNIVERSAL_REWARD_URL . 'public/css/public-style.css', [], UNIVERSAL_REWARD_VERSION );

        wp_enqueue_script( 'universal-reward-public-js', UNIVERSAL_REWARD_URL . 'public/js/public-script.js', [ 'jquery' ], UNIVERSAL_REWARD_VERSION, true );
        wp_localize_script( 'universal-reward-public-js', 'universal_reward_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'universal-reward-nonce' ),
        ] );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets( $hook ) {
        // Load only on our specific settings page
        if ( strpos( $hook, 'universal-reward' ) === false ) {
            return;
        }

        wp_enqueue_style( 'universal-reward-admin-css', UNIVERSAL_REWARD_URL . 'admin/css/admin-style.css', [], UNIVERSAL_REWARD_VERSION );

        wp_enqueue_script( 'universal-reward-admin-js', UNIVERSAL_REWARD_URL . 'admin/js/admin-script.js', [ 'jquery' ], UNIVERSAL_REWARD_VERSION, true );
        wp_localize_script( 'universal-reward-admin-js', 'universal_reward_admin_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'universal-reward-admin-nonce' ),
        ] );
    }
}


/**
 * High-performance Cache Manager Class
 */
class Universal_Reward_Cache_Manager {

    /**
     * Get customer balance with local database fallback (15 min cache)
     */
    public static function get_cached_customer_balance( $user_id ) {
        if ( ! $user_id ) {
            return 0;
        }

        $cached_balance = get_user_meta( $user_id, '_universal_reward_points_balance', true );
        $last_updated   = get_user_meta( $user_id, '_universal_reward_points_last_updated', true );
        $cache_lifespan = 15 * MINUTE_IN_SECONDS;

        // Return cached balance if it's fresh
        if ( $cached_balance !== '' && ( time() - $last_updated ) < $cache_lifespan ) {
            return intval( $cached_balance );
        }

        // Cache expired or empty -> perform sync
        $user = get_userdata( $user_id );
        if ( $user ) {
            $api = new Universal_Reward_API_Client();
            $response = $api->get_customer_balance( $user->user_email );

            if ( ! empty( $response['success'] ) && isset( $response['customer']['points_balance'] ) ) {
                $balance = intval( $response['customer']['points_balance'] );
                self::update_local_customer_balance( $user_id, $balance );
                return $balance;
            }
        }

        // Fallback to old cache if api query fails
        return $cached_balance !== '' ? intval( $cached_balance) : 0;
    }

    /**
     * Update customer cache directly
     */
    public static function update_local_customer_balance( $user_id, $new_balance ) {
        update_user_meta( $user_id, '_universal_reward_points_balance', intval( $new_balance ) );
        update_user_meta( $user_id, '_universal_reward_points_last_updated', time() );
    }

    /**
     * Purge all cached data for a customer (both balance and history)
     */
    public static function purge_customer_cache( $user_id ) {
        if ( ! $user_id ) {
            return;
        }
        delete_user_meta( $user_id, '_universal_reward_points_balance' );
        delete_user_meta( $user_id, '_universal_reward_points_last_updated' );
        delete_user_meta( $user_id, '_universal_reward_customer_history' );
        delete_user_meta( $user_id, '_universal_reward_customer_history_updated' );
    }

    /**
     * Get cached admin statistics
     */
    public static function get_cached_admin_stats() {
        $stats = get_transient( 'universal_reward_admin_stats' );

        // If transient expired (returns false), pull from API
        if ( false === $stats ) {
            $api = new Universal_Reward_API_Client();
            $response = $api->get_provider_stats();

            if ( ! empty( $response['success'] ) && isset( $response['summary'] ) ) {
                $stats = $response['summary'];
                // Check if transaction list exists and bundle it
                if ( isset( $response['transactions'] ) ) {
                    $stats['recent_transactions'] = array_slice( $response['transactions'], 0, 10 );
                }
                
                // Cache for 12 hours
                set_transient( 'universal_reward_admin_stats', $stats, 12 * HOUR_IN_SECONDS );
            } else {
                $stats = [];
            }
        }

        return $stats;
    }

    /**
     * Invalidate admin stats cache to force update on next reload
     */
    public static function purge_stats_cache() {
        delete_transient( 'universal_reward_admin_stats' );
    }
}
