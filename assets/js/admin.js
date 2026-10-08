/**
 * RankyFy AI Crawler Monitor — admin app.
 *
 * Plain DOM, no build step. Every value from the server is inserted with
 * textContent (never innerHTML). Menu sections (RankyFy → …): #/ (dashboard),
 * #/crawlers, #/referrals, #/readiness, #/access, #/llms, #/opportunities,
 * #/settings. Deeper screens reached from them: #/crawlers/:id, #/pages,
 * #/pages/:id, #/recommendations, #/technical, #/history, #/alerts.
 *
 * Provenance is part of the UI contract: anything measured on the site is
 * badged "Observed"; anything produced by a model or a template is badged
 * "AI-suggested" or "Template idea" and is never shown as a real search.
 *
 * Charts: a stacked column chart (four categorical hues validated for
 * colour-vision deficiency, legend always shown, table view for every chart),
 * single-hue sparklines and lines, meters for ratios. Status colours always
 * come with an icon and a label.
 */
(function () {
	'use strict';

	var cfg = window.RFAIB || {};
	var D = 'rankyfy-ai-crawlers';
	var i18n = (window.wp && wp.i18n) || {};
	var __ = function (s) { return i18n.__ ? i18n.__(s, D) : s; };
	var _n = function (a, b, n) { return i18n._n ? i18n._n(a, b, n, D) : (n === 1 ? a : b); };
	var sprintf = i18n.sprintf || function (s) {
		var a = Array.prototype.slice.call(arguments, 1), i = 0;
		return s.replace(/%(\d+\$)?[sd]/g, function (m, pos) { return String(a[pos ? parseInt(pos, 10) - 1 : i++]); });
	};

	// ── DOM ──────────────────────────────────────────────────────────────────
	var SVG = 'http://www.w3.org/2000/svg';
	function attrs(el, a, svg) {
		Object.keys(a || {}).forEach(function (k) {
			var v = a[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'class') { el.setAttribute('class', v); }
			else if (k === 'text') { el.textContent = v; }
			else if (k.indexOf('on') === 0 && typeof v === 'function') { el.addEventListener(k.slice(2), v); }
			else if (!svg && k === 'value') { el.value = v; }
			else if (!svg && k === 'checked') { el.checked = !!v; }
			else if (!svg && k === 'selected') { el.selected = !!v; }
			else { el.setAttribute(k, v === true ? '' : String(v)); }
		});
	}
	function add(el, kids) {
		kids.forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			if (Array.isArray(c)) { add(el, c); return; }
			el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
		});
		return el;
	}
	function h(tag, a) { var el = document.createElement(tag); attrs(el, a, false); return add(el, [].slice.call(arguments, 2)); }
	function s(tag, a) { var el = document.createElementNS(SVG, tag); attrs(el, a, true); return add(el, [].slice.call(arguments, 2)); }
	function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } return el; }

	// ── formatting ───────────────────────────────────────────────────────────
	function has(v) { return v !== null && v !== undefined && v !== ''; }
	function num(v) {
		if (!has(v)) { return '—'; }
		v = Number(v);
		if (Math.abs(v) >= 100000) { return (v / 1000).toFixed(0) + 'K'; }
		if (Math.abs(v) >= 10000) { return (v / 1000).toFixed(1) + 'K'; }
		return Math.round(v).toLocaleString();
	}
	function pct(a, b) { return b ? Math.round(100 * a / b) : 0; }
	function toDate(v) {
		if (!has(v)) { return null; }
		if (typeof v === 'number') { return new Date(v * 1000); }
		var d = new Date(String(v).length === 10 ? v + 'T00:00:00' : String(v).replace(' ', 'T') + 'Z');
		return isNaN(d) ? null : d;
	}
	function fmtDate(v) { var d = toDate(v); return d ? d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'; }
	function fmtDayYear(v) { var d = toDate(v); return d ? d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'; }
	function fmtDay(v) { var d = toDate(v); return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '—'; }
	function ago(v) {
		var d = toDate(v);
		if (!d) { return __('never'); }
		var sec = Math.round((Date.now() - d.getTime()) / 1000);
		if (sec < 90) { return __('just now'); }
		if (sec < 3600) { return sprintf(__('%d min ago'), Math.round(sec / 60)); }
		if (sec < 86400 * 2) { return sprintf(__('%d h ago'), Math.round(sec / 3600)); }
		if (sec < 86400 * 45) { return sprintf(__('%d days ago'), Math.round(sec / 86400)); }
		return fmtDay(v);
	}
	function duration(sec) {
		if (sec < 60) { return sprintf(__('%ds'), sec); }
		if (sec < 3600) { return sprintf(__('%d min'), Math.round(sec / 60)); }
		return sprintf(__('%s h'), (sec / 3600).toFixed(1));
	}
	function q(name) { var m = location.hash.match(new RegExp('[?&]' + name + '=([^&]*)')); return m ? decodeURIComponent(m[1]) : ''; }

	// ── icons ────────────────────────────────────────────────────────────────
	var ICONS = {
		check: 'M20 6 9 17l-5-5', x: 'M18 6 6 18M6 6l12 12',
		alert: 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01',
		info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
		xCircle: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM15 9l-6 6M9 9l6 6',
		checkCircle: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM8.5 12.5l2.5 2.5 4.5-5',
		eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
		sparkles: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z',
		template: 'M4 4h16v4H4zM4 12h7v8H4zM15 12h5v8h-5z', ruler: 'M3 17 17 3l4 4L7 21zM7 13l2 2M10 10l2 2M13 7l2 2',
		shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z', shieldX: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM10 9l4 4M14 9l-4 4',
		bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
		refresh: 'M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6', external: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
		arrowUp: 'M12 19V5M5 12l7-7 7 7', arrowDown: 'M12 5v14M19 12l-7 7-7-7', arrowLeft: 'M19 12H5M12 19l-7-7 7-7', minus: 'M5 12h14',
		table: 'M3 5h18v14H3zM3 10h18M3 15h18M9 5v14', chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2', robot: 'M12 2v3M5 8h14a1 1 0 0 1 1 1v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9a1 1 0 0 1 1-1zM9 13h.01M15 13h.01M9 17h6',
		file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM14 2v6h6', pin: 'M12 17v5M5 17h14l-2-4V5H7v8z', ban: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM4.9 4.9l14.2 14.2',
		upload: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12', download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
		link: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'
	};
	function icon(name, size) {
		size = size || 16;
		return s('svg', { 'class': 'rf-icon', width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, s('path', { d: ICONS[name] || ICONS.info }));
	}

	// ── labels ───────────────────────────────────────────────────────────────
	var CAT = {
		ai_search: __('AI search'), ai_user: __('Fetched for a user'), ai_agent: __('AI agent'), ai_training: __('AI training'),
		ai_other: __('Other AI'), search: __('Search engine'), seo: __('SEO tool'), social: __('Link preview'), monitor: __('Monitor'), other: __('Other'), potential: __('Possible AI')
	};
	var REASONS = {
		home: __('home page'), hub: __('shop/blog hub'), menu: __('in a menu'), cornerstone: __('cornerstone'), product: __('product'), sales: __('sells'),
		page: __('page'), archive: __('archive'), inlinks: __('linked internally'), ai_referrals: __('visits from AI assistants'), in_depth: __('in-depth'), fresh: __('recently updated'), pinned: __('marked important'), excluded: __('excluded')
	};
	var SEV = { critical: ['xCircle', __('Critical')], warning: ['alert', __('Warning')], info: ['info', __('Info')] };

	function sev(level) {
		var m = SEV[level] || SEV.info;
		return h('span', { 'class': 'rf-sev rf-sev-' + level }, icon(m[0], 14), m[1]);
	}
	/** Where a piece of information comes from. */
	function prov(kind, extra) {
		var map = {
			observed: ['eye', __('Observed'), __('Measured on your site: real requests, responses or your own content.')],
			measured: ['ruler', __('Measured'), __('Real data from a measurement source (for example search volume), not generated.')],
			search_results: ['eye', __('Seen in search results'), __('Taken from real search results for this topic.')],
			inferred: ['sparkles', __('AI-suggested'), __('Generated by an AI model. Not a real search or prompt anyone was observed making.')],
			suggested: ['sparkles', __('AI-suggested'), __('Generated by an AI model. Not a real search or prompt anyone was observed making.')],
			template: ['template', __('Template idea'), __('Built from your page\'s key terms with a fixed pattern. Not an observed search.')],
			estimated: ['ruler', __('Estimated'), __('Estimated by rules from the page type and wording.')]
		};
		var m = map[kind] || map.observed;
		return h('span', { 'class': 'rf-prov rf-prov-' + (kind === 'suggested' ? 'inferred' : kind), title: m[2] }, icon(m[0], 12), extra || m[1]);
	}
	function verif(cls, vstate) {
		if (cls === 'spoofed' || vstate === 'failed') { return h('span', { 'class': 'rf-chip rf-chip-crit', title: __('Claimed to be this crawler but did not come from its published addresses.') }, icon('shieldX', 12), __('Impersonation')); }
		if (vstate === 'verified') { return h('span', { 'class': 'rf-chip rf-chip-good', title: __('Confirmed by published addresses, reverse DNS or a request signature.') }, icon('shield', 12), __('Verified')); }
		if (vstate === 'pending') { return h('span', { 'class': 'rf-chip', title: __('Verification is queued.') }, icon('refresh', 12), __('Checking')); }
		if (cls === 'potential') { return h('span', { 'class': 'rf-chip rf-chip-warn', title: __('Not in the registry; the user agent suggests an AI service.') }, icon('alert', 12), __('Possible AI')); }
		return h('span', { 'class': 'rf-chip', title: __('Identified by user agent only. The operator publishes no way to verify it, or verification was not possible.') }, icon('info', 12), __('User agent only'));
	}
	function catLabel(c) { return CAT[c] || c || '—'; }
	function pagePath(p) { return p || '/'; }

	// ── API ──────────────────────────────────────────────────────────────────
	/** REST URL for a route plus query, on pretty and on plain ("?rest_route=") permalinks. */
	function restUrl(path) {
		var i = path.indexOf('?'), route = i >= 0 ? path.slice(0, i) : path, qs = i >= 0 ? path.slice(i + 1) : '';
		return cfg.root + route + (qs ? (cfg.root.indexOf('?') >= 0 ? '&' : '?') + qs : '');
	}
	/** A fresh REST nonce once the page has been open longer than the nonce lives. */
	function renewNonce() {
		return fetch(cfg.ajaxUrl + '?action=rest-nonce', { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : ''; }).then(function (n) {
			if (/^[a-f0-9]{8,20}$/.test((n || '').trim())) { cfg.nonce = n.trim(); return true; }
			return false;
		}).catch(function () { return false; });
	}
	function api(path, opts, retried) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' } };
		if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return fetch(restUrl(path), init).then(function (res) {
			return res.text().then(function (t) {
				var data = null;
				try { data = t ? JSON.parse(t) : null; } catch (e) { data = null; }
				if (res.status === 403 && data && data.code === 'rest_cookie_invalid_nonce' && !retried) {
					return renewNonce().then(function (ok) {
						if (ok) { return api(path, opts, true); }
						throw new Error(__('Your session has expired. Reload the page to continue.'));
					});
				}
				if (!res.ok) {
					var err = new Error((data && data.message) || sprintf(__('Request failed (HTTP %d).'), res.status));
					err.code = data && data.code; err.status = res.status;
					throw err;
				}
				return data;
			});
		});
	}

	// ── feedback ─────────────────────────────────────────────────────────────
	var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	/** Run after the element is in the page, so CSS transitions start from the first state. */
	function later(fn) { setTimeout(fn, reduceMotion ? 0 : 30); }
	function countUp(el, to) {
		if (reduceMotion || !isFinite(to) || to <= 0) { el.textContent = String(to); return; }
		var start = null;
		(function step(t) {
			start = start || t;
			var p = Math.min(1, (t - start) / 700);
			el.textContent = String(Math.round(to * (1 - Math.pow(1 - p, 3))));
			if (p < 1) { requestAnimationFrame(step); }
		})(performance.now());
	}

	var toastBox = null;
	/** opts.undo: a function; shows an Undo button and keeps the toast up longer. */
	function toast(msg, kind, opts) {
		opts = opts || {};
		if (!toastBox) { toastBox = h('div', { 'class': 'rf-toasts', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(toastBox); }
		var t = h('div', { 'class': 'rf-toast rf-toast-' + (kind || 'info') }, icon(kind === 'error' ? 'xCircle' : 'checkCircle'), h('span', { text: msg }));
		var gone = false;
		var leave = function () {
			if (gone) { return; }
			gone = true;
			if (reduceMotion) { t.remove(); return; }
			t.classList.add('is-leaving');
			setTimeout(function () { t.remove(); }, 200);
		};
		if (opts.undo) {
			t.appendChild(h('button', { type: 'button', 'class': 'rf-toast-btn', onclick: function () { leave(); opts.undo(); } }, __('Undo')));
		}
		toastBox.appendChild(t);
		setTimeout(leave, opts.undo ? 8000 : kind === 'error' ? 7000 : 4000);
	}

	/**
	 * A panel that slides in from the right. Esc, the backdrop and the close
	 * button close it; focus stays inside while it is open and returns to
	 * where it was.
	 */
	var openDrawer = null;
	function drawer(title, sub) {
		if (openDrawer) { openDrawer.close(true); }
		var prev = document.activeElement;
		var head = h('div', null, h('h2', { text: title }), sub ? h('p', { text: sub }) : null);
		var body = h('div', { 'class': 'rf-drawer-body' });
		var foot = h('footer', null);
		var panel = h('aside', { 'class': 'rf-drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, tabindex: '-1' },
			h('header', null, head, h('button', { type: 'button', 'class': 'rf-x', 'aria-label': __('Close'), onclick: function () { api_.close(); } }, icon('x', 18))), body, foot);
		var wrap = h('div', { 'class': 'rf-drawer-wrap' }, h('div', { 'class': 'rf-drawer-back', onclick: function () { api_.close(); } }), panel);
		var onKey = function (e) {
			if (e.key === 'Escape') { api_.close(); return; }
			if (e.key !== 'Tab') { return; }
			var f = panel.querySelectorAll('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])');
			if (!f.length) { return; }
			if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
			else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
		};
		var api_ = {
			body: body, foot: foot, head: head,
			setTitle: function (t, sb) { clear(head); add(head, [h('h2', { text: t }), sb ? h('p', { text: sb }) : null]); panel.setAttribute('aria-label', t); },
			close: function (instant) {
				if (openDrawer !== api_) { return; }
				openDrawer = null;
				document.removeEventListener('keydown', onKey);
				wrap.classList.remove('is-open');
				setTimeout(function () { wrap.remove(); }, instant || reduceMotion ? 0 : 260);
				if (prev && prev.focus && document.contains(prev)) { prev.focus(); }
			}
		};
		document.body.appendChild(wrap);
		document.addEventListener('keydown', onKey);
		later(function () { wrap.classList.add('is-open'); panel.focus(); });
		openDrawer = api_;
		return api_;
	}
	function dialog(title, body, actions) {
		var prev = document.activeElement;
		var close = function () { overlay.remove(); document.removeEventListener('keydown', onKey); if (prev && prev.focus) { prev.focus(); } };
		var onKey = function (e) { if (e.key === 'Escape') { close(); } };
		var box = h('div', { 'class': 'rf-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
			h('h2', { text: title }), body,
			h('div', { 'class': 'rf-dialog-actions' }, (actions || []).map(function (a) {
				return h('button', { type: 'button', 'class': 'rf-btn' + (a.primary ? ' rf-btn-primary' : ''), onclick: function () { if (a.run(close) !== false) { close(); } } }, a.label);
			}), h('button', { type: 'button', 'class': 'rf-btn rf-btn-ghost', onclick: close }, __('Cancel'))));
		var overlay = h('div', { 'class': 'rf-overlay', onclick: function (e) { if (e.target === overlay) { close(); } } }, box);
		document.body.appendChild(overlay);
		document.addEventListener('keydown', onKey);
		var f = box.querySelector('input,select,textarea,button'); if (f) { f.focus(); }
		return close;
	}

	// ── building blocks ──────────────────────────────────────────────────────
	function card(title, opts) {
		opts = opts || {};
		var headRight = h('div', { 'class': 'rf-card-tools' }, opts.tools || null);
		var body = h('div', { 'class': 'rf-card-body' });
		var el = h('section', { 'class': 'rf-card' + (opts.cls ? ' ' + opts.cls : '') },
			title ? h('header', { 'class': 'rf-card-head' }, h('div', null, h('h3', { text: title }), opts.prov ? prov(opts.prov) : null, opts.sub ? h('p', { 'class': 'rf-hint', text: opts.sub }) : null), headRight) : null,
			body);
		el.body = body;
		return el;
	}
	function meter(value, max, label, opts) {
		opts = opts || {};
		var p = max ? Math.max(0, Math.min(100, 100 * value / max)) : 0;
		var level = opts.level || (p >= 70 ? 'good' : p >= 40 ? 'warn' : 'crit');
		return h('div', { 'class': 'rf-meter' },
			h('div', { 'class': 'rf-meter-top' }, h('span', { text: label }), h('span', { 'class': 'rf-meter-val', text: opts.text || (num(value) + ' / ' + num(max)) })),
			h('div', { 'class': 'rf-meter-track rf-meter-' + level, role: 'meter', 'aria-valuemin': 0, 'aria-valuemax': max, 'aria-valuenow': value, 'aria-label': label }, (function () {
				var fill = h('div', { 'class': 'rf-meter-fill', style: 'width:' + (reduceMotion ? p.toFixed(1) : 0) + '%' });
				if (!reduceMotion) { later(function () { fill.style.width = p.toFixed(1) + '%'; }); }
				return fill;
			})()));
	}
	/** Readiness score as a ring, with a worded status beside it (never colour alone). */
	function scoreLevel(v) { return !has(v) ? 'none' : v >= 70 ? 'good' : v >= 40 ? 'warn' : 'crit'; } // same bands as meter()
	function scoreRing(v, opts) {
		opts = opts || {};
		var size = opts.size || 116, sw = opts.size && opts.size < 90 ? 8 : 10, r = (size - sw) / 2, c = 2 * Math.PI * r, lvl = scoreLevel(v);
		var arc = s('circle', { 'class': 'rf-ring-val', cx: size / 2, cy: size / 2, r: r, 'stroke-width': sw, 'stroke-dasharray': c.toFixed(1), 'stroke-dashoffset': c.toFixed(1) });
		var n = h('span', { 'class': 'rf-ring-num', text: reduceMotion && has(v) ? String(v) : (has(v) ? '0' : '—') });
		var el = h('div', { 'class': 'rf-ring rf-ring-' + lvl + (size < 90 ? ' rf-ring-sm' : ''), role: 'img', 'aria-label': has(v) ? sprintf(__('Score %1$d out of 100: %2$s'), v, scoreWord(v)) : __('Not scored yet') },
			s('svg', { 'class': 'rf-ring-svg', width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, 'aria-hidden': 'true' },
				s('circle', { 'class': 'rf-ring-track', cx: size / 2, cy: size / 2, r: r, 'stroke-width': sw }), arc),
			h('div', { 'class': 'rf-ring-center', 'aria-hidden': 'true' }, n, has(v) ? h('small', { text: '/100' }) : null));
		if (has(v)) { later(function () { arc.setAttribute('stroke-dashoffset', (c * (1 - Math.max(0, Math.min(100, v)) / 100)).toFixed(1)); countUp(n, v); }); }
		return el;
	}
	function scoreWord(v) { return { good: __('Good'), warn: __('Needs work'), crit: __('Poor'), none: __('Not scored yet') }[scoreLevel(v)]; }
	function scoreStatus(v) {
		var l = scoreLevel(v);
		return h('span', { 'class': 'rf-chip' + (l === 'none' ? '' : ' rf-chip-' + l) }, icon(l === 'good' ? 'checkCircle' : l === 'none' ? 'minus' : 'alert', 12), scoreWord(v));
	}
	/** Change against the value about a week earlier in a [{day, value}] series. */
	function weekDelta(trend, now) {
		if (!trend || trend.length < 2 || !has(now)) { return null; }
		var cutoff = Date.now() - 6.5 * 86400000, base = null;
		trend.forEach(function (p) { var d = toDate(p.day); if (d && d.getTime() <= cutoff) { base = p.value; } });
		if (base === null) { base = trend[0].value; }
		var d = Math.round(now - base);
		if (!d) { return h('span', { 'class': 'rf-delta' }, icon('minus', 12), __('no change this week')); }
		return h('span', { 'class': 'rf-delta ' + (d > 0 ? 'rf-up-good' : 'rf-up-bad') }, icon(d > 0 ? 'arrowUp' : 'arrowDown', 12), sprintf(d > 0 ? __('up %d this week') : __('down %d this week'), Math.abs(d)));
	}
	/**
	 * Staged edits: count what differs from the saved values and show it next
	 * to the save button; nothing is written until Save.
	 */
	function staged(form, container, saveBtn, label) {
		var initial = {};
		Object.keys(form).forEach(function (k) { initial[k] = JSON.stringify(form[k]()); });
		var update = function () {
			var n = Object.keys(form).filter(function (k) { return JSON.stringify(form[k]()) !== initial[k]; }).length;
			label.hidden = !n;
			label.textContent = sprintf(_n('%d unsaved change', '%d unsaved changes', n), n);
			saveBtn.disabled = !n;
		};
		container.addEventListener('input', update);
		container.addEventListener('change', update);
		update();
		var api_ = {
			reset: function () { Object.keys(form).forEach(function (k) { initial[k] = JSON.stringify(form[k]()); }); update(); },
			dirty: function () { return Object.keys(form).some(function (k) { return JSON.stringify(form[k]()) !== initial[k]; }); }
		};
		leaveGuard = api_.dirty;
		return api_;
	}
	/** Unsaved changes on screen: asked before leaving the section or the page. */
	var leaveGuard = null;
	window.addEventListener('beforeunload', function (e) { if (leaveGuard && leaveGuard()) { e.preventDefault(); e.returnValue = ''; } });

	// ── design building blocks ───────────────────────────────────────────────
	/** A crawler's colour: slots 1–4 are the four series hues, everything else is "Other" grey. */
	function slotColor(slot) { return slot ? 'var(--rf-s' + slot + ')' : 'var(--rf-muted)'; }
	function botSq(slot) { return h('i', { 'class': 'rf-sq', style: 'background:' + slotColor(slot), 'aria-hidden': 'true' }); }
	/** WordPress-style dropdown filter ("Last 30 days ▾"). */
	function dropdown(options, value, onChange, label) {
		var el = h('select', { 'class': 'rf-input rf-select', 'aria-label': label }, options.map(function (o) { return h('option', { value: o[0], text: o[1], selected: String(o[0]) === String(value) }); }));
		el.addEventListener('change', function () { onChange(el.value); });
		return el;
	}
	function rangeDropdown(onChange) {
		return dropdown([[7, __('Last 7 days')], [30, __('Last 30 days')], [90, __('Last 90 days')]], state.days, function (v) { state.days = Number(v); onChange(); }, __('Time range'));
	}
	function searchBox(value, placeholder, onSearch) {
		var el = h('input', { type: 'search', 'class': 'rf-input', placeholder: placeholder, value: value || '', 'aria-label': placeholder });
		var t = null;
		el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { clearTimeout(t); onSearch(el.value.trim()); } });
		el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { onSearch(el.value.trim()); }, 500); });
		return el;
	}
	/** "▲ 38% vs prev 30d" */
	function deltaLine(cur, prev) {
		if (!prev) { return h('span', { 'class': 'rf-delta', text: cur ? __('new in this period') : '' }); }
		var d = Math.round(100 * (cur - prev) / prev);
		return h('span', { 'class': 'rf-delta ' + (d >= 0 ? 'rf-up-good' : 'rf-up-bad') }, (d >= 0 ? '▲ ' : '▼ ') + sprintf(__('%1$d%% vs prev %2$dd'), Math.abs(d), state.days));
	}
	/** Stat tile: small uppercase label, big number, one line under it. */
	function stile(label, value, sub) {
		return h('div', { 'class': 'rf-stile' }, h('span', { 'class': 'rf-stile-label', text: label }), h('span', { 'class': 'rf-stile-value' }, value), sub || null);
	}
	/** A real switch: <button role="switch" aria-checked>, with its state also in words beside it. */
	function toggleSwitch(on, label, onChange, opts) {
		opts = opts || {};
		var b = h('button', { type: 'button', role: 'switch', 'class': 'rf-switch' + (opts.cls ? ' ' + opts.cls : ''), 'aria-checked': String(!!on), 'aria-label': label, onclick: function () { onChange(b.getAttribute('aria-checked') !== 'true'); } }, h('span', { 'class': 'rf-switch-knob' }));
		return b;
	}
	/** Purpose of a crawler, short form, as a chip. */
	function purposeChip(cat) {
		var t = { ai_training: __('Training'), ai_search: __('Search'), ai_user: __('User fetch'), ai_agent: __('Agent'), ai_other: __('Other AI'), search: __('Search engine') }[cat] || catLabel(cat);
		return h('span', { 'class': 'rf-chip' + (cat === 'ai_search' || cat === 'ai_user' || cat === 'ai_agent' ? ' rf-chip-info' : '') , text: t });
	}
	function accessChip(a) {
		if (a === 'allowed') { return h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Allowed')); }
		if (a === 'blocked') { return h('span', { 'class': 'rf-chip rf-chip-crit' }, icon('ban', 12), __('Blocked')); }
		if (a === 'unreviewed') { return h('span', { 'class': 'rf-chip rf-chip-warn' }, icon('alert', 12), __('Unreviewed')); }
		return h('span', { 'class': 'rf-muted', text: '—' });
	}
	function empty(text, extra) { return h('div', { 'class': 'rf-empty' }, icon('info', 20), h('p', { text: text }), extra || null); }
	function button(label, onclick, opts) {
		opts = opts || {};
		var b = h('button', { type: 'button', 'class': 'rf-btn' + (opts.primary ? ' rf-btn-primary' : '') + (opts.small ? ' rf-btn-sm' : '') + (opts.ghost ? ' rf-btn-ghost' : ''), title: opts.title || null }, opts.icon ? icon(opts.icon, 14) : null, label);
		b.addEventListener('click', function () {
			if (b.disabled) { return; }
			var r = onclick(b);
			if (r && r.then) {
				b.disabled = true; b.classList.add('rf-busy');
				r.catch(function (e) { toast(e.message, 'error'); }).then(function () { b.disabled = false; b.classList.remove('rf-busy'); });
			}
		});
		return b;
	}
	function link(text, route) { return h('a', { href: '#' + route, text: text }); }
	function table(cols, rows, opts) {
		opts = opts || {};
		if (!rows.length) { return empty(opts.empty || __('Nothing to show yet.')); }
		return h('div', { 'class': 'rf-table-wrap' }, h('table', { 'class': 'rf-table' + (opts.compact ? ' rf-table-compact' : '') },
			h('thead', null, h('tr', null, cols.map(function (c) { return h('th', { scope: 'col', 'class': c.num ? 'rf-num' : null, text: c.label }); }))),
			h('tbody', null, rows.map(function (r) {
				var tr = opts.onRow ? h('tr', { 'class': 'rf-row-click' + (opts.rowClass ? ' ' + opts.rowClass(r) : ''), tabindex: '0', onclick: function (e) { if (!e.target.closest('a,button,input,select')) { opts.onRow(r); } }, onkeydown: function (e) { if (e.key === 'Enter' && e.target === tr) { opts.onRow(r); } } }) : h('tr', opts.rowClass ? { 'class': opts.rowClass(r) } : null);
				return add(tr, cols.map(function (c) {
					var v = c.render ? c.render(r) : r[c.key];
					return h('td', { 'class': c.num ? 'rf-num' : null }, v === null || v === undefined ? '—' : v);
				}));
			}))));
	}
	function pager(cur, total, go) {
		if (total <= 1) { return null; }
		return h('nav', { 'class': 'rf-pager', 'aria-label': __('Pages') },
			button(__('Previous'), function () { go(cur - 1); }, { small: true, ghost: true }), h('span', { text: sprintf(__('Page %1$d of %2$d'), cur, total) }),
			button(__('Next'), function () { go(cur + 1); }, { small: true, ghost: true }));
	}
	function segmented(options, current, onChange, label) {
		return h('div', { 'class': 'rf-seg', role: 'group', 'aria-label': label || null }, options.map(function (o) {
			return h('button', { type: 'button', 'class': 'rf-seg-btn' + (String(o[0]) === String(current) ? ' is-on' : ''), 'aria-pressed': String(String(o[0]) === String(current)), onclick: function () { onChange(o[0]); } }, o[1]);
		}));
	}
	function pageLink(p) {
		var title = p.title || p.label || pagePath(p.path);
		var id = p.page_id || p.id;
		return h('div', { 'class': 'rf-pagecell' }, id ? pageAnchor(title, id) : h('span', { text: title }), h('span', { 'class': 'rf-mono rf-muted', text: pagePath(p.path) }));
	}
	/** Link to a page's details: a plain click opens the quick view; Ctrl/⌘-click or a new tab opens the full screen. */
	function pageAnchor(text, id) {
		return h('a', { href: '#/pages/' + id, text: text, onclick: function (e) {
			if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) { return; }
			e.preventDefault();
			pageDrawer(id);
		} });
	}
	/** Link to a URL's detail drawer (by the URL's hash); Ctrl/⌘-click still opens the full page when there is one. */
	function urlAnchor(text, hash, pageId) {
		return h('a', { href: pageId ? '#/pages/' + pageId : '#', 'class': 'rf-mono-link', text: text, onclick: function (e) {
			if (pageId && (e.metaKey || e.ctrlKey || e.shiftKey || e.button)) { return; }
			e.preventDefault();
			urlDrawer(hash);
		} });
	}
	function pageDrawer(id) {
		urlDrawer(api('/pages/' + id).then(function (x) { return x.page.hash; }));
	}
	/** One URL, every bot that read it, and whether anything came back (design screen 5). */
	function urlDrawer(hashOrPromise) {
		var d = drawer(__('Loading…'));
		d.body.appendChild(h('div', { 'class': 'rf-skeleton' }, h('div'), h('div')));
		Promise.resolve(hashOrPromise).then(function (hash) { return api('/crawlers/url/' + hash); }).then(function (x) {
			if (openDrawer !== d) { return; }
			var p = x.page, bots = x.bots;
			d.setTitle(pagePath(x.path));
			clear(d.body);
			var top = bots.slice(0, 4), rest = bots.slice(4), restHits = rest.reduce(function (a, b) { return a + b.hits; }, 0);
			var rows = top.map(function (b) { return { name: b.name, hits: b.hits, color: slotColor(b.slot) }; });
			if (rest.length) { rows.push({ name: sprintf(__('Other (%d)'), rest.length), hits: restHits, color: slotColor(0) }); }
			var max = Math.max.apply(null, rows.map(function (r) { return r.hits; }).concat([1]));
			var visits = x.referrals.reduce(function (a, r) { return a + r.visits; }, 0);
			var notFound = x.status >= 400 && x.status < 500;
			add(d.body, [
				h('dl', { 'class': 'rf-facts' },
					p && p.type === 'post' ? [h('dt', { text: __('Post') }), h('dd', null, h('a', { href: p.edit || p.view, text: p.title }))] : p ? [h('dt', { text: __('Page') }), h('dd', null, h('a', { href: p.view, target: '_blank', rel: 'noopener', text: p.title }))] : [h('dt', { text: __('Page') }), h('dd', { 'class': 'rf-muted', text: __('Not one of your published pages') })],
					p && p.published ? [h('dt', { text: __('Published') }), h('dd', { text: fmtDayYear(p.published) + (p.modified && p.modified - p.published > 86400 ? ' · ' + sprintf(__('updated %s'), fmtDayYear(p.modified)) : '') })] : null,
					h('dt', { text: __('Total AI hits') }), h('dd', { text: sprintf(_n('%1$s across %2$d bot', '%1$s across %2$d bots', bots.length), num(x.total), bots.length) }),
					x.first ? [h('dt', { text: __('First read') }), h('dd', { text: sprintf(__('%1$s by %2$s'), fmtDayYear(x.first.at), x.first.bot) })] : null,
					p ? [h('dt', { text: __('Readiness') }), h('dd', null, has(p.score) ? h('span', { 'class': 'rf-chip rf-chip-' + (scoreLevel(p.score) === 'crit' ? 'crit' : scoreLevel(p.score)) }, icon(scoreLevel(p.score) === 'good' ? 'check' : 'alert', 12), p.score + ' — ' + scoreWord(p.score).toLowerCase()) : h('span', { 'class': 'rf-muted', text: __('not analysed yet') }))] : null),
				rows.length ? h('section', null, h('h3', { text: __('Hits by bot') }), h('div', { 'class': 'rf-bars' }, rows.map(function (r) {
					var bar = h('i', { style: 'background:' + r.color });
					later(function () { bar.style.width = (100 * r.hits / max).toFixed(1) + '%'; });
					return [h('span', { text: r.name }), h('div', { 'class': 'rf-bar' }, bar), h('b', { text: num(r.hits) })];
				}))) : null,
				x.recent.length ? h('section', null, h('h3', { text: __('Recent requests') }), table([
					{ label: __('When'), render: function (e) { return fmtDate(e.ts); } },
					{ label: __('Bot'), render: function (e) { return e.name || '—'; } },
					{ label: __('Status'), render: function (e) { return statusChip(e.status); } },
					{ label: __('Via'), render: function (e) { return h('span', { 'class': 'rf-chip', title: e.source === 'import' ? __('From an imported access log') : __('Caught by the WordPress hook'), text: e.source === 'import' ? 'log' : 'php' }); } }
				], x.recent.slice(0, 5), { compact: true })) : null,
				notFound
					? h('div', { 'class': 'rf-notice rf-notice-crit' }, h('span', null, h('strong', { text: sprintf(__('AI crawlers get "not found" (HTTP %d) here.'), x.status) }), ' ', __('Anything they learned about this address now leads nowhere.')), button(__('Add redirect'), function () { d.close(true); redirectDrawer(x.path); }, { small: true }))
					: visits
						? h('div', { 'class': 'rf-notice rf-notice-good' }, icon('check', 14), h('span', null, h('strong', { text: sprintf(_n('%s visit arrived from AI engines', '%s visits arrived from AI engines', visits), num(visits)) }), ' ', sprintf(__('to this page in 30 days — mostly %s. This page is working.'), x.referrals[0].name)))
						: h('p', { 'class': 'rf-hint', text: x.total ? __('No visits from AI engines yet. Bots have read it; people haven\'t arrived.') : __('No AI crawler has read this page yet.') })
			]);
			add(d.foot, [
				p && p.edit ? h('a', { 'class': 'rf-btn', href: p.edit }, __('Edit post')) : null,
				p ? h('a', { 'class': 'rf-btn', href: '#/pages/' + p.id, onclick: function () { d.close(true); } }, __('View readiness')) : null,
				h('span', { 'class': 'rf-hint', text: __('Esc to close') })
			]);
		}).catch(function (e) {
			if (openDrawer !== d) { return; }
			clear(d.body).appendChild(h('div', { 'class': 'rf-error' }, icon('xCircle', 20), h('p', { text: e.message })));
		});
	}
	/** Send an address AI crawlers keep requesting to a published page — previewed before it is written. */
	function redirectDrawer(source) {
		var d = drawer(__('Add a redirect'), source);
		var chosen = null;
		var results = h('ul', { 'class': 'rf-picklist' });
		var preview = h('div');
		var go = button(__('Create 301 redirect'), function () {
			return api('/redirects', { method: 'POST', body: { source: source, post_id: chosen.id } }).then(function (r) {
				d.close();
				var made = (r.items || []).filter(function (x) { return x.source === source; })[0];
				toast(sprintf(__('%1$s now redirects to %2$s.'), source, chosen.path), 'info', made ? { undo: function () { api('/redirects/' + made.id, { method: 'DELETE' }).then(function () { toast(__('Redirect removed.')); }); } } : null);
				render();
			});
		}, { primary: true });
		go.disabled = true;
		var search = searchBox('', __('Search your pages…'), function (v) {
			api('/pages?filter=all&sort=hits&search=' + encodeURIComponent(v)).then(function (res) {
				clear(results);
				res.items.slice(0, 8).forEach(function (p) {
					results.appendChild(h('li', null, h('button', { type: 'button', 'class': 'rf-pick', onclick: function () {
						chosen = p;
						Array.prototype.forEach.call(results.querySelectorAll('.rf-pick'), function (b) { b.classList.remove('is-on'); });
						this.classList.add('is-on');
						clear(preview).appendChild(h('pre', { 'class': 'rf-pre', text: sprintf(__('was  %s'), source) + '\n' + sprintf(__('now  %s'), p.path) + '\n\n301 Moved Permanently' }));
						go.disabled = false;
					} }, h('strong', { text: p.title || p.path }), h('span', { 'class': 'rf-mono rf-muted', text: p.path }))));
				});
				if (!res.items.length) { results.appendChild(h('li', { 'class': 'rf-muted', text: __('No pages match.') })); }
			});
		});
		add(d.body, [h('p', { text: __('AI crawlers and old links that ask for this address will be sent to the page you choose. Used only while the address would otherwise answer "not found".') }), search, results, preview]);
		add(d.foot, [go, button(__('Cancel'), function () { d.close(); }, { ghost: true })]);
		later(function () { search.focus(); search.dispatchEvent(new Event('input')); });
	}

	// ── charts ───────────────────────────────────────────────────────────────
	var SERIES = [
		{ key: 'ai_search', label: __('AI search crawlers'), color: 'var(--rf-s1)' },
		{ key: 'ai_user', label: __('Fetched for users / agents'), color: 'var(--rf-s2)' },
		{ key: 'ai_training', label: __('AI training crawlers'), color: 'var(--rf-s3)' },
		{ key: 'ai_other', label: __('Other AI'), color: 'var(--rf-s4)' }
	];
	var tip = null;
	function showTip(evt, lines) {
		if (!tip) { tip = h('div', { 'class': 'rf-tip', role: 'tooltip' }); document.body.appendChild(tip); }
		clear(tip);
		lines.forEach(function (l, i) { tip.appendChild(h('div', { 'class': i ? 'rf-tip-row' : 'rf-tip-head' }, l.color ? h('i', { style: 'background:' + l.color }) : null, h('span', { text: l.text }), has(l.value) ? h('b', { text: l.value }) : null)); });
		tip.style.display = 'block';
		var x = evt.clientX + 14, y = evt.clientY + 14, w = tip.offsetWidth, hh = tip.offsetHeight;
		if (x + w > window.innerWidth - 8) { x = evt.clientX - w - 14; }
		if (y + hh > window.innerHeight - 8) { y = evt.clientY - hh - 14; }
		tip.style.left = x + 'px'; tip.style.top = y + 'px';
	}
	function hideTip() { if (tip) { tip.style.display = 'none'; } }
	function niceMax(v) {
		if (v <= 5) { return 5; }
		var p = Math.pow(10, Math.floor(Math.log10(v))), n = v / p;
		return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
	}

	/** Stacked columns with legend, hover read-out and a table view. */
	function stacked(rows, series, opts) {
		opts = opts || {};
		var W = 720, H = 220, L = 44, R = 8, T = 10, B = 26;
		var totals = rows.map(function (r) { return series.reduce(function (a, sr) { return a + (r[sr.key] || 0); }, 0); });
		var max = niceMax(Math.max.apply(null, totals.concat([1])));
		var n = rows.length, band = (W - L - R) / Math.max(1, n), bw = Math.min(24, Math.max(2, band - 2));
		var y = function (v) { return T + (H - T - B) * (1 - v / max); };
		var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'rf-chart', role: 'img', 'aria-label': opts.label || '' });
		[0, 0.5, 1].forEach(function (f) {
			var v = max * f;
			svg.appendChild(s('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), 'class': 'rf-gridline' }));
			svg.appendChild(s('text', { x: L - 6, y: y(v) + 4, 'text-anchor': 'end', 'class': 'rf-axis' }, num(v)));
		});
		var every = Math.ceil(n / 8);
		rows.forEach(function (r, i) {
			var x = L + i * band + (band - bw) / 2, acc = 0;
			var g = s('g', { 'class': 'rf-col' });
			var segs = series.filter(function (sr) { return (r[sr.key] || 0) > 0; });
			segs.forEach(function (sr, j) {
				var v = r[sr.key], y0 = y(acc), y1 = y(acc + v);
				var hgt = Math.max(0, y0 - y1 - (j > 0 ? 2 : 0));
				var top = j === segs.length - 1;
				if (hgt > 0) {
					g.appendChild(top && hgt > 4
						? s('path', { d: 'M' + x + ' ' + (y0 - (j > 0 ? 2 : 0)) + 'V' + (y1 + 4) + 'q0 -4 4 -4h' + (bw - 8) + 'q4 0 4 4V' + (y0 - (j > 0 ? 2 : 0)) + 'Z', fill: sr.color })
						: s('rect', { x: x, y: y1, width: bw, height: hgt, fill: sr.color }));
				}
				acc += v;
			});
			g.appendChild(s('rect', { x: L + i * band, y: T, width: band, height: H - T - B, fill: 'transparent', 'class': 'rf-hit' }));
			g.addEventListener('mousemove', function (e) {
				showTip(e, [{ text: opts.fmtX ? opts.fmtX(r) : fmtDay(r.day) }].concat(series.map(function (sr) { return { color: sr.color, text: sr.label, value: num(r[sr.key] || 0) }; })).concat([{ text: __('Total'), value: num(totals[i]) }]));
			});
			g.addEventListener('mouseleave', hideTip);
			svg.appendChild(g);
			// Every n-th label plus the last one, unless the last would collide with its neighbour.
			if ((i % every === 0 && n - 1 - i >= every / 2) || i === n - 1) {
				svg.appendChild(s('text', { x: L + i * band + band / 2, y: H - 8, 'text-anchor': 'middle', 'class': 'rf-axis' }, opts.fmtX ? opts.fmtX(r) : fmtDay(r.day)));
			}
		});
		var legend = h('ul', { 'class': 'rf-legend' }, series.map(function (sr) {
			var tot = rows.reduce(function (a, r) { return a + (r[sr.key] || 0); }, 0);
			return h('li', null, h('i', { style: 'background:' + sr.color }), h('span', { text: sr.label }), h('b', { text: num(tot) }));
		}));
		var tbl = table([{ label: opts.xLabel || __('Day'), render: function (r) { return opts.fmtX ? opts.fmtX(r) : fmtDay(r.day); } }].concat(series.map(function (sr) { return { label: sr.label, num: true, render: function (r) { return num(r[sr.key] || 0); } }; })), rows.slice().reverse(), { compact: true });
		return chartFrame(svg, legend, tbl);
	}
	function chartFrame(svg, legend, tbl) {
		var plot = h('div', { 'class': 'rf-plot' }, svg, legend);
		var tw = h('div', { 'class': 'rf-chart-table', hidden: true }, tbl);
		var toggle = h('button', { type: 'button', 'class': 'rf-btn rf-btn-ghost rf-btn-sm rf-chart-toggle', 'aria-pressed': 'false' }, icon('table', 14), __('Table'));
		toggle.addEventListener('click', function () {
			var on = tw.hidden; tw.hidden = !on; plot.hidden = on;
			toggle.setAttribute('aria-pressed', String(on)); clear(toggle).appendChild(icon(on ? 'chart' : 'table', 14)); toggle.appendChild(document.createTextNode(on ? __('Chart') : __('Table')));
		});
		return h('div', { 'class': 'rf-chartbox' }, toggle, plot, tw);
	}
	/** Single-series line (one hue, no legend; the card title names it). */
	function line(points, opts) {
		opts = opts || {};
		var W = 720, H = 180, L = 40, R = 12, T = 12, B = 24;
		if (!points.length) { return empty(opts.empty || __('Not enough history yet.')); }
		var vals = points.map(function (p) { return Number(p.value) || 0; });
		var max = opts.max || niceMax(Math.max.apply(null, vals.concat([1])));
		var n = points.length;
		var x = function (i) { return n === 1 ? (L + W - R) / 2 : L + (W - L - R) * i / (n - 1); };
		var y = function (v) { return T + (H - T - B) * (1 - v / max); };
		var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'rf-chart', role: 'img', 'aria-label': opts.label || '' });
		[0, 0.5, 1].forEach(function (f) { svg.appendChild(s('line', { x1: L, x2: W - R, y1: y(max * f), y2: y(max * f), 'class': 'rf-gridline' })); svg.appendChild(s('text', { x: L - 6, y: y(max * f) + 4, 'text-anchor': 'end', 'class': 'rf-axis' }, num(max * f))); });
		var d = points.map(function (p, i) { return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(vals[i]).toFixed(1); }).join('');
		svg.appendChild(s('path', { d: d + 'L' + x(n - 1) + ' ' + y(0) + 'L' + x(0) + ' ' + y(0) + 'Z', 'class': 'rf-area' }));
		svg.appendChild(s('path', { d: d, 'class': 'rf-line' }));
		var dot = s('circle', { r: 5, 'class': 'rf-dot', cx: x(n - 1), cy: y(vals[n - 1]) });
		svg.appendChild(dot);
		svg.appendChild(s('text', { x: Math.min(x(n - 1), W - R - 2), y: y(vals[n - 1]) - 10, 'text-anchor': 'end', 'class': 'rf-endlabel' }, (opts.fmt || num)(vals[n - 1])));
		var every = Math.ceil(n / 7);
		points.forEach(function (p, i) { if ((i % every === 0 && n - 1 - i >= every / 2) || i === n - 1) { svg.appendChild(s('text', { x: x(i), y: H - 6, 'text-anchor': 'middle', 'class': 'rf-axis' }, fmtDay(p.day))); } });
		var hit = s('rect', { x: L, y: T, width: W - L - R, height: H - T - B, fill: 'transparent' });
		hit.addEventListener('mousemove', function (e) {
			var r = svg.getBoundingClientRect(), rel = (e.clientX - r.left) / r.width * W;
			var i = Math.max(0, Math.min(n - 1, Math.round((rel - L) / ((W - L - R) / Math.max(1, n - 1)))));
			dot.setAttribute('cx', x(i)); dot.setAttribute('cy', y(vals[i]));
			showTip(e, [{ text: fmtDay(points[i].day) }, { color: 'var(--rf-s1)', text: opts.label || '', value: (opts.fmt || num)(vals[i]) }]);
		});
		hit.addEventListener('mouseleave', function () { hideTip(); dot.setAttribute('cx', x(n - 1)); dot.setAttribute('cy', y(vals[n - 1])); });
		svg.appendChild(hit);
		var tbl = table([{ label: __('Day'), render: function (p) { return fmtDay(p.day); } }, { label: opts.label || __('Value'), num: true, render: function (p) { return (opts.fmt || num)(p.value); } }], points.slice().reverse(), { compact: true });
		return chartFrame(svg, null, tbl);
	}
	function spark(values, color) {
		var W = 84, H = 22, max = Math.max.apply(null, values.concat([1])), n = values.length;
		var d = values.map(function (v, i) { return (i ? 'L' : 'M') + (n === 1 ? W / 2 : (W - 4) * i / (n - 1) + 2).toFixed(1) + ' ' + (H - 3 - (H - 6) * v / max).toFixed(1); }).join('');
		return s('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'class': 'rf-spark', role: 'img', 'aria-label': sprintf(__('Last %1$d days: %2$s requests'), n, num(values.reduce(function (a, b) { return a + b; }, 0))) }, s('path', { d: d, style: color ? 'stroke:' + color : null }));
	}

	// ── app shell ────────────────────────────────────────────────────────────
	var root = document.getElementById('rfaib-app');
	var state = { days: 30, status: null };
	// Sections live in the WordPress admin menu (RankyFy → …), registered in class-admin.php.
	var SECTIONS = ['/', '/crawlers', '/referrals', '/readiness', '/access', '/llms', '/opportunities', '/settings'];
	// Deeper screens highlight the section they belong to.
	var PARENT = { '/pages': '/crawlers', '/recommendations': '/readiness', '/technical': '/access', '/ai-files': '/llms', '/history': '/', '/alerts': '/' };
	var shell = { main: null, banner: null };
	function buildShell() {
		clear(root);
		shell.banner = h('div', { 'class': 'rf-banners' });
		shell.main = h('main', { 'class': 'rf-main', tabindex: '-1' });
		add(root, [shell.banner, shell.main]);
	}
	function route() { var hsh = location.hash.replace(/^#/, '') || '/'; return hsh.split('?')[0]; }
	/** The section a route belongs to: /pages/12 → /pages. */
	function section(r) {
		var top = '/' + (r.split('/')[1] || '');
		if (PARENT[top]) { return PARENT[top]; }
		return SECTIONS.indexOf(top) >= 0 ? top : '/';
	}
	/**
	 * Highlight the current section in the WordPress admin menu and keep the
	 * Alerts count there up to date. WordPress marks the menu once per page
	 * load; routes change without a reload, so this follows them.
	 */
	function renderNav() {
		var cur = section(route());
		var items = document.querySelectorAll('#toplevel_page_' + cfg.slug + ' .wp-submenu li');
		Array.prototype.forEach.call(items, function (li) {
			var a = li.querySelector('a');
			if (!a) { return; }
			var href = a.getAttribute('href') || '';
			var i = href.indexOf('#');
			var target = i >= 0 ? href.slice(i + 1) : (href.indexOf('page=' + cfg.slug) >= 0 ? '/' : null);
			if (target === null) { return; }
			var on = target === cur;
			li.classList.toggle('current', on);
			a.classList.toggle('current', on);
			if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		// Browser tab title: the section's name, as WordPress would set it on a page load.
		var current = document.querySelector('#toplevel_page_' + cfg.slug + ' .wp-submenu a.current');
		var name = current && current.firstChild && current.firstChild.nodeType === 3 ? current.firstChild.nodeValue.trim() : '';
		if (name) { document.title = document.title.replace(/^[^‹]*‹/, name + ' ‹'); }
	}
	function renderBanners() {
		clear(shell.banner);
		var st = state.status;
		if (!st) { return; }
		if (!st.tracking) {
			shell.banner.appendChild(h('div', { 'class': 'rf-banner rf-banner-warn' }, icon('alert'), h('span', { text: __('Tracking is switched off. No crawler visits are being recorded.') }), link(__('Settings'), '/settings')));
		}
		if (st.worker && st.worker.stalled) {
			shell.banner.appendChild(h('div', { 'class': 'rf-banner rf-banner-crit' }, icon('xCircle'), h('span', { text: __('Background processing has not run for over an hour. Visits are still recorded, but history, verification and alerts are not updating. WP-Cron may be disabled — ask your host to run wp-cron.php every 5 minutes.') })));
		}
	}
	function refreshStatus() {
		return api('/status').then(function (st) { state.status = st; renderNav(); renderBanners(); return st; }).catch(function () {});
	}
	/**
	 * Only the latest view may draw. A view started for another route (for
	 * example a list refreshed after the user already navigated away) is
	 * dropped, and so is any view superseded by a newer one.
	 */
	var seq = 0;
	function begin(prefix) {
		if (prefix && (prefix === '/' ? route() !== '/' : route().indexOf(prefix) !== 0)) { return null; }
		var my = ++seq;
		return function () { return my === seq; };
	}
	function loading() { clear(shell.main).appendChild(h('div', { 'class': 'rf-skeleton' }, h('div'), h('div'), h('div'))); }
	function fail(e, retry) {
		clear(shell.main).appendChild(h('div', { 'class': 'rf-error' }, icon('xCircle', 20), h('p', { text: e.message || __('Something went wrong.') }), retry ? button(__('Try again'), retry, { icon: 'refresh' }) : null));
	}
	function view(title, sub, tools) {
		var head = h('div', { 'class': 'rf-viewhead' }, h('div', null, h('h2', { 'class': 'rf-title', text: title }), sub ? h('p', { 'class': 'rf-subtitle' }, sub) : null), h('div', { 'class': 'rf-viewtools' }, tools || null));
		var body = h('div', { 'class': 'rf-viewbody' });
		clear(shell.main);
		add(shell.main, [head, body]);
		return body;
	}
	function rangeControl(onChange, options) {
		options = options || [[7, __('7 days')], [30, __('30 days')], [90, __('90 days')]];
		return segmented(options, state.days, function (v) { state.days = Number(v); onChange(); }, __('Time range'));
	}

	// ── Dashboard ────────────────────────────────────────────────────────────
	/**
	 * Requests per day for each slotted crawler plus "Other": crosshair and a
	 * read-out of every series on hover, legend buttons hide a series without
	 * repainting the others, direct end labels, and a table view.
	 */
	function botChart(bs) {
		var all = bs.series.map(function (x) { return { id: x.id, name: x.name, color: slotColor(x.slot), values: x.values, total: x.total }; });
		if (bs.other.total) { all.push({ id: '_other', name: sprintf(_n('Other (%d bot)', 'Other (%d bots)', bs.other.count), bs.other.count), color: slotColor(0), values: bs.other.values, total: bs.other.total }); }
		if (!all.length) { return empty(__('No AI crawler hits in this period yet.')); }
		var hidden = {}, days = bs.days, n = days.length;
		var W = 720, H = 240, L = 36, R = 104, T = 10, B = 26;
		var holder = h('div', { 'class': 'rf-linechart' });
		function draw() {
			clear(holder);
			var vis = all.filter(function (x) { return !hidden[x.id]; });
			var max = niceMax(Math.max.apply(null, [1].concat.apply([1], vis.map(function (x) { return x.values; }))));
			var X = function (i) { return n === 1 ? (L + W - R) / 2 : L + (W - L - R) * i / (n - 1); };
			var Y = function (v) { return T + (H - T - B) * (1 - v / max); };
			var svg = s('svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'rf-chart', role: 'img', 'aria-label': __('AI crawler hits by bot, per day') });
			[0, 0.25, 0.5, 0.75, 1].forEach(function (f) {
				svg.appendChild(s('line', { x1: L, x2: W - R, y1: Y(max * f), y2: Y(max * f), 'class': 'rf-gridline' }));
				svg.appendChild(s('text', { x: L - 6, y: Y(max * f) + 4, 'text-anchor': 'end', 'class': 'rf-axis' }, num(Math.round(max * f))));
			});
			var ticks = Math.min(5, n);
			for (var t = 0; t < ticks; t++) {
				var i = Math.round(t * (n - 1) / Math.max(1, ticks - 1));
				svg.appendChild(s('text', { x: X(i), y: H - 8, 'text-anchor': t === 0 ? 'start' : t === ticks - 1 ? 'end' : 'middle', 'class': 'rf-axis' }, fmtDay(days[i])));
			}
			vis.forEach(function (x) {
				svg.appendChild(s('path', { d: x.values.map(function (v, i) { return (i ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1); }).join(''), 'class': 'rf-mline', style: 'stroke:' + x.color }));
			});
			// Direct labels at the line ends, nudged apart so they never overlap.
			var ends = vis.map(function (x) { return { x: x, y: Y(x.values[n - 1]) }; }).sort(function (a, b) { return a.y - b.y; });
			for (var k = 1; k < ends.length; k++) { if (ends[k].y - ends[k - 1].y < 13) { ends[k].y = ends[k - 1].y + 13; } }
			ends.forEach(function (e) {
				svg.appendChild(s('circle', { cx: X(n - 1), cy: Y(e.x.values[n - 1]), r: 3, style: 'fill:' + e.x.color }));
				svg.appendChild(s('text', { x: X(n - 1) + 8, y: e.y + 4, 'class': 'rf-endlabel', style: 'fill:' + e.x.color }, (e.x.id === '_other' ? __('Other') : e.x.name) + ' ' + num(e.x.values[n - 1])));
			});
			var cross = s('line', { y1: T, y2: H - B, 'class': 'rf-cross', visibility: 'hidden' });
			var dots = s('g', null);
			svg.appendChild(cross);
			svg.appendChild(dots);
			var hit = s('rect', { x: L, y: T, width: W - L - R, height: H - T - B, fill: 'transparent', 'class': 'rf-hit' });
			hit.addEventListener('mousemove', function (e) {
				var box = svg.getBoundingClientRect(), px = (e.clientX - box.left) * W / box.width;
				var i = Math.max(0, Math.min(n - 1, Math.round((px - L) / Math.max(1, (W - L - R)) * (n - 1))));
				cross.setAttribute('x1', X(i)); cross.setAttribute('x2', X(i)); cross.setAttribute('visibility', 'visible');
				clear(dots);
				vis.forEach(function (x) { dots.appendChild(s('circle', { cx: X(i), cy: Y(x.values[i]), r: 3.5, style: 'fill:' + x.color, 'class': 'rf-crossdot' })); });
				showTip(e, [{ text: fmtDay(days[i]) }].concat(vis.slice().sort(function (a, b) { return b.values[i] - a.values[i]; }).map(function (x) { return { color: x.color, text: x.name, value: num(x.values[i]) }; })));
			});
			hit.addEventListener('mouseleave', function () { cross.setAttribute('visibility', 'hidden'); clear(dots); hideTip(); });
			svg.appendChild(hit);
			holder.appendChild(svg);
		}
		draw();
		var legend = h('ul', { 'class': 'rf-legend' }, all.map(function (x) {
			var b = h('button', { type: 'button', 'class': 'rf-legend-btn', 'aria-pressed': 'true', title: __('Show or hide this crawler'), onclick: function () {
				hidden[x.id] = !hidden[x.id];
				b.setAttribute('aria-pressed', String(!hidden[x.id]));
				draw();
			} }, h('i', { style: 'background:' + x.color }), h('span', { text: x.name }));
			return h('li', null, b);
		}));
		var tbl = table([{ label: __('Day'), render: function (r) { return fmtDay(r.day); } }].concat(all.map(function (x) { return { label: x.name, num: true, render: function (r) { return num(x.values[r.i]); } }; })), days.map(function (d, i) { return { day: d, i: i }; }).reverse(), { compact: true });
		return chartFrame(holder, legend, tbl);
	}

	function viewOverview() {
		var live = begin('/');
		if (!live) { return; }
		loading();
		api('/overview?days=' + state.days).then(function (o) {
			if (!live()) { return; }
			var obs = o.observed, cov = o.coverage, ready = o.readiness;
			if (!obs.ai_requests && !cov.crawled_ever && !o.top_bots.some(function (b) { return b.ai; })) { dashboardEmpty(o); return; }
			var body = view(__('RankyFy'), sprintf(__('AI crawler activity and readiness for %1$s · last %2$d days'), o.host, o.range), rangeDropdown(viewOverview));
			if (o.unread_alerts) {
				body.appendChild(h('div', { 'class': 'rf-notice rf-notice-info' }, h('span', { text: sprintf(_n('%d unread alert.', '%d unread alerts.', o.unread_alerts), o.unread_alerts) }), link(__('View alerts'), '/alerts')));
			}
			var never = cov.pages - cov.crawled_ever;
			var verifiedPct = pct(obs.verified, obs.ai_requests);
			body.appendChild(h('div', { 'class': 'rf-stiles' },
				stile(__('AI crawler hits'), num(obs.ai_requests), deltaLine(obs.ai_requests, obs.ai_requests_prev)),
				stile(__('Pages ever read'), [num(cov.crawled_ever), h('small', { text: ' / ' + num(cov.pages) })], never > 0 ? h('a', { 'class': 'rf-crit-text rf-sub-link', href: '#/pages?filter=never', text: sprintf(__('%s never crawled'), num(never)) }) : h('span', { 'class': 'rf-delta', text: __('every page has been read') })),
				stile(__('Visits from AI'), num(obs.ai_referrals), deltaLine(obs.ai_referrals, o.ai_referrals_prev)),
				stile(__('Verified hits'), obs.ai_requests ? verifiedPct + '%' : '—', h('span', { 'class': 'rf-delta', text: obs.ai_requests ? sprintf(__('%d%% unconfirmed origin'), 100 - verifiedPct) : '' }))));

			var chart = card(__('AI crawler hits by bot'), { tools: h('span', { 'class': 'rf-hint', text: sprintf(__('Daily · last %d days'), o.range) }) });
			add(chart.body, [botChart(o.bot_series), h('div', { 'class': 'rf-linkrow rf-right' }, link(__('View all →'), '/crawlers'))]);

			var pages = card(__('Most-read pages by AI crawlers'), { tools: h('span', { 'class': 'rf-hint', text: sprintf(__('Last %d days'), o.range) }) });
			pages.body.appendChild(table([
				{ label: __('Page'), render: function (p) { return urlAnchor(pagePath(p.path), p.hash); } },
				{ label: __('Top bot'), render: function (p) { return p.top_bot ? h('span', { 'class': 'rf-botname' }, botSq(p.top_bot.slot), p.top_bot.name) : '—'; } },
				{ label: __('Hits'), num: true, render: function (p) { return num(p.hits); } },
				{ label: __('AI visits'), num: true, render: function (p) { return num(p.visits); } }
			], o.top_pages.slice(0, 5), { empty: __('No pages crawled by AI yet.') }));

			var rc = card(__('AI Readiness'));
			if (ready && has(ready.score)) {
				add(rc.body, [h('div', { 'class': 'rf-scorehead' }, scoreRing(ready.score, { size: 84 }),
					h('div', { 'class': 'rf-scoremeta' }, scoreStatus(ready.score),
						h('span', { text: sprintf(_n('%d open issue', '%d open issues', ready.open), ready.open) }),
						ready.quick ? h('span', { 'class': 'rf-muted', text: sprintf(_n('%d quick fix', '%d quick fixes', ready.quick), ready.quick) }) : null)),
					h('div', null, h('a', { 'class': 'rf-btn rf-btn-primary rf-btn-sm', href: '#/readiness' }, __('Review & fix')))]);
			} else { rc.body.appendChild(empty(__('Your pages are being checked. The score appears within a few minutes.'))); }

			var refs = card(__('Visits from AI engines'), { tools: h('span', { 'class': 'rf-hint', text: sprintf(__('%dd'), o.range) }) });
			refs.body.appendChild(o.referrals.length ? engineBars(o.referrals) : empty(__('No visits from AI assistants in this period.')));

			add(body, [h('div', { 'class': 'rf-dash' }, h('div', { 'class': 'rf-stack' }, chart, pages), h('div', { 'class': 'rf-stack' }, rc, refs))]);
		}).catch(function (e) { if (live()) { fail(e, viewOverview); } });
	}

	/** One measure across engines, so every bar shares one colour; the labels carry identity. */
	function engineBars(rows) {
		var max = Math.max.apply(null, rows.map(function (r) { return r.visits; }).concat([1]));
		return h('div', { 'class': 'rf-bars' }, rows.map(function (r) {
			var bar = h('i');
			later(function () { bar.style.width = (100 * r.visits / max).toFixed(1) + '%'; });
			return [h('span', { text: r.name }), h('div', { 'class': 'rf-bar' }, bar), h('b', { text: num(r.visits) })];
		}));
	}

	/** First days: answer "is it broken?" first, then give things to do that need no crawl data. */
	function dashboardEmpty(o) {
		var since = state.status && state.status.monitoring_since;
		var body = view(__('RankyFy'), since ? sprintf(__('Listening for AI crawlers since %1$s · %2$s'), fmtDate(since), ago(since)) : __('Listening for AI crawlers'));
		body.appendChild(state.status && state.status.tracking === false
			? h('div', { 'class': 'rf-notice rf-notice-warn' }, h('strong', { text: __('Capture is off.') }), ' ', h('span', { text: __('Crawler requests are not being recorded. Switch it on in Settings.') }), link(__('Settings'), '/settings'))
			: h('div', { 'class': 'rf-notice rf-notice-good' }, icon('check', 14), h('strong', { text: __('Capture is working.') }), ' ', h('span', { text: __('The WordPress hook is recording every AI crawler request that reaches WordPress. Nothing more to do.') })));
		if (o.caches && o.caches.length) {
			body.appendChild(h('div', { 'class': 'rf-notice rf-notice-warn' }, h('strong', { text: sprintf(__('Caching detected: %s.'), o.caches.join(' + ')) }), ' ', h('span', { text: __('Cached pages are answered before WordPress runs, so those crawler requests are missed. Importing your server\'s access log fills the gap.') }), h('a', { href: '#/settings?tab=capture', text: __('Set up') })));
		}
		var mailBtn = button(o.first_hit ? __('We\'ll email you') : __('Email me on first hit'), function () {
			return api('/first-hit', { method: 'POST', body: { on: !o.first_hit } }).then(function (r) {
				o.first_hit = r.on;
				clear(mailBtn).appendChild(document.createTextNode(r.on ? __('We\'ll email you') : __('Email me on first hit')));
				toast(r.on ? sprintf(__('We\'ll send one email to %s when the first AI crawler arrives.'), r.email) : __('No email will be sent.'));
			});
		}, { icon: o.first_hit ? 'check' : 'bell' });
		var listen = card(null);
		listen.body.appendChild(h('div', { 'class': 'rf-listen' },
			h('span', { 'class': 'rf-hint', text: __('waiting for the first hit') }),
			h('span', { 'class': 'rf-dashed', 'aria-hidden': 'true' }),
			h('h4', { text: __('No AI crawler hits yet — this is normal') }),
			h('p', { text: __('AI crawlers visit most sites every few days, not every hour. Expect your first hits within a few days. Each one shows up here within minutes.') }),
			h('div', { 'class': 'rf-row rf-center' }, mailBtn, button(__('Run a test request'), function () {
				return api('/technical/refresh', { method: 'POST' }).then(function (t) {
					var r = (t.probe && t.probe.results) || [], refused = r.filter(function (x) { return x.refused; }).length;
					if (t.probe && t.probe.available === false) { toast(__('Your server cannot request its own pages, so the test is not available here.'), 'error'); return; }
					toast(refused ? sprintf(__('%1$d of %2$d test requests as AI crawlers were refused — see Access Manager.'), refused, r.length) : sprintf(__('Test passed: all %d requests as AI crawlers got through.'), r.length), refused ? 'error' : 'info');
				});
			}))));
		var ready = o.readiness, items = [];
		if (ready && has(ready.score)) { items.push([sprintf(__('Your readiness score is %d'), ready.score), sprintf(_n('%d issue to fix — no waiting for data', '%d issues to fix — no waiting for data', ready.open), ready.open), __('Fix now'), '#/readiness', true]); }
		if (!o.llms_enabled) { items.push([__('No llms.txt yet'), sprintf(__('Generate one from your %s published pages'), num(o.coverage.pages)), __('Generate'), '#/llms']); }
		if (o.access_unreviewed) { items.push([sprintf(_n('%d AI crawler is unreviewed', '%d AI crawlers are unreviewed', o.access_unreviewed), o.access_unreviewed), __('Decide which AI bots may read this site'), __('Review'), '#/access']); }
		var todo = card(sprintf(_n('Meanwhile: %d thing worth doing now', 'Meanwhile: %d things worth doing now', Math.min(3, items.length)), Math.min(3, items.length)));
		todo.body.appendChild(h('ul', { 'class': 'rf-todo' }, items.slice(0, 3).map(function (it) { return h('li', null, h('div', null, h('strong', { text: it[0] }), h('span', { text: it[1] })), h('a', { 'class': 'rf-btn rf-btn-sm' + (it[4] ? ' rf-btn-primary' : ''), href: it[3] }, it[2])); })));
		var names = o.listening || [];
		var bots = card(__('What we\'re listening for'), { tools: h('span', { 'class': 'rf-hint', text: sprintf(_n('%d bot', '%d bots', names.length), names.length) }) });
		bots.body.appendChild(h('div', { 'class': 'rf-tags' }, names.slice(0, 13).map(function (n) { return h('span', { 'class': 'rf-tag', text: n }); }).concat(names.length > 13 ? [h('span', { 'class': 'rf-tag rf-tag-missing', text: sprintf(__('+ %d more'), names.length - 13) })] : [])));
		add(body, [listen, h('div', { 'class': 'rf-grid' }, todo, bots)]);
	}

	function recItem(f) {
		var where = f.page_id ? pageLink({ page_id: f.page_id, title: f.page_title, path: f.path }) : h('span', { 'class': 'rf-muted', text: __('Whole site') });
		return h('li', { 'class': 'rf-rec rf-rec-' + f.severity },
			h('div', { 'class': 'rf-rec-head' }, sev(f.severity), prov(f.kind), h('strong', { text: f.title })),
			where,
			h('p', { 'class': 'rf-why' }, h('span', { 'class': 'rf-lbl', text: __('Why it matters') }), f.why),
			h('p', { 'class': 'rf-action' }, h('span', { 'class': 'rf-lbl', text: __('What to do') }), f.action),
			f.items && f.items.length ? h('ul', { 'class': 'rf-items' }, f.items.map(function (i) { return h('li', { text: i }); })) : null);
	}

	// ── Crawlers ─────────────────────────────────────────────────────────────
	var crawlState = { bot: '', status: '', search: '', page: 1, showUnverified: false, hideUnverified: false };
	function viewCrawlers() {
		var tab = q('tab');
		if (tab === 'unknown' || tab === 'registry') {
			var back = h('div', { 'class': 'rf-row' }, link('← ' + __('AI Crawlers'), '/crawlers'), segmented([['unknown', __('Unrecognised bots')], ['registry', __('Registry')]], tab, function (v) { location.hash = '#/crawlers?tab=' + v; }, __('Show')));
			return tab === 'unknown' ? viewAgents(back) : viewRegistry(back);
		}
		if (q('status')) { crawlState.status = q('status'); }
		var live = begin('/crawlers');
		if (!live) { return; }
		loading();
		var go = function (changes) { Object.keys(changes).forEach(function (k) { crawlState[k] = changes[k]; }); viewCrawlers(); };
		var qs = '/crawlers/pages?days=' + state.days + '&bot=' + encodeURIComponent(crawlState.bot) + '&status=' + crawlState.status + '&search=' + encodeURIComponent(crawlState.search) + '&page=' + crawlState.page;
		Promise.all([api('/bots?days=' + state.days), api(qs)]).then(function (res) {
			if (!live()) { return; }
			var bots = res[0].items.filter(function (b) { return b.ai; }), pg = res[1];
			var hits = bots.reduce(function (a, b) { return a + b.requests; }, 0), spoofed = bots.reduce(function (a, b) { return a + b.impersonations; }, 0);
			var body = view(__('AI Crawlers'), __('Every AI crawler request to this site, verified against published IP ranges.'));
			var botName = function (id) { var b = bots.filter(function (x) { return x.id === id; })[0]; return b ? b.name : id; };
			body.appendChild(h('div', { 'class': 'rf-filterbar' },
				rangeDropdown(function () { go({ page: 1 }); }),
				dropdown([['', __('All bots')]].concat(bots.map(function (b) { return [b.id, b.name]; })), crawlState.bot, function (v) { go({ bot: v, page: 1 }); }, __('Bot')),
				dropdown([['', __('All status codes')], ['2xx', __('2xx — OK')], ['3xx', __('3xx — redirects')], ['4xx', __('4xx — not found, refused')], ['5xx', __('5xx — server errors')]], crawlState.status, function (v) { go({ status: v, page: 1 }); }, __('Status')),
				searchBox(crawlState.search, __('Search URL…'), function (v) { go({ search: v, page: 1 }); }),
				crawlState.bot ? h('span', { 'class': 'rf-filterchip' }, botName(crawlState.bot), h('button', { type: 'button', 'aria-label': __('Remove filter'), onclick: function () { go({ bot: '', page: 1 }); } }, icon('x', 12))) : null,
				h('span', { 'class': 'rf-grow rf-hint' }, sprintf(_n('%s hit', '%s hits', hits), num(hits)) + ' · ', h('a', { href: cfg.exportUrl + '&type=events&days=' + state.days, text: __('Export CSV') }))));
			if (spoofed && !crawlState.hideUnverified) {
				var list = h('ul', { 'class': 'rf-items', hidden: !crawlState.showUnverified }, bots.filter(function (b) { return b.impersonations; }).map(function (b) { return h('li', null, sprintf(__('%1$s: %2$s requests claimed the name'), b.name, num(b.impersonations))); }));
				var show = h('button', { type: 'button', 'class': 'rf-linkbtn', text: crawlState.showUnverified ? __('Hide them') : __('Show them'), onclick: function () { crawlState.showUnverified = !crawlState.showUnverified; list.hidden = !crawlState.showUnverified; show.textContent = crawlState.showUnverified ? __('Hide them') : __('Show them'); } });
				body.appendChild(h('div', { 'class': 'rf-notice rf-notice-warn' }, icon('alert', 14),
					h('div', { 'class': 'rf-grow' }, h('strong', { text: sprintf(__('%1$s hits (%2$d%%) could not be verified.'), num(spoofed), pct(spoofed, hits + spoofed)) }), ' ', __('The user-agent claimed to be an AI bot but the request didn\'t come from that bot\'s published addresses. They\'re counted separately and excluded from charts.'), ' ', show, list),
					h('button', { type: 'button', 'class': 'rf-x', 'aria-label': __('Dismiss'), onclick: function () { crawlState.hideUnverified = true; this.parentNode.remove(); } }, icon('x', 14))));
			}
			var byBot = card(__('By bot'), { tools: h('span', { 'class': 'rf-hint', text: __('Click a row to filter the page table') }) });
			byBot.body.appendChild(table([
				{ label: __('Bot'), render: function (b) { return h('span', { 'class': 'rf-botname' }, botSq(b.slot), h('a', { href: '#/crawlers/' + b.id, text: b.name })); } },
				{ label: __('Purpose'), render: function (b) { return purposeChip(b.category); } },
				{ label: __('Access'), render: function (b) { return accessChip(b.access); } },
				{ label: __('Hits'), num: true, render: function (b) { return num(b.requests); } },
				{ label: __('Pages'), num: true, render: function (b) { return num(b.pages); } },
				{ label: __('Last seen'), render: function (b) { return ago(b.last_seen); } },
				{ label: __('30d trend'), render: function (b) { return spark(b.spark, slotColor(b.slot)); } }
			], bots, { empty: __('No AI crawler activity in this period yet.'), onRow: function (b) { go({ bot: crawlState.bot === b.id ? '' : b.id, page: 1 }); }, rowClass: function (b) { return b.id === crawlState.bot ? 'is-on' : ''; } }));
			byBot.body.appendChild(h('p', { 'class': 'rf-hint' }, __('Also: '), link(__('unrecognised bots'), '/crawlers?tab=unknown'), ' · ', link(__('crawler registry'), '/crawlers?tab=registry')));

			var from = (pg.page - 1) * 20 + 1, to = Math.min(pg.total, pg.page * 20);
			var byPage = card(__('By page'), { tools: h('span', { 'class': 'rf-hint', text: pg.total ? sprintf(__('Showing %1$s–%2$s of %3$s'), num(from), num(to), num(pg.total)) : '' }) });
			byPage.body.appendChild(table([{ label: __('URL'), render: function (r) { return urlAnchor(pagePath(r.path), r.hash, r.page_id); } }]
				.concat(pg.columns.map(function (c) { return { label: c.name, num: true, render: function (r) { return num(r.counts[c.id] || 0); } }; }))
				.concat([
					{ label: __('Other'), num: true, render: function (r) { return num(r.other); } },
					{ label: __('Last status'), render: function (r) { return has(r.status) ? statusChip(r.status) : '—'; } },
					{ label: '', render: function (r) { return r.status >= 400 && r.status < 500 ? button(__('Add redirect'), function () { redirectDrawer(r.path); }, { small: true }) : button(__('Detail'), function () { urlDrawer(r.hash); }, { small: true }); } }
				]), pg.items, { empty: crawlState.bot || crawlState.status || crawlState.search ? __('No hits match these filters.') : __('No AI crawler requests in this period yet.') }));
			if (!pg.items.length && (crawlState.bot || crawlState.status || crawlState.search)) { byPage.body.appendChild(h('p', null, h('button', { type: 'button', 'class': 'rf-linkbtn', text: __('Clear filters'), onclick: function () { go({ bot: '', status: '', search: '', page: 1 }); } }))); }
			add(byPage.body, [pager(pg.page, pg.pages, function (p) { go({ page: p }); })]);
			add(body, [byBot, byPage]);
		}).catch(function (e) { if (live()) { fail(e, viewCrawlers); } });
	}

	// ── AI Referrals ─────────────────────────────────────────────────────────
	var refState = { engine: '', search: '', page: 1 };
	function viewReferrals() {
		var live = begin('/referrals');
		if (!live) { return; }
		loading();
		var go = function (changes) { Object.keys(changes).forEach(function (k) { refState[k] = changes[k]; }); viewReferrals(); };
		api('/referrals?days=' + state.days + '&engine=' + encodeURIComponent(refState.engine) + '&search=' + encodeURIComponent(refState.search) + '&page=' + refState.page).then(function (d) {
			if (!live()) { return; }
			var body = view(__('AI Referrals'), __('Visits that arrived from an AI assistant — counted on your server, not sampled.'));
			body.appendChild(h('div', { 'class': 'rf-filterbar' },
				rangeDropdown(function () { go({ page: 1 }); }),
				dropdown([['', __('All engines')]].concat(d.engines.map(function (e) { return [e.source, e.name]; })), refState.engine, function (v) { go({ engine: v, page: 1 }); }, __('Engine')),
				searchBox(refState.search, __('Search landing page…'), function (v) { go({ search: v, page: 1 }); }),
				h('span', { 'class': 'rf-grow rf-hint' }, sprintf(_n('%s visit', '%s visits', d.visits), num(d.visits)) + ' · ', h('a', { href: cfg.exportUrl + '&type=referrals&days=' + state.days, text: __('Export CSV') }))));
			var engines = card(__('By engine'));
			add(engines.body, [d.engines.length ? engineBars(d.engines) : empty(__('No visits from AI engines in this period.')), h('p', { 'class': 'rf-hint', text: __('One measure across engines, so all bars share one colour — the labels carry identity, not hue.') })]);
			var c = d.cited, cvc = card(__('Crawled vs cited'), { tools: h('span', { 'class': 'rf-hint', text: sprintf(__('Last %d days'), d.days) }) });
			add(cvc.body, [
				h('dl', { 'class': 'rf-facts rf-facts-wide' },
					h('dt', { text: __('Read by bots & getting AI visits') }), h('dd', null, h('strong', { text: sprintf(_n('%s page', '%s pages', c.cited), num(c.cited)) })),
					h('dt', { text: __('Read by bots, no AI visits') }), h('dd', null, h('strong', { text: sprintf(_n('%s page', '%s pages', c.read_only), num(c.read_only)) })),
					h('dt', { text: __('Never read by any bot') }), h('dd', null, h('a', { href: '#/pages?filter=never', text: sprintf(_n('%s page', '%s pages', c.never), num(c.never)) }))),
				c.read_only ? h('div', { 'class': 'rf-notice rf-notice-warn' }, icon('alert', 14), h('span', null, h('strong', { text: sprintf(_n('%s page is being read but never cited.', '%s pages are being read but never cited.', c.read_only), num(c.read_only)) }), ' ', __('Usually a content or structure problem rather than a crawling one. '), link(__('Readiness Score'), '/readiness'), __(' lists the likely causes per page.'))) : null
			]);
			var from = (d.page - 1) * 20 + 1, to = Math.min(d.total, d.page * 20);
			var lp = card(__('Landing pages'), { tools: h('span', { 'class': 'rf-hint', text: d.total ? sprintf(__('Showing %1$s–%2$s of %3$s'), num(from), num(to), num(d.total)) : '' }) });
			lp.body.appendChild(table([{ label: __('Landing page'), render: function (r) { return urlAnchor(pagePath(r.path), r.hash, r.page_id); } }]
				.concat(d.columns.map(function (c) { return { label: c.name, num: true, render: function (r) { return num(r.counts[c.id] || 0); } }; }))
				.concat([
					{ label: __('Other'), num: true, render: function (r) { return num(r.other); } },
					{ label: __('Total'), num: true, render: function (r) { return h('strong', { text: num(r.total) }); } },
					{ label: __('Bot hits'), num: true, render: function (r) { return num(r.bot_hits); } }
				]), d.items, { empty: (c.cited + c.read_only) ? sprintf(__('No visits from AI engines in this period. Bots have read %s of your pages, so the reading is happening — the citing isn\'t yet.'), num(c.cited + c.read_only)) : __('No visits from AI engines in this period.') }));
			add(lp.body, [pager(d.page, d.pages, function (p) { go({ page: p }); }), h('p', { 'class': 'rf-hint', text: __('Some apps send no referrer, and those visits are invisible to any server-side method — so this number is a floor, not a total.') })]);
			add(body, [h('div', { 'class': 'rf-grid' }, engines, cvc), lp]);
		}).catch(function (e) { if (live()) { fail(e, viewReferrals); } });
	}

	function viewCrawler(id) {
		var live = begin('/crawlers/');
		if (!live) { return; }
		loading();
		api('/bots/' + encodeURIComponent(id) + '?days=' + state.days).then(function (b) {
			if (!live()) { return; }
			var reg = b.registry || {};
			var body = view(b.name, reg.description || '', h('div', { 'class': 'rf-row' }, link('← ' + __('All crawlers'), '/crawlers'), rangeControl(function () { viewCrawler(id); })));
			var facts = h('dl', { 'class': 'rf-facts' },
				h('dt', { text: __('Operator') }), h('dd', { text: reg.provider || '—' }),
				h('dt', { text: __('Purpose') }), h('dd', { text: catLabel(reg.category) + (reg.user_triggered ? ' · ' + __('triggered by a person') : '') }),
				h('dt', { text: __('Verification') }), h('dd', { text: reg.verify ? [reg.verify.ranges.length ? __('published addresses') : null, reg.verify.rdns.length ? __('reverse DNS') : null, reg.verify.signature_hosts.length ? __('request signatures') : null].filter(Boolean).join(', ') || __('none published — user agent only') : '—' }),
				h('dt', { text: __('Respects robots.txt') }), h('dd', { text: reg.respects_robots === true ? __('Yes (per operator)') : reg.respects_robots === false ? __('No — user-triggered or not honoured') : __('Unknown') }),
				h('dt', { text: __('robots.txt for your site') }), h('dd', null, b.robots ? (b.robots.site_allowed ? __('Allowed') : __('Blocked') + ' (' + b.robots.rule + ')') : '—'),
				h('dt', { text: __('First / last visit') }), h('dd', { text: (b.first_seen ? fmtDate(b.first_seen) : '—') + ' / ' + (b.last_seen ? ago(b.last_seen) : '—') }),
				h('dt', { text: __('Registry entry') }), h('dd', null, (reg.source === 'official' ? __('Checked against operator documentation') : __('From public reports')) + (reg.verified_on ? ' · ' + reg.verified_on : ''), reg.docs ? h('a', { href: reg.docs, target: '_blank', rel: 'noopener noreferrer', 'class': 'rf-ext' }, icon('external', 12), __('Documentation')) : null)
			);
			var info = card(__('About this crawler'));
			info.body.appendChild(facts);
			var tl = card(__('Requests per day'), { prov: 'observed' });
			tl.body.appendChild(b.timeline.some(function (r) { return r.requests || r.spoofed; }) ? stacked(b.timeline, [{ key: 'requests', label: __('Requests'), color: 'var(--rf-s1)' }, { key: 'spoofed', label: __('Impersonations'), color: 'var(--rf-s2)' }], { label: __('Requests per day') }) : empty(__('No requests in this period.')));
			var sess = card(__('Crawl sessions'), { prov: 'observed', sub: __('A session is a run of requests with no gap longer than 30 minutes.') });
			sess.body.appendChild(table([
				{ label: __('Started'), render: function (r) { return fmtDate(r.started); } },
				{ label: __('Duration'), render: function (r) { return duration(r.ended - r.started); } },
				{ label: __('Requests'), num: true, render: function (r) { return num(r.hits); } },
				{ label: __('Pages'), num: true, render: function (r) { return num(r.pages); } },
				{ label: __('Addresses'), num: true, render: function (r) { return num(r.ips); } },
				{ label: __('Errors'), num: true, render: function (r) { return num(r.errors); } }
			], b.sessions, { compact: true }));
			var pages = card(__('Pages it requested'), { prov: 'observed' });
			pages.body.appendChild(table([
				{ label: __('Page'), render: function (p) { return pageLink(p); } },
				{ label: __('Requests'), num: true, render: function (p) { return num(p.hits); } },
				{ label: __('Errors'), num: true, render: function (p) { return num(p.errors); } },
				{ label: __('Last status'), num: true, render: function (p) { return statusChip(p.status); } }
			], b.pages, { compact: true }));
			var codes = card(__('Responses it received'), { prov: 'observed', sub: sprintf(__('From the last %d days of stored requests.'), Math.min(state.days, b.retention_days)) });
			codes.body.appendChild(table([{ label: __('HTTP status'), render: function (r) { return statusChip(r.status); } }, { label: __('Requests'), num: true, render: function (r) { return num(r.n); } }], b.status_codes, { compact: true }));
			var nets = card(__('Networks and user agents'), { prov: 'observed', sub: __('Addresses are shown as networks (/24 or /48), never as full addresses.') });
			add(nets.body, [
				table([{ label: __('Network'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.ip_net }); } }, { label: __('Verification'), render: function (r) { return verif('', r.vstate); } }, { label: __('Requests'), num: true, render: function (r) { return num(r.n); } }], b.networks, { compact: true }),
				table([{ label: __('User agent'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.ua }); } }, { label: __('Requests'), num: true, render: function (r) { return num(r.n); } }], b.user_agents, { compact: true })
			]);
			var recent = card(__('Latest requests'), { prov: 'observed' });
			recent.body.appendChild(eventsTable(b.recent));
			add(body, [h('div', { 'class': 'rf-grid' }, info, tl), h('div', { 'class': 'rf-grid' }, pages, h('div', { 'class': 'rf-stack' }, sess, codes)), nets, recent]);
		}).catch(function (e) { if (live()) { fail(e, function () { viewCrawler(id); }); } });
	}
	function statusChip(code) {
		if (!code) { return '—'; }
		var cls = code >= 500 ? 'rf-chip-crit' : code >= 400 ? 'rf-chip-warn' : code >= 300 ? '' : 'rf-chip-good';
		return h('span', { 'class': 'rf-chip ' + cls, text: String(code) });
	}
	function eventsTable(rows) {
		return table([
			{ label: __('Time'), render: function (r) { return fmtDate(r.ts); } },
			{ label: __('Crawler'), render: function (r) { return r.bot ? link(r.name || r.bot, '/crawlers/' + r.bot) : null; } },
			{ label: __('Request'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.method + ' ' + r.path }); } },
			{ label: __('Status'), render: function (r) { return statusChip(r.status); } },
			{ label: __('Time taken'), num: true, render: function (r) { return num(r.ms) + ' ms'; } },
			{ label: __('Identity'), render: function (r) { return verif(r.cls, r.vstate); } },
			{ label: __('Network'), render: function (r) { return h('span', { 'class': 'rf-mono rf-muted', text: r.net || '—' }); } },
			{ label: __('Source'), render: function (r) { return r.source === 'log' ? __('Imported log') : __('Live'); } }
		].filter(function (c, i) { return i !== 1 || rows.some(function (r) { return r.bot; }); }), rows, { compact: true, empty: __('No stored requests (requests are kept for the retention period set in Settings).') });
	}

	function viewAgents(tools) {
		var live = begin('/crawlers');
		if (!live) { return; }
		loading();
		api('/agents').then(function (d) {
			if (!live()) { return; }
			var body = view(__('Unrecognised bots'), __('Automated clients that are not in the crawler registry. Possible AI crawlers are listed first.'), tools);
			var c = card(null, { prov: 'observed' });
			c.body.appendChild(table([
				{ label: __('User agent'), render: function (a) { return h('span', { 'class': 'rf-mono', text: a.ua || __('(empty)') }); } },
				{ label: __('Looks like'), render: function (a) { return a.cls === 'potential' ? verif('potential') : __('Generic bot'); } },
				{ label: __('Requests'), num: true, render: function (a) { return num(a.hits); } },
				{ label: __('First seen'), render: function (a) { return fmtDay(a.first_seen); } },
				{ label: __('Last seen'), render: function (a) { return ago(a.last_seen); } },
				{ label: '', render: function (a) {
					return h('div', { 'class': 'rf-row' },
						button(__('Track as crawler'), function () { trackAgent(a); }, { small: true }),
						button(__('Ignore'), function () { return api('/agents/' + a.hash, { method: 'POST', body: { action: 'ignore' } }).then(function () { toast(__('Ignored.')); viewCrawlers(); }); }, { small: true, ghost: true }));
				} }
			], d.items, { empty: __('No unrecognised bots so far.') }));
			body.appendChild(c);
		}).catch(function (e) { if (live()) { fail(e, viewCrawlers); } });
	}
	function trackAgent(a) {
		var token = h('input', { type: 'text', value: a.token || '', 'class': 'rf-input' });
		var provider = h('input', { type: 'text', 'class': 'rf-input', placeholder: __('Company (optional)') });
		var cat = h('select', { 'class': 'rf-input' }, ['ai_training', 'ai_search', 'ai_user', 'ai_agent', 'ai_other', 'seo', 'other'].map(function (c) { return h('option', { value: c, text: catLabel(c) }); }));
		dialog(__('Track this bot'), h('div', { 'class': 'rf-form' },
			h('p', { 'class': 'rf-mono', text: a.ua }),
			h('label', null, __('Text that identifies it in the user agent'), token),
			h('label', null, __('Company'), provider), h('label', null, __('Purpose'), cat),
			h('p', { 'class': 'rf-hint', text: __('It is added to your own registry entries. Requests already recorded stay as they are; new visits are tracked page by page. It cannot be verified until its operator publishes addresses.') })),
			[{ label: __('Track'), primary: true, run: function (close) {
				api('/agents/' + a.hash, { method: 'POST', body: { action: 'track', token: token.value, provider: provider.value, category: cat.value } }).then(function () { toast(__('Now tracking this crawler.')); close(); viewCrawlers(); }).catch(function (e) { toast(e.message, 'error'); });
				return false;
			} }]);
	}

	function viewRegistry(tools) {
		var live = begin('/crawlers');
		if (!live) { return; }
		loading();
		api('/registry').then(function (r) {
			if (!live()) { return; }
			var body = view(__('Crawler registry'), sprintf(__('Version %1$s · source: %2$s'), r.version, r.source === 'rankyfy' ? __('RankyFy (live)') : __('built into the plugin')), tools);
			var actions = h('div', { 'class': 'rf-row' }, button(__('Check for updates'), function () { return api('/registry/sync', { method: 'POST' }).then(function (x) { toast(x.result === 'updated' ? __('Registry updated.') : x.result === 'unavailable' ? __('RankyFy is not reachable; using the built-in registry and operators\' own address lists.') : __('Registry is up to date.')); viewRegistry(tools); }); }, { icon: 'refresh' }), button(__('Add a crawler'), function () { addCustom(tools); }, { icon: 'robot' }));
			body.appendChild(actions);
			var groups = {};
			r.bots.forEach(function (b) { (groups[b.category] = groups[b.category] || []).push(b); });
			Object.keys(CAT).forEach(function (c) {
				if (!groups[c]) { return; }
				var cd = card(catLabel(c) + ' · ' + groups[c].length, { sub: r.categories[c] || '' });
				cd.body.appendChild(table([
					{ label: __('Crawler'), render: function (b) { return h('div', { 'class': 'rf-pagecell' }, h('strong', { text: b.name }), h('span', { 'class': 'rf-muted', text: b.provider })); } },
					{ label: __('Recognised by'), render: function (b) { return b.robots_only ? __('robots.txt token only (never visits)') : b.ip_only ? __('published addresses only') : b.signed_only ? __('request signature') : h('span', { 'class': 'rf-mono', text: b.patterns.join(', ') }); } },
					{ label: __('Verification'), render: function (b) {
						var parts = [];
						if (b.ranges_status.length) { parts.push(sprintf(__('%1$d address lists (%2$s)'), b.ranges_status.length, b.ranges_status.every(function (x) { return x.fetched_at; }) ? sprintf(__('updated %s'), ago(Math.min.apply(null, b.ranges_status.map(function (x) { return x.fetched_at; })))) : __('not fetched yet'))); }
						if (b.verify.rdns.length) { parts.push(__('reverse DNS')); }
						if (b.verify.signature_hosts.length) { parts.push(__('signatures')); }
						return parts.join(' · ') || h('span', { 'class': 'rf-muted', text: __('none published') });
					} },
					{ label: __('Confidence'), render: function (b) { return b.confidence; } },
					{ label: __('Checked'), render: function (b) { return (b.verified_on || '—') + ' · ' + (b.origin === 'custom' ? __('yours') : b.source === 'official' ? __('operator docs') : __('public reports')); } },
					{ label: '', render: function (b) { return b.origin === 'custom' ? button(__('Remove'), function () { return api('/registry/custom/' + b.id, { method: 'DELETE' }).then(function () { viewRegistry(tools); }); }, { small: true, ghost: true }) : (b.docs ? h('a', { href: b.docs, target: '_blank', rel: 'noopener noreferrer' }, icon('external', 12)) : null); } }
				], groups[c], { compact: true }));
				body.appendChild(cd);
			});
		}).catch(function (e) { if (live()) { fail(e, viewCrawlers); } });
	}
	function addCustom(tools) {
		var f = { id: h('input', { 'class': 'rf-input', placeholder: 'examplebot' }), name: h('input', { 'class': 'rf-input', placeholder: 'ExampleBot' }), provider: h('input', { 'class': 'rf-input' }), pattern: h('input', { 'class': 'rf-input', placeholder: 'ExampleBot' }), robots: h('input', { 'class': 'rf-input', placeholder: 'ExampleBot' }) };
		var cat = h('select', { 'class': 'rf-input' }, ['ai_training', 'ai_search', 'ai_user', 'ai_agent', 'ai_other', 'search', 'seo', 'other'].map(function (c) { return h('option', { value: c, text: catLabel(c) }); }));
		dialog(__('Add a crawler'), h('div', { 'class': 'rf-form' },
			h('label', null, __('Id (lowercase letters, digits, dashes)'), f.id), h('label', null, __('Name'), f.name), h('label', null, __('Company'), f.provider),
			h('label', null, __('Text to look for in the user agent'), f.pattern), h('label', null, __('robots.txt token (optional)'), f.robots), h('label', null, __('Purpose'), cat)),
			[{ label: __('Add'), primary: true, run: function (close) {
				api('/registry/custom', { method: 'POST', body: { id: f.id.value, name: f.name.value, provider: f.provider.value, category: cat.value, patterns: [f.pattern.value], robots_tokens: f.robots.value ? [f.robots.value] : [] } }).then(function () { toast(__('Crawler added.')); close(); viewRegistry(tools); }).catch(function (e) { toast(e.message, 'error'); });
				return false;
			} }]);
	}

	// ── Pages ────────────────────────────────────────────────────────────────
	var pagesState = { filter: 'important', sort: 'importance', search: '', page: 1, bot: '' };
	/** Change the page list's filters through the URL, so the URL always describes the screen. */
	function goPages(changes) {
		Object.keys(changes).forEach(function (k) { pagesState[k] = changes[k]; });
		var h = '#/pages?filter=' + encodeURIComponent(pagesState.filter) + '&sort=' + encodeURIComponent(pagesState.sort) + (pagesState.search ? '&search=' + encodeURIComponent(pagesState.search) : '') + (pagesState.page > 1 ? '&page=' + pagesState.page : '');
		if (location.hash === h) { render(); } else { location.hash = h; }
	}
	function viewPages() {
		var live = begin('/pages');
		if (!live) { return; }
		pagesState.filter = q('filter') || 'important';
		pagesState.sort = q('sort') || 'importance';
		pagesState.search = q('search');
		pagesState.page = parseInt(q('page'), 10) || 1;
		loading();
		var qs = '/pages?filter=' + pagesState.filter + '&sort=' + pagesState.sort + '&page=' + pagesState.page + '&days=' + state.days + '&bot=' + encodeURIComponent(pagesState.bot) + '&search=' + encodeURIComponent(pagesState.search);
		Promise.all([api(qs), api('/overview?days=' + state.days)]).then(function (res) {
			if (!live()) { return; }
			var d = res[0], cov = res[1].coverage;
			var body = view(__('Pages'), __('Which pages AI crawlers read, which they ignore, and which are blocked.'), rangeControl(viewPages));
			body.appendChild(h('div', { 'class': 'rf-grid rf-grid-3' },
				meter(cov.important_crawled, cov.important, sprintf(__('Important pages crawled (%d days)'), state.days)),
				meter(cov.crawled, cov.pages, sprintf(__('All pages crawled (%d days)'), state.days), { level: 'info' }),
				meter(cov.crawled_ever, cov.pages, __('Crawled at least once since monitoring began'), { level: 'info' })));
			var filters = [['important', __('Important')], ['all', __('All')], ['crawled', __('Crawled')], ['uncrawled', __('Not crawled')], ['never', __('Never crawled')], ['dropped', __('No longer crawled')], ['errors', __('Errors')], ['blocked', __('Blocked')]];
			var search = h('input', { type: 'search', 'class': 'rf-input', placeholder: __('Search title or URL'), value: pagesState.search });
			search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { goPages({ search: search.value, page: 1 }); } });
			var sort = h('select', { 'class': 'rf-input', 'aria-label': __('Sort') }, [['importance', __('Most important')], ['hits', __('Most crawled')], ['last', __('Recently crawled')], ['score', __('Lowest AEO score')], ['title', __('Title')]].map(function (o) { return h('option', { value: o[0], text: o[1], selected: o[0] === pagesState.sort }); }));
			sort.addEventListener('change', function () { goPages({ sort: sort.value, page: 1 }); });
			body.appendChild(h('div', { 'class': 'rf-filterbar' }, segmented(filters, pagesState.filter, function (v) { goPages({ filter: v, page: 1 }); }, __('Filter')), search, sort));
			var c = card(sprintf(_n('%s page', '%s pages', d.total), num(d.total)), { prov: 'observed' });
			c.body.appendChild(table([
				{ label: __('Page'), render: function (p) { return h('div', { 'class': 'rf-pagecell' }, pageAnchor(p.title || p.path, p.id), h('span', { 'class': 'rf-mono rf-muted', text: p.path }), p.important ? h('span', { 'class': 'rf-hint', text: p.reasons.map(function (r) { return REASONS[r] || r; }).join(' · ') }) : null); } },
				{ label: __('Importance'), num: true, render: function (p) { return p.pinned > 0 ? h('span', { title: __('Marked important') }, icon('pin', 12), ' 100') : p.pinned < 0 ? __('excluded') : String(p.importance); } },
				{ label: __('AEO'), num: true, render: function (p) { return has(p.score) ? scoreChip(p.score) : h('span', { 'class': 'rf-muted', text: __('pending') }); } },
				{ label: sprintf(__('AI requests (%dd)'), d.days), num: true, render: function (p) { return num(p.hits); } },
				{ label: __('Last AI crawl'), render: function (p) { return p.last_ai ? ago(p.last_ai) : h('span', { 'class': 'rf-warn-text', text: __('never') }); } },
				{ label: __('Crawled by'), render: function (p) { return p.bots.length ? h('span', { 'class': 'rf-muted', text: p.bots.join(', ') }) : '—'; } },
				{ label: __('Issues'), render: function (p) { return h('span', null, p.critical ? sev('critical') : null, p.critical ? ' ' + p.critical + ' ' : null, p.warnings ? h('span', { 'class': 'rf-muted', text: sprintf(_n('%d warning', '%d warnings', p.warnings), p.warnings) }) : null); } }
			], d.items, { empty: __('No pages match this filter.') }));
			add(c.body, [pager(d.page, d.pages, function (p) { goPages({ page: p }); })]);
			body.appendChild(c);
		}).catch(function (e) { if (live()) { fail(e, viewPages); } });
	}
	function scoreChip(v) {
		var cls = v >= 70 ? 'rf-chip-good' : v >= 45 ? 'rf-chip-warn' : 'rf-chip-crit';
		return h('span', { 'class': 'rf-chip ' + cls, text: String(v) });
	}

	function viewPage(id) {
		var live = begin('/pages/');
		if (!live) { return; }
		loading();
		api('/pages/' + id).then(function (d) {
			if (!live()) { return; }
			var p = d.page, obs = d.observed, inf = d.inferred;
			var pin = function (v) { return api('/pages/' + id + '/pin', { method: 'POST', body: { pin: v } }).then(function () { toast(__('Saved.')); viewPage(id); }); };
			var tools = h('div', { 'class': 'rf-row' },
				link('← ' + __('Pages'), '/pages'),
				h('a', { 'class': 'rf-btn rf-btn-ghost rf-btn-sm', href: p.view, target: '_blank', rel: 'noopener' }, icon('external', 14), __('View')),
				p.edit ? h('a', { 'class': 'rf-btn rf-btn-ghost rf-btn-sm', href: p.edit }, __('Edit')) : null,
				p.pinned > 0 ? button(__('Unmark important'), function () { return pin(0); }, { small: true, icon: 'pin' }) : button(__('Mark important'), function () { return pin(1); }, { small: true, icon: 'pin' }),
				p.pinned < 0 ? button(__('Include again'), function () { return pin(0); }, { small: true, ghost: true }) : button(__('Exclude'), function () { return pin(-1); }, { small: true, ghost: true, title: __('Leave this page out of scores and alerts') }),
				button(__('Re-check now'), function () { return api('/pages/' + id + '/analyze', { method: 'POST' }).then(function () { toast(__('Page re-checked.')); viewPage(id); }); }, { small: true, icon: 'refresh' }));
			var body = view(p.title || p.path, p.path + ' · ' + sprintf(__('importance %d'), p.importance) + (p.reasons.length ? ' (' + p.reasons.map(function (r) { return REASONS[r] || r; }).join(', ') + ')' : ''), tools);

			var sc = card(__('AI search readiness'));
			if (d.score) {
				add(sc.body, [h('div', { 'class': 'rf-hero-row' }, h('div', { 'class': 'rf-hero-num' }, h('span', { text: String(d.score.score) }), h('small', { text: '/100' })),
					h('div', { 'class': 'rf-parts' }, Object.keys(d.score.max).map(function (k) { return meter(d.score.parts[k] || 0, d.score.max[k], { access: __('Access'), discovery: __('Discovery'), structure: __('Structure'), depth: __('Depth'), trust: __('Trust'), linking: __('Internal links') }[k], { text: (d.score.parts[k] || 0) + ' / ' + d.score.max[k] }); })))]);
			} else { sc.body.appendChild(empty(__('Not analysed yet.'))); }

			var crawlers = card(__('AI crawlers on this page'), { prov: 'observed' });
			crawlers.body.appendChild(table([
				{ label: __('Crawler'), render: function (b) { return h('div', { 'class': 'rf-pagecell' }, link(b.name, '/crawlers/' + b.id), h('span', { 'class': 'rf-muted', text: catLabel(b.category) })); } },
				{ label: __('robots.txt'), render: function (b) { return b.allowed ? h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Allowed')) : h('span', { 'class': 'rf-chip rf-chip-crit', title: b.rule }, icon('ban', 12), __('Blocked')); } },
				{ label: __('First crawl'), render: function (b) { return b.robots_only ? h('span', { 'class': 'rf-muted', text: __('token only') }) : b.first_seen ? fmtDay(b.first_seen) : h('span', { 'class': 'rf-muted', text: __('never') }); } },
				{ label: __('Last crawl'), render: function (b) { return b.last_seen ? ago(b.last_seen) : '—'; } },
				{ label: __('Requests'), num: true, render: function (b) { return num(b.hits); } },
				{ label: __('Last status'), render: function (b) { return statusChip(b.status); } }
			], obs.crawlers, { compact: true }));

			var tl = card(__('Crawl activity, last 90 days'), { prov: 'observed' });
			tl.body.appendChild(obs.timeline.some(function (r) { return r.ai || r.search; }) ? stacked(obs.timeline, [{ key: 'ai', label: __('AI crawlers'), color: 'var(--rf-s1)' }, { key: 'search', label: __('Search engines'), color: 'var(--rf-s2)' }], {}) : empty(__('No crawler requests for this page in the last 90 days.')));

			var findings = card(__('Issues and recommendations'));
			findings.body.appendChild(d.findings.length ? h('ol', { 'class': 'rf-recs' }, d.findings.map(function (f) {
				var li = recItem(f);
				li.appendChild(h('div', { 'class': 'rf-row' }, button(__('Ignore'), function () { return api('/findings/' + f.id, { method: 'POST', body: { status: 'ignored' } }).then(function () { viewPage(id); toast(__('Ignored.'), 'info', { undo: function () { api('/findings/' + f.id, { method: 'POST', body: { status: 'open' } }).then(function () { viewPage(id); }); } }); }); }, { small: true, ghost: true })));
				return li;
			})) : empty(__('No open issues on this page.')));
			if (d.resolved.length) {
				findings.body.appendChild(h('details', { 'class': 'rf-details' }, h('summary', { text: sprintf(_n('%d fixed issue', '%d fixed issues', d.resolved.length), d.resolved.length) }), h('ul', { 'class': 'rf-items' }, d.resolved.map(function (f) { return h('li', null, icon('checkCircle', 12), ' ', f.title, h('span', { 'class': 'rf-muted', text: ' · ' + fmtDay(f.resolved_at) })); }))));
			}

			var content = card(__('What crawlers find on the page'), { prov: 'observed', sub: obs.content && obs.content.fetched ? __('Read from the page as your server delivers it.') : __('Read from the stored content (the page itself is fetched for important pages).') });
			if (obs.content) {
				var cdata = obs.content;
				add(content.body, [h('dl', { 'class': 'rf-facts' },
					h('dt', { text: __('Words') }), h('dd', { text: num(cdata.words) }),
					h('dt', { text: __('Likely search intent') }), h('dd', null, cdata.intent ? cdata.intent.intent : '—', ' ', prov('estimated')),
					h('dt', { text: __('Headings') }), h('dd', { text: sprintf(__('%1$d (%2$d phrased as questions)'), cdata.headings.length, cdata.question_headings.length) }),
					h('dt', { text: __('Structured data') }), h('dd', { text: cdata.schema.length ? cdata.schema.join(', ') : __('none found') }),
					h('dt', { text: __('FAQ section') }), h('dd', { text: cdata.faq ? __('yes') : __('no') }),
					h('dt', { text: __('Lists / tables') }), h('dd', { text: cdata.lists + ' / ' + cdata.tables }),
					h('dt', { text: __('Internal links in / out') }), h('dd', { text: cdata.inlinks + ' / ' + cdata.outlinks }),
					h('dt', { text: __('Images without alt') }), h('dd', { text: cdata.images_no_alt + ' / ' + cdata.images }),
					h('dt', { text: __('Author') }), h('dd', { text: cdata.author ? (cdata.author.name || '—') + (cdata.author.bio ? '' : ' · ' + __('no bio')) : __('not applicable') }),
					h('dt', { text: __('Last updated') }), h('dd', { text: has(cdata.modified_days) ? sprintf(__('%d days ago'), cdata.modified_days) : '—' }),
					h('dt', { text: __('HTTP status / robots') }), h('dd', { text: (cdata.status || '—') + ' / ' + ((cdata.robots_meta || []).concat(cdata.x_robots ? [cdata.x_robots] : []).join('; ') || __('no directives')) }),
					h('dt', { text: __('Canonical') }), h('dd', { 'class': 'rf-mono', text: cdata.canonical || '—' })),
					cdata.terms.length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Key terms in your content') }), h('div', { 'class': 'rf-tags' }, cdata.terms.map(function (t) { return h('span', { 'class': 'rf-tag', title: sprintf(__('Used %1$d times; on %2$d pages of the site'), t.count, t.pages), text: t.term }); }))) : null,
					cdata.entities.length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Names mentioned') }), h('div', { 'class': 'rf-tags' }, cdata.entities.map(function (t) { return h('span', { 'class': 'rf-tag', text: t }); }))) : null,
					cdata.question_headings.length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Questions the page already answers') }), h('ul', { 'class': 'rf-items' }, cdata.question_headings.map(function (t) { return h('li', { text: t }); }))) : null]);
			} else { content.body.appendChild(empty(__('Not analysed yet.'))); }

			var links = card(__('Internal linking opportunities'), { prov: 'observed', sub: __('Pages about the same topics that do not link here yet, from your own content.') });
			links.body.appendChild(table([
				{ label: __('Add a link on'), render: function (l) { return h('div', { 'class': 'rf-pagecell' }, link(l.from_title || l.from_path, '/pages/' + l.from_id), h('span', { 'class': 'rf-mono rf-muted', text: l.from_path })); } },
				{ label: __('Suggested anchor text'), render: function (l) { return h('span', { 'class': 'rf-tag', text: l.anchor }); } },
				{ label: '', render: function (l) { return l.edit ? h('a', { href: l.edit, text: __('Edit') }) : null; } }
			], d.links, { compact: true, empty: __('No suggestions — either enough pages link here, or no related pages were found.') }));

			var aiCard = card(__('AI analysis'), { prov: 'inferred', sub: __('Generated by RankyFy Content AI from this page. Keyword volumes marked "Measured" and questions marked "Seen in search results" come from real data; everything else is a suggestion.') });
			renderAssist(aiCard.body, inf.analysis, d.ai_available, id);
			var patterns = card(__('Questions people might ask'), { prov: 'template', sub: __('Built from this page\'s key terms and likely intent with fixed patterns. They are ideas to check against your audience — not searches or prompts anyone was observed making.') });
			patterns.body.appendChild(inf.query_patterns.length ? h('ul', { 'class': 'rf-items' }, inf.query_patterns.map(function (t) { return h('li', { text: t }); })) : empty(__('Not enough content to build ideas.')));

			var recent = card(__('Latest requests to this page'), { prov: 'observed' });
			recent.body.appendChild(eventsTable(obs.recent));
			var refs = obs.referrals.length ? card(__('Visits from AI assistants, 90 days'), { prov: 'observed' }) : null;
			if (refs) { refs.body.appendChild(table([{ label: __('Assistant'), key: 'name' }, { label: __('Visits'), num: true, render: function (r) { return num(r.visits); } }], obs.referrals, { compact: true })); }

			add(body, [
				h('div', { 'class': 'rf-grid' }, sc, crawlers), findings,
				h('div', { 'class': 'rf-section-label' }, prov('observed'), h('span', { text: __('Measured on your site') })),
				h('div', { 'class': 'rf-grid' }, content, h('div', { 'class': 'rf-stack' }, tl, links, refs)), recent,
				h('div', { 'class': 'rf-section-label' }, prov('inferred'), h('span', { text: __('Suggestions — not observed data') })),
				h('div', { 'class': 'rf-grid' }, aiCard, patterns)
			]);
		}).catch(function (e) { if (live()) { fail(e, function () { viewPage(id); }); } });
	}
	function renderAssist(el, a, available, id) {
		var run = button(a ? __('Analyze again') : __('Analyze with RankyFy AI'), function () {
			return api('/pages/' + id + '/ai-analyze', { method: 'POST' }).then(function () { toast(__('Analysis ready.')); viewPage(id); });
		}, { primary: !a, icon: 'sparkles', title: __('Uses RankyFy Content AI credits') });
		if (!available && !a) {
			el.appendChild(empty(__('AI analysis uses your RankyFy account. Install and connect the RankyFy SEO plugin to enable it. Everything else on this screen works without it.')));
			return;
		}
		if (!a) {
			add(el, [h('p', { 'class': 'rf-hint', text: __('Get keywords with real search volumes, related terms and entities, questions to answer, missing topics and link ideas for this page. Uses Content AI credits from your plan.') }), run]);
			return;
		}
		var kw = [a.primary_keyword].concat(a.secondary_keywords || []).filter(function (k) { return k && k.keyword; });
		add(el, [
			h('p', { 'class': 'rf-hint', text: sprintf(__('Analysed %s.'), ago(a.at)) }), available ? run : null,
			a.summary ? h('p', { text: a.summary }) : null,
			a.intent && a.intent.primary ? h('p', null, h('strong', { text: __('Intent: ') }), a.intent.primary + (a.intent.audience ? ' · ' + a.intent.audience : ''), a.intent.explanation ? h('span', { 'class': 'rf-muted', text: ' — ' + a.intent.explanation }) : null) : null,
			kw.length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Keywords') }), table([
				{ label: __('Keyword'), key: 'keyword' },
				{ label: __('Monthly searches'), num: true, render: function (k) { return has(k.volume) ? num(k.volume) : '—'; } },
				{ label: __('Source'), render: function (k) { return prov(k.source); } },
				{ label: __('On the page'), render: function (k) { return k.used ? __('yes') : h('strong', { text: __('missing') }); } }
			], kw, { compact: true })) : null,
			(a.semantic_terms || []).length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Related terms') }), h('div', { 'class': 'rf-tags' }, a.semantic_terms.map(function (t) { return h('span', { 'class': 'rf-tag' + (t.used ? '' : ' rf-tag-missing'), title: t.used ? __('Already on the page') : __('Not on the page yet'), text: t.term }); }))) : null,
			(a.entities || []).length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Entities') }), h('div', { 'class': 'rf-tags' }, a.entities.map(function (t) { return h('span', { 'class': 'rf-tag' + (t.used ? '' : ' rf-tag-missing'), text: t.term }); }))) : null,
			(a.questions || []).length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Questions to answer') }), h('ul', { 'class': 'rf-items' }, a.questions.map(function (x) { return h('li', null, x.question, ' ', prov(x.source)); }))) : null,
			(a.gaps || []).concat((a.additions || []).map(function (x) { return x.topic; })).length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Missing topics') }), h('ul', { 'class': 'rf-items' }, (a.gaps || []).concat((a.additions || []).map(function (x) { return x.topic + (x.why ? ' — ' + x.why : ''); })).map(function (t) { return h('li', { text: t }); }))) : null,
			(a.internal_links || []).length ? h('div', { 'class': 'rf-sub' }, h('h4', { text: __('Suggested internal links') }), h('ul', { 'class': 'rf-items' }, a.internal_links.map(function (l) { return h('li', null, h('span', { 'class': 'rf-tag', text: l.anchor }), ' → ', l.title || l.url, l.why ? h('span', { 'class': 'rf-muted', text: ' — ' + l.why }) : null); }))) : null,
			a.depth && a.depth.recommended ? h('p', { 'class': 'rf-hint', text: sprintf(__('Depth: %1$d words now, about %2$d suggested. %3$s'), a.depth.words, a.depth.recommended, a.depth.verdict || '') }) : null
		]);
	}

	// ── Opportunities ────────────────────────────────────────────────────────
	function viewOpportunities() {
		var live = begin('/opportunities');
		if (!live) { return; }
		loading();
		api('/opportunities').then(function (o) {
			if (!live()) { return; }
			var body = view(__('AI search opportunities'), __('Where your site can win more AI visibility. Observed data and suggestions are kept apart.'));
			body.appendChild(h('div', { 'class': 'rf-banner rf-banner-info' }, icon('info'), h('span', { text: __('"Observed" sections come from real requests to your site and from your own content. "AI-suggested" and "Template idea" items are generated — they are not real searches or prompts anyone was observed making. AI assistants do not share the questions people ask.') })));

			// What the crawl data itself says to do, before any content suggestion.
			var cr = o.crawl || {};
			body.appendChild(h('div', { 'class': 'rf-section-label' }, prov('observed'), h('span', { text: __('From your crawl data') })));
			var blocked = card(__('Blocked crawlers that already send value'), { prov: 'observed', sub: __('Your robots.txt blocks these AI crawlers, yet their assistants send visitors — or they keep asking. If that is not a deliberate choice, weigh the trade-off in the Access Manager.') });
			blocked.body.appendChild(table([
				{ label: __('Crawler'), render: function (b) { return link(b.name, '/crawlers/' + b.id); } },
				{ label: __('Provider'), key: 'provider' },
				{ label: __('Blocked from'), render: function (b) { return b.site_allowed ? sprintf(_n('%d important page', '%d important pages', b.blocked_pages), b.blocked_pages) : __('the whole site'); } },
				{ label: __('What the block costs'), render: function (b) {
					if (b.visits) { return sprintf(__('%1$s sent %2$s visits in 30 days'), b.assistant, num(b.visits)); }
					if (b.attempts) { return sprintf(_n('%d request turned away in 30 days', '%d requests turned away in 30 days', b.attempts), b.attempts); }
					return h('span', { 'class': 'rf-muted', text: __('nothing observed yet') });
				} }
			], cr.blocked || [], { compact: true, empty: __('No blocked AI crawler is costing you anything we can see.') }));
			if ((cr.blocked || []).length) { blocked.body.appendChild(h('p', { 'class': 'rf-hint' }, link(__('Open the Access Manager →'), '/access'))); }
			var un = cr.uncrawled || { items: [], total: 0 };
			var unc = card(__('Important pages no AI crawler has read'), { prov: 'observed', sub: __('Assistants cannot cite what their crawlers have never fetched. Check that these pages are linked, in the sitemap and in llms.txt.') });
			unc.body.appendChild(table([
				{ label: __('Page'), render: pageLink },
				{ label: __('Importance'), num: true, render: function (p) { return num(p.importance); } }
			], un.items || [], { compact: true, empty: __('Every important page has been read by at least one AI crawler.') }));
			if (un.total > (un.items || []).length) { unc.body.appendChild(h('p', { 'class': 'rf-hint' }, sprintf(_n('%s page in total.', '%s pages in total.', un.total), num(un.total)), ' ', link(__('All important pages'), '/pages?filter=important'))); }
			var weak = card(__('Crawled often, but not answer-ready'), { prov: 'observed', sub: __('AI crawlers already want these pages; a low readiness score means the content is hard to lift into an answer. Improving them is the shortest path to being cited.') });
			weak.body.appendChild(table([
				{ label: __('Page'), render: pageLink },
				{ label: __('AI requests (30 d)'), num: true, render: function (p) { return num(p.hits); } },
				{ label: __('Crawlers'), num: true, render: function (p) { return num(p.bots); } },
				{ label: __('Score'), num: true, render: function (p) { return String(p.aeo_score); } }
			], cr.weak || [], { compact: true, empty: __('No frequently crawled page has a weak readiness score.') }));
			var errs = card(__('Pages failing for AI crawlers'), { prov: 'observed', sub: __('These URLs returned errors to AI crawlers in the last 14 days. A page that errors drops out of answers quickly.') });
			errs.body.appendChild(table([
				{ label: __('Page'), render: pageLink },
				{ label: __('Errors (14 d)'), num: true, render: function (p) { return num(p.errors); } },
				{ label: __('Last status'), num: true, render: function (p) { return String(p.status || '—'); } }
			], cr.errors || [], { compact: true, empty: __('No errors served to AI crawlers in the last 14 days.') }));
			var slow = card(__('Slow for AI crawlers'), { prov: 'observed', sub: __('Average response above 1.5 seconds across at least five fetches. Slow pages get fewer and shallower crawls.') });
			slow.body.appendChild(table([
				{ label: __('Page'), render: pageLink },
				{ label: __('Avg response'), num: true, render: function (p) { return sprintf(__('%s ms'), num(p.avg_ms)); } },
				{ label: __('Fetches (30 d)'), num: true, render: function (p) { return num(p.hits); } }
			], cr.slow || [], { compact: true, empty: __('No page is slow for AI crawlers.') }));
			add(body, [blocked, h('div', { 'class': 'rf-grid' }, unc, weak), h('div', { 'class': 'rf-grid' }, errs, slow)]);

			body.appendChild(h('div', { 'class': 'rf-section-label' }, prov('observed'), h('span', { text: __('From your content and visitors') })));
			var asked = card(__('Pages AI assistants fetched for their users'), { prov: 'observed', sub: __('Each fetch is a real conversation in which an assistant opened your page (last 30 days). These pages are already in play — keep them accurate.') });
			asked.body.appendChild(table([{ label: __('Page'), render: pageLink }, { label: __('Fetches'), num: true, render: function (p) { return num(p.fetches); } }, { label: __('Assistants'), render: function (p) { return p.bots.join(', '); } }], o.observed.assistant_pages, { compact: true, empty: __('No user-triggered fetches yet.') }));
			var refs = card(__('Visits from AI assistants'), { prov: 'observed' });
			refs.body.appendChild(table([{ label: __('Assistant'), key: 'name' }, { label: __('Visits'), num: true, render: function (r) { return num(r.visits); } }, { label: __('Landing pages'), num: true, render: function (r) { return num(r.pages); } }], o.observed.referrals, { compact: true, empty: __('None in the last 30 days.') }));
			var topics = card(__('Topics your content covers'), { prov: 'observed', sub: __('Key terms used across several of your pages. Topics with few pages are candidates for more depth.') });
			topics.body.appendChild(o.observed.topics.length ? h('div', { 'class': 'rf-tags' }, o.observed.topics.map(function (t) { return h('span', { 'class': 'rf-tag', title: sprintf(_n('%d page', '%d pages', t.pages), t.pages) }, t.term, h('small', { text: ' ' + t.pages })); })) : empty(__('Topics appear once several pages are analysed.')));
			var linksCard = card(__('Internal linking opportunities'), { prov: 'observed', sub: __('Important pages with few internal links, and related pages that could link to them.') });
			linksCard.body.appendChild(o.links.items.length ? h('ul', { 'class': 'rf-linkops' }, o.links.items.slice(0, 15).map(function (it) {
				return h('li', null, pageLink(it), h('span', { 'class': 'rf-muted', text: sprintf(_n('%d page links here', '%d pages link here', it.inlinks), it.inlinks) }),
					h('ul', { 'class': 'rf-items' }, it.suggestions.map(function (sg) { return h('li', null, __('Link from '), link(sg.from_title || sg.from_path, '/pages/' + sg.from_id), __(' with '), h('span', { 'class': 'rf-tag', text: sg.anchor })); })));
			})) : empty(__('No linking gaps found among important pages.')));
			add(body, [h('div', { 'class': 'rf-grid' }, asked, refs), h('div', { 'class': 'rf-grid' }, topics, linksCard)]);

			body.appendChild(h('div', { 'class': 'rf-section-label' }, prov('inferred'), h('span', { text: __('AI-suggested') })));
			var inf = o.inferred;
			if (inf.not_analyzed) {
				body.appendChild(h('div', { 'class': 'rf-banner rf-banner-info' }, icon('sparkles'), h('span', { text: sprintf(_n('%d important page has no AI analysis yet. Open a page and choose "Analyze with RankyFy AI" to get keyword, question and topic suggestions.', '%d important pages have no AI analysis yet. Open a page and choose "Analyze with RankyFy AI" to get keyword, question and topic suggestions.', inf.not_analyzed), inf.not_analyzed) }), link(__('Important pages'), '/pages?filter=important')));
			}
			var kws = card(__('Keyword opportunities'), { prov: 'inferred', sub: __('Volumes marked "Measured" are real monthly searches (Google Ads Keyword Planner). The keywords themselves are AI suggestions.') });
			kws.body.appendChild(table([
				{ label: __('Keyword'), key: 'keyword' }, { label: __('Monthly searches'), num: true, render: function (k) { return has(k.volume) ? num(k.volume) : '—'; } },
				{ label: __('Source'), render: function (k) { return prov(k.source); } }, { label: __('For page'), render: function (k) { return pageLink(k.page); } }, { label: __('On the page'), render: function (k) { return k.used ? __('yes') : __('missing'); } }
			], inf.keywords, { compact: true, empty: __('Analyse important pages with RankyFy AI to see keyword opportunities.') }));
			var qs = card(__('Questions to answer'), { prov: 'inferred' });
			qs.body.appendChild(table([{ label: __('Question'), key: 'question' }, { label: __('Source'), render: function (x) { return prov(x.source); } }, { label: __('For page'), render: function (x) { return pageLink(x.page); } }], inf.questions, { compact: true, empty: __('No question suggestions yet.') }));
			var gaps = card(__('Content gaps'), { prov: 'inferred' });
			gaps.body.appendChild(table([{ label: __('Missing topic'), key: 'topic' }, { label: __('Page'), render: function (x) { return pageLink(x.page); } }], inf.gaps, { compact: true, empty: __('No content gaps suggested yet.') }));
			var pats = card(__('Possible questions, by page'), { prov: 'template', sub: __('Patterns built from each page\'s key terms. Use them as a checklist, not as data.') });
			pats.body.appendChild(inf.query_patterns.length ? h('ul', { 'class': 'rf-linkops' }, inf.query_patterns.map(function (p) { return h('li', null, pageLink(p.page), h('ul', { 'class': 'rf-items' }, p.patterns.map(function (t) { return h('li', { text: t }); }))); })) : empty(__('Ideas appear once important pages are analysed.')));
			add(body, [kws, h('div', { 'class': 'rf-grid' }, qs, gaps), pats]);
		}).catch(function (e) { if (live()) { fail(e, viewOpportunities); } });
	}
	// ── Recommendations ──────────────────────────────────────────────────────
	var recState = { severity: '', kind: '', group: '', code: '', page: 1 };
	function goRecs(changes) {
		Object.keys(changes).forEach(function (k) { recState[k] = changes[k]; });
		var h = '#/recommendations?' + ['severity', 'kind', 'group', 'code', 'page'].filter(function (k) { return recState[k] && !(k === 'page' && recState[k] === 1); }).map(function (k) { return k + '=' + encodeURIComponent(recState[k]); }).join('&');
		if (location.hash === h) { render(); } else { location.hash = h; }
	}
	function viewRecommendations() {
		var live = begin('/recommendations');
		if (!live) { return; }
		['severity', 'kind', 'group', 'code'].forEach(function (k) { recState[k] = q(k); });
		recState.page = parseInt(q('page'), 10) || 1;
		loading();
		var qs = '/recommendations?severity=' + recState.severity + '&kind=' + recState.kind + '&group=' + recState.group + '&page=' + recState.page + (recState.code ? '&code=' + recState.code : '');
		api(qs).then(function (d) {
			if (!live()) { return; }
			var body = view(__('Recommendations'), __('Ordered by severity and by how important the page is. Fixed issues close automatically on the next check.'));
			var bar = h('div', { 'class': 'rf-filterbar' },
				segmented([['', __('All')], ['critical', __('Critical')], ['warning', __('Warnings')], ['info', __('Suggestions')]], recState.severity, function (v) { goRecs({ severity: v, code: '', page: 1 }); }, __('Severity')),
				segmented([['', __('Any source')], ['observed', __('Observed')], ['inferred', __('AI-suggested')]], recState.kind, function (v) { goRecs({ kind: v, page: 1 }); }, __('Source')),
				segmented([['', __('All areas')], ['access', __('Access')], ['discovery', __('Discovery')], ['content', __('Content')], ['trust', __('Trust')]], recState.group, function (v) { goRecs({ group: v, code: '', page: 1 }); }, __('Area')));
			body.appendChild(bar);
			var c = card(sprintf(_n('%s open item', '%s open items', d.total), num(d.total)));
			c.body.appendChild(d.items.length ? h('ol', { 'class': 'rf-recs' }, d.items.map(function (f) {
				var li = recItem(f);
				li.appendChild(h('div', { 'class': 'rf-row' }, button(__('Ignore'), function () { return api('/findings/' + f.id, { method: 'POST', body: { status: 'ignored' } }).then(function () { viewRecommendations(); toast(__('Ignored. It will not be shown again unless it changes.'), 'info', { undo: function () { api('/findings/' + f.id, { method: 'POST', body: { status: 'open' } }).then(viewRecommendations); } }); }); }, { small: true, ghost: true })));
				return li;
			})) : empty(__('Nothing here. Change the filters, or check back after the next analysis.')));
			if (recState.code) { body.insertBefore(h('div', { 'class': 'rf-banner rf-banner-info' }, icon('info'), h('span', { text: __('Showing one issue type.') }), button(__('Show all'), function () { goRecs({ code: '', page: 1 }); }, { small: true, ghost: true })), c); }
			add(c.body, [pager(d.page, d.pages, function (p) { goRecs({ page: p }); })]);
			body.appendChild(c);
		}).catch(function (e) { if (live()) { fail(e, viewRecommendations); } });
	}

	// ── Technical ────────────────────────────────────────────────────────────
	/** What blocking a crawler costs, in plain words. [text, serious?] */
	function consequence(r) {
		var who = r.provider || r.name;
		switch (r.category) {
			case 'ai_training': return [__('Stops use for training future models. No effect on AI search answers.'), false];
			case 'ai_search': return [sprintf(__('You disappear from %s\'s AI search answers.'), who), true];
			case 'ai_user': case 'ai_agent': return [sprintf(__('%s can\'t open your pages when a user asks about them.'), who), true];
			case 'search': return [sprintf(__('Drops you from %s search — and the AI answers built on it.'), who), true];
		}
		return [__('Little visible effect.'), false];
	}
	function viewTechnical() {
		var live = begin('/technical');
		if (!live) { return; }
		loading();
		Promise.all([api('/technical'), api('/redirects')]).then(function (res) {
			var t = res[0], redirects = res[1];
			if (!live()) { return; }
			var body = view(__('Technical access'), __('robots.txt, server responses and verification, as AI crawlers experience them.'), button(__('Check again now'), function () { return api('/technical/refresh', { method: 'POST' }).then(function () { toast(__('Checked.')); viewTechnical(); }); }, { icon: 'refresh' }));
			if (t.site.length) {
				var sc = card(__('Site-wide issues'));
				sc.body.appendChild(h('ol', { 'class': 'rf-recs' }, t.site.map(recItem)));
				body.appendChild(sc);
			}
			var m = card(__('robots.txt by crawler'), { prov: 'observed', sub: sprintf(__('Rules read from %1$s (%2$s). Blocked important pages: out of %3$d checked.'), t.robots.source === 'http' ? __('your live robots.txt') : __('what WordPress would serve'), t.robots.fetched_at ? ago(t.robots.fetched_at) : __('not fetched yet'), t.robots.pages_checked) });
			m.body.appendChild(table([
				{ label: __('Crawler'), render: function (r) { return h('div', { 'class': 'rf-pagecell' }, h('strong', { text: r.name }), h('span', { 'class': 'rf-muted', text: r.provider + ' · ' + catLabel(r.category) })); } },
				{ label: __('Token'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.token }); } },
				{ label: __('Whole site'), render: function (r) { return r.site_allowed ? h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Allowed')) : h('span', { 'class': 'rf-chip ' + (r.category === 'ai_training' ? 'rf-chip-warn' : 'rf-chip-crit') }, icon('ban', 12), __('Blocked')); } },
				{ label: __('Rule'), render: function (r) { return r.rule ? h('span', { 'class': 'rf-mono', text: r.rule + (r.group ? '  (' + r.group + ')' : '') }) : h('span', { 'class': 'rf-muted', text: r.group ? __('no matching rule') : __('no group applies') }); } },
				{ label: __('If blocked'), render: function (r) { var c = consequence(r); return h('span', { 'class': 'rf-consequence' + (c[1] && !r.site_allowed ? ' is-bad' : ''), text: (r.site_allowed ? '' : __('Blocked now: ')) + c[0] }); } },
				{ label: __('Important pages blocked'), num: true, render: function (r) { return r.blocked_pages ? h('span', { 'class': 'rf-crit-text', text: num(r.blocked_pages) }) : '0'; } },
				{ label: __('Obeys robots.txt'), render: function (r) { return r.respects === true ? __('yes') : r.respects === false ? __('no (user-triggered)') : __('unknown'); } }
			], t.matrix.sort(function (a, b) { return (b.priority - a.priority) || a.name.localeCompare(b.name); }), { compact: true }));
			var probe = card(__('How your server answers AI crawlers'), { prov: 'observed', sub: __('Test requests identifying as each crawler, compared with a browser. A refusal points to a firewall, CDN or security plugin. Because the test does not come from the crawler\'s real addresses, a refusal is reported only when the real crawler has not got through recently.') });
			probe.body.appendChild(t.probe.available === false ? empty(__('Your server cannot request its own pages (loopback requests are blocked), so this test is not available.')) : table([
				{ label: __('Crawler'), render: function (r) { return r.bot; } },
				{ label: __('Page'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.url }); } },
				{ label: __('Browser got'), render: function (r) { return statusChip(r.browser_status); } },
				{ label: __('Crawler got'), render: function (r) { return r.refused ? h('span', { 'class': 'rf-chip rf-chip-crit' }, icon('ban', 12), String(r.bot_status || __('no answer'))) : statusChip(r.bot_status); } }
			], t.probe.results || [], { compact: true, empty: __('The test runs once a day; it has not run yet.') }));
			var issues = card(__('Page-level issues'), { sub: __('Open issues across all pages, by type.') });
			issues.body.appendChild(table([{ label: __('Issue'), render: function (r) { return h('a', { href: '#/recommendations?code=' + r.code, text: r.title }); } }, { label: __('Severity'), render: function (r) { return sev(r.severity); } }, { label: __('Pages'), num: true, render: function (r) { return num(r.n); } }], t.page_issues, { compact: true, empty: __('No page-level issues.') }));
			var ver = card(__('Crawler verification data'), { sub: __('Published address lists used to tell real crawlers from impersonators.') });
			ver.body.appendChild(table([
				{ label: __('Address list'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.url }); } },
				{ label: __('Prefixes'), num: true, render: function (r) { return num(r.count || 0); } },
				{ label: __('Updated'), render: function (r) { return r.fetched_at ? ago(r.fetched_at) : h('span', { 'class': 'rf-warn-text', text: __('not yet') }); } },
				{ label: __('From'), render: function (r) { return r.origin === 'rankyfy' ? 'RankyFy' : r.origin === 'publisher' ? __('operator') : '—'; } },
				{ label: __('Last error'), render: function (r) { return r.error || ''; } }
			], t.verification.ranges, { compact: true, empty: __('Address lists are downloaded within the hour.') }));
			var raw = card(__('robots.txt'), { sub: t.robots.sitemaps.length ? sprintf(__('Sitemaps listed: %s'), t.robots.sitemaps.join(', ')) : __('No sitemap listed.') });
			raw.body.appendChild(h('pre', { 'class': 'rf-pre', text: t.robots.body || __('(empty — everything allowed)') }));
			var rd = card(__('Redirects for changed URLs'), { sub: __('Added automatically when a published page\'s address changes, so AI crawlers, assistants and old links still reach it. Used only when the old address would otherwise be "not found".') });
			rd.body.appendChild(table([
				{ label: __('Old address'), render: function (r) { return h('span', { 'class': 'rf-mono', text: r.source }); } },
				{ label: __('Now goes to'), render: function (r) { return r.active ? h('div', { 'class': 'rf-pagecell' }, h('span', { text: r.title }), h('span', { 'class': 'rf-mono rf-muted', text: r.target })) : h('span', { 'class': 'rf-chip rf-chip-warn', title: __('The page is no longer published, so the old address answers "not found".') }, icon('ban', 12), __('Inactive')); } },
				{ label: __('Why'), render: function (r) { return r.reason === 'parent' ? __('parent page moved') : __('address changed'); } },
				{ label: __('Added'), render: function (r) { return fmtDay(r.created_at); } },
				{ label: __('Used'), num: true, render: function (r) { return num(r.hits) + (r.bot_hits ? ' (' + sprintf(__('%s by crawlers'), num(r.bot_hits)) + ')' : ''); } },
				{ label: '', render: function (r) { return button(__('Remove'), function () { return api('/redirects/' + r.id, { method: 'DELETE' }).then(function () { toast(__('Redirect removed.')); viewTechnical(); }); }, { small: true, ghost: true }); } }
			], redirects.items, { compact: true, empty: __('No published address has changed yet.') }));
			add(body, [m, h('div', { 'class': 'rf-grid' }, probe, issues), rd, h('div', { 'class': 'rf-grid' }, ver, raw)]);
		}).catch(function (e) { if (live()) { fail(e, viewTechnical); } });
	}

	// ── AI readiness ─────────────────────────────────────────────────────────
	var GROUP = { access: __('Access'), discovery: __('Discovery'), content: __('Content'), trust: __('Trust') };
	var EFFORT = { quick: __('Quick fix'), medium: __('Some work'), larger: __('Larger project') };
	function checkChip(c) {
		if (c.accepted) { return h('span', { 'class': 'rf-chip', title: __('You accepted this result as intended. It does not count towards the score.') }, icon('check', 12), __('Accepted')); }
		if (c.status === 'pass') { return h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Pass')); }
		if (c.status === 'warn') { return h('span', { 'class': 'rf-chip rf-chip-warn' }, icon('alert', 12), __('Warning')); }
		if (c.status === 'fail') { return h('span', { 'class': 'rf-chip rf-chip-crit' }, icon('xCircle', 12), __('Problem')); }
		return h('span', { 'class': 'rf-chip', title: __('Nothing to judge yet, so this check does not count towards the score.') }, icon('minus', 12), __('Not applicable'));
	}
	var readyState = { sev: '', group: '' };
	var SEVWORD = { critical: __('Critical'), warning: __('Warning'), info: __('Notice') };
	/** Checks the plugin can take the user straight to a fix for; the rest get instructions. */
	var FIXES = {
		llms_txt: [__('Fix — set up llms.txt'), '#/llms'],
		ai_search_access: [__('Fix — open Access Manager'), '#/access'],
		ai_user_access: [__('Fix — open Access Manager'), '#/access'],
		crawler_health: [__('Fix — add redirects'), '#/crawlers?status=4xx'],
		sitemap: [__('How to fix'), null]
	};
	function sevChip(level) {
		var m = { critical: ['xCircle', 'rf-chip-crit'], warning: ['alert', 'rf-chip-warn'], info: ['info', ''] }[level] || ['info', ''];
		return h('span', { 'class': 'rf-chip ' + m[1] }, icon(m[0], 12), SEVWORD[level]);
	}
	/** "How to fix": everything about one issue, and the pages to start with. */
	function issueDrawer(qi, score, accept) {
		var d = drawer(qi.title);
		var pages = qi.pages || [];
		add(d.body, [
			h('div', { 'class': 'rf-row' }, sevChip(qi.severity), h('span', { 'class': 'rf-chip' }, EFFORT[qi.effort] || qi.effort), h('span', { 'class': 'rf-chip' }, GROUP[qi.group])),
			h('section', null, h('h3', { text: __('What we found') }), h('p', { text: qi.evidence })),
			h('section', null, h('h3', { text: __('Why it matters for AI citation') }), h('p', { text: qi.why })),
			h('section', null, h('h3', { text: __('What to do') }), h('p', { text: qi.action })),
			pages.length ? h('section', null, h('h3', { text: sprintf(__('Affected pages (%s)'), num(qi.affected)) }), table([
				{ label: __('Page'), render: function (p) { return pageAnchor(p.title || pagePath(p.path), p.page_id); } },
				{ label: __('Importance'), num: true, render: function (p) { return String(p.importance); } }
			], pages, { compact: true }), qi.affected > pages.length && qi.route ? h('p', null, h('a', { href: qi.route, onclick: function () { d.close(true); }, text: sprintf(__('… and %s more — see the full list'), num(qi.affected - pages.length)) })) : null) : null
		]);
		var fx = FIXES[qi.id];
		add(d.foot, [
			fx && fx[1] ? h('a', { 'class': 'rf-btn rf-btn-primary', href: fx[1], onclick: function () { d.close(true); } }, fx[0]) : (qi.route ? h('a', { 'class': 'rf-btn rf-btn-primary', href: qi.route, onclick: function () { d.close(true); } }, __('Go to the pages')) : null),
			button(__('Accept as intended'), function () { d.close(true); return accept(qi); }, { ghost: true, title: __('For a deliberate choice. It leaves the queue and stops counting towards the score; you can reopen it from View history.') }),
			has(score) ? h('span', { 'class': 'rf-hint' }, sprintf(__('Score %1$d → up to %2$d'), score, Math.min(100, Math.round(score + qi.gain)))) : null
		]);
	}
	function historyDrawer(d0, setStatus) {
		var d = drawer(__('Readiness history'));
		var accepted = d0.checks.filter(function (c) { return c.accepted; });
		add(d.body, [
			d0.trend && d0.trend.length > 1 ? h('section', null, h('h3', { text: __('Score over time') }), line(d0.trend, { label: __('AI readiness'), max: 100 })) : null,
			h('section', null, h('h3', { text: __('Recently fixed') }), d0.resolved.length ? h('ul', { 'class': 'rf-items' }, d0.resolved.map(function (r) { return h('li', null, r.title, h('span', { 'class': 'rf-muted', text: ' · ' + fmtDay(r.at) })); })) : h('p', { 'class': 'rf-hint', text: __('Checks that start passing are listed here.') })),
			h('section', null, h('h3', { text: sprintf(__('Accepted as intended (%d)'), accepted.length) }), accepted.length ? h('ul', { 'class': 'rf-todo' }, accepted.map(function (c) { return h('li', null, h('div', null, h('strong', { text: c.title }), h('span', { text: c.evidence })), button(__('Restore'), function () { d.close(true); return setStatus(c.id, 'open').then(function () { toast(__('Restored to the queue.')); viewReadiness(); }); }, { small: true })); })) : h('p', { 'class': 'rf-hint', text: __('Nothing accepted.') })),
			h('section', null, h('h3', { text: __('All 18 checks') }), table([
				{ label: __('Check'), render: function (c) { return c.title; } },
				{ label: __('Result'), render: checkChip },
				{ label: __('Points'), num: true, render: function (c) { return c.status === 'na' || c.accepted ? '—' : (Math.round(c.weight * c.frac * 10) / 10) + ' / ' + c.weight; } }
			], d0.checks, { compact: true }))
		]);
		add(d.foot, [h('span', { 'class': 'rf-hint', text: __('Esc to close') })]);
	}
	function viewReadiness() {
		var live = begin('/readiness');
		if (!live) { return; }
		loading();
		var setStatus = function (id, status) { return api('/readiness/' + id, { method: 'POST', body: { status: status } }); };
		var accept = function (qi) {
			return setStatus(qi.id, 'accepted').then(function () {
				viewReadiness();
				toast(sprintf(__('"%s" accepted as intended.'), qi.title), 'info', { undo: function () { setStatus(qi.id, 'open').then(viewReadiness).catch(function (e) { toast(e.message, 'error'); }); } });
			});
		};
		api('/readiness').then(function (d) {
			if (!live()) { return; }
			var body = view(__('AI Readiness Score'), sprintf(__('18 checks run against your published content. Last run %s.'), ago(d.computed_at)));
			var n = { critical: 0, warning: 0, info: 0 };
			d.queue.forEach(function (qi) { n[qi.severity]++; });
			var head = h('section', { 'class': 'rf-card' }, h('div', { 'class': 'rf-card-body' }, h('div', { 'class': 'rf-scorehead' },
				scoreRing(d.score, { size: 96 }),
				h('div', { 'class': 'rf-scoremeta' }, scoreStatus(d.score), weekDelta(d.trend, d.score)),
				h('div', { 'class': 'rf-counts' },
					h('div', null, h('span', { text: __('Critical') }), h('b', { text: String(n.critical) })),
					h('div', null, h('span', { text: __('Warnings') }), h('b', { text: String(n.warning) })),
					h('div', null, h('span', { text: __('Notices') }), h('b', { text: String(n.info) })),
					h('div', null, h('span', { text: __('Passing') }), h('b', { text: String(d.counts.pass) }))),
				h('div', { 'class': 'rf-grow rf-row rf-end' }, button(__('Re-run checks'), function () { return api('/readiness/run', { method: 'POST' }).then(function () { toast(__('Checks re-run.')); viewReadiness(); }); })))));
			var shown = d.queue.filter(function (qi) { return (!readyState.sev || qi.severity === readyState.sev) && (!readyState.group || qi.group === readyState.group); });
			var bar = h('div', { 'class': 'rf-filterbar' },
				dropdown([['', __('All severities')], ['critical', __('Critical')], ['warning', __('Warning')], ['info', __('Notice')]], readyState.sev, function (v) { readyState.sev = v; viewReadiness(); }, __('Severity')),
				dropdown([['', __('All areas')], ['access', GROUP.access], ['discovery', GROUP.discovery], ['content', GROUP.content], ['trust', GROUP.trust]], readyState.group, function (v) { readyState.group = v; viewReadiness(); }, __('Area')),
				h('span', { 'class': 'rf-grow rf-hint' }, sprintf(__('%1$d open · %2$d accepted · '), d.queue.length, d.counts.accepted), h('button', { type: 'button', 'class': 'rf-linkbtn', text: __('View history'), onclick: function () { historyDrawer(d, setStatus); } })));
			var issues = card(__('Open issues'), { tools: h('span', { 'class': 'rf-hint', text: __('Ranked by impact on AI citation') }) });
			issues.body.appendChild(d.queue.length ? table([
				{ label: __('Severity'), render: function (qi) { return sevChip(qi.severity); } },
				{ label: __('Issue'), render: function (qi) { return h('div', { 'class': 'rf-issuecell' }, h('button', { type: 'button', 'class': 'rf-linkbtn rf-strong', text: qi.title, onclick: function () { issueDrawer(qi, d.score, accept); } }), h('span', { text: qi.evidence })); } },
				{ label: __('Affects'), render: function (qi) { return qi.of > 1 && qi.affected ? sprintf(_n('%s page', '%s pages', qi.affected), num(qi.affected)) : __('Site-wide'); } },
				{ label: '', render: function (qi) {
					var fx = FIXES[qi.id];
					return h('div', { 'class': 'rf-row rf-end' }, fx && fx[1] ? h('a', { 'class': 'rf-btn rf-btn-primary rf-btn-sm', href: fx[1] }, fx[0]) : button(__('How to fix'), function () { issueDrawer(qi, d.score, accept); }, { small: true }));
				} }
			], shown, { empty: __('Nothing matches these filters.') }) : h('div', { 'class': 'rf-listen' }, icon('checkCircle', 28), h('h4', { text: __('Nothing open — keep it there') }), h('p', { text: __('Every check passes or was accepted as intended. The checks run again every hour and an alert tells you if the score drops.') })));
			add(body, [head, bar, issues]);
		}).catch(function (e) { if (live()) { fail(e, viewReadiness); } });
	}

	// ── Access Manager ───────────────────────────────────────────────────────
	function viewAccess() {
		var live = begin('/access');
		if (!live) { return; }
		loading();
		api('/access').then(function (d) {
			if (!live()) { return; }
			var all = [];
			d.groups.forEach(function (g) { g.bots.forEach(function (b) { all.push(b); }); });
			var staged = {}, saved = {};
			all.forEach(function (b) { saved[b.id] = staged[b.id] = b.choice; });
			var body = view(__('AI Crawler Access'), __('Choose which AI crawlers may read this site. Written to robots.txt.'));
			body.appendChild(h('div', { 'class': 'rf-notice rf-notice-warn' }, icon('alert', 14), h('span', null, h('strong', { text: __('Blocking training is not the same as blocking search.') }), ' ', __('Block GPTBot and OpenAI stops using your pages to train models. Block OAI-SearchBot and you disappear from ChatGPT\'s answers. Most sites want the first and not the second — the Purpose column tells you which is which.'))));
			if (!d.public) { body.appendChild(h('div', { 'class': 'rf-notice rf-notice-crit' }, h('span', { text: __('The whole site asks search engines not to index it (Settings → Reading), so robots.txt already blocks every crawler. Rules here take effect once that is switched off.') }))); }
			var rowsEl = {}, dirtyLbl = h('span', { 'class': 'rf-dirty', hidden: true }), pre = h('pre', { 'class': 'rf-pre' });
			var saveBtn;
			function effective(b) { return b.blocked_elsewhere ? 'blocked' : staged[b.id] === 'block' ? 'blocked' : staged[b.id] === 'allow' ? 'allowed' : 'unreviewed'; }
			function blockText() {
				var lines = ['## BEGIN RankyFy AI rules'];
				all.forEach(function (b) { if (staged[b.id] === 'block') { b.tokens.forEach(function (t) { lines.push('User-agent: ' + t); }); lines.push('Disallow: /'); lines.push(''); } });
				lines.push('## END RankyFy AI rules');
				return lines.length > 2 ? lines.join('\n') : __('(no lines — nothing is blocked by RankyFy)');
			}
			function refresh() {
				all.forEach(function (b) { if (rowsEl[b.id]) { rowsEl[b.id](); } });
				pre.textContent = blockText();
				var n = all.filter(function (b) { return staged[b.id] !== saved[b.id]; }).length;
				dirtyLbl.hidden = !n;
				dirtyLbl.textContent = sprintf(_n('%d unsaved change', '%d unsaved changes', n), n);
				if (saveBtn) { saveBtn.disabled = !n && !d.physical; }
			}
			leaveGuard = function () { return all.some(function (b) { return staged[b.id] !== saved[b.id]; }); };
			function setRule(id, v) { staged[id] = v; refresh(); }
			var presets = card(__('Quick presets'));
			add(presets.body, [h('div', { 'class': 'rf-row' },
				button(__('Allow search, block training'), function () { all.forEach(function (b) { staged[b.id] = b.category === 'ai_training' ? 'block' : 'allow'; }); refresh(); }, { primary: true, small: true }),
				button(__('Allow everything'), function () { all.forEach(function (b) { staged[b.id] = 'allow'; }); refresh(); }, { small: true }),
				button(__('Block everything'), function () { all.forEach(function (b) { staged[b.id] = 'block'; }); refresh(); }, { small: true }),
				h('span', { 'class': 'rf-hint', text: __('Presets set the switches below — nothing is written until you save.') }))]);

			var tbl = h('table', { 'class': 'rf-table rf-access' },
				h('thead', null, h('tr', null, [__('Crawler'), __('Purpose'), __('30d hits'), __('Access'), __('Consequence of blocking')].map(function (t, i) { return h('th', { scope: 'col', 'class': i === 2 ? 'rf-num' : null, text: t }); }))));
			d.groups.forEach(function (g) {
				var tb = h('tbody', null, h('tr', { 'class': 'rf-group' }, h('th', { colspan: 5, scope: 'rowgroup', text: g.operator })));
				g.bots.forEach(function (b) {
					var accCell = h('td'), confirmRow = h('tr', { 'class': 'rf-confirm', hidden: true });
					var cons = h('span', { 'class': 'rf-consequence' + (b.serious ? ' is-bad' : ''), text: b.consequence });
					rowsEl[b.id] = function () {
						clear(accCell);
						var eff = effective(b);
						if (b.blocked_elsewhere) {
							add(accCell, [h('span', { 'class': 'rf-chip rf-chip-crit', title: b.elsewhere_rule }, icon('ban', 12), __('Blocked outside RankyFy')), h('div', { 'class': 'rf-hint', text: b.elsewhere_rule })]);
							return;
						}
						add(accCell, [h('span', { 'class': 'rf-access-ctl' },
							toggleSwitch(eff !== 'blocked', sprintf(__('Allow %s'), b.name), function (on) {
								if (!on && b.serious) { confirmRow.hidden = false; return; } // blocking search: confirm in the row first
								confirmRow.hidden = true;
								setRule(b.id, on ? 'allow' : 'block');
							}, { cls: 'is-' + eff }),
							h('span', { 'class': 'rf-access-lbl is-' + eff, text: { allowed: __('Allowed'), blocked: __('Blocked'), unreviewed: __('Unreviewed') }[eff] }))]);
						// Live blocks only, not staged ones.
						if ((b.blocked_elsewhere || saved[b.id] === 'block') && b.hits > 0) { accCell.appendChild(h('div', { 'class': 'rf-hint', text: __('Still visiting: robots.txt is a request, not enforcement.') })); }
					};
					add(confirmRow, [h('td', { colspan: 5 }, h('div', { 'class': 'rf-notice rf-notice-crit' }, h('span', { text: b.consequence + ' ' + __('Block anyway?') }),
						button(__('Block anyway'), function () { confirmRow.hidden = true; setRule(b.id, 'block'); }, { small: true }),
						button(__('Cancel'), function () { confirmRow.hidden = true; }, { small: true, ghost: true })))]);
					add(tb, [h('tr', null,
						h('td', null, h('span', { 'class': 'rf-botname' }, h('strong', { text: b.name }))),
						h('td', null, h('span', { 'class': 'rf-chip' + (b.category === 'ai_training' ? '' : ' rf-chip-info'), text: b.purpose })),
						h('td', { 'class': 'rf-num', text: num(b.hits) }),
						accCell,
						h('td', null, cons)), confirmRow]);
				});
				tbl.appendChild(tb);
			});
			var crawlers = card(__('By crawler'), { tools: h('span', { 'class': 'rf-hint', text: sprintf(__('%d known bots · grouped by operator'), d.count) }) });
			crawlers.body.appendChild(h('div', { 'class': 'rf-table-wrap' }, tbl));

			var prev = card(__('robots.txt preview'), { sub: d.physical ? __('A robots.txt file on your server answers before WordPress runs, so RankyFy cannot add to it. Copy these lines into that file.') : sprintf(__('%s serves robots.txt — RankyFy appends only these lines.'), d.robots.writer) });
			if (d.physical) {
				saveBtn = button(__('Copy rules'), function () { return (navigator.clipboard ? navigator.clipboard.writeText(blockText()) : Promise.reject(new Error(__('Copying is not available in this browser.')))).then(function () { toast(__('Copied. Paste them at the end of robots.txt on your server.')); }); }, { primary: true });
			} else {
				saveBtn = button(__('Save & write rules'), function () {
					var rules = {};
					all.forEach(function (b) { if (staged[b.id] !== saved[b.id]) { rules[b.id] = staged[b.id] || ''; } });
					return api('/access', { method: 'POST', body: { rules: rules } }).then(function () { leaveGuard = null; toast(__('Saved. robots.txt now has these rules.')); viewAccess(); });
				}, { primary: true });
			}
			add(prev.body, [pre, h('div', { 'class': 'rf-savebar' }, saveBtn, button(__('Discard changes'), function () { all.forEach(function (b) { staged[b.id] = saved[b.id]; }); refresh(); }, { ghost: true }), dirtyLbl, h('span', { 'class': 'rf-grow rf-hint' }, h('a', { href: d.robots.url, target: '_blank', rel: 'noopener', text: __('View live robots.txt') })))]);
			refresh();
			add(body, [presets, crawlers, prev]);
		}).catch(function (e) { if (live()) { fail(e, viewAccess); } });
	}

	// ── llms.txt (and ai.txt) ────────────────────────────────────────────────
	function fileState(f) {
		var p = f.probe;
		if (f.physical) { return h('span', { 'class': 'rf-chip rf-chip-warn', title: __('A file with this name in the site\'s root folder is served before WordPress runs.') }, icon('file', 12), __('File on the server wins')); }
		if (p && p.state === 'ours') { return h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), sprintf(__('%d OK'), p.status)); }
		if (p && p.state === 'other') { return h('span', { 'class': 'rf-chip rf-chip-warn' }, icon('info', 12), __('Served by something else')); }
		if (!f.enabled) { return h('span', { 'class': 'rf-chip' }, icon('minus', 12), __('Off')); }
		if (!p) { return h('span', { 'class': 'rf-chip' }, icon('refresh', 12), __('Not checked yet')); }
		return h('span', { 'class': 'rf-chip rf-chip-crit', title: p.error || '' }, icon('xCircle', 12), p.status ? sprintf(__('Not reachable (HTTP %d)'), p.status) : __('Not reachable'));
	}
	/** Accessible tabs: arrow keys move between them. */
	function tabs(items, current, onPick) {
		var list = h('div', { 'class': 'rf-ctabs', role: 'tablist' });
		items.forEach(function (it, i) {
			list.appendChild(h('button', { type: 'button', role: 'tab', 'class': 'rf-ctab', 'aria-selected': String(it.id === current), tabindex: it.id === current ? '0' : '-1', onclick: function () { onPick(it.id); },
				onkeydown: function (e) {
					var dd = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
					if (!dd) { return; }
					e.preventDefault();
					onPick(items[(i + dd + items.length) % items.length].id, true);
				} }, it.label));
		});
		return list;
	}
	var llmsState = { tab: 'llms.txt' };
	function viewLlms() {
		var live = begin('/llms');
		if (!live) { return; }
		loading();
		api('/ai-files').then(function (d) {
			if (!live()) { return; }
			var st = d.settings, form = {}, files = {};
			d.files.forEach(function (f) { files[f.file] = f; });
			var lf = files['llms.txt'], af = files['ai.txt'];
			var body = view(__('llms.txt'), [__('A structured summary of this site for AI models. Served at '), h('a', { href: lf.url, target: '_blank', rel: 'noopener', text: lf.url.replace(/^https?:\/\//, '') })]);
			if (d.subdir) { body.appendChild(h('div', { 'class': 'rf-notice rf-notice-warn' }, h('span', { text: __('WordPress runs in a subfolder, so these files are served there. Crawlers look for them at the root of the domain — copy them there or ask your host for a rewrite.') }))); }
			if (d.conflicts.length) { body.appendChild(h('div', { 'class': 'rf-notice rf-notice-info' }, h('span', { text: sprintf(__('%s also generates llms.txt. Use one generator: while RankyFy\'s is on, it answers first (a real file on the server still wins).'), d.conflicts.join(', ')) }))); }
			if (lf.enabled && lf.probe && lf.probe.state === 'missing') { body.appendChild(h('div', { 'class': 'rf-notice rf-notice-crit' }, h('span', { text: sprintf(__('A request for /llms.txt got HTTP %d instead of the file. Pretty permalinks may be off, or the server does not pass .txt requests to WordPress.'), lf.probe.status) }), link(__('Permalink settings'), '/settings'))); }

			// Left: the file itself.
			var editing = false;
			var codeBox = h('div');
			var content = card(__('Generated content'), { tools: h('span', { 'class': 'rf-hint', text: lf.built_at ? sprintf(__('Auto-updates on publish · last built %s'), fmtDate(lf.built_at)) : __('Auto-updates on publish') }) });
			function showCode(text, file) {
				clear(codeBox);
				if (!text) { codeBox.appendChild(h('div', { 'class': 'rf-listen' }, icon('file', 26), h('h4', { text: sprintf(__('%s is off'), file) }), h('p', { text: __('Switch it on in Status. You will see the generated file here before anything is public.') }))); return; }
				codeBox.appendChild(h('pre', { 'class': 'rf-pre rf-code', text: text }));
			}
			function download(name, text) { return h('a', { 'class': 'rf-btn', href: URL.createObjectURL(new Blob([text], { type: 'text/plain' })), download: name }, icon('download', 14), __('Download')); }
			var tabBar = null, footer = h('div', { 'class': 'rf-row rf-between' }), right = h('div', { 'class': 'rf-stack' });
			function show(id, focus) {
				llmsState.tab = id;
				var nt = tabs([{ id: 'llms.txt', label: 'llms.txt' }, { id: 'ai.txt', label: 'ai.txt' }, { id: 'about', label: __('What is this?') }], id, show);
				if (tabBar) { tabBar.replaceWith(nt); } tabBar = nt;
				if (focus) { nt.querySelector('[aria-selected="true"]').focus(); }
				clear(footer);
				Array.prototype.forEach.call(right.children, function (c) { c.hidden = c.dataset.tab !== id && c.dataset.tab !== 'both'; });
				content.hidden = id === 'about';
				about.hidden = id !== 'about';
				if (id === 'ai.txt') {
					showCode(af.preview, 'ai.txt');
					add(footer, [h('div', { 'class': 'rf-row' }, download('ai.txt', af.preview)), h('span', { 'class': 'rf-hint', text: num(af.bytes) + ' ' + __('bytes') })]);
					return;
				}
				showCode(lf.preview, 'llms.txt');
				var size = (lf.bytes / 1024).toFixed(1) + ' KB' + (lf.links ? ' · ' + sprintf(_n('%s page included', '%s pages included', lf.links), num(lf.links)) : '');
				if (!lf.preview) { return; }
				add(footer, [h('div', { 'class': 'rf-row' },
					button(__('Rebuild now'), function () { return api('/ai-files/check', { method: 'POST' }).then(function () { toast(__('Rebuilt and checked.')); viewLlms(); }); }, { primary: true }),
					button(d.override ? __('Edit override') : __('Edit manually'), function () {
						if (editing) { return; }
						editing = true;
						var ta = h('textarea', { 'class': 'rf-input rf-code', rows: 18, 'aria-label': __('llms.txt content'), value: lf.preview });
						clear(codeBox);
						add(codeBox, [ta, h('p', { 'class': 'rf-hint', text: __('Your version replaces the generated file and survives every rebuild until you revert.') }), h('div', { 'class': 'rf-row' },
							button(__('Save override'), function () { return api('/llms/override', { method: 'POST', body: { text: ta.value } }).then(function () { toast(__('Saved. Your version is served now.')); viewLlms(); }); }, { primary: true }),
							button(__('Cancel'), function () { editing = false; showCode(lf.preview, 'llms.txt'); }, { ghost: true }))]);
						ta.focus();
					}),
					d.override ? button(__('Revert to generated'), function () { return api('/llms/override', { method: 'POST', body: { text: '' } }).then(function () { toast(__('Back to the generated file.')); viewLlms(); }); }, { ghost: true }) : null,
					download('llms.txt', lf.preview)),
					h('span', { 'class': 'rf-hint', text: size })]);
			}
			// Right: what goes in (staged), and status.
			function sw(key, label, hint) {
				var cur = !!st[key], b = toggleSwitch(cur, label, function (on) { cur = on; b.setAttribute('aria-checked', String(on)); b.dispatchEvent(new Event('change', { bubbles: true })); });
				form[key] = function () { return cur; };
				return h('div', { 'class': 'rf-swrow' }, h('span', null, h('span', { text: label }), hint ? h('span', { 'class': 'rf-hint', text: hint }) : null), b);
			}
			function numField(key, min, max) { var el = h('input', { type: 'number', min: min, max: max, 'class': 'rf-input rf-num-in', value: st[key] }); form[key] = function () { return Number(el.value); }; return el; }
			var chosen = (st.llms_types || '').split(',').filter(Boolean);
			var typeState = {};
			var typeRows = d.types.map(function (t) {
				typeState[t.id] = chosen.length ? chosen.indexOf(t.id) >= 0 : t.kind === 'post_type';
				var b = toggleSwitch(typeState[t.id], t.label, function (on) { typeState[t.id] = on; b.setAttribute('aria-checked', String(on)); b.dispatchEvent(new Event('change', { bubbles: true })); });
				return h('div', { 'class': 'rf-swrow' }, h('span', { text: t.label + (t.kind === 'taxonomy' ? ' ' + __('(archives)') : '') }), b);
			});
			// All post types and no archives is the default: store it as "" so new post types are included automatically.
			form.llms_types = function () {
				var on = d.types.filter(function (t) { return typeState[t.id]; });
				var isDefault = d.types.every(function (t) { return typeState[t.id] === (t.kind === 'post_type'); });
				return isDefault ? '' : on.map(function (t) { return t.id; }).join(',');
			};
			var summary = h('input', { type: 'text', 'class': 'rf-input', value: st.llms_summary, placeholder: d.tagline || __('What the site is about, in one sentence') });
			form.llms_summary = function () { return summary.value; };
			var notes = h('textarea', { 'class': 'rf-input', rows: 3, value: st.llms_intro, placeholder: __('For example: prices include VAT; we ship within the EU only.') });
			form.llms_intro = function () { return notes.value; };
			var inc = card(__('What\'s included'));
			add(inc.body, [h('div', { 'class': 'rf-swlist' }, typeRows),
				h('h4', { 'class': 'rf-minihead', text: __('Selection') }),
				h('div', { 'class': 'rf-inline' }, __('Top'), ' ', numField('llms_max_links', 10, 500), ' ', __('pages by AI crawler interest')),
				h('div', { 'class': 'rf-inline' }, __('Minimum'), ' ', numField('llms_min_words', 0, 5000), ' ', __('words')),
				h('div', { 'class': 'rf-swrow' }, h('span', { text: __('Exclude noindex pages') }), h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Always'))),
				sw('llms_full', __('Also serve llms-full.txt'), __('The text of your top pages in one file.')),
				h('h4', { 'class': 'rf-minihead', text: __('Header') }),
				h('label', { 'class': 'rf-field' }, h('span', { text: __('One-line summary') }), summary),
				h('label', { 'class': 'rf-field' }, h('span', { text: __('Notes for AI assistants (optional)') }), notes),
				h('p', { 'class': 'rf-hint', text: __('Grouped by content type, the pages AI crawlers read most first. Descriptions come from each page\'s meta description, falling back to its excerpt.') })]);
			inc.dataset.tab = 'llms.txt';
			var policy = h('select', { 'class': 'rf-input' }, [['allow', __('Allow training on all content')], ['no_media', __('Allow text, not images, audio or video')], ['no_training', __('Do not use any content for training')]].map(function (o) { return h('option', { value: o[0], text: o[1], selected: st.ai_txt_policy === o[0] }); }));
			form.ai_txt_policy = function () { return policy.value; };
			var aiCard = card(__('Training policy'));
			add(aiCard.body, [h('label', { 'class': 'rf-field' }, h('span', { text: __('ai.txt says') }), policy), h('p', { 'class': 'rf-hint', text: __('Advisory. Which crawlers may read the site at all is set in Access Manager (robots.txt).') })]);
			aiCard.dataset.tab = 'ai.txt';
			var fx = lf.fetches, status = card(__('Status'));
			add(status.body, [h('dl', { 'class': 'rf-facts' },
				h('dt', { text: __('Served') }), h('dd', null, h('div', { 'class': 'rf-row' }, sw('llms_enabled', __('Serve /llms.txt')).lastChild, fileState(lf))),
				h('dt', { text: __('Override') }), h('dd', null, d.override ? h('span', { 'class': 'rf-chip rf-chip-warn' }, icon('alert', 12), __('Overridden — rebuilds disabled')) : h('span', { 'class': 'rf-muted', text: __('None — fully generated') })),
				h('dt', { text: 'ai.txt' }), h('dd', null, h('div', { 'class': 'rf-row' }, sw('ai_txt_enabled', __('Serve /ai.txt')).lastChild, fileState(af))),
				h('dt', { text: __('Fetched by') }), h('dd', { text: fx.hits ? fx.bots.map(function (b) { return b.name; }).join(', ') + ' — ' + sprintf(__('%s× in 30d'), num(fx.hits)) : __('No AI crawler has fetched it yet') }))]);
			status.dataset.tab = 'both';
			var dirty = h('span', { 'class': 'rf-dirty', hidden: true });
			var save = button(__('Save changes'), function () {
				var out = {};
				Object.keys(form).forEach(function (k) { out[k] = form[k](); });
				return api('/settings', { method: 'POST', body: out }).then(function () { return api('/ai-files/check', { method: 'POST' }); }).then(function () { leaveGuard = null; toast(__('Saved. The files were rebuilt and checked.')); viewLlms(); });
			}, { primary: true });
			var bar = h('div', { 'class': 'rf-savebar', 'data-tab': 'both' }, save, button(__('Discard'), function () { leaveGuard = null; viewLlms(); }, { ghost: true }), dirty);
			add(right, [inc, aiCard, status, bar]);
			var about = card(__('What is this?'));
			add(about.body, [
				h('p', { text: __('llms.txt is a short Markdown file at the root of a site that tells AI assistants and agents what the site is and which pages matter most, with a link and a one-line description for each. It is a proposed convention (llmstxt.org): cheap to provide, and some AI tools read it.') }),
				h('p', { text: __('llms-full.txt puts the text of your main pages in one file, for tools that read everything at once.') }),
				h('p', { text: __('ai.txt (from Spawning) states whether your content may be used to train AI models, by file type. It is a request, not a block: crawlers follow robots.txt, which you manage in Access Manager.') }),
				h('p', { 'class': 'rf-hint', text: __('Everything here is built from your published pages and rebuilt when they change. A real file with the same name in your site\'s root folder always wins; this screen tells you when that happens.') })]);
			add(content.body, [codeBox, footer]);
			show(llmsState.tab);
			var left = h('div', { 'class': 'rf-stack' }, tabBar, content, about);
			add(body, [h('div', { 'class': 'rf-dash' }, left, right)]);
			staged(form, right, save, dirty);
		}).catch(function (e) { if (live()) { fail(e, viewLlms); } });
	}

	// ── History ──────────────────────────────────────────────────────────────
	var histState = { days: 90, group: 'day' };
	function viewHistory() {
		var live = begin('/history');
		if (!live) { return; }
		loading();
		api('/history?days=' + histState.days + '&group=' + histState.group).then(function (d) {
			if (!live()) { return; }
			var tools = h('div', { 'class': 'rf-row' },
				segmented([[30, __('30 days')], [90, __('90 days')], [180, __('6 months')], [365, __('1 year')]], histState.days, function (v) { histState.days = Number(v); histState.group = v > 120 ? 'week' : 'day'; viewHistory(); }, __('Range')),
				segmented([['day', __('Daily')], ['week', __('Weekly')], ['month', __('Monthly')]], d.group, function (v) { histState.group = v; viewHistory(); }, __('Group by')));
			var body = view(__('History'), __('How AI crawling, coverage and readiness change over time.'), tools);
			var fmtP = function (r) { return d.group === 'day' ? fmtDay(r.period) : r.period; };
			var act = card(__('AI crawler activity'), { prov: 'observed' });
			act.body.appendChild(d.activity.length ? stacked(d.activity, SERIES, { fmtX: fmtP, xLabel: __('Period'), label: __('AI crawler activity') }) : empty(__('No activity recorded yet.')));
			var snaps = d.snapshots;
			var score = card(__('AI search readiness over time'));
			score.body.appendChild(line(snaps.filter(function (x) { return has(x.readiness) || has(x.aeo_score); }).map(function (x) { return { day: x.day, value: has(x.readiness) ? x.readiness : x.aeo_score }; }), { label: __('Score'), max: 100 }));
			var cov = card(__('Important pages crawled (30-day window)'), { prov: 'observed' });
			cov.body.appendChild(line(snaps.filter(function (x) { return has(x.coverage); }).map(function (x) { return { day: x.day, value: x.coverage }; }), { label: __('Coverage'), max: 100, fmt: function (v) { return Math.round(v) + '%'; } }));
			var bots = card(__('Growth by crawler'), { prov: 'observed' });
			bots.body.appendChild(table([{ label: __('Crawler'), render: function (b) { return link(b.name, '/crawlers/' + b.id); } }, { label: __('Requests in range'), num: true, render: function (b) { return num(b.total); } }, { label: __('Trend'), render: function (b) { return spark(Object.keys(b.series).sort().map(function (k) { return b.series[k]; })); } }], d.bots, { compact: true, empty: __('No AI crawler activity yet.') }));
			var newb = card(__('Newly detected crawlers'), { prov: 'observed' });
			newb.body.appendChild(table([{ label: __('Crawler'), render: function (b) { return link(b.name, '/crawlers/' + b.id); } }, { label: __('First visit'), render: function (b) { return fmtDate(b.first_seen); } }], d.new_bots, { compact: true, empty: __('No new crawlers in this period.') }));
			var fixed = card(__('Recently fixed issues'));
			fixed.body.appendChild(d.resolved_recent.length ? h('ul', { 'class': 'rf-items' }, d.resolved_recent.map(function (f) { return h('li', null, icon('checkCircle', 12), ' ', f.title, ' ', f.page_id ? link(f.page_title || f.path, '/pages/' + f.page_id) : h('span', { 'class': 'rf-muted', text: __('site-wide') }), h('span', { 'class': 'rf-muted', text: ' · ' + fmtDay(f.resolved_at) })); })) : empty(__('No issues fixed yet in this period.')));
			add(body, [act, h('div', { 'class': 'rf-grid' }, score, cov), h('div', { 'class': 'rf-grid' }, bots, h('div', { 'class': 'rf-stack' }, newb, fixed))]);
		}).catch(function (e) { if (live()) { fail(e, viewHistory); } });
	}

	// ── Alerts ───────────────────────────────────────────────────────────────
	function viewAlerts() {
		var live = begin('/alerts');
		if (!live) { return; }
		loading();
		api('/alerts').then(function (d) {
			if (!live()) { return; }
			var body = view(__('Alerts'), __('What happened, why it matters and what to do.'), d.unread ? button(__('Mark all as read'), function () { return api('/alerts/all', { method: 'POST', body: { status: 'read' } }).then(function () { refreshStatus(); viewAlerts(); }); }, { icon: 'check' }) : null);
			if (!d.items.length) { body.appendChild(empty(__('No alerts yet. You will be notified when a new AI crawler appears, an important page is crawled for the first time or gets blocked, activity changes sharply, and more.'))); return; }
			body.appendChild(h('ol', { 'class': 'rf-alerts' }, d.items.map(function (a) {
				var mark = function (st, stay) { return api('/alerts/' + a.id, { method: 'POST', body: { status: st } }).then(function () { refreshStatus(); if (stay !== false) { viewAlerts(); } }); };
				return h('li', { 'class': 'rf-alert rf-alert-' + a.severity + (a.status === 'unread' ? ' is-unread' : '') },
					h('div', { 'class': 'rf-rec-head' }, sev(a.severity), h('strong', { text: a.title }), h('span', { 'class': 'rf-muted', text: fmtDate(a.created_at) })),
					h('p', null, h('span', { 'class': 'rf-lbl', text: __('What happened') }), a.what),
					h('p', null, h('span', { 'class': 'rf-lbl', text: __('Why it matters') }), a.why),
					a.affected.length ? h('div', null, h('span', { 'class': 'rf-lbl', text: __('Affected') }), h('ul', { 'class': 'rf-items' }, a.affected.map(function (p) { return h('li', null, p.page_id ? link(p.label, '/pages/' + p.page_id) : p.label, p.detail ? h('span', { 'class': 'rf-muted', text: ' — ' + p.detail }) : null); }))) : null,
					h('p', null, h('span', { 'class': 'rf-lbl', text: __('What to do') }), a.action),
					h('div', { 'class': 'rf-row' }, a.route ? h('a', { 'class': 'rf-btn rf-btn-sm', href: a.route, onclick: function () { if (a.status === 'unread') { mark('read', false); } } }, __('Open')) : null,
						a.status === 'unread' ? button(__('Mark as read'), function () { return mark('read'); }, { small: true, ghost: true }) : null,
						button(__('Dismiss'), function () { var was = a.status; return mark('dismissed').then(function () { toast(__('Alert dismissed.'), 'info', { undo: function () { mark(was === 'unread' ? 'unread' : 'read'); } }); }); }, { small: true, ghost: true })));
			})));
		}).catch(function (e) { if (live()) { fail(e, viewAlerts); } });
	}

	// ── Settings ─────────────────────────────────────────────────────────────
	function bytes(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
	function viewSettings() {
		var live = begin('/settings');
		if (!live) { return; }
		loading();
		Promise.all([api('/settings'), api('/status'), api('/log'), api('/compat')]).then(function (res) {
			if (!live()) { return; }
			var st = res[0].settings, reg = res[0].registry, status = res[1], log = res[2].items, compat = res[3];
			var body = view(__('Settings'));
			var form = {};
			function toggle(key, label, hint) { var el = h('input', { type: 'checkbox', checked: !!st[key] }); form[key] = function () { return el.checked; }; return h('label', { 'class': 'rf-check' }, el, h('span', null, h('strong', { text: label }), hint ? h('span', { 'class': 'rf-hint', text: hint }) : null)); }
			function field(key, label, input, hint) { form[key] = function () { return input.type === 'number' ? Number(input.value) : input.value; }; return h('label', { 'class': 'rf-field' }, h('strong', { text: label }), input, hint ? h('span', { 'class': 'rf-hint', text: hint }) : null); }
			function select(key, label, opts, hint) { return field(key, label, h('select', { 'class': 'rf-input' }, opts.map(function (o) { return h('option', { value: o[0], text: o[1], selected: String(st[key]) === String(o[0]) }); })), hint); }
			function row(label, value, extra) { return h('tr', null, h('th', { scope: 'row', text: label }), h('td', null, value), h('td', { 'class': 'rf-num' }, extra || null)); }

			// General
			var prio = (st.priority_bots || '').split(',');
			var prioBoxes = reg.filter(function (b) { return b.ai || b.category === 'search'; }).map(function (b) { var el = h('input', { type: 'checkbox', value: b.id, checked: prio.indexOf(b.id) >= 0 }); return h('label', { 'class': 'rf-check rf-check-sm' }, el, h('span', { text: b.name })); });
			form.priority_bots = function () { return prioBoxes.map(function (l) { return l.querySelector('input'); }).filter(function (i) { return i.checked; }).map(function (i) { return i.value; }).join(','); };
			var notify = card(__('Notifications'));
			add(notify.body, [
				select('notify_mode', __('Email me'), [['critical', __('Immediately, for critical alerts only (default)')], ['all', __('Immediately, for every alert')], ['digest', __('One daily digest')], ['off', __('Never (alerts stay on the Alerts screen)')]]),
				field('notify_email', __('Email address'), h('input', { type: 'email', 'class': 'rf-input', value: st.notify_email })),
				field('webhook_url', __('Webhook URL (Slack, Teams, Google Chat or any HTTPS endpoint)'), h('input', { type: 'url', 'class': 'rf-input', value: st.webhook_url, placeholder: 'https://hooks.slack.com/services/…' })),
				select('webhook_level', __('Send to the webhook'), [['critical', __('Critical alerts')], ['warning', __('Warnings and critical alerts')], ['info', __('All alerts')]]),
				h('div', { 'class': 'rf-row' }, button(__('Send a test notification'), function () { return saveAndReport().then(function () { return api('/alerts/test', { method: 'POST' }); }).then(function (r) { toast(r.webhook && r.webhook !== true ? r.webhook : __('Test sent.'), r.webhook && r.webhook !== true ? 'error' : 'info'); }); }, { small: true, icon: 'bell' }),
					link(status.unread_alerts ? sprintf(__('Alerts (%d unread)'), status.unread_alerts) : __('All alerts'), '/alerts'))
			]);
			var guard = card(__('Publish check'), { sub: __('Checks pages in the editor before they go live: noindex, robots.txt blocks, canonical URL, address changes and thin content.') });
			add(guard.body, [
				select('guard_mode', __('When something is wrong'), [['warn', __('Warn in the editor (default)')], ['confirm', __('Ask for confirmation before publishing')], ['off', __('Do not check')]]),
				toggle('guard_redirects', __('Redirect old addresses when a published page\'s address changes'), __('A permanent (301) redirect, used only when the old address would otherwise be "not found".')),
				field('guard_thin_words', __('Warn below this many words'), h('input', { type: 'number', min: 50, max: 3000, 'class': 'rf-input', value: st.guard_thin_words }), __('Products are held to 40% of this.'))
			]);
			var important = card(__('Important pages and priority crawlers'));
			add(important.body, [
				field('importance_min', __('A page is important from score'), h('input', { type: 'number', min: 1, max: 100, 'class': 'rf-input', value: st.importance_min }), __('Importance comes from your menus, home and shop pages, cornerstone flags, internal links, sales and visits from AI assistants.')),
				h('strong', { text: __('Priority crawlers') }), h('p', { 'class': 'rf-hint', text: __('Their access is checked on every important page and counts towards the score.') }), h('div', { 'class': 'rf-checks' }, prioBoxes)
			]);
			var rk = card(__('RankyFy account'));
			add(rk.body, [
				res[0].rankyfy === 'connected'
					? h('p', { text: __('Connected through the RankyFy SEO plugin. AI analysis is available.') })
					: h('p', { text: __('Not connected. Crawler monitoring, readiness, llms.txt, opportunities and access rules all work without an account. Connecting adds AI analysis of your pages.') }),
				toggle('ai_auto', __('Analyse my most important pages with AI automatically'), __('Up to 5 pages a day that have no recent analysis. Uses Content AI credits.'))
			]);
			var general = h('div', { 'class': 'rf-stack' }, h('div', { 'class': 'rf-grid' }, notify, h('div', { 'class': 'rf-stack' }, guard, rk)), important, h('p', { 'class': 'rf-hint' }, __('Also: '), link(__('History'), '/history'), ' · ', link(__('All recommendations'), '/recommendations'), ' · ', link(__('Opportunities'), '/opportunities')));

			// Capture
			var t = status.tables || {}, raw = (t.events || {}).bytes || 0, rollups = 0;
			Object.keys(t).forEach(function (k) { if (k !== 'events') { rollups += t[k].bytes || 0; } });
			var days = Math.max(1, (Date.now() / 1000 - status.monitoring_since) / 86400);
			var projected = raw + rollups * Math.max(1, 365 / days);
			var importBox = h('div', { hidden: true }, importer());
			var paths = h('table', { 'class': 'rf-table rf-table-compact' }, h('thead', null, h('tr', null, [__('Path'), __('Covers'), __('Status')].map(function (x) { return h('th', { scope: 'col', text: x }); }))), h('tbody', null,
				h('tr', null, h('td', null, h('strong', { text: __('WordPress hook') }), h('div', { 'class': 'rf-hint', text: __('Always on') })), h('td', { text: __('Every request that reaches WordPress. Adds about a microsecond and no queries for normal visitors.') }), h('td', null, st.tracking ? h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Active')) : h('span', { 'class': 'rf-chip rf-chip-crit' }, __('Off')))),
				h('tr', null, h('td', null, h('strong', { text: __('Access log') }), h('div', { 'class': 'rf-hint', text: __('Backfill') })), h('td', { text: __('Requests a page cache or CDN answered before WordPress ran. Read in your browser; only crawler lines are sent.') }), h('td', null, button(__('Import a log'), function () { importBox.hidden = !importBox.hidden; }, { small: true })))));
			var cap = card(__('Capture paths'), { tools: h('span', { 'class': 'rf-hint', text: __('Ways a crawler request is caught') }) });
			add(cap.body, [h('div', { 'class': 'rf-table-wrap' }, paths), importBox,
				toggle('tracking', __('Record crawler visits'), __('Ordinary visitors are never recorded; for them the plugin performs one quick text check and nothing else.')),
				toggle('track_search_pages', __('Record search engines page by page'), __('Off: Googlebot, Bingbot and others are only counted per day.')),
				toggle('track_unknown', __('Remember unrecognised bots'), __('Needed to discover new AI crawlers.')),
				toggle('track_referrals', __('Count visits from AI assistants'), __('Only a daily count per page and assistant — no visitor data.')),
				field('exclude_paths', __('Paths not to monitor (one per line)'), h('textarea', { 'class': 'rf-input', rows: 3, value: st.exclude_paths }))]);
			var storage = card(__('Storage'));
			add(storage.body, [h('table', { 'class': 'rf-table rf-formtable' }, h('tbody', null,
				row(__('Keep individual requests for'), h('span', { 'class': 'rf-inline' }, (function () { var el = h('input', { type: 'number', min: 1, max: 365, 'class': 'rf-input rf-num-in', value: st.retention_events }); form.retention_events = function () { return Number(el.value); }; return el; })(), ' ', __('days')), h('span', { 'class': 'rf-hint', text: __('Daily totals are what every chart reads.') })),
				row(__('Keep daily history for'), h('span', { 'class': 'rf-inline' }, (function () { var el = h('input', { type: 'number', min: 30, max: 1825, 'class': 'rf-input rf-num-in', value: st.retention_history }); form.retention_history = function () { return Number(el.value); }; return el; })(), ' ', __('days'))),
				row(__('Keep crawl sessions for'), h('span', { 'class': 'rf-inline' }, (function () { var el = h('input', { type: 'number', min: 7, max: 1825, 'class': 'rf-input rf-num-in', value: st.retention_sessions }); form.retention_sessions = function () { return Number(el.value); }; return el; })(), ' ', __('days'))),
				row(__('Current size'), sprintf(__('Requests %1$s · daily totals and analysis %2$s'), bytes(raw), bytes(rollups)), button(__('Prune now'), function () { return api('/prune', { method: 'POST' }).then(function () { toast(__('Old data removed.')); viewSettings(); }); }, { small: true, ghost: true })),
				row(__('Projected in 12 months'), sprintf(__('About %s at your crawl rate (estimate)'), bytes(projected)))))]);
			var verify = card(__('Verification'));
			add(verify.body, [
				toggle('verify_rdns', __('Confirm bot identity by reverse DNS'), __('For Googlebot, Bingbot, Applebot, Amazonbot and others. Runs in the background.')),
				toggle('verify_signatures', __('Verify signed requests (Web Bot Auth)')),
				toggle('fetch_ranges_direct', __('Download published IP ranges from operators when RankyFy is unreachable')),
				toggle('probe', __('Test daily how the server answers AI crawlers')),
				select('proxy_header', __('Visitor address header'), [['', __('None — use the connection address (default)')], ['cf-connecting-ip', 'CF-Connecting-IP (Cloudflare)'], ['x-forwarded-for', 'X-Forwarded-For'], ['x-real-ip', 'X-Real-IP']], __('Only behind a proxy or CDN, and only together with trusted proxies below.')),
				field('trusted_proxies', __('Trusted proxy addresses (CIDR, one per line)'), h('textarea', { 'class': 'rf-input', rows: 2, value: st.trusted_proxies })),
				link(__('Server test results and verification data →'), '/technical')]);
			var capture = h('div', { 'class': 'rf-stack' },
				compat.caches.length ? h('div', { 'class': 'rf-notice rf-notice-warn' }, icon('alert', 14), h('span', null, h('strong', { text: sprintf(__('Caching detected: %s.'), compat.caches.join(' + ')) }), ' ', __('Cached pages are answered before PHP runs, so those crawler requests never reach the WordPress hook. Import your access log regularly to fill the gap.'))) : null,
				cap, h('div', { 'class': 'rf-grid' }, storage, verify));

			// Compatibility
			var compatCard = card(__('Who handles what'), { sub: compat.seo.length ? sprintf(__('Found: %s. RankyFy leaves their outputs alone and adds only what is missing.'), compat.seo.join(', ')) : __('No SEO plugin found. RankyFy handles its own outputs; a general SEO plugin is still recommended for titles, sitemaps and schema.') });
			compatCard.body.appendChild(table([
				{ label: __('Output'), render: function (r) { return r.output; } },
				{ label: __('Handled by'), render: function (r) { return r.ours ? h('span', { 'class': 'rf-chip rf-chip-good', text: r.owner }) : r.owner === __('Not found') ? h('span', { 'class': 'rf-chip rf-chip-warn' }, icon('alert', 12), r.owner) : r.owner; } },
				{ label: __('RankyFy'), render: function (r) { return h('span', { 'class': r.ours ? '' : 'rf-muted', text: r.note }); } }
			], compat.table, { compact: true }));
			var compatTab = h('div', { 'class': 'rf-stack' }, compat.warning ? h('div', { 'class': 'rf-notice rf-notice-warn' }, h('span', { text: sprintf(__('Two SEO plugins are active (%s). Both write titles, schema and sitemaps; deactivate one.'), compat.seo.join(', ')) })) : null, compatCard);

			// Data & privacy
			var ext = [__('The crawler list and published crawler addresses, from RankyFy (no site data sent)')];
			if (st.fetch_ranges_direct) { ext.push(__('Published crawler addresses, from the operators when RankyFy is unreachable')); }
			if (st.share_unknown) { ext.push(__('The user-agent text of unrecognised bots, to RankyFy (nothing else)')); }
			if (res[0].rankyfy === 'connected') { ext.push(__('A page\'s text, only when you ask for its AI analysis')); }
			var privacy = h('table', { 'class': 'rf-table rf-formtable' }, h('tbody', null,
				row(__('Crawler addresses'), (function () { var el = h('select', { 'class': 'rf-input' }, [['network', __('Network only (/24, /48) — default')], ['none', __('Nothing')]].map(function (o) { return h('option', { value: o[0], text: o[1], selected: st.ip_storage === o[0] }); })); form.ip_storage = function () { return el.value; }; return h('div', null, el, h('p', { 'class': 'rf-hint', text: __('Full addresses are kept only until verification finishes, then discarded. Ordinary visitors are never recorded.') })); })()),
				row(__('External requests'), h('div', null, h('ul', { 'class': 'rf-items' }, ext.map(function (x) { return h('li', { text: x }); })), toggle('share_unknown', __('Help identify new AI crawlers'), __('Sends only the user-agent text of unrecognised bots.')))),
				row(__('Export'), h('div', { 'class': 'rf-row' }, ['events', 'pages', 'bots', 'referrals'].map(function (x) { return h('a', { 'class': 'rf-btn rf-btn-sm', href: cfg.exportUrl + '&type=' + x + '&days=' + state.days }, icon('download', 14), { events: __('Crawler requests'), pages: __('Pages'), bots: __('Crawlers'), referrals: __('AI referrals') }[x]); }))),
				row(__('On uninstall'), __('Deleting the plugin removes every table and setting it created. Deactivating keeps everything.'))));
			var pcard = card(__('Data & privacy'), { tools: h('span', { 'class': 'rf-hint', text: __('Mirrors the plugin\'s readme') }) });
			pcard.body.appendChild(privacy);
			var sys = card(__('Status'));
			sys.body.appendChild(h('dl', { 'class': 'rf-facts' },
				h('dt', { text: __('Monitoring since') }), h('dd', { text: fmtDate(status.monitoring_since) }),
				h('dt', { text: __('Background work') }), h('dd', { text: status.worker.age === null ? __('not run yet') : sprintf(__('last run %s'), ago(Math.floor(Date.now() / 1000) - status.worker.age)) }),
				h('dt', { text: __('Waiting to be processed') }), h('dd', { text: sprintf(__('%1$s requests · %2$s verifications · %3$s pages to analyse'), num(status.worker.backlog), num(status.worker.pending_verification), num(status.inventory.dirty)) }),
				h('dt', { text: __('Crawler registry') }), h('dd', { text: sprintf(__('%1$s (%2$d crawlers, %3$s)'), status.registry.version, status.registry.bots, status.registry.source === 'rankyfy' ? __('live from RankyFy') : __('built in')) })));
			var lg = card(__('Diagnostics log'));
			lg.body.appendChild(table([{ label: __('Time'), render: function (e) { return fmtDate(e.t); } }, { label: __('Level'), key: 'l' }, { label: __('Message'), key: 'm' }], log.slice(0, 20), { compact: true, empty: __('Nothing logged.') }));
			var data = h('div', { 'class': 'rf-stack' }, pcard, h('div', { 'class': 'rf-grid' }, sys, lg));

			var panels = { general: general, capture: capture, compat: compatTab, privacy: data };
			var cur = panels[q('tab')] ? q('tab') : 'general';
			var tabBar = null;
			var pick = function (id, focus) {
				cur = id;
				var nt = tabs([{ id: 'general', label: __('General') }, { id: 'capture', label: __('Capture') }, { id: 'compat', label: __('Compatibility') }, { id: 'privacy', label: __('Data & privacy') }], id, pick);
				if (tabBar) { tabBar.replaceWith(nt); } tabBar = nt;
				if (focus) { nt.querySelector('[aria-selected="true"]').focus(); }
				Object.keys(panels).forEach(function (k) { panels[k].hidden = k !== id; });
				history.replaceState(null, '', '#/settings' + (id === 'general' ? '' : '?tab=' + id));
			};
			pick(cur);
			function saveAndReport() {
				var sent = {};
				Object.keys(form).forEach(function (k) { sent[k] = form[k](); });
				return api('/settings', { method: 'POST', body: sent }).then(function (r) {
					var got = (r && r.settings) || {};
					var refused = ['webhook_url', 'notify_email'].filter(function (k) { return String(sent[k] || '') !== String(got[k] || '') && String(sent[k] || '') !== ''; });
					toast(refused.length ? sprintf(__('Saved, except: %s (not a valid value — the previous one is kept). Webhooks must use https on a public host.'), refused.join(', ')) : __('Settings saved.'), refused.length ? 'error' : 'info');
					refreshStatus();
					return r;
				});
			}
			var dirty = h('span', { 'class': 'rf-dirty', hidden: true });
			var saveBtn = button(__('Save changes'), function () { return saveAndReport().then(function () { setTimeout(stg.reset, 0); }); }, { primary: true });
			var bar = h('div', { 'class': 'rf-savebar' }, saveBtn, button(__('Discard'), function () { leaveGuard = null; viewSettings(); }, { ghost: true }), dirty);
			var wrap = h('div', { 'class': 'rf-stack' }, tabBar, general, capture, compatTab, data, bar);
			body.appendChild(wrap);
			var stg = staged(form, wrap, saveBtn, dirty);
		}).catch(function (e) { if (live()) { fail(e, viewSettings); } });
	}

	function importer() {
		var input = h('input', { type: 'file', accept: '.log,.txt,.gz,.json,.jsonl,text/plain', 'class': 'rf-input' });
		var prog = h('progress', { max: 100, value: 0, hidden: true, 'class': 'rf-progress' });
		var out = h('p', { 'class': 'rf-hint', 'aria-live': 'polite' });
		var go = button(__('Import'), function () {
			var file = input.files && input.files[0];
			if (!file) { toast(__('Choose a log file first.'), 'error'); return; }
			if (!window.Worker) { toast(__('This browser cannot read files in the background.'), 'error'); return; }
			return api('/registry').then(function (reg) { return runImport(file, reg, prog, out); });
		}, { icon: 'upload', primary: true });
		return h('div', { 'class': 'rf-form' }, input, h('p', { 'class': 'rf-hint', text: __('Supported: Apache/Nginx combined format, and JSON lines (Cloudflare, Fastly and similar). Gzip files are fine.') }), go, prog, out);
	}
	function runImport(file, reg, prog, out) {
		return new Promise(function (resolve, reject) {
			var worker = new Worker(cfg.workerUrl);
			var queue = [], sending = false, done = false, batchNo = 0, noUa = 0, totals = { lines: 0, kept: 0, events: 0, duplicates: 0, invalid: 0, skipped: 0 };
			// Same file (name, size, date) → same batch keys → a re-import is skipped by the server.
			var fp = Array.prototype.map.call(file.name + '|' + file.size + '|' + file.lastModified, function (c) { return c.charCodeAt(0); }).reduce(function (h, c) { return ((h * 31) + c) >>> 0; }, 2166136261).toString(16);
			fp = (fp + '0000000000000000').slice(0, 16);
			prog.hidden = false;
			function report() { out.textContent = sprintf(__('%1$s lines read · %2$s crawler lines · %3$s new AI crawler requests recorded · %4$s already known'), num(totals.lines), num(totals.kept), num(totals.events), num(totals.duplicates)) + (totals.skipped ? ' · ' + sprintf(__('%s search/SEO bot lines not imported (only AI crawler requests are imported)'), num(totals.skipped)) : ''); }
			function pump() {
				if (sending) { return; }
				if (!queue.length) { if (done) { worker.terminate(); toast(__('Import finished. History updates within a few minutes.')); resolve(); } return; }
				sending = true;
				var item = queue.shift();
				api('/import', { method: 'POST', body: { lines: item.lines, key: fp + ':' + item.no } }).then(function (r) {
					totals.events += r.events; totals.duplicates += r.duplicates; totals.invalid += r.invalid; totals.skipped += r.not_recorded || 0; report();
					sending = false; worker.postMessage({ type: 'ack' }); pump();
				}).catch(function (e) { worker.terminate(); out.textContent = e.message; reject(e); });
			}
			worker.onmessage = function (e) {
				var m = e.data;
				if (m.type === 'batch') { queue.push({ lines: m.lines, no: batchNo++ }); totals.kept += m.lines.length; pump(); }
				else if (m.type === 'progress') { prog.value = m.pct; totals.lines = m.lines; report(); }
				else if (m.type === 'done') {
					done = true; prog.value = 100; totals.lines = m.lines; noUa = m.noUa || 0; report();
					if (m.lines && noUa > m.lines / 2) { toast(__('Most lines have no user agent, so crawlers cannot be identified. Export the log in "combined" format (with referrer and user agent).'), 'error'); }
					pump();
				}
				else if (m.type === 'error') { worker.terminate(); out.textContent = m.message; reject(new Error(m.message)); }
			};
			worker.postMessage({ type: 'start', file: file, batch: cfg.importBatch || 2000, registry: { patterns: reg.bots.reduce(function (a, b) { return a.concat(b.patterns || []); }, []), heuristics: reg.heuristics, referrers: reg.referrers.reduce(function (a, r) { return a.concat(r.hosts); }, []) } });
		});
	}

	// ── router ───────────────────────────────────────────────────────────────
	function render() {
		hideTip();
		leaveGuard = null;
		if (openDrawer) { openDrawer.close(true); }
		renderNav();
		var r = route(), m;
		if (r === '/') { viewOverview(); }
		else if ((m = r.match(/^\/crawlers\/([_a-z0-9-]+)$/))) { viewCrawler(m[1]); }
		else if (r === '/crawlers') { viewCrawlers(); }
		else if ((m = r.match(/^\/pages\/(\d+)$/))) { viewPage(m[1]); }
		else if (r === '/pages') { viewPages(); }
		else if (r === '/opportunities') { viewOpportunities(); }
		else if (r === '/recommendations') { viewRecommendations(); }
		else if (r === '/referrals') { viewReferrals(); }
		else if (r === '/readiness') { viewReadiness(); }
		else if (r === '/access') { viewAccess(); }
		else if (r === '/llms' || r === '/ai-files') { viewLlms(); }
		else if (r === '/visibility') { location.hash = '#/opportunities'; return; } // old links from before the section was folded into Opportunities
		else if (r === '/technical') { viewTechnical(); }
		else if (r === '/history') { viewHistory(); }
		else if (r === '/alerts') { viewAlerts(); }
		else if (r === '/settings') { viewSettings(); }
		else { location.hash = '#/'; }
		// Chosen from the admin menu: move focus to the content, as a page load would.
		if (shell.main && document.activeElement && document.activeElement.closest && document.activeElement.closest('#adminmenu')) { shell.main.focus({ preventScroll: true }); }
	}
	if (root) {
		buildShell();
		// Unsaved changes on the screen being left: ask first, and stay if the answer is no.
		var lastHash = location.hash;
		window.addEventListener('hashchange', function () {
			if (leaveGuard && leaveGuard() && route() !== (lastHash.replace(/^#/, '') || '/').split('?')[0] && !window.confirm(__('You have unsaved changes. Leave without saving?'))) {
				history.replaceState(null, '', lastHash || '#/');
				return;
			}
			lastHash = location.hash;
			render();
		});
		refreshStatus().then(render);
	}
})();
