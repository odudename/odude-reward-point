<?php
/**
 * WooCommerce Webhook Manager for universal-reward
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Universal_Reward_Webhook_Manager {

    /**
     * Get target delivery URL for points awarding
     */
    private static function get_award_url() {
        $api_url    = get_option( 'universal_reward_api_url' );
        $secret_key = get_option( 'universal_reward_secret_key' );
        if ( ! $api_url || ! $secret_key ) {
            return '';
        }
        return add_query_arg( 'secret_key', $secret_key, untrailingslashit( $api_url ) . '/award' );
    }

    /**
     * Get target delivery URL for points deduction (redeem)
     */
    private static function get_redeem_url() {
        $api_url    = get_option( 'universal_reward_api_url' );
        $secret_key = get_option( 'universal_reward_secret_key' );
        if ( ! $api_url || ! $secret_key ) {
            return '';
        }
        return add_query_arg( 'secret_key', $secret_key, untrailingslashit( $api_url ) . '/redeem' );
    }

    /**
     * Delete old legacy webhook stored under universal_reward_wc_webhook_id
     */
    private static function delete_legacy_webhook() {
        $legacy_id = get_option( 'universal_reward_wc_webhook_id' );
        if ( $legacy_id ) {
            if ( class_exists( 'WC_Webhook' ) ) {
                try {
                    $web = new WC_Webhook( $legacy_id );
                    if ( $web && $web->get_id() > 0 ) {
                        $web->delete( true ); // Force delete from DB
                    }
                } catch ( Exception $e ) {
                    // Ignore
                }
            }
            delete_option( 'universal_reward_wc_webhook_id' );
        }
    }

    /**
     * Programmatically create WooCommerce Webhooks (both Award and Redeem)
     */
    public static function create_webhook() {
        if ( ! class_exists( 'WC_Webhook' ) ) {
            return false;
        }

        $secret_key = get_option( 'universal_reward_secret_key' );
        if ( empty( $secret_key ) ) {
            return false;
        }

        // Get an active administrator user ID to set as owner to prevent REST API 401 payload errors
        $admin_users = get_users( array(
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ) );
        $admin_id = 1;
        if ( ! empty( $admin_users ) ) {
            $admin_id = $admin_users[0]->ID;
        }

        // Clean up legacy webhook if any
        self::delete_legacy_webhook();

        // 1. Award Webhook (order.created)
        $award_url = self::get_award_url();
        if ( ! empty( $award_url ) ) {
            $existing_award_id = get_option( 'universal_reward_wc_webhook_award_id' );
            $need_create_award = true;
            if ( $existing_award_id ) {
                try {
                    $web = new WC_Webhook( $existing_award_id );
                    if ( $web && $web->get_id() > 0 ) {
                        $dirty = false;
                        if ( $web->get_delivery_url() !== $award_url ) {
                            $web->set_delivery_url( $award_url );
                            $dirty = true;
                        }
                        if ( $web->get_secret() !== $secret_key ) {
                            $web->set_secret( $secret_key );
                            $dirty = true;
                        }
                        if ( $web->get_topic() !== 'order.created' ) {
                            $web->set_topic( 'order.created' );
                            $dirty = true;
                        }
                        if ( $web->get_user_id() !== $admin_id ) {
                            $web->set_user_id( $admin_id );
                            $dirty = true;
                        }
                        if ( $dirty ) {
                            $web->save();
                        }
                        $need_create_award = false;
                    }
                } catch ( Exception $e ) {
                    delete_option( 'universal_reward_wc_webhook_award_id' );
                }
            }

            if ( $need_create_award ) {
                try {
                    $web = new WC_Webhook();
                    $web->set_name( 'ODude Reward Point - Automated Earning Webhook' );
                    $web->set_status( 'active' );
                    $web->set_topic( 'order.created' );
                    $web->set_delivery_url( $award_url );
                    $web->set_secret( $secret_key );
                    $web->set_user_id( $admin_id );
                    $web->set_api_version( 'wp_api_v3' );
                    $web->save();
                    update_option( 'universal_reward_wc_webhook_award_id', $web->get_id() );
                } catch ( Exception $e ) {
                    // Ignore errors
                }
            }
        }

        // Delete redeem webhook if it exists, as points deduction (redeem) is processed synchronously during checkout
        $redeem_id = get_option( 'universal_reward_wc_webhook_redeem_id' );
        if ( $redeem_id ) {
            if ( class_exists( 'WC_Webhook' ) ) {
                try {
                    $web = new WC_Webhook( $redeem_id );
                    if ( $web && $web->get_id() > 0 ) {
                        $web->delete( true ); // Force delete from DB
                    }
                } catch ( Exception $e ) {
                    // Ignore
                }
            }
            delete_option( 'universal_reward_wc_webhook_redeem_id' );
        }

        return true;
    }

    /**
     * Programmatically delete WooCommerce Webhooks (both Award and Redeem)
     */
    public static function delete_webhook() {
        self::delete_legacy_webhook();

        $award_id = get_option( 'universal_reward_wc_webhook_award_id' );
        if ( $award_id && class_exists( 'WC_Webhook' ) ) {
            try {
                $web = new WC_Webhook( $award_id );
                if ( $web && $web->get_id() > 0 ) {
                    $web->delete( true ); // Force delete from DB
                }
            } catch ( Exception $e ) {
                // Ignore
            }
        }
        delete_option( 'universal_reward_wc_webhook_award_id' );

        $redeem_id = get_option( 'universal_reward_wc_webhook_redeem_id' );
        if ( $redeem_id && class_exists( 'WC_Webhook' ) ) {
            try {
                $web = new WC_Webhook( $redeem_id );
                if ( $web && $web->get_id() > 0 ) {
                    $web->delete( true ); // Force delete from DB
                }
            } catch ( Exception $e ) {
                // Ignore
            }
        }
        delete_option( 'universal_reward_wc_webhook_redeem_id' );
    }

    /**
     * Synchronize WooCommerce Webhooks based on current connection and toggles
     */
    public static function sync_webhook_state() {
        if ( ! class_exists( 'WC_Webhook' ) ) {
            return;
        }

        $connection_status = get_option( 'universal_reward_connection_status' );
        $wc_settings       = get_option( 'universal_reward_wc_settings', [] );
        $earning_enabled   = ! empty( $wc_settings['enable_earning'] ) && $wc_settings['enable_earning'] === 'yes';

        // If not connected or earning toggle is disabled, delete webhooks
        if ( $connection_status !== 'connected' || ! $earning_enabled ) {
            self::delete_webhook();
            return;
        }

        // Otherwise, create/sync webhooks
        self::create_webhook();
    }
}
