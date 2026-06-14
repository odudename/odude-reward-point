<?php
/**
 * WooCommerce Integration Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Universal_Reward_WC {

    public function __construct() {
        // Only hook if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // 1. Render Points Form at Checkout
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_checkout_points_widget' ], 15 );

        // 2. AJAX handlers for applying/removing points
        add_action( 'wp_ajax_universal_reward_apply_points', [ $this, 'ajax_apply_points' ] );
        add_action( 'wp_ajax_universal_reward_remove_points', [ $this, 'ajax_remove_points' ] );

        // 3. Apply discount fee in cart
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_points_discount_fee' ], 20, 1 );

        // 4. Order Checkout Complete (Execute Redemption API)
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'process_redemption' ], 10, 3 );

        // 5. Clear Caches on Order Status Changes (E.g. Completed order updates stats and user cache)
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status_changed' ], 10, 4 );
    }

    /**
     * Check if WooCommerce settings are active
     */
    private function is_connected() {
        return ( get_option( 'universal_reward_connection_status' ) === 'connected' );
    }

    /**
     * Render the Checkout Loyalty slider
     */
    public function render_checkout_points_widget() {
        if ( ! $this->is_connected() ) {
            return;
        }

        $wc_settings = get_option( 'universal_reward_wc_settings', [] );
        if ( empty( $wc_settings['enable_redemption'] ) || $wc_settings['enable_redemption'] !== 'yes' ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            // Ask guests to log in to redeem points
            echo '<div class="universal-reward-checkout-notice notice info">';
            printf( 
                wp_kses( 
                    // translators: %s: Log in page URL.
                    __( 'Have an account? <a href="%s">Log in</a> to redeem your ODude Loyalty Points.', 'universal-reward' ), 
                    [ 'a' => [ 'href' => [] ] ] 
                ), 
                esc_url( wc_get_page_permalink( 'myaccount' ) ) 
            ); 
            echo '</div>';
            return;
        }

        // Get cached points balance
        $balance = Universal_Reward_Cache_Manager::get_cached_customer_balance( $user_id );
        if ( $balance <= 0 ) {
            return;
        }

        $redemption_ratio = isset( $wc_settings['redemption_ratio'] ) ? floatval( $wc_settings['redemption_ratio'] ) : 100;
        $monetary_value  = $balance / $redemption_ratio;

        // Fetch current cart subtotal
        $cart_subtotal = WC()->cart->get_subtotal();
        $min_subtotal  = isset( $wc_settings['min_order_subtotal'] ) ? floatval( $wc_settings['min_order_subtotal'] ) : 0;

        if ( $cart_subtotal < $min_subtotal ) {
            return; // Subtotal too low
        }

        // Calculate max allowed discount
        $max_percent = isset( $wc_settings['max_redemption_percent'] ) ? floatval( $wc_settings['max_redemption_percent'] ) : 50;
        $max_discount = ($cart_subtotal * $max_percent) / 100;

        $max_points_cap = isset( $wc_settings['max_redemption_points'] ) ? intval( $wc_settings['max_redemption_points'] ) : 1000;
        $max_discount_points = min( $balance, $max_points_cap, floor( $max_discount * $redemption_ratio ) );

        $applied_points = WC()->session->get( 'universal_reward_applied_points', 0 );

        // Render Widget
        ?>
        <div class="universal-reward-checkout-wrapper">
            <div class="woocommerce-info universal-reward-checkout-toggle">
                <?php if ( $applied_points > 0 ) : ?>
                    <?php 
                    echo wp_kses(
                        sprintf( 
                            // translators: %s: Link tag for editing applied points.
                            __( 'ODude Reward Points applied. %s', 'universal-reward' ), 
                            '<a href="#" id="universal-reward-toggle-btn">' . __( 'Click here to edit', 'universal-reward' ) . '</a>'
                        ),
                        [ 'a' => [ 'href' => [], 'id' => [] ] ]
                    );
                    ?>
                <?php else : ?>
                    <?php 
                    echo wp_kses(
                        sprintf( 
                            // translators: %s: Link tag to enter points.
                            __( 'Have ODude Reward Points? %s', 'universal-reward' ), 
                            '<a href="#" id="universal-reward-toggle-btn">' . __( 'Click here to enter your points', 'universal-reward' ) . '</a>'
                        ),
                        [ 'a' => [ 'href' => [], 'id' => [] ] ]
                    );
                    ?>
                <?php endif; ?>
            </div>

            <div class="universal-reward-checkout-content" style="<?php echo $applied_points > 0 ? 'display: block;' : 'display: none;'; ?>">
                <div class="universal-reward-checkout-form-inner<?php echo $applied_points > 0 ? ' universal-reward-applied' : ''; ?>">
                    <?php if ( $applied_points > 0 ) : ?>
                        <div class="universal-reward-form-row universal-reward-applied-info">
                            <?php 
                             echo wp_kses(
                                 sprintf( 
                                     // translators: %1$s: Number of applied points, %2$s: Discount value.
                                     __( 'Applied %1$s points for a discount of %2$s.', 'universal-reward' ), 
                                     '<strong>' . esc_html( $applied_points ) . '</strong>', 
                                     '<strong>' . wc_price( $applied_points / $redemption_ratio ) . '</strong>' 
                                 ),
                                 [
                                     'strong' => [],
                                     'span'   => [ 'class' => [] ],
                                     'bdi'    => [],
                                 ]
                             );
                            ?>
                        </div>
                        <div class="universal-reward-form-row universal-reward-action-row">
                            <button type="button" id="universal-reward-remove-btn" class="button"><?php esc_html_e( 'Remove Points', 'universal-reward' ); ?></button>
                        </div>
                    <?php else : ?>
                        <div class="universal-reward-form-row universal-reward-info-row">
                            <span class="universal-reward-balance-info">
                                <?php 
                                 echo wp_kses(
                                     sprintf( 
                                         // translators: %1$s: Points count, %2$s: Monetary value.
                                         __( 'You have %1$s ODude points (worth %2$s).', 'universal-reward' ), 
                                         '<strong>' . esc_html( $balance ) . '</strong>', 
                                         '<strong>' . wc_price( $monetary_value ) . '</strong>' 
                                     ),
                                     [
                                         'strong' => [],
                                         'span'   => [ 'class' => [] ],
                                         'bdi'    => [],
                                     ]
                                 );
                                ?>
                            </span>
                            <span class="universal-reward-helper-text">
                                <?php 
                                 printf( 
                                     // translators: %d: Maximum allowed points.
                                     esc_html__( 'Maximum redeemable: %d points.', 'universal-reward' ), 
                                     esc_html( $max_discount_points ) 
                                 ); 
                                ?>
                            </span>
                        </div>
                        <div class="universal-reward-form-row universal-reward-input-row">
                            <div class="universal-reward-input-group">
                                <label for="universal-reward-points-input" class="universal-reward-input-label"><?php esc_html_e( 'Enter points to redeem:', 'universal-reward' ); ?></label>
                                <input type="number" id="universal-reward-points-input" min="1" max="<?php echo esc_attr( $max_discount_points ); ?>" value="<?php echo esc_attr( $max_discount_points ); ?>" />
                                <button type="button" id="universal-reward-apply-btn" class="button alt"><?php esc_html_e( 'Apply Points', 'universal-reward' ); ?></button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to apply loyalty points
     */
    public function ajax_apply_points() {
        check_ajax_referer( 'universal-reward-nonce', 'security' );

        if ( ! $this->is_connected() ) {
            wp_send_json_error( [ 'message' => __( 'Plugin is not connected to ledger.', 'universal-reward' ) ] );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'universal-reward' ) ] );
        }

        $points = isset( $_POST['points'] ) ? intval( wp_unslash( $_POST['points'] ) ) : 0;
        if ( $points <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid points amount.', 'universal-reward' ) ] );
        }

        $balance = Universal_Reward_Cache_Manager::get_cached_customer_balance( $user_id );
        if ( $points > $balance ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient points balance.', 'universal-reward' ) ] );
        }

        $wc_settings      = get_option( 'universal_reward_wc_settings', [] );
        $redemption_ratio = isset( $wc_settings['redemption_ratio'] ) ? floatval( $wc_settings['redemption_ratio'] ) : 100;
        $max_points_cap   = isset( $wc_settings['max_redemption_points'] ) ? intval( $wc_settings['max_redemption_points'] ) : 1000;

        if ( $points > $max_points_cap ) {
            // translators: %d: Maximum allowed points.
            wp_send_json_error( [ 'message' => sprintf( __( 'Cannot redeem more than %d points.', 'universal-reward' ), $max_points_cap ) ] );
        }

        // Apply dynamic discount values
        $discount_amount = $points / $redemption_ratio;

        WC()->session->set( 'universal_reward_applied_points', $points );
        WC()->session->set( 'universal_reward_discount_amount', $discount_amount );

        // Force cart recalculation
        WC()->cart->calculate_totals();

        // Capture updated widget HTML
        ob_start();
        $this->render_checkout_points_widget();
        $html = ob_get_clean();

        wp_send_json_success( [
            'message' => __( 'Points applied successfully.', 'universal-reward' ),
            'html'    => $html,
        ] );
    }

    /**
     * AJAX handler to remove applied loyalty points
     */
    public function ajax_remove_points() {
        check_ajax_referer( 'universal-reward-nonce', 'security' );

        WC()->session->set( 'universal_reward_applied_points', 0 );
        WC()->session->set( 'universal_reward_discount_amount', 0 );

        WC()->cart->calculate_totals();

        // Capture updated widget HTML
        ob_start();
        $this->render_checkout_points_widget();
        $html = ob_get_clean();

        wp_send_json_success( [
            'message' => __( 'Points removed successfully.', 'universal-reward' ),
            'html'    => $html,
        ] );
    }

    /**
     * Apply discount fee to checkout total
     */
    public function apply_points_discount_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $discount_amount = WC()->session->get( 'universal_reward_discount_amount', 0 );
        if ( $discount_amount > 0 ) {
            $cart->add_fee( 
                __( 'ODude Points Discount', 'universal-reward' ), 
                -$discount_amount, 
                false // Points discount is typically non-taxable
            );
        }
    }

    /**
     * Deduct points securely on order completion
     */
    public function process_redemption( $order_id, $posted_data, $order ) {
        $points = WC()->session->get( 'universal_reward_applied_points', 0 );
        $discount = WC()->session->get( 'universal_reward_discount_amount', 0 );

        if ( $points <= 0 ) {
            return;
        }

        $user_id = $order->get_user_id();
        $email = '';
        if ( $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $email = $user->user_email;
            }
        }
        if ( empty( $email ) ) {
            $email = $order->get_billing_email();
        }
        $api = new Universal_Reward_API_Client();
        
        // translators: %d: Order ID.
        $remarks = sprintf( __( 'Redeemed for WooCommerce Order #%d', 'universal-reward' ), $order_id );
        
        $response = $api->redeem_points( $email, $points, $remarks );

        if ( ! empty( $response['success'] ) ) {
            update_post_meta( $order_id, '_universal_reward_redeemed_points', $points );
            update_post_meta( $order_id, '_universal_reward_redeemed_amount', $discount );

            $user_id = $order->get_user_id();
            if ( $user_id ) {
                Universal_Reward_Cache_Manager::purge_customer_cache( $user_id );
                Universal_Reward_Cache_Manager::update_local_customer_balance( $user_id, $response['new_balance'] );
            }

            // translators: %d: Redeemed points.
            $order->add_order_note( sprintf( __( 'Deducted %d ODude points for order discount.', 'universal-reward' ), $points ) );

            // Clear checkout session
            WC()->session->set( 'universal_reward_applied_points', 0 );
            WC()->session->set( 'universal_reward_discount_amount', 0 );
            
            Universal_Reward_Cache_Manager::purge_stats_cache();
        } else {
            // API returned failure - prevent checkout completing with unpaid points
            $error_message = ! empty( $response['error'] ) ? $response['error'] : __( 'Failed to deduct your ODude Points. Please adjust points and try again.', 'universal-reward' );
            throw new Exception( esc_html( $error_message ) );
        }
    }

    /**
     * Hook to clear cache on status change (e.g., when WooCommerce webhook executes async)
     */
    public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        // Clear merchant statistics
        Universal_Reward_Cache_Manager::purge_stats_cache();

        // Clear and force update the user points cache
        $user_id = $order->get_user_id();
        if ( $user_id ) {
            Universal_Reward_Cache_Manager::purge_customer_cache( $user_id );
        }

        // If order changes to refunded or cancelled, restore points if they were redeemed
        if ( in_array( $new_status, [ 'cancelled', 'refunded' ], true ) ) {
            $redeemed_points = intval( get_post_meta( $order_id, '_universal_reward_redeemed_points', true ) );
            if ( $redeemed_points > 0 && ! get_post_meta( $order_id, '_universal_reward_redeem_refunded', true ) ) {
                $user_id = $order->get_user_id();
                $email = '';
                if ( $user_id ) {
                    $user = get_userdata( $user_id );
                    if ( $user ) {
                        $email = $user->user_email;
                    }
                }
                if ( empty( $email ) ) {
                    $email = $order->get_billing_email();
                }
                $api = new Universal_Reward_API_Client();
                
                // translators: %s: Order ID.
                $remarks = sprintf( __( 'Refund points for Order #%s', 'universal-reward' ), $order_id );
                $response = $api->award_points( $email, '', 0, $redeemed_points, $remarks );

                if ( ! empty( $response['success'] ) ) {
                    update_post_meta( $order_id, '_universal_reward_redeem_refunded', true );
                    // translators: %d: Restored points count.
                    $order->add_order_note( sprintf( __( 'Restored %d ODude points due to order cancellation/refund.', 'universal-reward' ), $redeemed_points ) );
                    
                    if ( $user_id ) {
                        // Resync user balance
                        Universal_Reward_Cache_Manager::purge_customer_cache( $user_id );
                        $cust_response = $api->get_customer_balance( $email );
                        if ( ! empty( $cust_response['success'] ) && isset( $cust_response['customer']['points_balance'] ) ) {
                            Universal_Reward_Cache_Manager::update_local_customer_balance( $user_id, $cust_response['customer']['points_balance'] );
                        }
                    }
                }
            }
        }
    }
}
