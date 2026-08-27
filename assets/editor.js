(function (wp, config) {
	'use strict';

	if (!wp || !config) {
		return;
	}

	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { Button, Notice, Spinner } = wp.components;
	const { useState } = wp.element;
	const { useSelect } = wp.data;
	const { createNotice } = wp.data.dispatch('core/notices');

	function downloadBlob(blob, filename) {
		const url = window.URL.createObjectURL(blob);
		const link = document.createElement('a');

		link.href = url;
		link.download = filename;
		document.body.appendChild(link);
		link.click();
		link.remove();
		window.URL.revokeObjectURL(url);
	}

	function QRCodePanel() {
		const [isDownloading, setIsDownloading] = useState(false);
		const [error, setError] = useState('');
		const post = useSelect((select) => select('core/editor').getCurrentPost(), []);

		if (!post || !config.enabledPostTypes.includes(post.type) || post.status !== 'publish' || !post.link) {
			return null;
		}

		function downloadQRCode() {
			setIsDownloading(true);
			setError('');

			window
				.fetch(`${config.restBase}/download/${post.id}`, {
					headers: {
						'X-WP-Nonce': config.nonce,
					},
				})
				.then((response) => {
					if (!response.ok) {
						return response.json().then((data) => {
							throw new Error(data.message || 'QR code download failed.');
						});
					}

					return response.json();
				})
				.then((data) => window.fetch(data.url).then((response) => response.blob()).then((blob) => ({ blob, filename: data.filename })))
				.then(({ blob, filename }) => {
					downloadBlob(blob, filename || `${post.slug || 'qr-code'}.png`);
					createNotice('success', 'QR code downloaded.', { type: 'snackbar' });
				})
				.catch((downloadError) => {
					setError(downloadError.message || 'QR code download failed.');
				})
				.finally(() => {
					setIsDownloading(false);
				});
		}

		return wp.element.createElement(
			PluginDocumentSettingPanel,
			{
				name: 'viney-post-qr-code',
				title: 'QR Code',
				className: 'viney-post-qr-code-panel',
			},
			error
				? wp.element.createElement(
						Notice,
						{
							status: 'error',
							isDismissible: true,
							onRemove: () => setError(''),
						},
						error
					)
				: null,
			wp.element.createElement(
				Button,
				{
					variant: 'secondary',
					onClick: downloadQRCode,
					disabled: isDownloading,
					alignSelf: 'stretch'
				},
				isDownloading ? wp.element.createElement(Spinner, null) : 'Download QR Code'
			)
		);
	}

	registerPlugin('viney-post-qr-codes', {
		render: QRCodePanel,
		icon: null,
	});
})(window.wp, window.VineyPostQRCodesEditor);
