/**
 * ODude Reward Point - Public Frontend JS Handler
 */
jQuery(document).ready(function($) {

    // Toggle Loyalty Points form slide down/up
    $(document).on('click', '#odude-reward-point-toggle-btn', function(e) {
        e.preventDefault();
        $('.odude-reward-point-checkout-content').slideToggle(300);
    });

    // Apply Loyalty Points at Checkout
    $(document).on('click', '#odude-reward-point-apply-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        var input = $('#odude-reward-point-points-input');
        var points = parseInt(input.val());

        if (isNaN(points) || points <= 0) {
            alert('Please enter a valid amount of points.');
            return;
        }

        btn.prop('disabled', true).text('Applying...');

        $.ajax({
            url: odude_reward_point_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'odude_reward_point_apply_points',
                security: odude_reward_point_ajax.nonce,
                points: points
            },
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.html) {
                        $('.odude-reward-point-checkout-wrapper').replaceWith(response.data.html);
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
    $(document).on('click', '#odude-reward-point-remove-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        btn.prop('disabled', true).text('Removing...');

        $.ajax({
            url: odude_reward_point_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'odude_reward_point_remove_points',
                security: odude_reward_point_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data && response.data.html) {
                        $('.odude-reward-point-checkout-wrapper').replaceWith(response.data.html);
                        // Show the content form after removing points so they can apply again
                        $('.odude-reward-point-checkout-content').show();
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
    $(document).on('click', '.odude-reward-point-sync-btn', function(e) {
        e.preventDefault();

        var btn = $(this);
        var icon = btn.find('.odude-reward-point-sync-icon');

        if (btn.hasClass('loading')) {
            return;
        }

        btn.addClass('loading');
        icon.addClass('spinning');

        $.ajax({
            url: odude_reward_point_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'odude_reward_point_sync_customer_data',
                security: odude_reward_point_ajax.nonce
            },
            success: function(response) {
                btn.removeClass('loading');
                icon.removeClass('spinning');

                if (response.success) {
                    // Update all balance indicators on page
                    $('.odude-reward-point-points-balance').text(response.data.balance);
                    
                    // Update history table body
                    $('.odude-reward-point-history-table tbody').html(response.data.history_html);
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
