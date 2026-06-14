<?php
/**
 * Frontend Views and Shortcodes Class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Universal_Reward_Public {

    public function __construct() {
        // Register Shortcodes
        add_shortcode( 'universal_reward_balance', [ $this, 'render_balance_shortcode' ] );
        add_shortcode( 'universal_reward_history', [ $this, 'render_history_shortcode' ] );

        // AJAX handler for customer sync
        add_action( 'wp_ajax_universal_reward_sync_customer_data', [ $this, 'ajax_sync_customer_data' ] );
    }

    /**
     * Shortcode: Renders logged-in user's points balance
     * Usage: [universal_reward_balance]
     */
    public function render_balance_shortcode( $atts ) {
        if ( get_option( 'universal_reward_connection_status' ) !== 'connected' ) {
            return '';
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return esc_html__( 'Please log in to view points.', 'universal-reward' );
        }

        $balance = Universal_Reward_Cache_Manager::get_cached_customer_balance( $user_id );

        return '<span class="universal-reward-points-balance">' . esc_html( $balance ) . '</span>';
    }

    /**
     * Shortcode: Renders a transaction history ledger for the customer
     * Usage: [universal_reward_history]
     */
    public function render_history_shortcode( $atts ) {
        if ( get_option( 'universal_reward_connection_status' ) !== 'connected' ) {
            return '';
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<p class="universal-reward-login-prompt">' . esc_html__( 'Log in to check your loyalty transaction history.', 'universal-reward' ) . '</p>';
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return '';
        }

        // Cache customer transaction data for 10 minutes to minimize API hits
        $cached_txs   = get_user_meta( $user_id, '_universal_reward_customer_history', true );
        $last_updated = get_user_meta( $user_id, '_universal_reward_customer_history_updated', true );
        $cache_time   = 10 * MINUTE_IN_SECONDS;

        if ( ! is_array( $cached_txs ) || empty( $last_updated ) || ( time() - $last_updated ) > $cache_time ) {
            $api = new Universal_Reward_API_Client();
            $response = $api->get_customer_balance( $user->user_email );

            if ( ! empty( $response['success'] ) && isset( $response['customer']['transactions'] ) ) {
                $cached_txs = $response['customer']['transactions'];
                update_user_meta( $user_id, '_universal_reward_customer_history', $cached_txs );
                update_user_meta( $user_id, '_universal_reward_customer_history_updated', time() );
                
                // Keep the cached balance fresh too
                if ( isset( $response['customer']['points_balance'] ) ) {
                    Universal_Reward_Cache_Manager::update_local_customer_balance( $user_id, $response['customer']['points_balance'] );
                }
            } else {
                $cached_txs = is_array( $cached_txs ) ? $cached_txs : [];
            }
        }

        // Build HTML Table output
        ob_start();
        ?>
        <div class="universal-reward-history-container">
            <div class="universal-reward-history-header">
                <h4 class="universal-reward-history-title"><?php esc_html_e( 'Your Point Transactions', 'universal-reward' ); ?></h4>
                <button type="button" class="universal-reward-sync-btn button">
                    <svg class="universal-reward-sync-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                    <?php esc_html_e( 'Sync Balance', 'universal-reward' ); ?>
                </button>
            </div>
            <table class="universal-reward-history-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Points', 'universal-reward' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'universal-reward' ); ?></th>
                        <th><?php esc_html_e( 'Details', 'universal-reward' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'universal-reward' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $cached_txs ) ) : ?>
                        <?php foreach ( $cached_txs as $tx ) : 
                            $is_award = ( $tx['type'] === 'award' );
                            $badge_class = $is_award ? 'universal-reward-tx-award' : 'universal-reward-tx-redeem';
                            $sign = $is_award ? '+' : '-';
                            ?>
                            <tr>
                                <td class="universal-reward-tx-points <?php echo esc_attr( $badge_class ); ?>">
                                    <strong><?php echo esc_html( $sign . $tx['points'] ); ?></strong>
                                </td>
                                <td>
                                    <span class="universal-reward-badge <?php echo esc_attr( $badge_class ); ?>">
                                        <?php echo esc_html( ucfirst( $tx['type'] ) ); ?>
                                    </span>
                                </td>
                                <td class="universal-reward-tx-remarks"><?php echo esc_html( $tx['remarks'] ); ?></td>
                                <td class="universal-reward-tx-date"><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $tx['created_at'] ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4" class="universal-reward-no-transactions" style="text-align: center;"><?php esc_html_e( 'No point transactions registered yet.', 'universal-reward' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler to sync and retrieve fresh customer balance & history from remote ledger
     */
    public function ajax_sync_customer_data() {
        check_ajax_referer( 'universal-reward-nonce', 'security' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'universal-reward' ) ] );
        }

        // Purge the local caches first
        Universal_Reward_Cache_Manager::purge_customer_cache( $user_id );

        $balance = 0;
        $cached_txs = [];
        $user = get_userdata( $user_id );

        if ( $user ) {
            $api = new Universal_Reward_API_Client();
            $response = $api->get_customer_balance( $user->user_email );

            if ( ! empty( $response['success'] ) && isset( $response['customer'] ) ) {
                if ( isset( $response['customer']['points_balance'] ) ) {
                    $balance = intval( $response['customer']['points_balance'] );
                    Universal_Reward_Cache_Manager::update_local_customer_balance( $user_id, $balance );
                }
                if ( isset( $response['customer']['transactions'] ) ) {
                    $cached_txs = $response['customer']['transactions'];
                    update_user_meta( $user_id, '_universal_reward_customer_history', $cached_txs );
                    update_user_meta( $user_id, '_universal_reward_customer_history_updated', time() );
                }
            }
        }

        // Render the new transaction history HTML table rows
        ob_start();
        ?>
        <?php if ( ! empty( $cached_txs ) ) : ?>
            <?php foreach ( $cached_txs as $tx ) : 
                $is_award = ( $tx['type'] === 'award' );
                $badge_class = $is_award ? 'universal-reward-tx-award' : 'universal-reward-tx-redeem';
                $sign = $is_award ? '+' : '-';
                ?>
                <tr>
                    <td class="universal-reward-tx-points <?php echo esc_attr( $badge_class ); ?>">
                        <strong><?php echo esc_html( $sign . $tx['points'] ); ?></strong>
                    </td>
                    <td>
                        <span class="universal-reward-badge <?php echo esc_attr( $badge_class ); ?>">
                            <?php echo esc_html( ucfirst( $tx['type'] ) ); ?>
                        </span>
                    </td>
                    <td class="universal-reward-tx-remarks"><?php echo esc_html( $tx['remarks'] ); ?></td>
                    <td class="universal-reward-tx-date"><?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $tx['created_at'] ) ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr>
                <td colspan="4" class="universal-reward-no-transactions" style="text-align: center;"><?php esc_html_e( 'No point transactions registered yet.', 'universal-reward' ); ?></td>
            </tr>
        <?php endif; ?>
        <?php
        $history_html = ob_get_clean();

        wp_send_json_success( [
            'balance'      => $balance,
            'history_html' => $history_html,
            'message'      => __( 'Loyalty points and history synced.', 'universal-reward' )
        ] );
    }
}
