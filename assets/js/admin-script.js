/**
 * WP Media Export — Admin Script
 *
 * Handles batch export with progress bar and "select all" functionality.
 */
(function ($) {
    'use strict';

    var state = {
        allFilteredIds: null,  // Non-null when "select all matching" is active.
        exporting: false
    };

    /* ── DOM references ───────────────────────────── */
    var $form           = $('#wpme-media-form');
    var $downloadBtn    = $('#wpme-download-btn');
    var $selectedCount  = $('#wpme-selected-count');
    var $progressWrap   = $('#wpme-progress-wrap');
    var $progressBar    = $('#wpme-progress-bar');
    var $progressText   = $('#wpme-progress-text');
    var $progressStatus = $('#wpme-progress-status');
    var $errors         = $('#wpme-errors');
    var $errorList      = $('#wpme-error-list');
    var $banner         = $('#wpme-select-all-banner');
    var $downloadFrame  = $('#wpme-download-frame');

    /* ── Checkbox handling ────────────────────────── */

    function getCheckedIds() {
        if (state.allFilteredIds) {
            return state.allFilteredIds;
        }
        var ids = [];
        $form.find('input[name="media_ids[]"]:checked').each(function () {
            ids.push(parseInt(this.value, 10));
        });
        return ids;
    }

    function updateSelectedCount() {
        var ids = getCheckedIds();
        if (ids.length > 0) {
            $selectedCount.text(ids.length + '件選択中');
        } else {
            $selectedCount.text('');
        }
    }

    // Individual checkbox change.
    $form.on('change', 'input[name="media_ids[]"]', function () {
        // If user unchecks while "all filtered" is active, deactivate.
        if (state.allFilteredIds && !this.checked) {
            state.allFilteredIds = null;
            $banner.removeClass('active');
        }
        updateSelectedCount();
        updateBanner();
    });

    // Header checkbox (select all on page).
    $form.on('change', '#cb-select-all-1, #cb-select-all-2', function () {
        var checked = this.checked;
        $form.find('input[name="media_ids[]"]').prop('checked', checked);

        if (!checked) {
            state.allFilteredIds = null;
            $banner.removeClass('active');
        }

        updateSelectedCount();
        updateBanner();
    });

    function updateBanner() {
        var pageCheckboxes = $form.find('input[name="media_ids[]"]');
        var allPageChecked = pageCheckboxes.length > 0 &&
            pageCheckboxes.filter(':checked').length === pageCheckboxes.length;

        var totalItems = parseInt($form.find('.displaying-num').text(), 10) || 0;

        if (allPageChecked && totalItems > pageCheckboxes.length && !state.allFilteredIds) {
            var text = wpme.i18n.select_all_page.replace('%d', pageCheckboxes.length);
            text += ' <a id="wpme-select-all-matching">';
            text += wpme.i18n.select_all_match.replace('%d', totalItems);
            text += '</a>';
            $banner.html(text).addClass('active');
        } else if (state.allFilteredIds) {
            var allText = wpme.i18n.all_selected.replace('%d', state.allFilteredIds.length);
            allText += ' <a id="wpme-clear-selection">' + wpme.i18n.clear_selection + '</a>';
            $banner.html(allText).addClass('active');
        } else {
            $banner.removeClass('active');
        }
    }

    // "Select all matching" link.
    $banner.on('click', '#wpme-select-all-matching', function (e) {
        e.preventDefault();
        fetchAllFilteredIds();
    });

    // "Clear selection" link.
    $banner.on('click', '#wpme-clear-selection', function (e) {
        e.preventDefault();
        state.allFilteredIds = null;
        $form.find('input[name="media_ids[]"]').prop('checked', false);
        $form.find('#cb-select-all-1, #cb-select-all-2').prop('checked', false);
        $banner.removeClass('active');
        updateSelectedCount();
    });

    function fetchAllFilteredIds() {
        $.post(wpme.ajax_url, {
            action:      'wpme_get_filtered_ids',
            nonce:       wpme.nonce,
            mime_filter:  $('#wpme-mime-filter').val() || '',
            m:            $('#wpme-date-filter').val() || 0,
            s:            $form.find('input[name="s"]').val() || ''
        }).done(function (res) {
            if (res.success && res.data.ids) {
                state.allFilteredIds = res.data.ids;
                updateSelectedCount();
                updateBanner();
            }
        });
    }

    /* ── Export workflow ───────────────────────────── */

    $downloadBtn.on('click', function () {
        if (state.exporting) {
            return;
        }

        var ids = getCheckedIds();

        if (ids.length === 0) {
            alert(wpme.i18n.no_selection);
            return;
        }

        startExport(ids);
    });

    function startExport(ids) {
        state.exporting = true;
        $downloadBtn.prop('disabled', true);

        // Reset UI.
        $progressWrap.addClass('active');
        $progressBar.css('width', '0%');
        $progressText.text('0%');
        $progressStatus.text(wpme.i18n.preparing);
        $errors.removeClass('active');
        $errorList.empty();

        var allErrors = [];

        $.post(wpme.ajax_url, {
            action: 'wpme_start_export',
            nonce:  wpme.nonce,
            total:  ids.length
        }).done(function (res) {
            if (!res.success) {
                showError(res.data && res.data.message ? res.data.message : wpme.i18n.error);
                resetExport();
                return;
            }

            var token = res.data.token;
            var batches = chunkArray(ids, wpme.batch_size);
            var processed = 0;

            processBatch(batches, 0, token, processed, ids.length, allErrors);

        }).fail(function () {
            showError(wpme.i18n.error);
            resetExport();
        });
    }

    function processBatch(batches, index, token, processed, total, allErrors) {
        if (index >= batches.length) {
            // All batches done — trigger download.
            $progressBar.css('width', '100%');
            $progressText.text('100%');
            $progressStatus.text(wpme.i18n.downloading);

            if (allErrors.length > 0) {
                showErrors(allErrors);
            }

            triggerDownload(token);
            return;
        }

        var batch = batches[index];

        $.post(wpme.ajax_url, {
            action: 'wpme_add_batch',
            nonce:  wpme.nonce,
            token:  token,
            ids:    batch
        }).done(function (res) {
            if (!res.success) {
                showError(res.data && res.data.message ? res.data.message : wpme.i18n.error);
                resetExport();
                return;
            }

            processed += batch.length;
            var pct = Math.min(Math.round((processed / total) * 100), 99);
            $progressBar.css('width', pct + '%');
            $progressText.text(pct + '%');
            $progressStatus.text(
                wpme.i18n.processing.replace('%1$d', processed).replace('%2$d', total)
            );

            if (res.data.errors && res.data.errors.length > 0) {
                allErrors = allErrors.concat(res.data.errors);
            }

            processBatch(batches, index + 1, token, processed, total, allErrors);

        }).fail(function () {
            showError(wpme.i18n.error);
            resetExport();
        });
    }

    function triggerDownload(token) {
        var url = wpme.ajax_url +
            '?action=wpme_download' +
            '&nonce=' + encodeURIComponent(wpme.nonce) +
            '&token=' + encodeURIComponent(token);

        $downloadFrame.attr('src', url);

        // Reset UI after a short delay to let the download start.
        setTimeout(function () {
            $progressStatus.text(wpme.i18n.complete);
            resetExport();
        }, 3000);
    }

    function resetExport() {
        state.exporting = false;
        $downloadBtn.prop('disabled', false);
    }

    function showError(message) {
        $errors.addClass('active');
        $errorList.append('<li>' + escapeHtml(message) + '</li>');
    }

    function showErrors(errorsArr) {
        $errors.addClass('active');
        $.each(errorsArr, function (_, msg) {
            $errorList.append('<li>' + escapeHtml(msg) + '</li>');
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function chunkArray(arr, size) {
        var chunks = [];
        for (var i = 0; i < arr.length; i += size) {
            chunks.push(arr.slice(i, i + size));
        }
        return chunks;
    }

})(jQuery);
