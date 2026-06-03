jQuery(document).ready(function($) {
    'use strict';

    const $wrap = $('.wrap.wsacsc-cleaner');
    const scheduleFieldSelector = '#actions-schedule-interval, #actions-schedule-time, #actions-retention, #logs-schedule-interval, #logs-schedule-time, #logs-retention';
    const destructiveButtonSelector = '#clear-actions, #clear-logs, #optimize-actions, #optimize-logs';
    const POLL_INTERVAL_MS = 650;
    const POLL_RETRY_MAX_MS = 8000;

    function setAriaBusy(busy) {
        if ($wrap.length) {
            $wrap.attr('aria-busy', busy ? 'true' : 'false');
        }
    }

    let destructiveOpInProgress = false;
    let initialLoadComplete = false;
    $('button.button-primary, button.button-secondary, #clear-actions').prop('disabled', true);

    $('input[name="status[]"]').on('change', function() {
        if (initialLoadComplete && !destructiveOpInProgress) {
            const $clearButton = $('#clear-actions');
            const hasChecked = $('input[name="status[]"]:checked').length > 0;
            $clearButton.prop('disabled', !hasChecked);
        }
    });

    $(document).ready(function() {
        $('input[name="status[]"]').trigger('change');
    });

    function setDestructiveButtonsDisabled(disabled) {
        destructiveOpInProgress = disabled;
        $(destructiveButtonSelector).prop('disabled', disabled);
        $('.wsacsc-refresh').prop('disabled', disabled);
        if (!disabled && initialLoadComplete) {
            $('input[name="status[]"]').trigger('change');
        }
    }

    let tableSizesCache = null;
    let tableSizesCacheTime = 0;
    const CACHE_LIFETIME = 30000;
    let isLoading = false;

    function hasTableSizeData(data) {
        return !!(
            data &&
            typeof data.actions_count !== 'undefined' &&
            typeof data.logs_count !== 'undefined' &&
            typeof data.actions_mb !== 'undefined' &&
            typeof data.logs_mb !== 'undefined'
        );
    }

    function updateTableSizeDisplay(data) {
        if (!hasTableSizeData(data)) {
            return;
        }

        $('#actions-count').text(data.actions_count);
        $('#logs-count').text(data.logs_count);
        $('#actions-size').text(String(data.actions_mb) + ' MB');
        $('#logs-size').text(String(data.logs_mb) + ' MB');
    }

    function applyTableSizeData(data, tableType) {
        if (!hasTableSizeData(data)) {
            return;
        }

        tableSizesCache = data;
        tableSizesCacheTime = Date.now();

        if (tableType === 'actions') {
            $('#actions-count').text(data.actions_count);
            $('#actions-size').text(String(data.actions_mb) + ' MB');
            return;
        }

        if (tableType === 'logs') {
            $('#logs-count').text(data.logs_count);
            $('#logs-size').text(String(data.logs_mb) + ' MB');
            return;
        }

        updateTableSizeDisplay(data);
    }

    /**
     * Fetch current row counts and MB sizes from the server.
     *
     * @param {Object} options
     * @param {boolean} [options.force=false] Bypass client cache and destructive-op guard.
     * @param {string|null} [options.tableType=null] When set, only update that table in the UI.
     */
    function fetchTableSizes(options) {
        const opts = options || {};
        const force = !!opts.force;
        const tableType = opts.tableType || null;
        const now = Date.now();

        if (!initialLoadComplete) {
            $('.wsacsc-cleaner button').prop('disabled', true);
        }

        if (!force && tableSizesCache && (now - tableSizesCacheTime) < CACHE_LIFETIME) {
            applyTableSizeData(tableSizesCache, tableType);
            if (!initialLoadComplete) {
                initialLoadComplete = true;
                $('.wsacsc-cleaner button').prop('disabled', false);
                $('input[name="status[]"]').trigger('change');
            }
            return;
        }

        if (!force && (isLoading || destructiveOpInProgress)) {
            return;
        }

        isLoading = true;
        setAriaBusy(true);

        const $refreshButtons = tableType ? $('.wsacsc-refresh-' + tableType) : $('.wsacsc-refresh');
        $refreshButtons.addClass('spin');

        if (tableType) {
            $(`#${tableType}-count, #${tableType}-size`).text(wsacsc_cleaner.updating_text);
        } else {
            $('#actions-count, #logs-count, #actions-size, #logs-size').text(wsacsc_cleaner.updating_text);
        }

        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_get_table_sizes',
                nonce: wsacsc_cleaner.nonce
            },
            success: function(response) {
                if (response.success && hasTableSizeData(response.data)) {
                    applyTableSizeData(response.data, tableType);

                    if (!initialLoadComplete) {
                        initialLoadComplete = true;
                        $('.wsacsc-cleaner button').prop('disabled', false);
                        $('input[name="status[]"]').trigger('change');
                    }
                } else {
                    showMessage('#general-status-message', response.data?.message || wsacsc_cleaner.error_message, 'error');
                    if (tableType) {
                        $(`#${tableType}-count`).text('0');
                        $(`#${tableType}-size`).text('0 MB');
                    } else {
                        $('#actions-count, #logs-count').text('0');
                        $('#actions-size, #logs-size').text('0 MB');
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                showMessage('#general-status-message', wsacsc_cleaner.error_message, 'error');
                if (tableType) {
                    $(`#${tableType}-count`).text('0');
                    $(`#${tableType}-size`).text('0 MB');
                } else {
                    $('#actions-count, #logs-count').text('0');
                    $('#actions-size, #logs-size').text('0 MB');
                }
            },
            complete: function() {
                isLoading = false;
                if (!destructiveOpInProgress) {
                    setAriaBusy(false);
                }
                $refreshButtons.removeClass('spin');
            }
        });
    }

    function updateSingleTableSize(tableType) {
        fetchTableSizes({ tableType: tableType });
    }

    function updateTableSizes(forceRefresh) {
        fetchTableSizes({ force: !!forceRefresh });
    }

    fetchTableSizes({ force: true });

    $('.wsacsc-refresh').on('click', function(e) {
        e.preventDefault();
        if (destructiveOpInProgress) {
            return;
        }
        const tableType = $(this).hasClass('wsacsc-refresh-actions') ? 'actions' : 'logs';
        fetchTableSizes({ tableType: tableType, force: true });
    });

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
            error: function() {
                showMessage('#status-save-message', wsacsc_cleaner.error_message, 'error');
            }
        });
    }

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

    $('input[name="status[]"]').change(function() {
        saveSelectedStatuses();
    });

    function refreshTableSizesAfterDestructive(tableType) {
        tableSizesCache = null;
        tableSizesCacheTime = 0;
        fetchTableSizes({ force: true, tableType: tableType || null });
    }

    function finishDestructiveOp(messageSelector, tableType) {
        setDestructiveButtonsDisabled(false);
        setAriaBusy(false);
        refreshTableSizesAfterDestructive(tableType);
    }

    function pollCleanupProgress(cleanupId, messageSelector, tableType) {
        let pollBackoff = POLL_INTERVAL_MS;

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
                    pollBackoff = POLL_INTERVAL_MS;

                    if (!response.success) {
                        showMessage(messageSelector, response.data?.message || wsacsc_cleaner.error_message, 'error');
                        finishDestructiveOp(messageSelector, null);
                        return;
                    }

                    if (response.data.stale) {
                        showMessage(messageSelector, response.data.message, 'info', true);
                        finishDestructiveOp(messageSelector, tableType);
                        return;
                    }

                    if (response.data.completed) {
                        showMessage(messageSelector, response.data.message, 'success');
                        finishDestructiveOp(messageSelector, tableType);
                        return;
                    }

                    showMessage(messageSelector, response.data.message || wsacsc_cleaner.clearing_message, 'info', true);
                    setTimeout(checkProgress, POLL_INTERVAL_MS);
                },
                error: function() {
                    showMessage(messageSelector, wsacsc_cleaner.error_message + ' ' + (wsacsc_cleaner.retrying_message || ''), 'error');
                    pollBackoff = Math.min(pollBackoff * 2, POLL_RETRY_MAX_MS);
                    setTimeout(checkProgress, pollBackoff);
                }
            });
        }

        setTimeout(checkProgress, POLL_INTERVAL_MS);
    }

    function handleCleanupStart(response, messageSelector, successMessage, tableType) {
        if (!response.success) {
            const msg = response.data?.message || wsacsc_cleaner.error_message;
            showMessage(messageSelector, msg, 'error');
            finishDestructiveOp(messageSelector, null);
            return;
        }

        if (response.data.completed) {
            showMessage(messageSelector, response.data.message || successMessage, 'success');
            finishDestructiveOp(messageSelector, tableType);
            return;
        }

        if (response.data.cleanup_id) {
            showMessage(messageSelector, response.data.message || wsacsc_cleaner.clearing_message, 'info', true);
            pollCleanupProgress(response.data.cleanup_id, messageSelector, tableType);
            return;
        }

        showMessage(messageSelector, wsacsc_cleaner.error_message, 'error');
        finishDestructiveOp(messageSelector, null);
    }

    $('#clear-actions').click(function() {
        if ($(this).prop('disabled') || destructiveOpInProgress) {
            return;
        }

        var selectedStatuses = [];
        $('input[name="status[]"]:checked').each(function() {
            selectedStatuses.push($(this).val());
        });

        if (selectedStatuses.length === 0) {
            showMessage('#actions-status-message', wsacsc_cleaner.select_status_message, 'error');
            return;
        }

        setDestructiveButtonsDisabled(true);
        setAriaBusy(true);
        showMessage('#actions-status-message', wsacsc_cleaner.clearing_message, 'info', true);

        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_clear_actions',
                nonce: wsacsc_cleaner.nonce,
                statuses: selectedStatuses
            },
            success: function(response) {
                handleCleanupStart(
                    response,
                    '#actions-status-message',
                    wsacsc_cleaner.success_actions_cleared,
                    'actions'
                );
            },
            error: function() {
                showMessage('#actions-status-message', wsacsc_cleaner.error_message, 'error');
                finishDestructiveOp('#actions-status-message', null);
            }
        });
    });

    $('#clear-logs').click(function() {
        if ($(this).prop('disabled') || destructiveOpInProgress) {
            return;
        }

        setDestructiveButtonsDisabled(true);
        setAriaBusy(true);
        showMessage('#logs-status-message', wsacsc_cleaner.clearing_message, 'info', true);

        $.ajax({
            url: wsacsc_cleaner.ajax_url,
            type: 'POST',
            data: {
                action: 'wsacsc_clear_logs',
                nonce: wsacsc_cleaner.nonce
            },
            success: function(response) {
                handleCleanupStart(
                    response,
                    '#logs-status-message',
                    wsacsc_cleaner.success_logs_cleared,
                    'logs'
                );
            },
            error: function() {
                showMessage('#logs-status-message', wsacsc_cleaner.error_message, 'error');
                finishDestructiveOp('#logs-status-message', null);
            }
        });
    });

    function validateScheduleFields() {
        var isValid = true;

        $(scheduleFieldSelector).removeClass('wsacsc-field-error').attr('aria-invalid', 'false');

        var actionsScheduleInterval = $('#actions-schedule-interval').val();
        var actionsScheduleTime = $('#actions-schedule-time').val();
        var actionsRetention = $('#actions-retention').val();
        var logsScheduleInterval = $('#logs-schedule-interval').val();
        var logsScheduleTime = $('#logs-schedule-time').val();
        var logsRetention = $('#logs-retention').val();

        if (actionsScheduleInterval === '0') {
            actionsScheduleInterval = '';
        }
        if (logsScheduleInterval === '0') {
            logsScheduleInterval = '';
        }

        if (actionsScheduleInterval !== '') {
            var actionsIntervalNum = parseInt(actionsScheduleInterval, 10);
            if (isNaN(actionsIntervalNum) || actionsIntervalNum < 1 || actionsIntervalNum > 365) {
                $('#actions-schedule-interval').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
                isValid = false;
            }
        }

        if (logsScheduleInterval !== '') {
            var logsIntervalNum = parseInt(logsScheduleInterval, 10);
            if (isNaN(logsIntervalNum) || logsIntervalNum < 1 || logsIntervalNum > 365) {
                $('#logs-schedule-interval').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
                isValid = false;
            }
        }

        if (actionsScheduleTime !== '' && !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(actionsScheduleTime)) {
            $('#actions-schedule-time').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
            isValid = false;
        }

        if (logsScheduleTime !== '' && !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(logsScheduleTime)) {
            $('#logs-schedule-time').addClass('wsacsc-field-error').attr('aria-invalid', 'true');
            isValid = false;
        }

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

        $('#save-schedule').prop('disabled', !isValid || destructiveOpInProgress);

        return isValid;
    }

    $(scheduleFieldSelector).on('input change blur', function() {
        validateScheduleFields();
    });

    validateScheduleFields();

    $('#save-schedule').click(function() {
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

        if (actionsScheduleInterval === '0') {
            actionsScheduleInterval = '';
        }
        if (logsScheduleInterval === '0') {
            logsScheduleInterval = '';
        }

        if (!validateScheduleFields()) {
            showMessage('#schedule-status-message', wsacsc_cleaner.validation_fix_fields_message, 'error');
            validateScheduleFields();
            return;
        }

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
                if (!destructiveOpInProgress) {
                    setAriaBusy(false);
                }
                validateScheduleFields();
            }
        });
    });

    $('#optimize-actions, #optimize-logs').on('click', function() {
        if ($(this).prop('disabled') || destructiveOpInProgress) {
            return;
        }

        const tableType = $(this).attr('id').replace('optimize-', '');
        const $button = $(this);

        setDestructiveButtonsDisabled(true);
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
                } else {
                    showMessage(
                        '#optimize-status-message',
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
                finishDestructiveOp('#optimize-status-message', tableType);
            }
        });
    });
});
