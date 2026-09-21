/* Worx Wizard - Vanilla JS live preview, no dependencies, 0 requests. */
(function () {
	'use strict';
	var wm = document.getElementById('wx-wm');
	var op = document.getElementById('wx-opacity');
	var opOut = document.getElementById('wx-op-out');
	var pos = document.getElementById('wx-position');
	var grid = document.getElementById('wx-pos');
	var on = document.getElementById('wx-enabled');
	var sel = document.getElementById('wx-wm-select');
	var q = document.getElementById('wx-quality');
	var qOut = document.getElementById('wx-q-out');
	if (!wm || !pos) { return; }
	function layout() {
		var x = +wm.getAttribute('data-x'), y = +wm.getAttribute('data-y');
		wm.style.left = x * 10 + '%';
		wm.style.top = y * 10 + '%';
		var tx = x === 5 ? -50 : x < 5 ? 0 : -100;
		var ty = y === 5 ? -50 : y < 5 ? 0 : -100;
		wm.style.transform = 'translate(' + tx + '%,' + ty + '%)';
	}
	function activate(b) {
		wm.setAttribute('data-x', b.getAttribute('data-x'));
		wm.setAttribute('data-y', b.getAttribute('data-y'));
		pos.value = b.getAttribute('data-pos');
		layout();
		if (grid) {
			var all = grid.querySelectorAll('button');
			for (var i = 0; i < all.length; i++) { all[i].classList.toggle('is-active', all[i] === b); }
		}
	}
	if (grid) {
		grid.addEventListener('click', function (e) {
			var b = e.target.closest ? e.target.closest('button') : null;
			if (b) { activate(b); }
		});
	}
	if (op) {
		op.addEventListener('input', function () {
			wm.style.opacity = op.value / 100;
			if (opOut) { opOut.textContent = op.value + '%'; }
		});
	}
	if (on) {
		on.addEventListener('change', function () { wm.style.display = on.checked ? '' : 'none'; });
	}
	if (sel) {
		sel.addEventListener('change', function () {
			var o = sel.options[sel.selectedIndex];
			var s = o && o.getAttribute('data-src');
			if (s) { wm.src = s; }
		});
	}
	if (q && qOut) {
		q.addEventListener('input', function () { qOut.textContent = q.value; });
	}
	layout();
	window.addEventListener('resize', layout);
})();
