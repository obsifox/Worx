/* Worx Media Hub - reprocess actions, pure Vanilla JS (no jQuery).
 * One delegated click handler on the whole media table. */
(function () {
	'use strict';

	if (typeof worxHub === 'undefined') { return; }

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-worx-reprocess]') : null;
		if (!btn || btn.disabled) { return; }
		e.preventDefault();

		var id = btn.getAttribute('data-worx-reprocess');
		var status = btn.parentNode.querySelector('.wx-hub-status');

		var body = new URLSearchParams();
		body.append('action', 'worx_reprocess_image');
		body.append('nonce', worxHub.nonce);
		body.append('attachment_id', id);

		btn.disabled = true;
		btn.classList.add('is-busy');
		if (status) { status.textContent = worxHub.i18n.working; }

		fetch(worxHub.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					var td = btn.closest('td');
					if (td && res.data && res.data.html) {
						td.innerHTML = res.data.html;
					}
					var pct = res.data && res.data.meta && typeof res.data.meta.savings_percent !== 'undefined'
						? ' - ' + res.data.meta.savings_percent + '%'
						: '';
					if (status) { status.textContent = worxHub.i18n.done + pct; }
					setTimeout(function () {
						var s = btn.parentNode && btn.parentNode.querySelector ? btn.parentNode.querySelector('.wx-hub-status') : null;
						if (s) { s.textContent = ''; }
						if (btn.parentNode) { btn.disabled = false; }
						btn.classList.remove('is-busy');
					}, 2500);
				} else {
					btn.disabled = false;
					btn.classList.remove('is-busy');
					if (status) {
						status.textContent = (res && res.data && res.data.message) ? res.data.message : worxHub.i18n.error;
						status.classList.add('is-error');
					}
				}
			})
			.catch(function () {
				btn.disabled = false;
				btn.classList.remove('is-busy');
				if (status) {
					status.textContent = worxHub.i18n.error;
					status.classList.add('is-error');
				}
			});
	});
})();
