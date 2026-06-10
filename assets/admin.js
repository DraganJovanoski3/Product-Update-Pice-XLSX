(function ($) {
	'use strict';

	var sessionId = null;
	var totalRows = 0;

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

	function renderPreview(preview, total) {
		var $body = $('#pupx-preview-body');
		$body.empty();

		preview.forEach(function (row) {
			$body.append(
				'<tr>' +
					'<td>' + escapeHtml(row.row_num) + '</td>' +
					'<td>' + escapeHtml(row.sku) + '</td>' +
					'<td>' + escapeHtml(row.price) + '</td>' +
				'</tr>'
			);
		});

		$('#pupx-total-rows').text(
			pupxAdmin.i18n.totalRows + ': ' + total
		);

		if (total > 5) {
			$('#pupx-preview-note').text('Showing first 5 of ' + total + ' rows.');
		} else {
			$('#pupx-preview-note').text('');
		}

		$('#pupx-preview-section').removeClass('pupx-hidden');
	}

	function updateProgress(data) {
		$('#pupx-progress-bar').css('width', data.percent + '%');
		$('#pupx-progress-text').text(
			pupxAdmin.i18n.processed + ': ' + data.processed + ' / ' + data.total +
			' | ' + pupxAdmin.i18n.updated + ': ' + data.updated +
			' | ' + pupxAdmin.i18n.skipped + ': ' + data.skipped
		);
	}

	function renderResults(data) {
		var $summary = $('#pupx-summary');
		$summary.html(
			'<div class="pupx-summary-item">' +
				'<strong>' + data.total + '</strong>' +
				'<span>Total</span>' +
			'</div>' +
			'<div class="pupx-summary-item pupx-summary-updated">' +
				'<strong>' + data.updated + '</strong>' +
				'<span>' + pupxAdmin.i18n.updated + '</span>' +
			'</div>' +
			'<div class="pupx-summary-item pupx-summary-skipped">' +
				'<strong>' + data.skipped + '</strong>' +
				'<span>' + pupxAdmin.i18n.skipped + '</span>' +
			'</div>'
		);

		var $body = $('#pupx-not-updated-body');
		$body.empty();

		if (data.not_updated && data.not_updated.length) {
			data.not_updated.forEach(function (row) {
				$body.append(
					'<tr>' +
						'<td>' + escapeHtml(row.row_num) + '</td>' +
						'<td>' + escapeHtml(row.sku) + '</td>' +
						'<td>' + escapeHtml(row.price) + '</td>' +
						'<td>' + escapeHtml(row.reason_label || row.reason) + '</td>' +
					'</tr>'
				);
			});

			if (data.not_updated_truncated) {
				$body.append(
					'<tr><td colspan="4">' +
						escapeHtml('Showing first 200 of ' + (data.not_updated_count || data.not_updated.length) + ' not-updated rows. Download the full report below.') +
					'</td></tr>'
				);
			}
		} else {
			$body.append(
				'<tr><td colspan="4">All rows were updated successfully.</td></tr>'
			);
		}

		$('#pupx-results-section').removeClass('pupx-hidden');
		showNotice(pupxAdmin.i18n.complete, 'success');
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
				if (xhr.status === 413) {
					return 'File too large for server upload limits.';
				}
			}
		}

		return fallback;
	}

	function processBatch() {
		return $.ajax({
			url: pupxAdmin.ajaxUrl,
			type: 'POST',
			timeout: 300000,
			data: {
				action: 'pupx_process_batch',
				nonce: pupxAdmin.nonce,
				session_id: sessionId
			}
		});
	}

	function runImport() {
		hideNotice();
		$('#pupx-progress-section').removeClass('pupx-hidden');
		$('#pupx-results-section').addClass('pupx-hidden');
		$('#pupx-start-import').prop('disabled', true).addClass('pupx-loading');

		function nextBatch() {
			processBatch()
				.done(function (response) {
					if (!response.success) {
						showNotice(response.data && response.data.message ? response.data.message : pupxAdmin.i18n.error, 'error');
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
					showNotice(getAjaxErrorMessage(xhr, pupxAdmin.i18n.error), 'error');
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
			showNotice(pupxAdmin.i18n.selectFile, 'error');
			return;
		}

		var formData = new FormData();
		formData.append('action', 'pupx_upload_file');
		formData.append('nonce', pupxAdmin.nonce);
		formData.append('pupx_file', fileInput.files[0]);

		$('#pupx-upload-btn').prop('disabled', true).text(pupxAdmin.i18n.uploading);
		$('#pupx-preview-section').addClass('pupx-hidden');
		$('#pupx-progress-section').addClass('pupx-hidden');
		$('#pupx-results-section').addClass('pupx-hidden');

		$.ajax({
			url: pupxAdmin.ajaxUrl,
			type: 'POST',
			timeout: 300000,
			data: formData,
			processData: false,
			contentType: false
		})
			.done(function (response) {
				if (!response.success) {
					showNotice(response.data && response.data.message ? response.data.message : pupxAdmin.i18n.error, 'error');
					return;
				}

				sessionId = response.data.session_id;
				totalRows = response.data.total;
				renderPreview(response.data.preview, response.data.total);
			})
			.fail(function (xhr) {
				showNotice(getAjaxErrorMessage(xhr, pupxAdmin.i18n.error), 'error');
			})
			.always(function () {
				$('#pupx-upload-btn').prop('disabled', false).text('Upload & Preview');
			});
	});

	$('#pupx-start-import').on('click', function () {
		if (!sessionId) {
			showNotice(pupxAdmin.i18n.error, 'error');
			return;
		}
		runImport();
	});

	function downloadReport(format) {
		if (!sessionId) {
			return;
		}
		var url = pupxAdmin.ajaxUrl +
			'?action=pupx_download_report' +
			'&session_id=' + encodeURIComponent(sessionId) +
			'&format=' + encodeURIComponent(format) +
			'&nonce=' + encodeURIComponent(pupxAdmin.nonce);
		window.location.href = url;
	}

	$('#pupx-download-xlsx').on('click', function () {
		downloadReport('xlsx');
	});

	$('#pupx-download-csv').on('click', function () {
		downloadReport('csv');
	});
})(jQuery);
