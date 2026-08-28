(function ($) {
	'use strict';

	const config = window.VineyPostQRCodesAdmin;
	const option = config.optionName;
	const isCustomMode = config.mode === 'custom';
	const $form = $('#viney-post-qr-codes-settings-form');
	const $previewImage = $('#viney-post-qr-codes-preview-image');
	const $previewStatus = $('#viney-post-qr-codes-preview-status');
	const $previewFrame = $('.viney-post-qr-codes-preview-frame');
	const $regenButton = $('#viney-post-qr-codes-regenerate-button');
	const $regenPostType = $('#viney-post-qr-codes-regenerate-post-type');
	const $regenStatus = $('#viney-post-qr-codes-regenerate-status');
	const $logoId = $('#viney-post-qr-codes-logo-id');
	const $logoPreview = $('.viney-post-qr-codes-logo-preview');
	const $selectLogo = $('#viney-post-qr-codes-select-logo');
	const $removeLogo = $('#viney-post-qr-codes-remove-logo');
	const $customUrl = $('#viney-post-qr-codes-custom-url');
	const $customDownload = $('#viney-post-qr-codes-custom-download');
	const $customStatus = $('#viney-post-qr-codes-custom-status');
	const $matchGlobal = $('#viney-post-qr-codes-match-global');
	const $customAppearanceFields = $('.viney-post-qr-codes-custom-appearance-fields');

	function debounce(fn, delay) {
		let timer;

		return function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(fn, delay);
		};
	}

	function getSettingsAppearance() {
		return {
			background_color: $(`[name="${option}[appearance][background_color]"]`).val(),
			foreground_color: $(`[name="${option}[appearance][foreground_color]"]`).val(),
			transparent: $(`[name="${option}[appearance][transparent]"]`).is(':checked') ? '1' : '',
			margin: $(`[name="${option}[appearance][margin]"]`).val(),
			module_shape: $(`[name="${option}[appearance][module_shape]"]`).val(),
			logo_id: $logoId.val(),
		};
	}

	function getCustomAppearance() {
		return {
			background_color: $('#viney-post-qr-codes-custom-background-color').val(),
			foreground_color: $('#viney-post-qr-codes-custom-foreground-color').val(),
			transparent: $('#viney-post-qr-codes-custom-transparent').is(':checked') ? '1' : '',
			margin: $('#viney-post-qr-codes-custom-margin').val(),
			module_shape: $('#viney-post-qr-codes-custom-module-shape').val(),
			logo_id: $logoId.val(),
		};
	}

	function getCustomTracking() {
		return {
			anchor: $('#viney-post-qr-codes-custom-anchor').val(),
			source: $('#viney-post-qr-codes-custom-source').val(),
			medium: $('#viney-post-qr-codes-custom-medium').val(),
			campaign: $('#viney-post-qr-codes-custom-campaign').val(),
			term: $('#viney-post-qr-codes-custom-term').val(),
		};
	}

	function getPreviewPayload() {
		if (!isCustomMode) {
			return getSettingsAppearance();
		}

		return {
			url: $customUrl.val().trim(),
			utm: getCustomTracking(),
			match_global_styles: $matchGlobal.is(':checked') ? '1' : '',
			appearance: getCustomAppearance(),
		};
	}

	function downloadDataUri(dataUri, filename) {
		const link = document.createElement('a');

		link.href = dataUri;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		link.remove();
	}

	function parseErrorResponse(response) {
		return response.json().then((error) => {
			throw new Error(error.message || config.i18n.failed);
		});
	}

	function updatePreview() {
		if (!$previewFrame.length) {
			return;
		}

		$previewFrame.removeClass('has-image');
		$previewStatus.text('Loading preview...');

		window
			.fetch(config.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce,
				},
				body: JSON.stringify(getPreviewPayload()),
			})
			.then((response) => {
				if (!response.ok) {
					return parseErrorResponse(response);
				}

				return response.json();
			})
			.then((data) => {
				$previewImage.attr('src', data.src);
				$previewFrame.addClass('has-image');
			})
			.catch((error) => {
				$previewImage.removeAttr('src');
				$previewStatus.text(error.message || 'Preview unavailable.');
			});
	}

	function setBusy(isBusy) {
		$regenButton.prop('disabled', isBusy);
		$regenPostType.prop('disabled', isBusy);
		$form.find('input, select, button').prop('disabled', isBusy);
	}

	function format(template, generated, total) {
		return template.replace('%1$d', generated).replace('%2$d', total);
	}

	function regenerateBatch(offset, total) {
		return $.post(config.ajaxUrl, {
			action: 'viney_post_qr_codes_batch',
			nonce: config.ajaxNonce,
			postType: $regenPostType.val(),
			offset,
			limit: 5,
		}).then((response) => {
			if (!response || !response.success) {
				throw new Error(response && response.data && response.data.message ? response.data.message : config.i18n.failed);
			}

			const data = response.data;
			const nextTotal = typeof data.total === 'number' ? data.total : total;

			$regenStatus.text(format(data.done ? config.i18n.complete : config.i18n.generating, data.generated, nextTotal));

			if (!data.done) {
				return regenerateBatch(data.nextOffset, nextTotal);
			}

			return data;
		});
	}

	function updateCustomAppearanceState() {
		const isMatchingGlobal = $matchGlobal.is(':checked');

		$customAppearanceFields.find('input, select, button').prop('disabled', isMatchingGlobal);
	}

	$('.viney-post-qr-codes-color').wpColorPicker({
		change: debounce(updatePreview, 250),
		clear: debounce(updatePreview, 250),
	});

	$('.viney-post-qr-codes-post-type-toggle').on('change', function () {
		const postType = $(this).data('post-type');
		$(`[data-utm-fields="${postType}"]`).toggleClass('is-hidden', !$(this).is(':checked'));
	});

	$('.viney-post-qr-codes-term-use-title').on('change', function () {
		$(this).closest('.viney-post-qr-codes-term-field').find('.viney-post-qr-codes-term-input').prop('disabled', $(this).is(':checked'));
	});

	$selectLogo.on('click', function () {
		const frame = wp.media({
			title: 'Select PNG Logo',
			button: {
				text: 'Use this logo',
			},
			library: {
				type: 'image/png',
			},
			multiple: false,
		});

		frame.on('select', function () {
			const attachment = frame.state().get('selection').first().toJSON();
			const previewUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

			$logoId.val(attachment.id).trigger('change');
			$logoPreview.html(`<img src="${previewUrl}" alt="" />`);
			$removeLogo.removeClass('is-hidden');
			updatePreview();
		});

		frame.open();
	});

	$removeLogo.on('click', function () {
		$logoId.val('').trigger('change');
		$logoPreview.empty();
		$removeLogo.addClass('is-hidden');
		updatePreview();
	});

	$form.on('change input', 'input, select', debounce(updatePreview, 300));
	$('#viney-post-qr-codes-custom-form').on('change input', 'input, select', debounce(updatePreview, 300));

	$matchGlobal.on('change', function () {
		updateCustomAppearanceState();
		updatePreview();
	});

	$regenButton.on('click', function () {
		setBusy(true);
		$regenStatus.text(format(config.i18n.generating, 0, 0));

		regenerateBatch(0, 0)
			.catch((error) => {
				$regenStatus.text(error.message || config.i18n.failed);
			})
			.always(() => {
				setBusy(false);
			});
	});

	$customDownload.on('click', function () {
		const url = $customUrl.val().trim();

		if (!url) {
			$customStatus.text('Enter a URL to generate a QR code.');
			return;
		}

		$customDownload.prop('disabled', true);
		$customStatus.text('Generating QR code...');

		window
			.fetch(`${config.restBase}/custom`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce,
				},
				body: JSON.stringify({
					url,
					utm: getCustomTracking(),
					match_global_styles: $matchGlobal.is(':checked') ? '1' : '',
					appearance: getCustomAppearance(),
				}),
			})
			.then((response) => {
				if (!response.ok) {
					return parseErrorResponse(response);
				}

				return response.json();
			})
			.then((response) => {
				downloadDataUri(response.src, response.filename || 'custom-qr-code.png');
				$customStatus.text('QR code downloaded.');
			})
			.catch((error) => {
				$customStatus.text(error.message || config.i18n.failed);
			})
			.finally(() => {
				$customDownload.prop('disabled', false);
			});
	});

	updateCustomAppearanceState();
	updatePreview();
})(jQuery);
