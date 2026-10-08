/**
 * RankyFy — publish-time guard for the block editor.
 *
 * Plain wp.* globals, no build step. Four places:
 *   - "RankyFy" sidebar panel: the post's readiness, "Before you update"
 *     checks and what AI crawlers did with this post;
 *   - a warning in the editor as soon as a published post's URL is changed,
 *     with this post's real crawler and visit numbers and three choices:
 *     create a 301, keep the old URL, or change anyway. The save is never
 *     blocked;
 *   - pre-publish panel: the same checks for a new post; in "confirm" mode
 *     critical problems hold the Publish button until acknowledged;
 *   - after an update of a published post: a notice when a check fails.
 */
(function (wp) {
	'use strict';
	if (!wp || !wp.plugins || !wp.data || !wp.element || !wp.apiFetch || !wp.components) { return; }

	var cfg = window.RFY_GUARD || {};
	var D = 'rankyfy-ai-seo';
	var el = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect;
	var __ = function (s) { return wp.i18n.__(s, D); };
	var sprintf = wp.i18n.sprintf;
	var C = wp.components;
	var PrePublish = (wp.editor && wp.editor.PluginPrePublishPanel) || (wp.editPost && wp.editPost.PluginPrePublishPanel);
	var DocPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	var LOCK = cfg.lock || 'rfy-guard';

	var STATUS = {
		fail: ['✕', '#b32d2e', __('Problem')],
		warn: ['!', '#996800', __('Warning')],
		pass: ['✓', '#00702a', __('OK')],
		na: ['–', '#646970', __('Not checked')]
	};

	function editor() { return wp.data.select('core/editor'); }

	function runCheck() {
		var ed = editor();
		return wp.apiFetch({
			path: cfg.path + '/' + ed.getCurrentPostId(),
			method: 'POST',
			data: {
				title: ed.getEditedPostAttribute('title') || '',
				content: ed.getEditedPostContent() || '',
				slug: ed.getEditedPostAttribute('slug') || '',
				parent: ed.getEditedPostAttribute('parent') || 0
			}
		});
	}

	/** Put the saved slug back: the URL stays as it is and nothing needs redirecting. */
	function keepOldUrl(slug, after) {
		wp.data.dispatch('core/editor').editPost({ slug: slug });
		wp.data.dispatch('core/notices').createNotice('info', __('The old URL is back. Nothing will need a redirect.'), { type: 'snackbar', id: 'rfy-guard-slug' });
		if (after) { setTimeout(after, 50); }
	}

	// Sidebar styles, scoped to the panel.
	var css = document.createElement('style');
	css.textContent = '.rfy-guard-score{display:flex;gap:12px;align-items:center;margin-bottom:8px}.rfy-guard-head{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#50575e;margin:14px 0 6px}.rfy-guard-list{margin:0}.rfy-guard-list li{margin:0 0 6px}.rfy-guard-hint{color:#646970;font-size:12px;margin:2px 0 0}';
	document.head.appendChild(css);

	function Results(props) {
		var res = props.result;
		return el('ul', { style: { margin: 0 } }, res.checks.map(function (c) {
			var st = STATUS[c.status] || STATUS.na;
			var problem = c.status === 'fail' || c.status === 'warn';
			return el('li', { key: c.id, style: { marginBottom: '10px' } },
				el('strong', { style: { color: st[1] } }, el('span', { 'aria-hidden': 'true' }, st[0] + ' '), el('span', { className: 'screen-reader-text' }, st[2] + ': '), c.title),
				// A passing URL change still says where the redirect goes.
				c.detail && (c.status !== 'pass' || c.id === 'url_change') ? el('div', null, c.detail) : null,
				c.action && problem ? el('div', { style: { fontStyle: 'italic' } }, c.action) : null,
				c.id === 'url_change' && res.old_slug ? el(C.Button, { variant: 'secondary', size: 'small', style: { marginTop: '6px' }, onClick: function () { keepOldUrl(res.old_slug, props.onChange); } }, __('Keep the old URL')) : null);
		}));
	}

	function Redirects(props) {
		if (!props.items || !props.items.length) { return null; }
		return el('div', { style: { marginTop: '8px' } },
			el('strong', null, __('Old URLs that redirect here')),
			el('ul', { style: { margin: '4px 0 0' } }, props.items.map(function (r) {
				return el('li', { key: r.source, style: { wordBreak: 'break-all' } }, el('code', null, r.source), r.hits ? ' · ' + sprintf(__('%d visits'), r.hits) : null);
			})));
	}

	function useCheck(auto) {
		var r = useState(null), e = useState(''), b = useState(false);
		var result = r[0], setResult = r[1], error = e[0], setError = e[1], busy = b[0], setBusy = b[1];
		function go() {
			setBusy(true); setError('');
			return runCheck().then(function (res) { setResult(res); }).catch(function (err) { setError((err && err.message) || __('The check could not run.')); }).then(function () { setBusy(false); });
		}
		useEffect(function () {
			if (auto) { go(); }
			if (!auto) { return undefined; }
			window.addEventListener('rfy-recheck', go);
			return function () { window.removeEventListener('rfy-recheck', go); };
		}, []);
		return { result: result, error: error, busy: busy, run: go };
	}

	/** Rendered only while the pre-publish step is open. */
	function PrePublishBody() {
		var chk = useCheck(true);
		var a = useState(false), ack = a[0], setAck = a[1];
		var needsAck = cfg.mode === 'confirm' && chk.result && chk.result.needs_ack;
		useEffect(function () {
			var d = wp.data.dispatch('core/editor');
			if (needsAck && !ack) { d.lockPostSaving(LOCK); } else { d.unlockPostSaving(LOCK); }
			return function () { d.unlockPostSaving(LOCK); };
		}, [needsAck, ack]);
		if (chk.busy && !chk.result) { return el(C.Spinner); }
		if (chk.error) { return el(C.Notice, { status: 'warning', isDismissible: false }, chk.error); }
		if (!chk.result) { return null; }
		return el('div', null,
			el(Results, { result: chk.result, onChange: chk.run }),
			needsAck ? el(C.CheckboxControl, { label: __('I understand — publish anyway'), checked: ack, onChange: setAck }) : null,
			el(C.Button, { variant: 'link', onClick: chk.run, disabled: chk.busy }, __('Check again')));
	}

	/** The post's readiness as a small ring, with its status in words. */
	function Score(props) {
		var v = props.value, r = 20, c = 2 * Math.PI * r;
		var lvl = v === null || v === undefined ? null : v >= 70 ? ['#0ca30c', __('Good')] : v >= 40 ? ['#c98500', __('Needs work')] : ['#d03b3b', __('Poor')];
		return el('div', { className: 'rfy-guard-score' },
			el('svg', { width: 52, height: 52, viewBox: '0 0 52 52', 'aria-hidden': 'true' },
				el('circle', { cx: 26, cy: 26, r: r, fill: 'none', stroke: '#dcdcde', strokeWidth: 5 }),
				lvl ? el('circle', { cx: 26, cy: 26, r: r, fill: 'none', stroke: lvl[0], strokeWidth: 5, strokeLinecap: 'round', strokeDasharray: c, strokeDashoffset: c * (1 - v / 100), transform: 'rotate(-90 26 26)' }) : null,
				el('text', { x: 26, y: 31, textAnchor: 'middle', fontSize: 15, fontWeight: 600, fill: '#1d2327' }, lvl ? String(v) : '–')),
			el('div', null,
				el('div', { style: { fontWeight: 600, color: lvl ? lvl[0] : '#646970' } }, lvl ? (v >= 70 ? '✓ ' : '△ ') + lvl[1] : __('Not scored yet')),
				el('div', { style: { color: '#646970' } }, props.note)));
	}

	/** "Before you update": one line per check, the problem's detail on the line below. */
	function Checklist(props) {
		var res = props.result;
		return el('ul', { className: 'rfy-guard-list' }, res.checks.map(function (c) {
			var st = STATUS[c.status] || STATUS.na;
			var icon = c.status === 'fail' ? '●' : c.status === 'warn' ? '△' : c.status === 'pass' ? '✓' : '–';
			var problem = c.status === 'fail' || c.status === 'warn';
			return el('li', { key: c.id },
				el('span', { 'aria-hidden': 'true', style: { color: st[1] } }, icon), ' ',
				el('span', { className: 'screen-reader-text' }, st[2] + ': '),
				el('span', null, c.title),
				problem && c.action ? el('div', { className: 'rfy-guard-hint' }, c.action) : null,
				c.id === 'url_change' && res.old_slug ? el(C.Button, { variant: 'link', onClick: function () { keepOldUrl(res.old_slug, props.onChange); } }, __('Keep the old URL')) : null);
		}));
	}

	function ago(ts) {
		var s = Math.round(Date.now() / 1000 - ts);
		if (s < 3600) { return sprintf(__('%dm ago'), Math.max(1, Math.round(s / 60))); }
		if (s < 172800) { return sprintf(__('%dh ago'), Math.round(s / 3600)); }
		return sprintf(__('%dd ago'), Math.round(s / 86400));
	}

	function SidebarBody() {
		var chk = useCheck(true);
		var res = chk.result;
		if (chk.error) { return el(C.Notice, { status: 'warning', isDismissible: false }, chk.error); }
		if (!res) { return el(C.Spinner); }
		var notices = res.counts.fail + res.counts.warn, c = res.crawl;
		return el('div', { className: 'rfy-guard-side' },
			el(Score, { value: res.score, note: notices ? sprintf(wp.i18n._n('This post · %d notice', 'This post · %d notices', notices, D), notices) : __('This post · no notices') }),
			el('h3', { className: 'rfy-guard-head' }, __('Before you update')),
			el(Checklist, { result: res, onChange: chk.run }),
			el('h3', { className: 'rfy-guard-head' }, __('AI crawlers on this post')),
			c && c.hits ? el('ul', { className: 'rfy-guard-list' }, c.by_bot.slice(0, 3).map(function (b) { return el('li', { key: b.name }, b.name + ' — ' + b.hits + ', ' + sprintf(__('last %s'), ago(b.last))); })) : el('p', { className: 'rfy-guard-hint' }, res.old_url === '' && !c ? __('Published posts show their crawler data here.') : __('No AI crawler has read this post yet.')),
			c && c.referrals ? el('p', null, sprintf(__('%d visits from AI engines, 30d'), c.referrals)) : null,
			el(Redirects, { items: res.redirects }),
			res.seo ? el('p', { className: 'rfy-guard-hint' }, sprintf(__('%s handles the title and meta description for this post.'), res.seo)) : null,
			el(C.Button, { variant: 'link', onClick: chk.run, disabled: chk.busy }, chk.busy ? __('Checking…') : __('Check again')));
	}

	function title(res) {
		if (!res) { return __('AI crawler check'); }
		return res.counts.fail ? sprintf(__('AI crawler check: %d problem(s)'), res.counts.fail) : __('AI crawler check');
	}

	wp.plugins.registerPlugin('rfy-guard', {
		render: function () {
			return el(wp.element.Fragment, null,
				PrePublish ? el(PrePublish, { title: title(null), initialOpen: true, className: 'rfy-guard' }, el(PrePublishBody)) : null,
				DocPanel ? el(DocPanel, { name: 'rfy-guard', title: 'RankyFy', className: 'rfy-guard' }, el(SidebarBody)) : null);
		}
	});

	/**
	 * A published post's URL is being changed: quantify what points at the old
	 * one, and offer to redirect, revert or go ahead. Drafts get no warning —
	 * a slug change before publication breaks nothing.
	 */
	var slugTimer = null, lastSlug = null;
	function choose(choice) {
		return wp.apiFetch({ path: cfg.path + '/' + editor().getCurrentPostId() + '/choice', method: 'POST', data: { choice: choice } });
	}
	function urlNotice() {
		var ed = editor(), notices = wp.data.dispatch('core/notices');
		window.dispatchEvent(new Event('rfy-recheck'));
		var saved = ed.getCurrentPostAttribute('slug'), edited = ed.getEditedPostAttribute('slug');
		if (ed.getCurrentPostAttribute('status') !== 'publish' || !edited || edited === saved) { notices.removeNotice('rfy-url'); return; }
		runCheck().then(function (res) {
			if (!res.old_url) { notices.removeNotice('rfy-url'); return; }
			var c = res.crawl || { hits: 0, referrals: 0 };
			var was = res.old_url.replace(/^https?:\/\/[^/]+/, ''), now = res.url.replace(/^https?:\/\/[^/]+/, '');
			var lead = c.hits && c.referrals
				? sprintf(__('You changed this post\'s URL. %1$d AI crawler hits and %2$d visits point at the old address.'), c.hits, c.referrals)
				: c.hits ? sprintf(__('You changed this post\'s URL. %d AI crawler hits point at the old address.'), c.hits)
					: c.referrals ? sprintf(__('You changed this post\'s URL. %d visits from AI engines point at the old address.'), c.referrals)
						: __('You changed this post\'s URL.');
			var keep = { label: __('Keep the old URL'), onClick: function () { choose('').catch(function () {}); keepOldUrl(res.old_slug); } };
			if (res.redirects_on) {
				notices.createNotice('warning', lead + ' ' + __('A 301 redirect will be created when you update.') + '  ' + sprintf(__('was %1$s — now %2$s'), was, now), { id: 'rfy-url', isDismissible: false, actions: [
					keep,
					{ label: __('Change without a redirect'), onClick: function () { choose('no').then(function () { notices.createNotice('info', __('No redirect will be created for this change.'), { type: 'snackbar', id: 'rfy-url-choice' }); }); } }
				] });
			} else {
				notices.createNotice('error', lead + ' ' + __('Without a redirect all of that breaks.') + '  ' + sprintf(__('was %1$s — now %2$s'), was, now), { id: 'rfy-url', isDismissible: false, actions: [
					{ label: __('Create 301 redirect'), variant: 'primary', onClick: function () { choose('yes').then(function () { notices.createNotice('success', __('A 301 redirect will be created when you update.'), { type: 'snackbar', id: 'rfy-url-choice' }); }); } },
					keep,
					{ label: __('Change anyway'), onClick: function () { choose('').catch(function () {}); notices.removeNotice('rfy-url'); } }
				] });
			}
		}).catch(function () {});
	}

	// After an update of an already published post (no pre-publish step), say so when a check fails.
	var wasSaving = false;
	wp.data.subscribe(function () {
		var ed = editor();
		if (!ed || !ed.getCurrentPostId) { return; }
		var slug = ed.getEditedPostAttribute('slug');
		if (slug !== lastSlug) {
			lastSlug = slug;
			clearTimeout(slugTimer);
			slugTimer = setTimeout(urlNotice, 700);
		}
		var saving = ed.isSavingPost() && !ed.isAutosavingPost();
		if (wasSaving && !saving) { wp.data.dispatch('core/notices').removeNotice('rfy-url'); }
		if (wasSaving && !saving && ed.didPostSaveRequestSucceed() && ed.getCurrentPostAttribute('status') === 'publish') {
			runCheck().then(function (res) {
				var notices = wp.data.dispatch('core/notices');
				var bad = res.checks.filter(function (c) { return c.status === 'fail' || c.status === 'warn'; });
				if (!bad.length) { notices.removeNotice('rfy-guard'); return; }
				notices.createNotice(res.counts.fail ? 'error' : 'warning', sprintf(__('AI crawler check: %s'), bad.map(function (c) { return c.title; }).join(' · ')), { id: 'rfy-guard', isDismissible: true });
			}).catch(function () {});
		}
		wasSaving = saving;
	});
})(window.wp);
