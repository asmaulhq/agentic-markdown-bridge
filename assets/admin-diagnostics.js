(function () {
	'use strict';

	var config = window.AgenticMarkdownDiagnostics || {};
	var button = document.getElementById('amb-live-test');
	var output = document.getElementById('amb-live-results');
	if (!button || !output) {
		return;
	}

	button.addEventListener('click', function () {
		button.disabled = true;
		button.setAttribute('aria-busy', 'true');
		output.setAttribute('aria-busy', 'true');
		output.textContent = config.running || 'Running…';

		var tests = Array.isArray(config.tests) ? config.tests.filter(function (test) {
			return test && test.url;
		}) : [];

		Promise.all(tests.map(function (test) {
			return fetch(test.url, {
				method: 'HEAD',
				credentials: 'same-origin',
				headers: { 'Accept': 'text/markdown' }
			})
				.then(function (response) {
					return test.label + ': HTTP ' + response.status + ' | ' +
						(response.headers.get('content-type') || (config.noContentType || 'no content-type')) + ' | Link: ' +
						(response.headers.get('link') || '—');
				})
				.catch(function (error) {
					return test.label + ': ' + (config.failed || 'FAILED') + ' ' + error.message;
				});
		}))
			.then(function (lines) {
				output.textContent = lines.join('\n');
			})
			.catch(function (error) {
				output.textContent = (config.failed || 'FAILED') + ' ' + error.message;
			})
			.finally(function () {
				button.disabled = false;
				button.removeAttribute('aria-busy');
				output.removeAttribute('aria-busy');
			});
	});
}());
