/* AT SMTP antispam: honeypot + form token for every POST form, optional invisible Yandex SmartCaptcha. */
(function () {
	'use strict';

	var cfg = window.atsmtpAntispam;
	if (!cfg || !document.querySelectorAll || !window.addEventListener) {
		return;
	}

	var start = Date.now();
	var interactions = 0;
	var forms = [];
	var timer = null;

	function matches(el, selector) {
		if (!selector || !el || el.nodeType !== 1) {
			return false;
		}
		var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
		try {
			return fn.call(el, selector);
		} catch (e) {
			return false;
		}
	}

	function closest(el, selector) {
		while (el && el.nodeType === 1) {
			if (matches(el, selector)) {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function eligible(form) {
		if (matches(form, cfg.exclude)) {
			return false;
		}
		return matches(form, cfg.protected) || (form.getAttribute('method') || '').toLowerCase() === 'post';
	}

	function tokenValue() {
		return cfg.key + '.' + Math.floor((Date.now() - start) / 1000) + '.' + interactions;
	}

	function refresh() {
		var value = tokenValue();
		for (var i = 0; i < forms.length; i++) {
			forms[i].__atsmtpToken.value = value;
		}
	}

	var captcha = cfg.captcha && cfg.captcha.forms && cfg.captcha.forms.length ? createCaptcha(cfg.captcha) : null;

	function prepare(form) {
		if (form.__atsmtp || !eligible(form)) {
			return;
		}
		form.__atsmtp = true;

		var wrap = document.createElement('div');
		wrap.setAttribute('aria-hidden', 'true');
		wrap.style.cssText = 'position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;';
		var hp = document.createElement('input');
		hp.type = 'text';
		hp.name = cfg.hp;
		hp.value = '';
		hp.tabIndex = -1;
		hp.setAttribute('autocomplete', 'off');
		wrap.appendChild(hp);

		var token = document.createElement('input');
		token.type = 'hidden';
		token.name = cfg.token;
		token.value = tokenValue();

		form.appendChild(wrap);
		form.appendChild(token);
		form.__atsmtpToken = token;
		forms.push(form);

		if (!timer) {
			// Keeps the token current for forms submitted programmatically (timers, auto-submit).
			timer = setInterval(refresh, 1000);
		}
		if (captcha) {
			captcha.prepare(form);
		}
	}

	function scan() {
		var list = document.querySelectorAll('form');
		for (var i = 0; i < list.length; i++) {
			prepare(list[i]);
		}
	}

	function onInteract() {
		interactions++;
		refresh();
	}

	['keydown', 'pointerdown', 'mousedown', 'touchstart', 'input', 'change'].forEach(function (type) {
		window.addEventListener(type, onInteract, true);
	});
	window.addEventListener('click', refresh, true);
	window.addEventListener('submit', refresh, true);

	function createCaptcha(c) {
		var formSelector = c.forms.map(function (f) { return f.form; }).join(', ');
		var clicks = c.forms.filter(function (f) { return f.click; });
		var clickSelector = clicks.map(function (f) { return f.click; }).join(', ');
		var ready = false;
		var loading = false;
		var queue = [];

		function render(form) {
			if (form.__atsmtpWidget !== undefined || !window.smartCaptcha) {
				return;
			}
			var box = document.createElement('div');
			box.className = 'atsmtp-captcha';
			form.appendChild(box);
			form.__atsmtpWidget = window.smartCaptcha.render(box, {
				sitekey: c.sitekey,
				invisible: true,
				hl: 'ru',
				callback: function (token) {
					onToken(form, token);
				}
			});
		}

		function load() {
			if (loading) {
				return;
			}
			loading = true;
			window.atsmtpCaptchaOnload = function () {
				ready = !!window.smartCaptcha;
				for (var i = 0; i < queue.length; i++) {
					render(queue[i]);
				}
				queue = [];
			};
			var s = document.createElement('script');
			s.src = 'https://smartcaptcha.cloud.yandex.ru/captcha.js?render=onload&onload=atsmtpCaptchaOnload';
			s.async = true;
			s.defer = true;
			document.head.appendChild(s);
		}

		function onToken(form, token) {
			var action = form.__atsmtpPending;
			form.__atsmtpPending = null;
			if (!token || !action) {
				return;
			}
			var input = form.querySelector('input[name="smart-token"]');
			if (!input) {
				input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'smart-token';
				form.appendChild(input);
			}
			input.value = token;

			// Form plugins read the data synchronously inside the re-dispatched event.
			form.__atsmtpPass = true;
			try {
				action();
			} finally {
				form.__atsmtpPass = false;
			}
			setTimeout(function () {
				try {
					window.smartCaptcha.reset(form.__atsmtpWidget);
				} catch (e) {}
			}, 0);
		}

		// Without a loaded widget the submission goes through and the server rejects it with a message.
		function intercept(event, form, action) {
			if (!form || !form.__atsmtpCaptcha || form.__atsmtpPass || !ready || form.__atsmtpWidget === undefined) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			form.__atsmtpPending = action;
			window.smartCaptcha.execute(form.__atsmtpWidget);
		}

		window.addEventListener('submit', function (event) {
			var form = event.target;
			var submitter = event.submitter;
			intercept(event, form, function () {
				if (form.requestSubmit) {
					try {
						form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
						return;
					} catch (e) {}
				}
				if (submitter && submitter.click) {
					submitter.click();
				} else {
					HTMLFormElement.prototype.submit.call(form);
				}
			});
		}, true);

		if (clickSelector) {
			window.addEventListener('click', function (event) {
				var button = closest(event.target, clickSelector);
				if (!button) {
					return;
				}
				var form = closest(button, 'form');
				for (var i = 0; !form && i < clicks.length; i++) {
					var scope = clicks[i].scope ? closest(button, clicks[i].scope) : null;
					form = scope ? scope.querySelector(clicks[i].form) : null;
				}
				intercept(event, form, function () {
					button.click();
				});
			}, true);
		}

		return {
			prepare: function (form) {
				if (!matches(form, formSelector)) {
					return;
				}
				form.__atsmtpCaptcha = true;
				if (ready) {
					render(form);
				} else {
					queue.push(form);
					load();
				}
			}
		};
	}

	var observerQueued = false;
	function scheduleScan() {
		if (observerQueued) {
			return;
		}
		observerQueued = true;
		setTimeout(function () {
			observerQueued = false;
			scan();
		}, 200);
	}

	function init() {
		scan();
		if (window.MutationObserver) {
			new MutationObserver(scheduleScan).observe(document.body || document.documentElement, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
