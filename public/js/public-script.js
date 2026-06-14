/**
 * Universal Reward Points Ledger - Public Frontend JS Handler
 */
jQuery(document).ready(function($) {

    // Toggle Loyalty Points form slide down/up
    $(document).on('click', '#universal-reward-toggle-btn', function(e) {
        e.preventDefault();
        $('.universal-reward-checkout-content').slideToggle(300);
    });

    // Apply Loyalty Points at Checkout
    $(document).on('click', '#universal-reward-apply-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        var input = $('#universal-reward-points-input');
        var points = parseInt(input.val());

        if (isNaN(points) || points <= 0) {
            alert('Please enter a valid amount of points.');
            return;
        }

        btn.prop('disabled', true).text('Applying...');

        $.ajax({
            url: universal_reward_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'universal_reward_apply_points',
                security: universal_reward_ajax.nonce,
                points: points
            },
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.html) {
                        $('.universal-reward-checkout-wrapper').replaceWith(response.data.html);
                    }
                    // Trigger native WooCommerce checkout update event
                    $(document.body).trigger('update_checkout');
                } else {
                    btn.prop('disabled', false).text('Apply Points');
                    alert(response.data.message);
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Apply Points');
                alert('An error occurred. Please try again.');
            }
        });
    });

    // Remove Applied Loyalty Points
    $(document).on('click', '#universal-reward-remove-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        btn.prop('disabled', true).text('Removing...');

        $.ajax({
            url: universal_reward_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'universal_reward_remove_points',
                security: universal_reward_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.html) {
                        $('.universal-reward-checkout-wrapper').replaceWith(response.data.html);
                        // Show the content form after removing points so they can apply again
                        $('.universal-reward-checkout-content').show();
                    }
                    // Trigger native WooCommerce checkout update
                    $(document.body).trigger('update_checkout');
                } else {
                    btn.prop('disabled', false).text('Remove Points');
                    alert(response.data.message);
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Remove Points');
                alert('An error occurred. Please try again.');
            }
        });
    });

    // Sync/Refresh Points and History from Ledger
    $(document).on('click', '.universal-reward-sync-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        var icon = btn.find('.universal-reward-sync-icon');

        if (btn.hasClass('loading')) {
            return;
        }

        btn.addClass('loading');
        icon.addClass('spinning');

        $.ajax({
            url: universal_reward_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'universal_reward_sync_customer_data',
                security: universal_reward_ajax.nonce
            },
            success: function(response) {
                btn.removeClass('loading');
                icon.removeClass('spinning');

                if (response.success) {
                    // Update all balance indicators on page
                    $('.universal-reward-points-balance').text(response.data.balance);
                    
                    // Update history table body
                    $('.universal-reward-history-table tbody').html(response.data.history_html);
                } else {
                    alert(response.data.message || 'Failed to sync data.');
                }
            },
            error: function() {
                btn.removeClass('loading');
                icon.removeClass('spinning');
                alert('An error occurred while syncing data.');
            }
        });
    });
});
