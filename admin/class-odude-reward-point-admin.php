<?php
/**
 * Admin Screen and Settings Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ODude_Reward_Point_Admin {

    public function __construct() {
        // Register Admin Menu Page
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

        // AJAX handlers
        add_action( 'wp_ajax_odude_reward_point_verify_connection', [ $this, 'ajax_verify_connection' ] );
        add_action( 'wp_ajax_odude_reward_point_sync_stats', [ $this, 'ajax_sync_stats' ] );
        add_action( 'wp_ajax_odude_reward_point_disconnect', [ $this, 'ajax_disconnect' ] );
        add_action( 'wp_ajax_odude_reward_point_save_settings', [ $this, 'ajax_save_settings' ] );
    }

    /**
     * Add settings page in admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'ODude Reward Point', 'odude-reward-point' ),
            __( 'ODude Reward Point', 'odude-reward-point' ),
            'manage_options',
            'odude-reward-point',
            [ $this, 'render_admin_page' ],
            'dashicons-awards',
            99
        );
    }

    /**
     * Verify connection via API and set options
     */
    public function ajax_verify_connection() {
        check_ajax_referer( 'odude-reward-point-admin-nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'odude-reward-point' ) ] );
        }

        $api_url    = isset( $_POST['api_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['api_url'] ) ) ) : '';
        $secret_key = isset( $_POST['secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret_key'] ) ) ) : '';

        if ( empty( $api_url ) || empty( $secret_key ) ) {
            wp_send_json_error( [ 'message' => __( 'Both Host URL and Secret Key are required.', 'odude-reward-point' ) ] );
        }

        // Save URL temporarily to let API client read it
        update_option( 'odude_reward_point_api_url', $api_url );

        $api = new ODude_Reward_Point_API_Client();
        $response = $api->verify_key( $secret_key );

        if ( ! empty( $response['success'] ) && isset( $response['provider'] ) ) {
            update_option( 'odude_reward_point_secret_key', $secret_key );
            update_option( 'odude_reward_point_connection_status', 'connected' );
            update_option( 'odude_reward_point_provider_profile', [
                'id'            => $response['provider']['id'],
                'business_name' => $response['provider']['business_name'],
                'email'         => $response['provider']['email'],
                'odude_name'    => $response['provider']['odude_name'],
            ] );

            // Sync the webhook state now that we are connected
            ODude_Reward_Point_Webhook_Manager::sync_webhook_state();
            // Clear stats to force update
            ODude_Reward_Point_Cache_Manager::purge_stats_cache();

            wp_send_json_success( [ 'message' => __( 'Connected successfully!', 'odude-reward-point' ) ] );
        } else {
            // Revert changes on error
            update_option( 'odude_reward_point_connection_status', 'disconnected' );
            $error_msg = ! empty( $response['message'] ) ? $response['message'] : __( 'Invalid Secret Key or URL.', 'odude-reward-point' );
            wp_send_json_error( [ 'message' => $error_msg ] );
        }
    }

    /**
     * Force manual stats refresh
     */
    public function ajax_sync_stats() {
        check_ajax_referer( 'odude-reward-point-admin-nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'odude-reward-point' ) ] );
        }

        ODude_Reward_Point_Cache_Manager::purge_stats_cache();
        $stats = ODude_Reward_Point_Cache_Manager::get_cached_admin_stats();

        if ( ! empty( $stats ) ) {
            wp_send_json_success( [ 'message' => __( 'Statistics synced.', 'odude-reward-point' ), 'stats' => $stats ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to retrieve fresh stats from API.', 'odude-reward-point' ) ] );
        }
    }

    /**
     * Disconnect from server
     */
    public function ajax_disconnect() {
        check_ajax_referer( 'odude-reward-point-admin-nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'odude-reward-point' ) ] );
        }

        // Clean up webhook first
        ODude_Reward_Point_Webhook_Manager::delete_webhook();

        update_option( 'odude_reward_point_connection_status', 'disconnected' );
        update_option( 'odude_reward_point_secret_key', '' );
        delete_option( 'odude_reward_point_provider_profile' );
        ODude_Reward_Point_Cache_Manager::purge_stats_cache();

        wp_send_json_success( [ 'message' => __( 'Disconnected successfully.', 'odude-reward-point' ) ] );
    }

    /**
     * Save settings via AJAX
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'odude-reward-point-admin-nonce', 'security' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'odude-reward-point' ) ] );
        }

        $tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

        if ( $tab === 'wp' ) {
            $wp_settings = [
                'enable_wp_rewards'   => isset( $_POST['enable_wp_rewards'] ) ? 'yes' : 'no',
                'enable_registration' => isset( $_POST['enable_registration'] ) ? 'yes' : 'no',
                'points_registration' => isset( $_POST['points_registration'] ) ? max( 0, intval( wp_unslash( $_POST['points_registration'] ) ) ) : 0,
                'enable_comment'      => isset( $_POST['enable_comment'] ) ? 'yes' : 'no',
                'points_comment'      => isset( $_POST['points_comment'] ) ? max( 0, intval( wp_unslash( $_POST['points_comment'] ) ) ) : 0,
                'max_comment_daily'   => isset( $_POST['max_comment_daily'] ) ? max( 1, intval( wp_unslash( $_POST['max_comment_daily'] ) ) ) : 1,
                'enable_daily_login'  => isset( $_POST['enable_daily_login'] ) ? 'yes' : 'no',
                'points_daily_login'  => isset( $_POST['points_daily_login'] ) ? max( 0, intval( wp_unslash( $_POST['points_daily_login'] ) ) ) : 0,
                'enable_debug_logging'=> isset( $_POST['enable_debug_logging'] ) ? 'yes' : 'no',
            ];
            update_option( 'odude_reward_point_wp_settings', $wp_settings );
            wp_send_json_success( [ 'message' => __( 'WordPress settings saved.', 'odude-reward-point' ) ] );

        } elseif ( $tab === 'wc' ) {
            $wc_settings = [
                'enable_earning'         => isset( $_POST['enable_earning'] ) ? 'yes' : 'no',
                'points_earning_type'    => 'remote',
                'earning_ratio'          => 1.0,
                'exclude_tax_shipping'   => isset( $_POST['exclude_tax_shipping'] ) ? 'yes' : 'no',
                'enable_redemption'      => isset( $_POST['enable_redemption'] ) ? 'yes' : 'no',
                'redemption_ratio'       => isset( $_POST['redemption_ratio'] ) ? max( 1, floatval( wp_unslash( $_POST['redemption_ratio'] ) ) ) : 1,
                'min_order_subtotal'     => isset( $_POST['min_order_subtotal'] ) ? max( 0, floatval( wp_unslash( $_POST['min_order_subtotal'] ) ) ) : 0,
                'max_redemption_percent' => isset( $_POST['max_redemption_percent'] ) ? min( 100, max( 1, floatval( wp_unslash( $_POST['max_redemption_percent'] ) ) ) ) : 1,
                'max_redemption_points'  => isset( $_POST['max_redemption_points'] ) ? max( 1, intval( wp_unslash( $_POST['max_redemption_points'] ) ) ) : 1,
            ];
            update_option( 'odude_reward_point_wc_settings', $wc_settings );

            // Sync the webhook based on the new WooCommerce settings toggles
            ODude_Reward_Point_Webhook_Manager::sync_webhook_state();

            wp_send_json_success( [ 'message' => __( 'WooCommerce settings saved.', 'odude-reward-point' ) ] );
        }

        wp_send_json_error( [ 'message' => __( 'Invalid request tab.', 'odude-reward-point' ) ] );
    }

    /**
     * Render the admin dashboard/settings page
     */
    public function render_admin_page() {
        $status           = get_option( 'odude_reward_point_connection_status', 'disconnected' );
        $api_url          = get_option( 'odude_reward_point_api_url', 'https://point.odude.com/api/v1' );
        $profile          = get_option( 'odude_reward_point_provider_profile', [] );
        $wp_settings      = get_option( 'odude_reward_point_wp_settings', [] );
        $wc_settings      = get_option( 'odude_reward_point_wc_settings', [] );

        ?>
        <div class="wrap wpreward-admin-wrap">
            <div class="wpreward-header">
                <h1><?php esc_html_e( 'ODude Reward Point - Loyalty Bridge', 'odude-reward-point' ); ?></h1>
                <?php if ( $status === 'connected' && ! empty( $profile ) ) : ?>
                    <div class="wpreward-connection-badge">
                        <span class="status-indicator connected"></span>
                        <?php 
                        printf( 
                            wp_kses(
                                // translators: %1$s: Profile URL, %2$s: Odude name, %3$s: Business name.
                                __( 'Connected to <a href="%1$s" target="_blank">%2$s</a> (%3$s)', 'odude-reward-point' ),
                                [
                                    'a' => [
                                        'href'   => [],
                                        'target' => [],
                                    ],
                                ]
                            ),
                            esc_url( 'https://odude.com/' . $profile['odude_name'] ),
                            esc_html( $profile['odude_name'] ),
                            esc_html( $profile['business_name'] )
                        ); 
                        ?>
                        <button type="button" id="wpreward-disconnect-btn" class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'odude-reward-point' ); ?></button>
                    </div>
                <?php endif; ?>
            </div>

            <div id="wpreward-ajax-feedback" class="notice notice-info" style="display:none;"><p></p></div>

            <?php if ( $status !== 'connected' ) : ?>
                <!-- Connection Setup Panel -->
                <div class="wpreward-card wpreward-connection-wizard">
                    <h2><?php esc_html_e( 'Link your ODude Reward Point Account', 'odude-reward-point' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Configure the backend Host URL and enter your merchant Secret Key to connect your store with the Loyalty Ledger.', 'odude-reward-point' ); ?></p>
                    
                    <div class="wpreward-info-box">
                        <p><strong><?php esc_html_e( 'Don\'t have an ODude account?', 'odude-reward-point' ); ?></strong></p>
                        <p>
                            <?php 
                            printf(
                                wp_kses(
                                    // translators: %s: ODude Points registration URL.
                                    __( 'If you do not have an ODude Name for your company, you can <a href="%s" target="_blank">register for free at point.odude.com</a> to get a free ODude Name and retrieve your required Secret Key.', 'odude-reward-point' ),
                                    [
                                        'a' => [
                                            'href'   => [],
                                            'target' => [],
                                        ],
                                    ]
                                ),
                                esc_url( 'https://point.odude.com/' )
                            ); 
                            ?>
                        </p>
                    </div>

                    <form id="wpreward-connection-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="wizard_api_url"><?php esc_html_e( 'API Host Endpoint', 'odude-reward-point' ); ?></label></th>
                                <td>
                                    <input type="url" id="wizard_api_url" class="regular-text" value="<?php echo esc_attr( $api_url ); ?>" required />
                                    <p class="description"><?php esc_html_e( 'Target API path, e.g. https://point.odude.com/api/v1', 'odude-reward-point' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="wizard_secret_key"><?php esc_html_e( 'Merchant Secret Key', 'odude-reward-point' ); ?></label></th>
                                <td>
                                    <input type="password" id="wizard_secret_key" class="regular-text" placeholder="op_sk_..." required />
                                    <p class="description"><?php esc_html_e( 'Enter your provider secret key generated from the ODude Points panel.', 'odude-reward-point' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <button type="submit" class="button button-primary button-hero" id="wpreward-connect-submit">
                            <?php esc_html_e( 'Verify & Connect Account', 'odude-reward-point' ); ?>
                        </button>
                    </form>
                </div>
            <?php else : ?>
                <!-- Settings Panel with Tabs -->
                <h2 class="nav-tab-wrapper">
                    <a href="#tab-dashboard" class="nav-tab nav-tab-active"><?php esc_html_e( 'Dashboard Stats', 'odude-reward-point' ); ?></a>
                    <a href="#tab-wordpress" class="nav-tab"><?php esc_html_e( 'WordPress Rewards', 'odude-reward-point' ); ?></a>
                    <a href="#tab-woocommerce" class="nav-tab"><?php esc_html_e( 'WooCommerce Rules', 'odude-reward-point' ); ?></a>
                </h2>

                <!-- TAB 1: DASHBOARD STATS -->
                <div id="tab-dashboard" class="wpreward-tab-content active">
                    <div class="wpreward-stats-sync-header">
                        <h3><?php esc_html_e( 'Ledger Balance Overview', 'odude-reward-point' ); ?></h3>
                        <button type="button" id="wpreward-sync-stats-btn" class="button alt"><?php esc_html_e( 'Sync Stats from Ledger', 'odude-reward-point' ); ?></button>
                    </div>

                    <?php 
                    $stats = ODude_Reward_Point_Cache_Manager::get_cached_admin_stats(); 
                    ?>
                    <div class="wpreward-kpi-grid">
                        <div class="wpreward-kpi-card">
                            <h4><?php esc_html_e( 'Points Awarded', 'odude-reward-point' ); ?></h4>
                            <span class="kpi-value" id="stats-awarded"><?php echo esc_html( isset( $stats['total_points_awarded'] ) ? $stats['total_points_awarded'] : '-' ); ?></span>
                        </div>
                        <div class="wpreward-kpi-card">
                            <h4><?php esc_html_e( 'Points Redeemed', 'odude-reward-point' ); ?></h4>
                            <span class="kpi-value" id="stats-redeemed"><?php echo esc_html( isset( $stats['total_points_redeemed'] ) ? $stats['total_points_redeemed'] : '-' ); ?></span>
                        </div>
                        <div class="wpreward-kpi-card">
                            <h4><?php esc_html_e( 'Net Liability (Net Points)', 'odude-reward-point' ); ?></h4>
                            <span class="kpi-value" id="stats-net"><?php echo esc_html( isset( $stats['net_points'] ) ? $stats['net_points'] : '-' ); ?></span>
                        </div>
                        <div class="wpreward-kpi-card">
                            <h4><?php esc_html_e( 'Total Sales Volume', 'odude-reward-point' ); ?></h4>
                            <span class="kpi-value" id="stats-sales"><?php echo esc_html( isset( $stats['total_sales'] ) ? ' ' . number_format( floatval( $stats['total_sales'] ), 2 ) : '-' ); ?></span>
                        </div>
                    </div>

                    <div class="wpreward-recent-ledger">
                        <h3><?php esc_html_e( 'Recent Activity Log', 'odude-reward-point' ); ?></h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'ID', 'odude-reward-point' ); ?></th>
                                    <th><?php esc_html_e( 'Points', 'odude-reward-point' ); ?></th>
                                    <th><?php esc_html_e( 'Type', 'odude-reward-point' ); ?></th>
                                    <th><?php esc_html_e( 'Remarks', 'odude-reward-point' ); ?></th>
                                    <th><?php esc_html_e( 'Date', 'odude-reward-point' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="stats-transactions-body">
                                <?php if ( ! empty( $stats['recent_transactions'] ) ) : ?>
                                    <?php foreach ( $stats['recent_transactions'] as $tx ) : ?>
                                        <tr>
                                            <td><code><?php echo esc_html( substr( $tx['id'], 0, 8 ) ); ?>...</code></td>
                                            <td><strong><?php echo esc_html( $tx['points'] ); ?></strong></td>
                                            <td><span class="tx-badge <?php echo esc_attr( $tx['type'] ); ?>"><?php echo esc_html( ucfirst( $tx['type'] ) ); ?></span></td>
                                            <td><?php echo esc_html( $tx['remarks'] ); ?></td>
                                            <td><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $tx['created_at'] ) ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center;"><?php esc_html_e( 'No recent transactions found.', 'odude-reward-point' ); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB 2: WORDPRESS REWARDS -->
                <div id="tab-wordpress" class="wpreward-tab-content">
                    <div class="wpreward-card">
                        <h3><?php esc_html_e( 'WordPress Core Activity Rewards', 'odude-reward-point' ); ?></h3>
                        <form id="wpreward-wp-form" class="wpreward-settings-form">
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="enable_wp_rewards"><?php esc_html_e( 'Master WordPress Rewards', 'odude-reward-point' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="enable_wp_rewards" name="enable_wp_rewards" value="1" <?php checked( isset( $wp_settings['enable_wp_rewards'] ) && $wp_settings['enable_wp_rewards'] === 'yes' ); ?> />
                                        <span class="description"><?php esc_html_e( 'Enable points generation for native WordPress activities.', 'odude-reward-point' ); ?></span>
                                    </td>
                                </tr>
                                <?php
                                 $wp_enabled = isset( $wp_settings['enable_wp_rewards'] ) && $wp_settings['enable_wp_rewards'] === 'yes';
                                 $wp_style = $wp_enabled ? '' : 'display: none;';
                                ?>
                                <tr class="wpreward-wp-dependent" style="<?php echo esc_attr( $wp_style ); ?>">
                                    <th scope="row"><label for="enable_registration"><?php esc_html_e( 'User Registration Reward', 'odude-reward-point' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="enable_registration" name="enable_registration" value="1" <?php checked( isset( $wp_settings['enable_registration'] ) && $wp_settings['enable_registration'] === 'yes' ); ?> />
                                        <input type="number" name="points_registration" class="small-text" value="<?php echo esc_attr( isset( $wp_settings['points_registration'] ) ? $wp_settings['points_registration'] : 50 ); ?>" />
                                        <span class="description"><?php esc_html_e( 'Points awarded to new user sign-ups.', 'odude-reward-point' ); ?></span>
                                    </td>
                                </tr>
                                <tr class="wpreward-wp-dependent" style="<?php echo esc_attr( $wp_style ); ?>">
                                    <th scope="row"><label for="enable_comment"><?php esc_html_e( 'Comment Submission Reward', 'odude-reward-point' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="enable_comment" name="enable_comment" value="1" <?php checked( isset( $wp_settings['enable_comment'] ) && $wp_settings['enable_comment'] === 'yes' ); ?> />
                                        <input type="number" name="points_comment" class="small-text" value="<?php echo esc_attr( isset( $wp_settings['points_comment'] ) ? $wp_settings['points_comment'] : 5 ); ?>" />
                                        <span class="description"><?php esc_html_e( 'Points awarded per approved comment.', 'odude-reward-point' ); ?></span>
                                    </td>
                                </tr>
                                <tr class="wpreward-wp-dependent" style="<?php echo esc_attr( $wp_style ); ?>">
                                    <th scope="row"><label for="max_comment_daily"><?php esc_html_e( 'Max Daily Comment Rewards', 'odude-reward-point' ); ?></label></th>
                                    <td>
                                        <input type="number" id="max_comment_daily" name="max_comment_daily" class="small-text" value="<?php echo esc_attr( isset( $wp_settings['max_comment_daily'] ) ? $wp_settings['max_comment_daily'] : 3 ); ?>" />
                                        <span class="description"><?php esc_html_e( 'Maximum number of comments per day that are eligible to earn points.', 'odude-reward-point' ); ?></span>
                                    </td>
                                </tr>
                                <tr class="wpreward-wp-dependent" style="<?php echo esc_attr( $wp_style ); ?>">
                                    <th scope="row"><label for="enable_daily_login"><?php esc_html_e( 'Daily Login Reward', 'odude-reward-point' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="enable_daily_login" name="enable_daily_login" value="1" <?php checked( isset( $wp_settings['enable_daily_login'] ) && $wp_settings['enable_daily_login'] === 'yes' ); ?> />
                                        <input type="number" name="points_daily_login" class="small-text" value="<?php echo esc_attr( isset( $wp_settings['points_daily_login'] ) ? $wp_settings['points_daily_login'] : 10 ); ?>" />
                                        <span class="description"><?php esc_html_e( 'Points awarded once per day when the user logs in.', 'odude-reward-point' ); ?></span>
                                    </td>
                                </tr>
                                <tr class="wpreward-wp-dependent" style="<?php echo esc_attr( $wp_style ); ?>">
                                    <th scope="row"><label for="enable_debug_logging"><?php esc_html_e( 'Developer Diagnostics', 'odude-reward-point' ); ?></label></th>
                                    <td>
                                        <input type="checkbox" id="enable_debug_logging" name="enable_debug_logging" value="1" <?php checked( isset( $wp_settings['enable_debug_logging'] ) && $wp_settings['enable_debug_logging'] === 'yes' ); ?> />
                                        <span class="description"><?php esc_html_e( 'Write detailed API request/response outputs to wp-content/uploads/wpreward-logs/debug.log.', 'odude-reward-point' ); ?></span>
                                    </td>
                                </tr>
                            </table>
                            <input type="hidden" name="tab" value="wp" />
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save WordPress Rules', 'odude-reward-point' ); ?></button>
                        </form>
                    </div>
                </div>

                <!-- TAB 3: WOOCOMMERCE RULES -->
                <div id="tab-woocommerce" class="wpreward-tab-content">
                    <?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
                        <div class="notice notice-warning inline">
                            <p><?php esc_html_e( 'WooCommerce is not active. Please install and activate WooCommerce to configure purchase earning rules and checkout redemption.', 'odude-reward-point' ); ?></p>
                        </div>
                    <?php else : ?>
                        <form id="wpreward-wc-form" class="wpreward-settings-form">
                            <!-- Earning Settings -->
                            <div class="wpreward-card">
                                <h3><?php esc_html_e( 'Purchase Earning Logic', 'odude-reward-point' ); ?></h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="enable_earning"><?php esc_html_e( 'Enable Order Earning', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="checkbox" id="enable_earning" name="enable_earning" value="1" <?php checked( isset( $wc_settings['enable_earning'] ) && $wc_settings['enable_earning'] === 'yes' ); ?> />
                                            <span class="description"><?php esc_html_e( 'Allows customers to earn loyalty points when completing orders. Toggling automatically registers/removes the WooCommerce Webhook.', 'odude-reward-point' ); ?></span>
                                        </td>
                                    </tr>

                                    <?php
                                     $wc_earning_enabled = isset( $wc_settings['enable_earning'] ) && $wc_settings['enable_earning'] === 'yes';
                                     $wc_earning_style = $wc_earning_enabled ? '' : 'display: none;';
                                     ?>
                                     <tr class="wpreward-wc-earning-dependent" style="<?php echo esc_attr( $wc_earning_style ); ?>">
                                        <th scope="row"><label for="exclude_tax_shipping"><?php esc_html_e( 'Exclude Tax & Shipping', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="checkbox" id="exclude_tax_shipping" name="exclude_tax_shipping" value="1" <?php checked( isset( $wc_settings['exclude_tax_shipping'] ) && $wc_settings['exclude_tax_shipping'] === 'yes' ); ?> />
                                            <span class="description"><?php esc_html_e( 'Calculate earned points using order subtotal instead of grand total.', 'odude-reward-point' ); ?></span>
                                        </td>
                                     </tr>
                                </table>
                            </div>

                            <!-- Redemption Settings -->
                            <div class="wpreward-card">
                                <h3><?php esc_html_e( 'Checkout Redemption (Spending Logic)', 'odude-reward-point' ); ?></h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row"><label for="enable_redemption"><?php esc_html_e( 'Enable Checkout Redemption', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="checkbox" id="enable_redemption" name="enable_redemption" value="1" <?php checked( isset( $wc_settings['enable_redemption'] ) && $wc_settings['enable_redemption'] === 'yes' ); ?> />
                                            <span class="description"><?php esc_html_e( 'Enables the points application widget on WooCommerce Checkout page.', 'odude-reward-point' ); ?></span>
                                        </td>
                                    </tr>
                                    <?php
                                     $wc_redemption_enabled = isset( $wc_settings['enable_redemption'] ) && $wc_settings['enable_redemption'] === 'yes';
                                     $wc_redemption_style = $wc_redemption_enabled ? '' : 'display: none;';
                                     ?>
                                     <tr class="wpreward-wc-redemption-dependent" style="<?php echo esc_attr( $wc_redemption_style ); ?>">
                                        <th scope="row"><label for="redemption_ratio"><?php esc_html_e( 'Redemption Ratio', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="number" id="redemption_ratio" name="redemption_ratio" step="1" class="small-text" value="<?php echo esc_attr( isset( $wc_settings['redemption_ratio'] ) ? $wc_settings['redemption_ratio'] : 100 ); ?>" />
                                            <span class="description"><?php esc_html_e( 'Number of points needed for a $1 discount (e.g., 100 points = $1 discount).', 'odude-reward-point' ); ?></span>
                                        </td>
                                     </tr>
                                     <tr class="wpreward-wc-redemption-dependent" style="<?php echo esc_attr( $wc_redemption_style ); ?>">
                                        <th scope="row"><label for="min_order_subtotal"><?php esc_html_e( 'Minimum Cart Subtotal', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="number" id="min_order_subtotal" name="min_order_subtotal" step="0.01" class="small-text" value="<?php echo esc_attr( isset( $wc_settings['min_order_subtotal'] ) ? $wc_settings['min_order_subtotal'] : 0 ); ?>" />
                                            <span class="description"><?php esc_html_e( 'Minimum order value in currency required to use points at checkout.', 'odude-reward-point' ); ?></span>
                                        </td>
                                     </tr>
                                     <tr class="wpreward-wc-redemption-dependent" style="<?php echo esc_attr( $wc_redemption_style ); ?>">
                                        <th scope="row"><label for="max_redemption_percent"><?php esc_html_e( 'Max Subtotal Discount %', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="number" id="max_redemption_percent" name="max_redemption_percent" step="1" class="small-text" value="<?php echo esc_attr( isset( $wc_settings['max_redemption_percent'] ) ? $wc_settings['max_redemption_percent'] : 50 ); ?>" />
                                            <span class="description"><?php esc_html_e( 'Maximum percentage of order subtotal discount allowed via loyalty points (e.g. 50%).', 'odude-reward-point' ); ?></span>
                                        </td>
                                     </tr>
                                     <tr class="wpreward-wc-redemption-dependent" style="<?php echo esc_attr( $wc_redemption_style ); ?>">
                                        <th scope="row"><label for="max_redemption_points"><?php esc_html_e( 'Max Points Per Order', 'odude-reward-point' ); ?></label></th>
                                        <td>
                                            <input type="number" id="max_redemption_points" name="max_redemption_points" step="1" class="small-text" value="<?php echo esc_attr( isset( $wc_settings['max_redemption_points'] ) ? $wc_settings['max_redemption_points'] : 1000 ); ?>" />
                                            <span class="description"><?php esc_html_e( 'Hard cap on the maximum points a customer can redeem in a single purchase.', 'odude-reward-point' ); ?></span>
                                        </td>
                                     </tr>
                                </table>
                            </div>

                            <input type="hidden" name="tab" value="wc" />
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save WooCommerce Rules', 'odude-reward-point' ); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
