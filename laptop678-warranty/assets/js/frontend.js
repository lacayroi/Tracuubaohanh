(function () {
	'use strict';

	var cfg = window.L678W || {};

	function digits(value) {
		return String(value || '').replace(/\D+/g, '');
	}

	function phoneFromUrl() {
		var query = new URLSearchParams(window.location.search);
		var hash = new URLSearchParams(window.location.hash.replace(/^#/, ''));
		return query.get('sdt') || query.get('phone') || hash.get('sdt') || hash.get('phone') || '';
	}

	function setUrl(phone) {
		if (!window.history || !window.history.replaceState) {
			return;
		}
		var url = new URL(window.location.href);
		url.searchParams.delete('sdt');
		url.searchParams.delete('phone');
		url.hash = phone ? 'sdt=' + encodeURIComponent(phone) : '';
		window.history.replaceState(null, '', url.toString());
	}

	function notice(box, message, type) {
		box.innerHTML = '';
		var div = document.createElement('div');
		div.className = 'l678w-notice l678w-' + (type || 'error');
		div.textContent = message;
		box.appendChild(div);
	}

	function lookup(wrapper, phone) {
		var input = wrapper.querySelector('input[name="sdt"]');
		var button = wrapper.querySelector('.l678w-submit');
		var box = wrapper.querySelector('.l678w-results');
		var i18n = cfg.i18n || {};

		phone = String(phone || '').trim();
		if (!phone) {
			notice(box, i18n.empty);
			input.focus();
			return;
		}
		if (digits(phone).length < 9) {
			notice(box, i18n.invalid);
			input.focus();
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'l678w_lookup');
		body.append('phone', phone);

		wrapper.classList.add('is-loading');
		button.disabled = true;

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				if (json && json.success) {
					box.innerHTML = json.data.html;
					setUrl(phone);
				} else {
					notice(box, (json && json.data && json.data.message) || i18n.error);
				}
			})
			.catch(function () {
				notice(box, i18n.error);
			})
			.then(function () {
				wrapper.classList.remove('is-loading');
				button.disabled = false;
			});
	}

	function init() {
		var initial = phoneFromUrl();

		document.querySelectorAll('.l678w-lookup').forEach(function (wrapper, index) {
			var form = wrapper.querySelector('.l678w-form');
			var input = form.querySelector('input[name="sdt"]');

			form.addEventListener('submit', function (event) {
				event.preventDefault();
				lookup(wrapper, input.value);
			});

			if (initial && index === 0) {
				input.value = initial;
				lookup(wrapper, initial);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
