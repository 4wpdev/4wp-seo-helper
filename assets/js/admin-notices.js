(function () {
	document.addEventListener('click', function (event) {
		var btn = event.target.closest('.notice-dismiss');
		if (!btn || !window.forwpSeoNotices) {
			return;
		}

		var notice = btn.closest('.forwp-seo-persistent-notice');
		var id = notice ? notice.getAttribute('data-notice') : '';
		if (!id) {
			return;
		}

		var body = new FormData();
		body.append('action', 'forwp_seo_dismiss_notice');
		body.append('notice', id);
		body.append('nonce', window.forwpSeoNotices.nonce);
		fetch(window.forwpSeoNotices.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		});
	});
})();
