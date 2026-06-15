/**
 * ODude Reward Point - Admin JS Orchestrator
 */
jQuery(document).ready(function($) {

    // 1. Tab Navigation
    $('.nav-tab-wrapper a').on('click', function(e) {
        e.preventDefault();
        
        // Remove active class from tabs
        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');
        
        // Hide all tab contents
        $('.wpreward-tab-content').removeClass('active');
        // Show target tab content
        var target = $(this).attr('href');
        $(target).addClass('active');

        // Store active tab in local storage to keep state across reloads
        localStorage.setItem('odude_reward_point_active_tab', target);
    });

    // Restore active tab from local storage
    var activeTab = localStorage.getItem('odude_reward_point_active_tab');
    if (activeTab && $('.nav-tab-wrapper a[href="' + activeTab + '"]').length > 0) {
        $('.nav-tab-wrapper a[href="' + activeTab + '"]').trigger('click');
    }


    // Helper to display feedback notices
    function showFeedback(message, isSuccess = true) {
        var feedback = $('#wpreward-ajax-feedback');
        feedback.removeClass('notice-error notice-success notice-info');
        feedback.addClass(isSuccess ? 'notice-success' : 'notice-error');
        feedback.find('p').text(message);
        feedback.slideDown();
        
        // Auto scroll to top to view feedback
        $('html, body').animate({ scrollTop: 0 }, 'slow');

        // Hide notice after 4 seconds
        setTimeout(function() {
            feedback.slideUp();
        }, 5000);
    }

    // 2. Connection Form (Verify Account Key)
    $('#wpreward-connection-form').on('submit', function(e) {
        e.preventDefault();

        var btn = $('#wpreward-connect-submit');
        var originalText = btn.text();
        btn.prop('disabled', true).text('Connecting...');
        $('<span class="wpreward-spinner"></span>').insertAfter(btn);

        $.ajax({
            url: odude_reward_point_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'odude_reward_point_verify_connection',
                security: odude_reward_point_admin_ajax.nonce,
                api_url: $('#wizard_api_url').val(),
                secret_key: $('#wizard_secret_key').val()
            },
            success: function(response) {
                $('.wpreward-spinner').remove();
                if (response.success) {
                    showFeedback(response.data.message, true);
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    btn.prop('disabled', false).text(originalText);
                    showFeedback(response.data.message, false);
                }
            },
            error: function() {
                $('.wpreward-spinner').remove();
                btn.prop('disabled', false).text(originalText);
                showFeedback('Network error occurred. Please try again.', false);
            }
        });
    });

    // 3. Disconnect Button
    $('#wpreward-disconnect-btn').on('click', function(e) {
        e.preventDefault();

        if (!confirm('Are you sure you want to disconnect your loyalty ledger?')) {
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true).text('Disconnecting...');

        $.ajax({
            url: odude_reward_point_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'odude_reward_point_disconnect',
                security: odude_reward_point_admin_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    btn.prop('disabled', false).text('Disconnect');
                    showFeedback(response.data.message, false);
                }
            }
        });
    });

    // 4. Manual Statistics Synchronization
    $('#wpreward-sync-stats-btn').on('click', function(e) {
        e.preventDefault();

        var btn = $(this);
        var originalText = btn.text();
        btn.prop('disabled', true).text('Syncing...');
        $('<span class="wpreward-spinner"></span>').insertAfter(btn);

        $.ajax({
            url: odude_reward_point_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'odude_reward_point_sync_stats',
                security: odude_reward_point_admin_ajax.nonce
            },
            success: function(response) {
                $('.wpreward-spinner').remove();
                btn.prop('disabled', false).text(originalText);

                if (response.success) {
                    var stats = response.data.stats;
                    
                    // Update KPI displays
                    $('#stats-awarded').text(stats.total_points_awarded || '-');
                    $('#stats-redeemed').text(stats.total_points_redeemed || '-');
                    $('#stats-net').text(stats.net_points || '-');
                    
                    if (stats.total_sales !== undefined) {
                        $('#stats-sales').text('$' + parseFloat(stats.total_sales).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                    } else {
                        $('#stats-sales').text('-');
                    }

                    // Render transactions table
                    var tbody = $('#stats-transactions-body');
                    tbody.empty();

                    if (stats.recent_transactions && stats.recent_transactions.length > 0) {
                        stats.recent_transactions.forEach(function(tx) {
                            var $tr = $('<tr>');
                            $tr.append($('<td>').append($('<code>').text(tx.id.substring(0, 8) + '...')));
                            $tr.append($('<td>').append($('<strong>').text(tx.points)));
                            $tr.append($('<td>').append($('<span>').addClass('tx-badge ' + tx.type).text(tx.type.charAt(0).toUpperCase() + tx.type.slice(1))));
                            $tr.append($('<td>').text(tx.remarks || ''));
                            $tr.append($('<td>').text(tx.created_at ? new Date(tx.created_at).toLocaleString() : ''));
                            tbody.append($tr);
                        });
                    } else {
                        tbody.append('<tr><td colspan="5" style="text-align:center;">No recent transactions found.</td></tr>');
                    }

                    showFeedback(response.data.message, true);
                } else {
                    showFeedback(response.data.message, false);
                }
            },
            error: function() {
                $('.wpreward-spinner').remove();
                btn.prop('disabled', false).text(originalText);
                showFeedback('Failed to execute sync. Network error.', false);
            }
        });
    });

    // 5. Submit Settings Forms (WordPress / WooCommerce Toggles)
    $('.wpreward-settings-form').on('submit', function(e) {
        e.preventDefault();

        var form = $(this);
        var btn = form.find('button[type="submit"]');
        var originalText = btn.text();
        
        btn.prop('disabled', true).text('Saving...');
        $('<span class="wpreward-spinner"></span>').insertAfter(btn);

        var formData = form.serializeArray();
        formData.push({ name: 'action', value: 'odude_reward_point_save_settings' });
        formData.push({ name: 'security', value: odude_reward_point_admin_ajax.nonce });

        $.ajax({
            url: odude_reward_point_admin_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                $('.wpreward-spinner').remove();
                btn.prop('disabled', false).text(originalText);

                if (response.success) {
                    showFeedback(response.data.message, true);
                } else {
                    showFeedback(response.data.message, false);
                }
            },
            error: function() {
                $('.wpreward-spinner').remove();
                btn.prop('disabled', false).text(originalText);
                showFeedback('Failed to save settings. Network error.', false);
            }
        });
    });

    // 6. Dynamic show/hide of dependent settings fields based on master checkboxes
    $('#enable_wp_rewards').on('change', function() {
        if (this.checked) {
            $('.wpreward-wp-dependent').fadeIn(200);
        } else {
            $('.wpreward-wp-dependent').fadeOut(200);
        }
    });

    // WooCommerce earning settings change
    $('#enable_earning').on('change', function() {
        if (this.checked) {
            $('.wpreward-wc-earning-dependent').fadeIn(200);
        } else {
            $('.wpreward-wc-earning-dependent').fadeOut(200);
        }
    });

    // WooCommerce redemption settings change
    $('#enable_redemption').on('change', function() {
        if (this.checked) {
            $('.wpreward-wc-redemption-dependent').fadeIn(200);
        } else {
            $('.wpreward-wc-redemption-dependent').fadeOut(200);
        }
    });
});
