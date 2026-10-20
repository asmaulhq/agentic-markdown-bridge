(function () {
	'use strict';

	var selectAll = document.getElementById('amb-select-all');
	if (!selectAll) {
		return;
	}

	selectAll.addEventListener('change', function () {
		Array.prototype.forEach.call(document.querySelectorAll('.amb-row-check'), function (checkbox) {
			checkbox.checked = selectAll.checked;
		});
	});
}());
