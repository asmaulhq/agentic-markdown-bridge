(function () {
	'use strict';

	function fallbackCopy(text) {
		return new Promise(function (resolve, reject) {
			var textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', '');
			textarea.setAttribute('aria-hidden', 'true');
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			textarea.style.top = '0';
			document.body.appendChild(textarea);
			textarea.focus();
			textarea.select();

			try {
				if (document.execCommand('copy')) {
					resolve();
				} else {
					reject(new Error('Copy failed'));
				}
			} catch (error) {
				reject(error);
			} finally {
				document.body.removeChild(textarea);
			}
		});
	}

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text).catch(function () {
				return fallbackCopy(text);
			});
		}

		return fallbackCopy(text);
	}

	function init() {
		var config = window.AgenticMarkdownFrontendActions || {};
		var actions = document.querySelectorAll('.agentic-mb-actions');

		actions.forEach(function (wrapper) {
			var button = wrapper.querySelector('.agentic-mb-copy');
			if (!button || button.getAttribute('data-agentic-mb-copy-bound') === '1') {
				return;
			}

			var markdownUrl = wrapper.getAttribute('data-markdown-url');
			if (!markdownUrl) {
				return;
			}

			button.setAttribute('data-agentic-mb-copy-bound', '1');
			var original = button.textContent;

			button.addEventListener('click', function () {
				button.disabled = true;

				fetch(markdownUrl, {
					credentials: 'same-origin',
					headers: { 'Accept': 'text/markdown' }
				})
					.then(function (response) {
						if (!response.ok) {
							throw new Error('HTTP ' + response.status);
						}
						return response.text();
					})
					.then(copyText)
					.then(function () {
						button.textContent = config.copied || 'Copied!';
					})
					.catch(function () {
						button.textContent = config.copyFailed || 'Copy failed';
					})
					.finally(function () {
						window.setTimeout(function () {
							button.textContent = original;
							button.disabled = false;
						}, 1600);
					});
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
