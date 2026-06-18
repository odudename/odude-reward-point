<?php
/**
 * Handles WordPress Core Activity Rewards
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ODude_Reward_Point_WP {

    public function __construct() {
        // 1. User Registration Hook
        add_action( 'user_register', [ $this, 'reward_registration' ] );

        // 2. Comment Approval Hook (for comments approved later)
        add_action( 'transition_comment_status', [ $this, 'reward_comment' ], 10, 3 );

        // 3. New Comment Submission Hook (for auto-approved comments on submission)
        add_action( 'comment_post', [ $this, 'reward_new_comment' ], 10, 3 );

        // 4. Daily Login Hook
        add_action( 'wp_login', [ $this, 'reward_daily_login' ], 10, 2 );
    }

    /**
     * Check if WordPress core rewards are active
     */
    private function is_active() {
        if ( get_option( 'odude_reward_point_connection_status' ) !== 'connected' ) {
            return false;
        }
        $settings = get_option( 'odude_reward_point_wp_settings', [] );
        return ( ! empty( $settings['enable_wp_rewards'] ) && $settings['enable_wp_rewards'] === 'yes' );
    }

    /**
     * Helper to award points and sync local cache
     */
    private function process_award( $user_id, $points, $remarks ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $api = new ODude_Reward_Point_API_Client();
        // Send email and empty phone, amount is 0 since it is flat points, and deserved_point is set
        $response = $api->award_points( $user->user_email, '', 0, $points, $remarks );

        if ( ! empty( $response['success'] ) ) {
            require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-core.php'; // ensure cache manager is loaded
            ODude_Reward_Point_Cache_Manager::purge_customer_cache( $user_id );

            // Update local user meta cache
            $balance_response = $api->get_customer_balance( $user->user_email );
            if ( ! empty( $balance_response['success'] ) && isset( $balance_response['customer']['points_balance'] ) ) {
                ODude_Reward_Point_Cache_Manager::update_local_customer_balance(
                    $user_id,
                    $balance_response['customer']['points_balance']
                );
            }
            ODude_Reward_Point_Cache_Manager::purge_stats_cache();
        }
    }

    /**
     * Reward user registration
     */
    public function reward_registration( $user_id ) {
        if ( ! $this->is_active() ) {
            return;
        }

        $settings = get_option( 'odude_reward_point_wp_settings', [] );
        if ( empty( $settings['enable_registration'] ) || $settings['enable_registration'] !== 'yes' ) {
            return;
        }

        $points = isset( $settings['points_registration'] ) ? intval( $settings['points_registration'] ) : 0;
        if ( $points <= 0 ) {
            return;
        }

        $remarks = __( 'Account registration reward', 'odude-reward-point' );
        $this->process_award( $user_id, $points, $remarks );
    }

    /**
     * Helper to process comment rewards with idempotency check
     */
    private function process_comment_reward( $comment ) {
        if ( ! $this->is_active() ) {
            return;
        }

        $comment_id = intval( $comment->comment_ID );
        if ( $comment_id <= 0 ) {
            return;
        }

        // Idempotency check: prevent double rewarding
        if ( get_comment_meta( $comment_id, '_odude_reward_point_awarded', true ) === 'yes' ) {
            return;
        }

        $user_id = intval( $comment->user_id );
        if ( $user_id <= 0 ) {
            return; // Only reward registered users
        }

        $settings = get_option( 'odude_reward_point_wp_settings', [] );
        if ( empty( $settings['enable_comment'] ) || $settings['enable_comment'] !== 'yes' ) {
            return;
        }

        $points = isset( $settings['points_comment'] ) ? intval( $settings['points_comment'] ) : 0;
        if ( $points <= 0 ) {
            return;
        }

        // Apply daily limit
        $max_daily = isset( $settings['max_comment_daily'] ) ? intval( $settings['max_comment_daily'] ) : 3;
        
        $today_start = strtotime( 'today' );
        $today_end   = strtotime( 'tomorrow' ) - 1;

        $args = [
            'user_id' => $user_id,
            'count'   => true,
            'date_query' => [
                [
                    'after'     => gmdate( 'Y-m-d H:i:s', $today_start ),
                    'before'    => gmdate( 'Y-m-d H:i:s', $today_end ),
                    'inclusive' => true,
                ],
            ],
            'status' => 'approve',
        ];

        $comments_count = get_comments( $args );

        // If the user has exceeded comments, don't reward
        if ( $comments_count > $max_daily ) {
            return;
        }

        // Mark as rewarded *before* making the API request to prevent race conditions
        update_comment_meta( $comment_id, '_odude_reward_point_awarded', 'yes' );

        // translators: %d: post ID.
        $remarks = sprintf( __( 'Reward for comment on post #%d', 'odude-reward-point' ), $comment->comment_post_ID );
        
        // Award points
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $api = new ODude_Reward_Point_API_Client();
        $response = $api->award_points( $user->user_email, '', 0, $points, $remarks );

        if ( empty( $response['success'] ) ) {
            // Revert reward flag if the API call failed
            delete_comment_meta( $comment_id, '_odude_reward_point_awarded' );
            return;
        }

        // Sync local cache
        require_once ODUDE_REWARD_POINT_PATH . 'includes/class-odude-reward-point-core.php';
        ODude_Reward_Point_Cache_Manager::purge_customer_cache( $user_id );

        // Update local user meta cache
        $balance_response = $api->get_customer_balance( $user->user_email );
        if ( ! empty( $balance_response['success'] ) && isset( $balance_response['customer']['points_balance'] ) ) {
            ODude_Reward_Point_Cache_Manager::update_local_customer_balance(
                $user_id,
                $balance_response['customer']['points_balance']
            );
        }
        ODude_Reward_Point_Cache_Manager::purge_stats_cache();
    }

    /**
     * Reward approved comments (transition status change)
     */
    public function reward_comment( $new_status, $old_status, $comment ) {
        if ( $new_status === 'approved' ) {
            $this->process_comment_reward( $comment );
        }
    }

    /**
     * Reward new comments (fired immediately on comment creation)
     */
    public function reward_new_comment( $comment_id, $comment_approved, $commentdata ) {
        // '1' or 1 means approved
        if ( $comment_approved === 1 || $comment_approved === '1' ) {
            $comment = get_comment( $comment_id );
            if ( $comment ) {
                $this->process_comment_reward( $comment );
            }
        }
    }

    /**
     * Reward daily login
     */
    public function reward_daily_login( $user_login, $user ) {
        if ( ! $this->is_active() ) {
            return;
        }

        $settings = get_option( 'odude_reward_point_wp_settings', [] );
        if ( empty( $settings['enable_daily_login'] ) || $settings['enable_daily_login'] !== 'yes' ) {
            return;
        }

        $points = isset( $settings['points_daily_login'] ) ? intval( $settings['points_daily_login'] ) : 0;
        if ( $points <= 0 ) {
            return;
        }

        $user_id = $user->ID;
        $today = gmdate( 'Y-m-d' );
        $last_login_reward = get_user_meta( $user_id, '_odude_reward_point_last_login_reward', true );

        if ( $last_login_reward === $today ) {
            return; // Already rewarded today
        }

        // Store login reward marker first to prevent race condition concurrency issues
        update_user_meta( $user_id, '_odude_reward_point_last_login_reward', $today );

        $remarks = __( 'Daily login loyalty bonus', 'odude-reward-point' );
        $this->process_award( $user_id, $points, $remarks );
    }
}
