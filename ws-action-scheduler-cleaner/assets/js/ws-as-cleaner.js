jQuery(document).ready(function($) {
    'use strict';

    const $wrap = $('.wrap.wsacsc-cleaner');
    const scheduleFieldSelector = '#actions-schedule-interval, #actions-schedule-time, #actions-retention, #logs-schedule-interval, #logs-schedule-time, #logs-retention';

    function setAriaBusy(busy) {
        if ($wrap.length) {
            $wrap.attr('aria-busy', busy ? 'true' : 'false');
        }
    }

    // Disable all buttons until table size and row calculations are complete
    let initialLoadComplete = false;
    $('button.button-primary, button.button-secondary, #clear-actions').prop('disabled', true);

    // Monitor checkbox changes - but only after initial load is complete
    $('input[name="status[]"]').on('change', function() {
        if (initialLoadComplete) {  // Only handle changes after initial load
            const $clearButton = $('#clear-actions');
            const hasChecked = $('input[name="status[]"]:checked').length > 0;
            $clearButton.prop('disabled', !hasChecked);
        }
    });

    // Initial check on page load
    $(document).ready(function() {
        $('input[name="status[]"]').trigger('change');
    });
   
    // Cache for table sizes
    let tableSizesCache = null;
    let tableSizesCacheTime = 0;
    const CACHE_LIFETIME = 30000; // 30 seconds
    let isLoading = false;

    // Function to update a single table's size
    function updateSingleTableSize(tableType) {
    if (isLoading) return;
    
    isLoading = true;
    setAriaBusy(true);
    const $refresh = $(`.wsacsc-refresh-${tableType}`);
    $refresh.addClass('spin');
    
    // Only update the clicked table's loading text
    $(`#${tableType}-count, #${tableType}-size`).text(wsacsc_cleaner.updating_text);

    $.ajax({
        url: wsacsc_cleaner.ajax_url,
        type: 'POST',
        data: {
            action: 'wsacsc_get_table_sizes',
            nonce: wsacsc_cleaner.nonce
        },
        success: function(response) {
            if (response.success && response.data) {
                // Update only the specific table's data
                if (tableType === 'actions') {
                    $('#actions-count').text(response.data.actions_count);
                    $('#actions-size').text(response.data.actions_mb + ' MB');
                } else if (tableType === 'logs') {
                    $('#logs-count').text(response.data.logs_count);
                    $('#logs-size').text(response.data.logs_mb + ' MB');
                }
            } else {
                showMessage('#general-status-message', wsacsc_cleaner.error_message, 'error');
                $(`#${tableType}-count`).text('0');
                $(`#${tableType}-size`).text('0 MB');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showMessage('#general-status-message', wsacsc_cleaner.error_message, 'error');
            $(`#${tableType}-count`).text('0');
            $(`#${tableType}-size`).text('0 MB');
        },
        complete: function() {
            isLoading = false;
            setAriaBusy(false);
            $refresh.removeClass('spin');
        }
    });
}

    // Initial load - moved after function definition
    updateTableSizes(true);

    function updateTableSizeDisplay(data) {
        if (!data) return;
        
        $('#actions-count').text(data.actions_count || '0');
        $('#logs-count').text(data.logs_count || '0');
        $('#actions-size').text((data.actions_mb || '0') + ' MB');
        $('#logs-size').text((data.logs_mb || '0') + ' MB');
    }

    // Function to update table sizes with caching
    function updateTableSizes(forceRefresh = false) {
    const now = Date.now();
    
    // Disable all buttons on initial load
    if (!initialLoadComplete) {
        $('.wsacsc-cleaner button').prop('disabled', true);
    }
    
    // Return cached data if available and not expired
    if (!forceRefresh && tableSizesCache && (now - tableSizesCacheTime) < CACHE_LIFETIME) {
        updateTableSizeDisplay(tableSizesCache);
        if (!initialLoadComplete) {
            initialLoadComplete = true;
            $('.wsacsc-cleaner button').prop('disabled', false);
            $('input[name="status[]"]').trigger('change');
        }
        return;
    }

    // Prevent multiple simultaneous requests
    if (isLoading) return;
    
    isLoading = true;
    setAriaBusy(true);
    $('.wsacsc-refresh').addClass('spin');
    $('#actions-count, #logs-count, #actions-size, #logs-size').text(wsacsc_cleaner.updating_text);

    $.ajax({
        url: wsacsc_cleaner.ajax_url,
        type: 'POST',
        data: {
            action: 'wsacsc_get_table_sizes',
            nonce: wsacsc_cleaner.nonce
        },
        success: function(response) {
            if (response.success && response.data) {
                tableSizesCache = response.data;
                tableSizesCacheTime = now;
                updateTableSizeDisplay(response.data);
                
                if (!initialLoadComplete) {
                    initialLoadComplete = true;
                    $('.wsacsc-cleaner button').prop('disabled', false);
                    $('input[name="status[]"]').trigger('change');
                }
            } else {
                showMessage('#general-status-message', response.data?.message || wsacsc_cleaner.error_message, 'error');
                $('#actions-count, #logs-count').text('0');
                $('#actions-size, #logs-size').text('0 MB');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showMessage('#general-status-message', wsacsc_cleaner.error_message, 'error');
            $('#actions-count, #logs-count').text('0');
            $('#actions-size, #logs-size').text('0 MB');
        },
        complete: function() {
            isLoading = false;
            setAriaBusy(false);
            $('.wsacsc-refresh').removeClass('spin');
        }
    });
    }

    $('.wsacsc-refresh').on('click', function(e) {
        e.preventDefault();
        const tableType = $(this).hasClass('wsacsc-refresh-actions') ? 'actions' : 'logs';
        updateSingleTableSize(tableType);
    });

    // Function to save selected statuses
    function saveSelectedStatuses() {
        var selectedStatuses = [];
        $('input[name="status[]"]:checked').each(function() {
            selectedStatuses.push($(this).val());
        });

        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_save_selected_statuses',
                nonce: wsacsc_cleaner.nonce,
                statuses: selectedStatuses
            },
            success: function(response) {
            },
            error: function() {
                showMessage('#status-save-message', wsacsc_cleaner.error_message, 'error');
            }
        });
    }

    // Helper function to show messages with proper transitions
    function showMessage(selector, message, type, persistent) {
        const $message = $(selector);

        if ($message.length === 0) {
            console.error('Message container not found:', selector);
            return;
        }

        $message
            .removeClass('success error info fade-out wsacsc-is-hidden')
            .addClass(type)
            .attr('aria-live', type === 'error' ? 'assertive' : 'polite')
            .text(message)
            .css('opacity', '1');

        if ($message[0]) {
            void $message[0].offsetHeight;
        }

        if (!persistent) {
            setTimeout(function() {
                $message.addClass('fade-out');
                setTimeout(function() {
                    $message
                        .removeClass('success error info fade-out')
                        .addClass('wsacsc-is-hidden')
                        .css('opacity', '');
                }, 300);
            }, 5000);
        }
    }

    // Call saveSelectedStatuses when checkboxes are changed
    $('input[name="status[]"]').change(function() {
        saveSelectedStatuses();
    });

    // Function to poll cleanup progress
    function pollCleanupProgress(cleanupId, messageSelector, buttonSelector, tableType) {
        const $button = $(buttonSelector);
        
        function checkProgress() {
            $.ajax({
                url: wsacsc_cleaner.ajax_url,
                type: 'POST',
                data: {
                    action: 'wsacsc_check_cleanup_progress',
                    nonce: wsacsc_cleaner.nonce,
                    cleanup_id: cleanupId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.completed) {
                            showMessage(messageSelector, response.data.message, 'success');
                            $button.prop('disabled', false);
                            setAriaBusy(false);
                            if (tableType) {
                                updateSingleTableSize(tableType);
                            }
                        } else {
                            showMessage(messageSelector, response.data.message, 'info', true);
                            setTimeout(checkProgress, 1000);
                        }
                    } else {
                        showMessage(messageSelector, response.data?.message || wsacsc_cleaner.error_message, 'error');
                        $button.prop('disabled', false);
                        setAriaBusy(false);
                    }
                },
                error: function() {
                    showMessage(messageSelector, wsacsc_cleaner.error_message, 'error');
                    $button.prop('disabled', false);
                    setAriaBusy(false);
                }
            });
        }
        
        setTimeout(checkProgress, 1000);
    }

    // Clear actions functionality
    $('#clear-actions').click(function() {
        // Prevent double-clicking
        if ($(this).prop('disabled')) {
            return;
        }
        $(this).prop('disabled', true);
        setAriaBusy(true);

        var selectedStatuses = [];
        $('input[name="status[]"]:checked').each(function() {
            selectedStatuses.push($(this).val());
        });

        if (selectedStatuses.length === 0) {
            showMessage('#actions-status-message', wsacsc_cleaner.select_status_message, 'error');
            $(this).prop('disabled', false);
            setAriaBusy(false);
            return;
        }

        showMessage('#actions-status-message', wsacsc_cleaner.clearing_message, 'info', true);

        const $button = $(this);
        const $message = $('#actions-status-message');
        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_clear_actions',
                nonce: wsacsc_cleaner.nonce,
                statuses: selectedStatuses
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.completed) {
                        showMessage('#actions-status-message', response.data.message, 'success');
                        $button.prop('disabled', false);
                        setAriaBusy(false);
                        updateSingleTableSize('actions');
                    } else {
                        showMessage('#actions-status-message', response.data.message, 'info', true);
                        pollCleanupProgress(response.data.cleanup_id, '#actions-status-message', '#clear-actions', 'actions');
                    }
                } else {
                    showMessage('#actions-status-message', response.data?.message || wsacsc_cleaner.error_message, 'error');
                    $button.prop('disabled', false);
                    setAriaBusy(false);
                }
            },
            error: function() {
                showMessage('#actions-status-message', wsacsc_cleaner.error_message, 'error');
                $button.prop('disabled', false);
                setAriaBusy(false);
            }
        });
    });

    // Clear logs functionality
    $('#clear-logs').click(function() {
        // Prevent double-clicking
        if ($(this).prop('disabled')) {
            return;
        }
        $(this).prop('disabled', true);
        setAriaBusy(true);

        showMessage('#logs-status-message', wsacsc_cleaner.clearing_message, 'info', true);

        const $button = $(this);
        const $message = $('#logs-status-message');
        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_clear_logs',
                nonce: wsacsc_cleaner.nonce
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.completed) {
                        showMessage('#logs-status-message', response.data.message, 'success');
                        $button.prop('disabled', false);
                        setAriaBusy(false);
                        updateSingleTableSize('logs');
                    } else {
                        showMessage('#logs-status-message', response.data.message, 'info', true);
                        pollCleanupProgress(response.data.cleanup_id, '#logs-status-message', '#clear-logs', 'logs');
                    }
                } else {
                    showMessage('#logs-status-message', response.data?.message || wsacsc_cleaner.error_message, 'error');
                    $button.prop('disabled', false);
                    setAriaBusy(false);
                }
            },
            error: function() {
                showMessage('#logs-status-message', wsacsc_cleaner.error_message, 'error');
                $button.prop('disabled', false);
                setAriaBusy(false);
            }
        });
    });

    // Validation function for schedule fields
    function validateScheduleFields() {
        var isValid = true;

        $(scheduleFieldSelector).removeClass('wsacsc-field-error').attr('aria-invalid', 'false');
        
        var actionsScheduleInterval = $('#actions-schedule-interval').val();
        var actionsScheduleTime = $('#actions-schedule-time').val();
        var actionsRetention = $('#actions-retention').val();
        var logsScheduleInterval = $('#logs-schedule-interval').val();
        var logsScheduleTime = $('#logs-schedule-time').val();
        var logsRetention = $('#logs-retention').val();

        // Treat 0 as empty for schedule interval
        if (actionsScheduleInterval === '0') {
            actionsScheduleInterval = '';
        }
        if (logsScheduleInterval === '0') {
            logsScheduleInterval = '';
        }

        // Validate actions schedule interval
        if (actionsScheduleInterval !== '') {
            var actionsIntervalNum = parseInt(actionsScheduleInterval, 10);
            if (isNaN(actionsIntervalNum) || actionsIntervalNum < 1 || actionsIntervalNum > 365) {
                $('#actions-schedule-interval').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
                isValid = false;
            }
        }

        // Validate logs schedule interval
        if (logsScheduleInterval !== '') {
            var logsIntervalNum = parseInt(logsScheduleInterval, 10);
            if (isNaN(logsIntervalNum) || logsIntervalNum < 1 || logsIntervalNum > 365) {
                $('#logs-schedule-interval').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
                isValid = false;
            }
        }

        // Validate schedule times (HH:MM format)
        if (actionsScheduleTime !== '' && !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(actionsScheduleTime)) {
            $('#actions-schedule-time').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
            isValid = false;
        }

        if (logsScheduleTime !== '' && !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(logsScheduleTime)) {
            $('#logs-schedule-time').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
            isValid = false;
        }

        // Validate retention periods (required, 0-365)
        var actionsRetentionNum = parseInt(actionsRetention, 10);
        if (actionsRetention === '' || isNaN(actionsRetentionNum) || actionsRetentionNum < 0 || actionsRetentionNum > 365) {
            $('#actions-retention').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
            isValid = false;
        }

        var logsRetentionNum = parseInt(logsRetention, 10);
        if (logsRetention === '' || isNaN(logsRetentionNum) || logsRetentionNum < 0 || logsRetentionNum > 365) {
            $('#logs-retention').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
            isValid = false;
        }

        // Enable/disable save button
        $('#save-schedule').prop('disabled', !isValid);
        
        return isValid;
    }

    // Add real-time validation on field changes
    $(scheduleFieldSelector).on('input change blur', function() {
        validateScheduleFields();
    });

    // Initial validation on page load
    validateScheduleFields();

    // Save schedule functionality
    $('#save-schedule').click(function() {
        // Prevent double-clicking
        if ($(this).prop('disabled')) {
            return;
        }
        $(this).prop('disabled', true);

        var actionsScheduleInterval = $('#actions-schedule-interval').val();
        var actionsScheduleTime = $('#actions-schedule-time').val();
        var actionsRetention = $('#actions-retention').val();
        var logsScheduleInterval = $('#logs-schedule-interval').val();
        var logsScheduleTime = $('#logs-schedule-time').val();
        var logsRetention = $('#logs-retention').val();

        // Treat 0 as empty for schedule interval
        if (actionsScheduleInterval === '0') {
            actionsScheduleInterval = '';
        }
        if (logsScheduleInterval === '0') {
            logsScheduleInterval = '';
        }

        // Validate before submitting (double-check)
        if (!validateScheduleFields()) {
            showMessage('#schedule-status-message', wsacsc_cleaner.validation_fix_fields_message, 'error');
            $(this).prop('disabled', false);
            return;
        }

        const $button = $(this);
        setAriaBusy(true);
        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_save_schedule',
                nonce: wsacsc_cleaner.nonce,
                actions_schedule_interval: actionsScheduleInterval,
                actions_schedule_time: actionsScheduleTime,
                actions_retention: actionsRetention,
                logs_schedule_interval: logsScheduleInterval,
                logs_schedule_time: logsScheduleTime,
                logs_retention: logsRetention
            },
            success: function(response) {
                if (response.success) {
                    showMessage('#schedule-status-message', response.data.message, 'success');
                } else {
                    var errorMsg = response.data && response.data.message ? response.data.message : wsacsc_cleaner.error_message;
                    showMessage('#schedule-status-message', errorMsg, 'error');
                }
            },
            error: function() {
                showMessage('#schedule-status-message', wsacsc_cleaner.error_message, 'error');
            },
            complete: function() {
                setAriaBusy(false);
                validateScheduleFields();
            }
        });
    });

    // Optimize tables functionality
    $('#optimize-actions, #optimize-logs').on('click', function() {
        const tableType = $(this).attr('id').replace('optimize-', '');
        const $button = $(this);
        const $message = $('#optimize-status-message');
        
        // Prevent double-clicking
        if ($button.prop('disabled')) {
            return;
        }
        
        $button.prop('disabled', true);
        setAriaBusy(true);
        showMessage('#optimize-status-message', wsacsc_cleaner.optimizing_message, 'info', true);

        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_optimize_table',
                nonce: wsacsc_cleaner.nonce,
                table_type: tableType
            },
            success: function(response) {
                if (response.success) {
                    showMessage('#optimize-status-message', response.data.message, 'success');
                    updateSingleTableSize(tableType);
                } else {
                    showMessage('#optimize-status-message', 
                        response.data?.message || wsacsc_cleaner.table_optimization_failed, 
                        'error'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                showMessage('#optimize-status-message', wsacsc_cleaner.error_message, 'error');
            },
            complete: function() {
                $button.prop('disabled', false);
                setAriaBusy(false);
            }
        });
    });
});
