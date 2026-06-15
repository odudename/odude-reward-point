<?php
/**
 * Main Loader & Core Cache Manager for odude-reward-point
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ODude_Reward_Point_Core {

    public function __construct() {
        $this->load_dependencies();
    }

    /**
     * Include all plugin components
     */
    private function load_dependencies() {
        require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-api.php';
        require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-webhook.php';
        require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-wp.php';
        require_once ODUDE_REWARD_POINT_PATH . 'public/class-odude-reward-point-public.php';

        if ( class_exists( 'WooCommerce' ) ) {
            require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-wc.php';
        }

        if ( is_admin() ) {
            require_once ODUDE_REWARD_POINT_PATH . 'admin/class-odude-reward-point-admin.php';
        }
    }

    /**
     * Run actions and register hooks
     */
    public function run() {
        // Instantiate controllers
        new ODude_Reward_Point_WP();
        new ODude_Reward_Point_Public();

        if ( class_exists( 'WooCommerce' ) ) {
            new ODude_Reward_Point_WC();
        }

        if ( is_admin() ) {
            new ODude_Reward_Point_Admin();
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
        wp_enqueue_style( 'odude-reward-point-public-css', ODUDE_REWARD_POINT_URL . 'public/css/public-style.css', [], ODUDE_REWARD_POINT_VERSION );

        wp_enqueue_script( 'odude-reward-point-public-js', ODUDE_REWARD_POINT_URL . 'public/js/public-script.js', [ 'jquery' ], ODUDE_REWARD_POINT_VERSION, true );
        wp_localize_script( 'odude-reward-point-public-js', 'odude_reward_point_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'odude-reward-point-nonce' ),
        ] );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets( $hook ) {
        // Load only on our specific settings page
        if ( strpos( $hook, 'odude-reward-point' ) === false ) {
            return;
        }

        wp_enqueue_style( 'odude-reward-point-admin-css', ODUDE_REWARD_POINT_URL . 'admin/css/admin-style.css', [], ODUDE_REWARD_POINT_VERSION );

        wp_enqueue_script( 'odude-reward-point-admin-js', ODUDE_REWARD_POINT_URL . 'admin/js/admin-script.js', [ 'jquery' ], ODUDE_REWARD_POINT_VERSION, true );
        wp_localize_script( 'odude-reward-point-admin-js', 'odude_reward_point_admin_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'odude-reward-point-admin-nonce' ),
        ] );
    }
}


/**
 * High-performance Cache Manager Class
 */
class ODude_Reward_Point_Cache_Manager {

    /**
     * Get customer balance with local database fallback (15 min cache)
     */
    public static function get_cached_customer_balance( $user_id ) {
        if ( ! $user_id ) {
            return 0;
        }

        $cached_balance = get_user_meta( $user_id, '_odude_reward_point_points_balance', true );
        $last_updated   = get_user_meta( $user_id, '_odude_reward_point_points_last_updated', true );
        $cache_lifespan = 15 * MINUTE_IN_SECONDS;

        // Return cached balance if it's fresh
        if ( $cached_balance !== '' && ( time() - $last_updated ) < $cache_lifespan ) {
            return intval( $cached_balance );
        }

        // Cache expired or empty -> perform sync
        $user = get_userdata( $user_id );
        if ( $user ) {
            $api = new ODude_Reward_Point_API_Client();
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
        update_user_meta( $user_id, '_odude_reward_point_points_balance', intval( $new_balance ) );
        update_user_meta( $user_id, '_odude_reward_point_points_last_updated', time() );
    }

    /**
     * Purge all cached data for a customer (both balance and history)
     */
    public static function purge_customer_cache( $user_id ) {
        if ( ! $user_id ) {
            return;
        }
        delete_user_meta( $user_id, '_odude_reward_point_points_balance' );
        delete_user_meta( $user_id, '_odude_reward_point_points_last_updated' );
        delete_user_meta( $user_id, '_odude_reward_point_customer_history' );
        delete_user_meta( $user_id, '_odude_reward_point_customer_history_updated' );
    }

    /**
     * Get cached admin statistics
     */
    public static function get_cached_admin_stats() {
        $stats = get_transient( 'odude_reward_point_admin_stats' );

        // If transient expired (returns false), pull from API
        if ( false === $stats ) {
            $api = new ODude_Reward_Point_API_Client();
            $response = $api->get_provider_stats();

            if ( ! empty( $response['success'] ) && isset( $response['summary'] ) ) {
                $stats = $response['summary'];
                // Check if transaction list exists and bundle it
                if ( isset( $response['transactions'] ) ) {
                    $stats['recent_transactions'] = array_slice( $response['transactions'], 0, 10 );
                }
                
                // Cache for 12 hours
                set_transient( 'odude_reward_point_admin_stats', $stats, 12 * HOUR_IN_SECONDS );
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
        delete_transient( 'odude_reward_point_admin_stats' );
    }
}
