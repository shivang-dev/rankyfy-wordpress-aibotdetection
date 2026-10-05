/**
 * RankyFy AI Crawler Monitor — admin app.
 *
 * Plain DOM, no build step. Every value from the server is inserted with
 * textContent (never innerHTML). Hash routes: #/ (overview), #/crawlers,
 * #/crawlers/:id, #/pages, #/pages/:id, #/opportunities, #/recommendations,
 * #/technical, #/history, #/alerts, #/settings.
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
	var toastBox = null;
	function toast(msg, kind) {
		if (!toastBox) { toastBox = h('div', { 'class': 'rf-toasts', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(toastBox); }
		var t = h('div', { 'class': 'rf-toast rf-toast-' + (kind || 'info') }, icon(kind === 'error' ? 'xCircle' : 'checkCircle'), h('span', { text: msg }));
		toastBox.appendChild(t);
		setTimeout(function () { t.remove(); }, kind === 'error' ? 7000 : 4000);
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
	function tile(label, value, opts) {
		opts = opts || {};
		var delta = null;
		if (has(opts.prev) && opts.prev > 0) {
			var d = Math.round(100 * (Number(value) - opts.prev) / opts.prev);
			delta = h('span', { 'class': 'rf-delta ' + (d === 0 ? '' : (d > 0) === (opts.upGood !== false) ? 'rf-up-good' : 'rf-up-bad') }, icon(d > 0 ? 'arrowUp' : d < 0 ? 'arrowDown' : 'minus', 12), sprintf(__('%s%% vs previous period'), (d > 0 ? '+' : '') + d));
		}
		var inner = [h('span', { 'class': 'rf-tile-label', text: label }), h('span', { 'class': 'rf-tile-value', text: typeof value === 'string' ? value : num(value) }), delta, opts.hint ? h('span', { 'class': 'rf-hint', text: opts.hint }) : null];
		return opts.href ? h('a', { 'class': 'rf-tile rf-tile-link', href: opts.href }, inner) : h('div', { 'class': 'rf-tile' }, inner);
	}
	function meter(value, max, label, opts) {
		opts = opts || {};
		var p = max ? Math.max(0, Math.min(100, 100 * value / max)) : 0;
		var level = opts.level || (p >= 70 ? 'good' : p >= 40 ? 'warn' : 'crit');
		return h('div', { 'class': 'rf-meter' },
			h('div', { 'class': 'rf-meter-top' }, h('span', { text: label }), h('span', { 'class': 'rf-meter-val', text: opts.text || (num(value) + ' / ' + num(max)) })),
			h('div', { 'class': 'rf-meter-track rf-meter-' + level, role: 'meter', 'aria-valuemin': 0, 'aria-valuemax': max, 'aria-valuenow': value, 'aria-label': label }, h('div', { 'class': 'rf-meter-fill', style: 'width:' + p.toFixed(1) + '%' })));
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
				return h('tr', null, cols.map(function (c) {
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
		return h('div', { 'class': 'rf-pagecell' }, id ? link(title, '/pages/' + id) : h('span', { text: title }), h('span', { 'class': 'rf-mono rf-muted', text: pagePath(p.path) }));
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
	function spark(values) {
		var W = 84, H = 22, max = Math.max.apply(null, values.concat([1])), n = values.length;
		var d = values.map(function (v, i) { return (i ? 'L' : 'M') + (n === 1 ? W / 2 : (W - 4) * i / (n - 1) + 2).toFixed(1) + ' ' + (H - 3 - (H - 6) * v / max).toFixed(1); }).join('');
		return s('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, 'class': 'rf-spark', role: 'img', 'aria-label': sprintf(__('Last 14 days: %s requests'), num(values.reduce(function (a, b) { return a + b; }, 0))) }, s('path', { d: d }));
	}

	// ── app shell ────────────────────────────────────────────────────────────
	var root = document.getElementById('rfaib-app');
	var state = { days: 30, status: null };
	var TABS = [
		['/', __('Overview')], ['/crawlers', __('Crawlers')], ['/pages', __('Pages')], ['/opportunities', __('Opportunities')],
		['/recommendations', __('Recommendations')], ['/technical', __('Technical')], ['/history', __('History')], ['/alerts', __('Alerts')], ['/settings', __('Settings')]
	];
	var shell = { nav: null, main: null, banner: null };
	function buildShell() {
		clear(root);
		shell.nav = h('nav', { 'class': 'rf-tabs', 'aria-label': __('AI Crawler Monitor sections') });
		shell.banner = h('div', { 'class': 'rf-banners' });
		shell.main = h('main', { 'class': 'rf-main', tabindex: '-1' });
		add(root, [h('header', { 'class': 'rf-top' }, h('div', { 'class': 'rf-brand' }, icon('robot', 22), h('div', null, h('strong', { text: __('AI Crawler Monitor') }), h('span', { 'class': 'rf-hint', text: __('by RankyFy') }))), shell.nav), shell.banner, shell.main]);
	}
	function route() { var hsh = location.hash.replace(/^#/, '') || '/'; return hsh.split('?')[0]; }
	function renderNav() {
		var cur = route();
		clear(shell.nav);
		TABS.forEach(function (t) {
			var on = t[0] === '/' ? cur === '/' : cur.indexOf(t[0]) === 0;
			var badge = t[0] === '/alerts' && state.status && state.status.unread_alerts ? h('span', { 'class': 'rf-count', text: num(state.status.unread_alerts) }) : null;
			shell.nav.appendChild(h('a', { href: '#' + t[0], 'class': 'rf-tab' + (on ? ' is-on' : ''), 'aria-current': on ? 'page' : null }, t[1], badge));
		});
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
		var head = h('div', { 'class': 'rf-viewhead' }, h('div', null, h('h2', { text: title }), sub ? h('p', { 'class': 'rf-hint', text: sub }) : null), h('div', { 'class': 'rf-viewtools' }, tools || null));
		var body = h('div', { 'class': 'rf-viewbody' });
		clear(shell.main);
		add(shell.main, [head, body]);
		return body;
	}
	function rangeControl(onChange, options) {
		options = options || [[7, __('7 days')], [30, __('30 days')], [90, __('90 days')]];
		return segmented(options, state.days, function (v) { state.days = Number(v); onChange(); }, __('Time range'));
	}

	// ── Overview ─────────────────────────────────────────────────────────────
	function viewOverview() {
		var live = begin('/');
		if (!live) { return; }
		loading();
		api('/overview?days=' + state.days).then(function (o) {
			if (!live()) { return; }
			var body = view(__('AI crawler overview'), __('Which AI crawlers visit your site, what they read and what to do next.'), rangeControl(viewOverview));
			var obs = o.observed, cov = o.coverage;
			if (cov.learning) {
				body.appendChild(h('div', { 'class': 'rf-banner rf-banner-info' }, icon('info'), h('span', { text: __('Monitoring is still building a baseline. Findings that depend on crawl history (such as "never crawled") appear after 14 days of data and once AI crawlers have visited.') })));
			}
			var score = o.score;
			var hero = card(__('AI search readiness'), { cls: 'rf-hero', sub: __('How ready your important pages are to be found, read and cited by AI search. Built from the checks below — not a prediction of rankings.') });
			if (score) {
				add(hero.body, [
					h('div', { 'class': 'rf-hero-row' },
						h('div', { 'class': 'rf-hero-num' }, h('span', { text: String(score.score) }), h('small', { text: '/100' })),
						h('div', { 'class': 'rf-parts' }, Object.keys(score.max).map(function (k) {
							return meter(score.parts[k], score.max[k], { access: __('Access'), discovery: __('Discovery'), structure: __('Structure'), depth: __('Depth'), trust: __('Trust'), linking: __('Internal links') }[k], { text: Math.round(score.parts[k]) + ' / ' + score.max[k] });
						}))),
					score.penalty ? h('p', { 'class': 'rf-hint', text: sprintf(__('Includes a %d-point penalty for site-wide blocks of AI search crawlers.'), score.penalty) }) : null,
					o.score_trend && o.score_trend.length > 1 ? line(o.score_trend, { label: __('AI search readiness'), max: 100 }) : null
				]);
			} else {
				hero.body.appendChild(empty(__('Your pages are being analysed. The score appears after the first pages are done (usually within a few minutes).')));
			}

			var tiles = h('div', { 'class': 'rf-tiles' },
				tile(__('AI crawler requests'), obs.ai_requests, { prev: obs.ai_requests_prev, hint: obs.ai_requests ? sprintf(__('%d%% verified'), pct(obs.verified, obs.ai_requests)) : null }),
				tile(__('AI crawlers seen'), obs.ai_bots, { href: '#/crawlers' }),
				tile(__('Fetches for assistant users'), obs.user_fetches, { hint: __('A person asked an AI about your page') }),
				tile(__('Visits from AI assistants'), obs.ai_referrals, { hint: __('People clicking through from ChatGPT, Perplexity…') }),
				tile(__('Pages crawled'), obs.pages_crawled, { href: '#/pages?filter=crawled' }),
				tile(__('Important pages never crawled'), cov.important_never, { href: '#/pages?filter=never', upGood: false }),
				obs.impersonations ? tile(__('Impersonations'), obs.impersonations, { hint: __('Requests that faked an AI crawler\'s name'), upGood: false }) : null
			);

			var covCard = card(__('Crawled vs. not crawled'), { prov: 'observed', sub: sprintf(__('Pages requested by at least one AI crawler in the last %d days.'), o.range) });
			add(covCard.body, [
				meter(cov.important_crawled, cov.important, __('Important pages crawled')),
				meter(cov.crawled, cov.pages, __('All pages crawled'), { level: 'info' }),
				h('div', { 'class': 'rf-linkrow' }, link(sprintf(__('%s not crawled'), num(cov.not_crawled)), '/pages?filter=uncrawled'), link(sprintf(__('%s important pages never crawled'), num(cov.important_never)), '/pages?filter=never'))
			]);

			var tl = card(__('Crawling timeline'), { prov: 'observed', sub: __('Requests per day from AI crawlers, by what they crawl for.') });
			tl.body.appendChild(o.timeline.some(function (r) { return SERIES.some(function (sr) { return r[sr.key]; }); }) ? stacked(o.timeline, SERIES, { label: __('AI crawler requests per day') }) : empty(__('No AI crawler requests in this period yet. New sites often wait days or weeks for their first visit.')));

			var bots = card(__('Bot-wise activity'), { prov: 'observed', tools: link(__('All crawlers'), '/crawlers') });
			bots.body.appendChild(table([
				{ label: __('Crawler'), render: function (b) { return h('div', { 'class': 'rf-pagecell' }, link(b.name, '/crawlers/' + b.id), h('span', { 'class': 'rf-muted', text: (b.provider ? b.provider + ' · ' : '') + catLabel(b.category) })); } },
				{ label: __('Requests'), num: true, render: function (b) { return num(b.requests); } },
				{ label: __('Verified'), num: true, render: function (b) { return b.verifiable ? pct(b.verified, b.requests) + '%' : __('n/a'); } },
				{ label: __('Last 14 days'), render: function (b) { return spark(b.spark); } },
				{ label: __('Last seen'), render: function (b) { return ago(b.last_seen); } }
			], o.top_bots.filter(function (b) { return b.ai; }), { empty: __('No AI crawler has visited yet.') }));

			var pages = card(__('Top AI-crawled pages'), { prov: 'observed', tools: link(__('All pages'), '/pages?sort=hits') });
			pages.body.appendChild(table([
				{ label: __('Page'), render: pageLink },
				{ label: __('Requests'), num: true, render: function (p) { return num(p.hits); } },
				{ label: __('Crawlers'), num: true, render: function (p) { return num(p.bots); } }
			], o.top_pages, { empty: __('No pages crawled by AI yet.') }));

			var next = card(__('What to do next'), { tools: link(__('All recommendations'), '/recommendations') });
			next.body.appendChild(o.next_steps.length ? h('ol', { 'class': 'rf-recs' }, o.next_steps.map(recItem)) : empty(__('No open issues. Nice.')));

			var alerts = card(__('Latest alerts'), { tools: link(__('All alerts'), '/alerts') });
			alerts.body.appendChild(o.alerts.length ? h('ul', { 'class': 'rf-alerts-mini' }, o.alerts.map(function (a) { return h('li', null, sev(a.severity), h('a', { href: '#/alerts', text: a.title }), h('span', { 'class': 'rf-muted', text: ago(a.created_at) })); })) : empty(__('No alerts yet.')));

			var refs = card(__('Visits from AI assistants'), { prov: 'observed', sub: __('People who clicked a link to your site inside an AI assistant.') });
			refs.body.appendChild(table([{ label: __('Assistant'), key: 'name' }, { label: __('Visits'), num: true, render: function (r) { return num(r.visits); } }, { label: __('Landing pages'), num: true, render: function (r) { return num(r.pages); } }], o.referrals, { empty: __('No visits from AI assistants recorded in this period.') }));

			add(body, [h('div', { 'class': 'rf-grid rf-grid-hero' }, hero, h('div', { 'class': 'rf-stack' }, tiles, covCard)), tl, h('div', { 'class': 'rf-grid' }, bots, pages), h('div', { 'class': 'rf-grid' }, next, h('div', { 'class': 'rf-stack' }, alerts, refs))]);
		}).catch(function (e) { if (live()) { fail(e, viewOverview); } });
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
	function viewCrawlers() {
		var tab = q('tab') || 'ai';
		var tools = h('div', { 'class': 'rf-row' }, segmented([['ai', __('AI crawlers')], ['all', __('All bots')], ['unknown', __('Unrecognised')], ['registry', __('Registry')]], tab, function (v) { location.hash = '#/crawlers?tab=' + v; }, __('Show')), tab === 'ai' || tab === 'all' ? rangeControl(viewCrawlers) : null);
		if (tab === 'unknown') { return viewAgents(tools); }
		if (tab === 'registry') { return viewRegistry(tools); }
		var live = begin('/crawlers');
		if (!live) { return; }
		loading();
		api('/bots?days=' + state.days).then(function (d) {
			if (!live()) { return; }
			var body = view(__('Crawlers'), __('Every automated client that visited, how sure we are who it is, and whether robots.txt lets it in.'), tools);
			var items = d.items.filter(function (b) { return tab === 'all' || b.ai; });
			var c = card(null);
			c.body.appendChild(table([
				{ label: __('Crawler'), render: function (b) { return h('div', { 'class': 'rf-pagecell' }, link(b.name, '/crawlers/' + b.id), h('span', { 'class': 'rf-muted', text: b.provider || '' })); } },
				{ label: __('Purpose'), render: function (b) { return catLabel(b.category); } },
				{ label: __('Requests'), num: true, render: function (b) { return h('span', null, num(b.requests), b.requests_prev ? h('span', { 'class': 'rf-muted', text: ' (' + (b.requests >= b.requests_prev ? '+' : '') + pct(b.requests - b.requests_prev, b.requests_prev) + '%)' }) : null); } },
				{ label: __('Verified'), num: true, render: function (b) { return b.verifiable ? pct(b.verified, b.requests) + '%' : h('span', { 'class': 'rf-muted', title: __('This operator publishes no way to verify its crawler.'), text: __('n/a') }); } },
				{ label: __('Impersonations'), num: true, render: function (b) { return b.impersonations ? h('span', { 'class': 'rf-crit-text', text: num(b.impersonations) }) : '0'; } },
				{ label: __('Pages'), num: true, render: function (b) { return num(b.pages); } },
				{ label: __('Avg. response'), num: true, render: function (b) { return has(b.avg_ms) ? num(b.avg_ms) + ' ms' : '—'; } },
				{ label: __('robots.txt'), render: function (b) { return b.robots ? (b.robots.allowed ? h('span', { 'class': 'rf-chip rf-chip-good' }, icon('check', 12), __('Allowed')) : h('span', { 'class': 'rf-chip rf-chip-crit', title: b.robots.rule }, icon('ban', 12), __('Blocked'))) : '—'; } },
				{ label: __('Last 14 days'), render: function (b) { return spark(b.spark); } },
				{ label: __('Last seen'), render: function (b) { return ago(b.last_seen); } }
			], items, { empty: __('No crawler activity in this period yet.') }));
			add(body, [c, h('p', { 'class': 'rf-hint', text: __('Verified: the request came from the operator\'s published addresses, passed reverse DNS, or carried a valid signature. "n/a" means the operator publishes no way to check, so identification relies on the user agent.') })]);
		}).catch(function (e) { if (live()) { fail(e, viewCrawlers); } });
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
				{ label: __('Page'), render: function (p) { return h('div', { 'class': 'rf-pagecell' }, link(p.title || p.path, '/pages/' + p.id), h('span', { 'class': 'rf-mono rf-muted', text: p.path }), p.important ? h('span', { 'class': 'rf-hint', text: p.reasons.map(function (r) { return REASONS[r] || r; }).join(' · ') }) : null); } },
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
				li.appendChild(h('div', { 'class': 'rf-row' }, button(__('Ignore'), function () { return api('/findings/' + f.id, { method: 'POST', body: { status: 'ignored' } }).then(function () { viewPage(id); }); }, { small: true, ghost: true })));
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
		Promise.all([api('/opportunities'), api('/visibility').catch(function () { return { state: 'error' }; })]).then(function (res) {
			if (!live()) { return; }
			var o = res[0], vis = res[1];
			var body = view(__('AI search opportunities'), __('Where your site can win more AI visibility. Observed data and suggestions are kept apart.'));
			body.appendChild(h('div', { 'class': 'rf-banner rf-banner-info' }, icon('info'), h('span', { text: __('"Observed" sections come from real requests to your site and from your own content. "AI-suggested" and "Template idea" items are generated — they are not real searches or prompts anyone was observed making. AI assistants do not share the questions people ask.') })));

			body.appendChild(h('div', { 'class': 'rf-section-label' }, prov('observed'), h('span', { text: __('Observed') })));
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

			body.appendChild(h('div', { 'class': 'rf-section-label' }, prov('inferred'), h('span', { text: __('AI visibility and suggestions') })));
			body.appendChild(visibilityCard(vis));
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
	function visibilityCard(vis) {
		var c = card(__('Mentions in AI answers (RankyFy AI Visibility)'), { sub: __('RankyFy asks AI assistants a set of tracked prompts and records whether your site is mentioned. The answers are real (observed); the prompts are generated, not collected from users.') });
		if (!vis || vis.state !== 'connected') {
			c.body.appendChild(empty(vis && vis.state === 'error' ? (vis.message || __('AI Visibility is not available right now.')) : __('Connect a RankyFy account through the RankyFy SEO plugin to see how often ChatGPT and Google AI mention your site.')));
			return c;
		}
		var ov = vis.overview || {}, run = ov.run || {};
		add(c.body, [
			h('div', { 'class': 'rf-tiles' },
				has(run.visibility_score) ? tile(__('AI visibility score'), String(Math.round(run.visibility_score)), { hint: has(ov.score_delta) ? sprintf(__('%s since the previous check'), (ov.score_delta > 0 ? '+' : '') + Math.round(ov.score_delta)) : null }) : null,
				has(run.prompt_count) ? tile(__('Prompts checked'), run.prompt_count) : null,
				has(run.mentioned_count) ? tile(__('Answers mentioning you'), run.mentioned_count, { hint: run.check_count ? sprintf(__('of %s answers'), num(run.check_count)) : null }) : null),
			(ov.platforms || []).length ? table([
				{ label: __('Assistant'), key: 'label' },
				{ label: __('Mention rate'), num: true, render: function (p) { return p.status === 'not_configured' ? h('span', { 'class': 'rf-muted', text: __('not checked') }) : Math.round(p.mention_rate) + '%'; } },
				{ label: __('Answers checked'), num: true, render: function (p) { return num(p.checks); } },
				{ label: __('Average position'), num: true, render: function (p) { return p.avg_position ? p.avg_position.toFixed(1) : '—'; } }
			], ov.platforms, { compact: true }) : null,
			(ov.top_competitors || []).length ? h('p', { 'class': 'rf-hint', text: sprintf(__('Mentioned most instead of you: %s'), ov.top_competitors.slice(0, 5).map(function (x) { return x.competitor; }).join(', ')) }) : null,
			h('div', { 'class': 'rf-sub' }, h('h4', null, __('Prompts where you are not mentioned yet'), ' ', prov('inferred', __('Generated prompts'))), table([
				{ label: __('Prompt'), key: 'prompt' },
				{ label: __('Missing on'), render: function (x) { return (x.missing_on || []).join(', ') || '—'; } },
				{ label: __('Mentioned instead'), render: function (x) { return (x.competitors || []).slice(0, 3).join(', ') || '—'; } },
				{ label: __('Recommendation'), render: function (x) { return x.recommendation || '—'; } }
			], (vis.opportunities || []).slice(0, 15), { compact: true, empty: __('No open opportunities.') }))
		]);
		return c;
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
				li.appendChild(h('div', { 'class': 'rf-row' }, button(__('Ignore'), function () { return api('/findings/' + f.id, { method: 'POST', body: { status: 'ignored' } }).then(function () { toast(__('Ignored. It will not be shown again unless it changes.')); viewRecommendations(); }); }, { small: true, ghost: true })));
				return li;
			})) : empty(__('Nothing here. Change the filters, or check back after the next analysis.')));
			if (recState.code) { body.insertBefore(h('div', { 'class': 'rf-banner rf-banner-info' }, icon('info'), h('span', { text: __('Showing one issue type.') }), button(__('Show all'), function () { goRecs({ code: '', page: 1 }); }, { small: true, ghost: true })), c); }
			add(c.body, [pager(d.page, d.pages, function (p) { goRecs({ page: p }); })]);
			body.appendChild(c);
		}).catch(function (e) { if (live()) { fail(e, viewRecommendations); } });
	}

	// ── Technical ────────────────────────────────────────────────────────────
	function viewTechnical() {
		var live = begin('/technical');
		if (!live) { return; }
		loading();
		api('/technical').then(function (t) {
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
			add(body, [m, h('div', { 'class': 'rf-grid' }, probe, issues), h('div', { 'class': 'rf-grid' }, ver, raw)]);
		}).catch(function (e) { if (live()) { fail(e, viewTechnical); } });
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
			score.body.appendChild(line(snaps.filter(function (x) { return has(x.aeo_score); }).map(function (x) { return { day: x.day, value: x.aeo_score }; }), { label: __('Score'), max: 100 }));
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
						button(__('Dismiss'), function () { return mark('dismissed'); }, { small: true, ghost: true })));
			})));
		}).catch(function (e) { if (live()) { fail(e, viewAlerts); } });
	}

	// ── Settings ─────────────────────────────────────────────────────────────
	function viewSettings() {
		var live = begin('/settings');
		if (!live) { return; }
		loading();
		Promise.all([api('/settings'), api('/status'), api('/log')]).then(function (res) {
			if (!live()) { return; }
			var st = res[0].settings, reg = res[0].registry, status = res[1], log = res[2].items;
			var body = view(__('Settings'));
			var form = {};
			function toggle(key, label, hint) { var el = h('input', { type: 'checkbox', checked: !!st[key] }); form[key] = function () { return el.checked; }; return h('label', { 'class': 'rf-check' }, el, h('span', null, h('strong', { text: label }), hint ? h('span', { 'class': 'rf-hint', text: hint }) : null)); }
			function field(key, label, input, hint) { form[key] = function () { return input.type === 'number' ? Number(input.value) : input.value; }; return h('label', { 'class': 'rf-field' }, h('strong', { text: label }), input, hint ? h('span', { 'class': 'rf-hint', text: hint }) : null); }
			function select(key, label, opts, hint) { return field(key, label, h('select', { 'class': 'rf-input' }, opts.map(function (o) { return h('option', { value: o[0], text: o[1], selected: String(st[key]) === String(o[0]) }); })), hint); }
			var prio = (st.priority_bots || '').split(',');
			var prioBoxes = reg.filter(function (b) { return b.ai || b.category === 'search'; }).map(function (b) { var el = h('input', { type: 'checkbox', value: b.id, checked: prio.indexOf(b.id) >= 0 }); return h('label', { 'class': 'rf-check rf-check-sm' }, el, h('span', { text: b.name })); });
			form.priority_bots = function () { return prioBoxes.map(function (l) { return l.querySelector('input'); }).filter(function (i) { return i.checked; }).map(function (i) { return i.value; }).join(','); };

			var tracking = card(__('Tracking'));
			add(tracking.body, [
				toggle('tracking', __('Record crawler visits'), __('Ordinary visitors are never recorded; for them the plugin performs one quick text check and nothing else.')),
				toggle('track_search_pages', __('Record search engines page by page'), __('Off: Googlebot, Bingbot and others are only counted per day.')),
				toggle('track_unknown', __('Remember unrecognised bots'), __('Needed to discover new AI crawlers.')),
				toggle('track_referrals', __('Count visits from AI assistants'), __('Only a daily count per page and assistant — no visitor data.')),
				field('exclude_paths', __('Paths not to monitor (one per line)'), h('textarea', { 'class': 'rf-input', rows: 3, value: st.exclude_paths })),
				select('proxy_header', __('Visitor address header'), [['', __('None — use the connection address (default)')], ['cf-connecting-ip', 'CF-Connecting-IP (Cloudflare)'], ['x-forwarded-for', 'X-Forwarded-For'], ['x-real-ip', 'X-Real-IP']], __('Only if your site is behind a proxy or CDN. Without the trusted proxies below, the header is ignored so nobody can fake an address.')),
				field('trusted_proxies', __('Trusted proxy addresses (CIDR, one per line)'), h('textarea', { 'class': 'rf-input', rows: 3, value: st.trusted_proxies }))
			]);
			var privacy = card(__('Privacy and data retention'));
			add(privacy.body, [
				select('ip_storage', __('Store crawler addresses as'), [['network', __('Network only (/24, /48) — default')], ['none', __('Nothing')]], __('Full addresses are kept only until verification finishes, then discarded.')),
				field('retention_events', __('Keep individual requests (days)'), h('input', { type: 'number', min: 1, max: 365, 'class': 'rf-input', value: st.retention_events })),
				field('retention_history', __('Keep daily history (days)'), h('input', { type: 'number', min: 30, max: 1825, 'class': 'rf-input', value: st.retention_history })),
				field('retention_sessions', __('Keep crawl sessions (days)'), h('input', { type: 'number', min: 7, max: 1825, 'class': 'rf-input', value: st.retention_sessions }))
			]);
			var verify = card(__('Verification'));
			add(verify.body, [
				toggle('verify_rdns', __('Verify by reverse DNS'), __('For Googlebot, Bingbot, Applebot, Amazonbot and others. Runs in the background.')),
				toggle('verify_signatures', __('Verify signed requests (Web Bot Auth)'), __('Checks cryptographic request signatures from AI agents that send them.')),
				toggle('fetch_ranges_direct', __('Download address lists from operators when RankyFy is unreachable')),
				toggle('probe', __('Test how the site answers AI crawlers (daily)'), __('A few requests to your own home page and top pages with crawler user agents.'))
			]);
			var important = card(__('Important pages and priority crawlers'));
			add(important.body, [
				field('importance_min', __('A page is important from score'), h('input', { type: 'number', min: 1, max: 100, 'class': 'rf-input', value: st.importance_min }), __('Importance comes from your menus, home and shop pages, cornerstone flags, internal links, sales and visits from AI assistants. You can also mark pages important by hand.')),
				h('strong', { text: __('Priority crawlers') }), h('p', { 'class': 'rf-hint', text: __('Their access is checked on every important page and counts towards the score.') }), h('div', { 'class': 'rf-checks' }, prioBoxes)
			]);
			var notify = card(__('Notifications'));
			add(notify.body, [
				select('notify_mode', __('Email me'), [['critical', __('Immediately, for critical alerts only (default)')], ['all', __('Immediately, for every alert')], ['digest', __('One daily digest')], ['off', __('Never (alerts stay on the Alerts screen)')]]),
				field('notify_email', __('Email address'), h('input', { type: 'email', 'class': 'rf-input', value: st.notify_email })),
				field('webhook_url', __('Webhook URL (Slack, Teams, Google Chat or any HTTPS endpoint)'), h('input', { type: 'url', 'class': 'rf-input', value: st.webhook_url, placeholder: 'https://hooks.slack.com/services/…' })),
				select('webhook_level', __('Send to the webhook'), [['critical', __('Critical alerts')], ['warning', __('Warnings and critical alerts')], ['info', __('All alerts')]]),
				button(__('Send a test notification'), function () { return saveAndReport().then(function () { return api('/alerts/test', { method: 'POST' }); }).then(function (r) { toast(r.webhook && r.webhook !== true ? r.webhook : __('Test sent.'), r.webhook && r.webhook !== true ? 'error' : 'info'); }); }, { small: true, icon: 'bell' })
			]);
			var rk = card(__('RankyFy'));
			add(rk.body, [
				h('p', { text: res[0].rankyfy === 'connected' ? __('Connected through the RankyFy SEO plugin. AI analysis and AI Visibility are available.') : res[0].rankyfy === 'not_connected' ? __('RankyFy SEO is installed but not connected. Connect it to use AI analysis and AI Visibility.') : __('Install and connect the RankyFy SEO plugin to add AI analysis and AI Visibility. Crawler monitoring works without it.') }),
				toggle('share_unknown', __('Help identify new AI crawlers'), __('Sends the user-agent text of unrecognised bots (nothing else — no addresses, no URLs) to RankyFy to classify them and grow the registry.')),
				toggle('ai_auto', __('Analyse my most important pages with AI automatically'), __('Up to 5 pages a day that have no recent analysis. Uses Content AI credits.'))
			]);
			function save() {
				var out = {};
				Object.keys(form).forEach(function (k) { out[k] = form[k](); });
				return api('/settings', { method: 'POST', body: out });
			}
			function saveAndReport() {
				var sent = {};
				Object.keys(form).forEach(function (k) { sent[k] = form[k](); });
				return save().then(function (res) {
					var got = (res && res.settings) || {};
					var refused = ['webhook_url', 'notify_email'].filter(function (k) { return String(sent[k] || '') !== String(got[k] || '') && String(sent[k] || '') !== ''; });
					if (refused.length) {
						toast(sprintf(__('Saved, except: %s (not a valid value — the previous one is kept). Webhooks must use https on a public host.'), refused.join(', ')), 'error');
					} else {
						toast(__('Settings saved.'));
					}
					refreshStatus();
					return res;
				});
			}
			var saveBar = h('div', { 'class': 'rf-savebar' }, button(__('Save settings'), saveAndReport, { primary: true }));

			var imp = card(__('Import a server access log'), { sub: __('Pages served from a page cache or CDN never reach WordPress, so their crawler visits are missed. Import an access log to fill the gap. The file is read in your browser — it is not uploaded; only crawler lines are sent to your site.') });
			imp.body.appendChild(importer());
			var exp = card(__('Export'));
			add(exp.body, [h('div', { 'class': 'rf-row' }, ['events', 'pages', 'bots'].map(function (t) { return h('a', { 'class': 'rf-btn rf-btn-sm', href: cfg.exportUrl + '&type=' + t + '&days=' + state.days }, icon('download', 14), { events: __('Crawler requests (CSV)'), pages: __('Pages (CSV)'), bots: __('Crawlers (CSV)') }[t]); }))]);
			var sys = card(__('Status'));
			sys.body.appendChild(h('dl', { 'class': 'rf-facts' },
				h('dt', { text: __('Monitoring since') }), h('dd', { text: fmtDate(status.monitoring_since) }),
				h('dt', { text: __('Background work') }), h('dd', { text: status.worker.age === null ? __('not run yet') : sprintf(__('last run %s'), ago(Math.floor(Date.now() / 1000) - status.worker.age)) + (status.worker.cron_disabled ? ' · ' + __('WP-Cron disabled; using a server cron') : '') }),
				h('dt', { text: __('Waiting to be processed') }), h('dd', { text: sprintf(__('%1$s requests · %2$s verifications · %3$s pages to analyse'), num(status.worker.backlog), num(status.worker.pending_verification), num(status.inventory.dirty)) }),
				h('dt', { text: __('Pages in inventory') }), h('dd', { text: num(status.inventory.pages) + (status.inventory.running ? ' · ' + __('scanning…') : '') }),
				h('dt', { text: __('Crawler registry') }), h('dd', { text: sprintf(__('%1$s (%2$d crawlers, %3$s)'), status.registry.version, status.registry.bots, status.registry.source === 'rankyfy' ? __('live from RankyFy') : __('built in')) }),
				h('dt', { text: __('Persistent object cache') }), h('dd', { text: status.object_cache ? __('yes') : __('no') }),
				h('dt', { text: __('Database') }), h('dd', { text: Object.keys(status.tables).map(function (k) { return k + ': ' + num(status.tables[k].rows); }).join(' · ') })));
			var lg = card(__('Diagnostics log'));
			lg.body.appendChild(table([{ label: __('Time'), render: function (e) { return fmtDate(e.t); } }, { label: __('Level'), key: 'l' }, { label: __('Message'), key: 'm' }, { label: __('Details'), render: function (e) { return h('span', { 'class': 'rf-mono', text: JSON.stringify(e.ctx) }); } }], log.slice(0, 30), { compact: true, empty: __('Nothing logged.') }));
			add(body, [h('div', { 'class': 'rf-grid' }, h('div', { 'class': 'rf-stack' }, tracking, verify), h('div', { 'class': 'rf-stack' }, privacy, notify, rk)), important, saveBar, h('div', { 'class': 'rf-grid' }, imp, h('div', { 'class': 'rf-stack' }, exp, sys)), lg]);
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
		renderNav();
		var r = route(), m;
		if (r === '/') { viewOverview(); }
		else if ((m = r.match(/^\/crawlers\/([_a-z0-9-]+)$/))) { viewCrawler(m[1]); }
		else if (r === '/crawlers') { viewCrawlers(); }
		else if ((m = r.match(/^\/pages\/(\d+)$/))) { viewPage(m[1]); }
		else if (r === '/pages') { viewPages(); }
		else if (r === '/opportunities') { viewOpportunities(); }
		else if (r === '/recommendations') { viewRecommendations(); }
		else if (r === '/technical') { viewTechnical(); }
		else if (r === '/history') { viewHistory(); }
		else if (r === '/alerts') { viewAlerts(); }
		else if (r === '/settings') { viewSettings(); }
		else { location.hash = '#/'; }
		if (shell.main && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('rf-tab')) { shell.main.focus({ preventScroll: true }); }
	}
	if (root) {
		buildShell();
		window.addEventListener('hashchange', render);
		refreshStatus().then(render);
	}
})();
