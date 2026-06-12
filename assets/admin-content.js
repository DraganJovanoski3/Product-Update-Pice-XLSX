(function ($) {
	'use strict';

	var cfg = window.pupxContentAdmin;
	var sessionId = null;

	function showNotice(message, type) {
		var $notice = $('#pupx-notice');
		$notice.removeClass('pupx-hidden pupx-notice-error pupx-notice-success');
		$notice.addClass(type === 'error' ? 'pupx-notice-error' : 'pupx-notice-success');
		$notice.text(message);
	}

	function hideNotice() {
		$('#pupx-notice').addClass('pupx-hidden');
	}

	function escapeHtml(text) {
		return $('<div>').text(text == null ? '' : text).html();
	}

	function truncate(text, len) {
		text = text == null ? '' : String(text);
		return text.length > len ? text.substring(0, len) + '…' : text;
	}

	function renderPreview(preview, total, columns) {
		var $head = $('#pupx-preview-head');
		var $body = $('#pupx-preview-body');
		$head.empty();
		$body.empty();

		var displayCols = ['sku'].concat(columns.filter(function (c) { return c !== 'sku'; })).slice(0, 6);
		displayCols.forEach(function (col) {
			$head.append('<th>' + escapeHtml(col) + '</th>');
		});

		preview.forEach(function (row) {
			var html = '';
			displayCols.forEach(function (col) {
				var val = col === 'sku' ? row.sku : (row.fields && row.fields[col] ? row.fields[col] : '');
				html += '<td>' + escapeHtml(truncate(val, 60)) + '</td>';
			});
			$body.append('<tr>' + html + '</tr>');
		});

		$('#pupx-total-rows').text(cfg.i18n.totalRows + ': ' + total);
		$('#pupx-preview-note').text(total > 5 ? 'Showing first 5 of ' + total + ' rows.' : '');
		$('#pupx-preview-section').removeClass('pupx-hidden');
	}

	function updateProgress(data) {
		$('#pupx-progress-bar').css('width', data.percent + '%');
		$('#pupx-progress-text').text(
			cfg.i18n.processed + ': ' + data.processed + ' / ' + data.total +
			' | ' + cfg.i18n.updated + ': ' + data.updated +
			' | ' + cfg.i18n.skipped + ': ' + data.skipped
		);
	}

	function renderResults(data) {
		$('#pupx-summary').html(
			'<div class="pupx-summary-item"><strong>' + data.total + '</strong><span>Total</span></div>' +
			'<div class="pupx-summary-item pupx-summary-updated"><strong>' + data.updated + '</strong><span>' + cfg.i18n.updated + '</span></div>' +
			'<div class="pupx-summary-item pupx-summary-skipped"><strong>' + data.skipped + '</strong><span>' + cfg.i18n.skipped + '</span></div>'
		);

		var $body = $('#pupx-not-updated-body');
		$body.empty();

		if (data.not_updated && data.not_updated.length) {
			data.not_updated.forEach(function (row) {
				$body.append(
					'<tr>' +
						'<td>' + escapeHtml(row.row_num) + '</td>' +
						'<td>' + escapeHtml(row.sku) + '</td>' +
						'<td>' + escapeHtml(row.fields || '') + '</td>' +
						'<td>' + escapeHtml(row.reason_label || row.reason) + '</td>' +
					'</tr>'
				);
			});
			if (data.not_updated_truncated) {
				$body.append('<tr><td colspan="4">Showing first 200 of ' + (data.not_updated_count || data.not_updated.length) + ' not-updated rows. Download the full report below.</td></tr>');
			}
		} else {
			$body.append('<tr><td colspan="4">All rows were updated successfully.</td></tr>');
		}

		$('#pupx-results-section').removeClass('pupx-hidden');
		showNotice(cfg.i18n.complete, 'success');
	}

	function getAjaxErrorMessage(xhr, fallback) {
		if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
			return xhr.responseJSON.data.message;
		}
		if (xhr.responseText) {
			try {
				var parsed = JSON.parse(xhr.responseText);
				if (parsed.data && parsed.data.message) {
					return parsed.data.message;
				}
			} catch (e) {
				if (xhr.status === 504 || xhr.status === 502) {
					return 'Server timeout — try again or import in smaller batches.';
				}
			}
		}
		return fallback;
	}

	function processBatch() {
		return $.ajax({
			url: cfg.ajaxUrl,
			type: 'POST',
			timeout: 300000,
			data: {
				action: cfg.batchAction,
				nonce: cfg.nonce,
				session_id: sessionId
			}
		});
	}

	function runImport() {
		hideNotice();
		$('#pupx-progress-section').removeClass('pupx-hidden');
		$('#pupx-results-section').addClass('pupx-hidden');
		$('#pupx-start-import').prop('disabled', true).addClass('pupx-loading');

		var retries = 0;
		var maxRetries = 3;

		function nextBatch() {
			processBatch()
				.done(function (response) {
					retries = 0;
					if (!response.success) {
						showNotice(response.data && response.data.message ? response.data.message : cfg.i18n.error, 'error');
						$('#pupx-start-import').prop('disabled', false).removeClass('pupx-loading');
						return;
					}
					var data = response.data;
					updateProgress(data);
					if (data.complete) {
						renderResults(data);
						$('#pupx-start-import').prop('disabled', false).removeClass('pupx-loading');
						return;
					}
					nextBatch();
				})
				.fail(function (xhr) {
					if (retries < maxRetries) {
						retries += 1;
						setTimeout(nextBatch, 2000);
						return;
					}
					showNotice(getAjaxErrorMessage(xhr, cfg.i18n.error), 'error');
					$('#pupx-start-import').prop('disabled', false).removeClass('pupx-loading');
				});
		}

		nextBatch();
	}

	$('#pupx-upload-form').on('submit', function (e) {
		e.preventDefault();
		hideNotice();

		var fileInput = $('#pupx-file')[0];
		if (!fileInput.files.length) {
			showNotice(cfg.i18n.selectFile, 'error');
			return;
		}

		var formData = new FormData();
		formData.append('action', cfg.uploadAction);
		formData.append('nonce', cfg.nonce);
		formData.append('pupx_file', fileInput.files[0]);

		$('#pupx-upload-btn').prop('disabled', true).text(cfg.i18n.uploading);
		$('#pupx-preview-section, #pupx-progress-section, #pupx-results-section').addClass('pupx-hidden');

		$.ajax({
			url: cfg.ajaxUrl,
			type: 'POST',
			timeout: 300000,
			data: formData,
			processData: false,
			contentType: false
		})
			.done(function (response) {
				if (!response.success) {
					showNotice(response.data && response.data.message ? response.data.message : cfg.i18n.error, 'error');
					return;
				}
				sessionId = response.data.session_id;
				renderPreview(response.data.preview, response.data.total, response.data.columns || ['sku']);
			})
			.fail(function (xhr) {
				showNotice(getAjaxErrorMessage(xhr, cfg.i18n.error), 'error');
			})
			.always(function () {
				$('#pupx-upload-btn').prop('disabled', false).text('Upload & Preview');
			});
	});

	$('#pupx-start-import').on('click', function () {
		if (!sessionId) {
			showNotice(cfg.i18n.error, 'error');
			return;
		}
		runImport();
	});

	function downloadReport(format) {
		if (!sessionId) {
			return;
		}
		window.location.href = cfg.downloadUrl +
			'?action=' + encodeURIComponent(cfg.reportAction) +
			'&session_id=' + encodeURIComponent(sessionId) +
			'&format=' + encodeURIComponent(format) +
			'&_wpnonce=' + encodeURIComponent(cfg.downloadNonce);
	}

	$('#pupx-download-xlsx').on('click', function () { downloadReport('xlsx'); });
	$('#pupx-download-csv').on('click', function () { downloadReport('csv'); });
})(jQuery);
