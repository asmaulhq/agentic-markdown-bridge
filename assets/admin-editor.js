(function () {
	'use strict';

	var source = document.getElementById('agentic-mb-custom-mode');
	var wrapper = document.getElementById('agentic-mb-custom-wrap');
	var editor = document.getElementById('agentic-mb-custom-markdown');
	if (!source || !wrapper || !editor) {
		return;
	}

	function syncCustomEditor(shouldFocus) {
		var isCustom = source.value === 'custom';
		wrapper.hidden = !isCustom;
		source.setAttribute('aria-expanded', isCustom ? 'true' : 'false');

		if (!isCustom || !shouldFocus) {
			return;
		}

		window.requestAnimationFrame(function () {
			editor.focus();
			var end = editor.value.length;
			if (typeof editor.setSelectionRange === 'function') {
				editor.setSelectionRange(end, end);
			}
		});
	}

	source.addEventListener('change', function () {
		syncCustomEditor(true);
	});
}());
