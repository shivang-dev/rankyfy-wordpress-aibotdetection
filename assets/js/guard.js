/**
 * RankyFy AI Crawler Monitor — publish-time guard for the block editor.
 *
 * Plain wp.* globals, no build step. Three places:
 *   - pre-publish panel: checks the unsaved post; in "confirm" mode critical
 *     problems hold the Publish button until they are acknowledged;
 *   - document sidebar panel: the same check on demand (updates of published
 *     posts have no pre-publish step), plus the redirects that point here;
 *   - after an update of a published post: a notice when a check fails.
 */
(function (wp) {
	'use strict';
	if (!wp || !wp.plugins || !wp.data || !wp.element || !wp.apiFetch || !wp.components) { return; }

	var cfg = window.RFAIB_GUARD || {};
	var D = 'rankyfy-ai-crawlers';
	var el = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect;
	var __ = function (s) { return wp.i18n.__(s, D); };
	var sprintf = wp.i18n.sprintf;
	var C = wp.components;
	var PrePublish = (wp.editor && wp.editor.PluginPrePublishPanel) || (wp.editPost && wp.editPost.PluginPrePublishPanel);
	var DocPanel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	var LOCK = cfg.lock || 'rfaib-guard';

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
		wp.data.dispatch('core/notices').createNotice('info', __('The old URL is back. Nothing will need a redirect.'), { type: 'snackbar', id: 'rfaib-guard-slug' });
		if (after) { setTimeout(after, 50); }
	}

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

	/** Observed: what AI crawlers did with this post's live URL. */
	function Crawl(props) {
		var c = props.crawl;
		if (!c) { return null; }
		if (!c.hits) { return el('p', { style: { color: '#646970', margin: '8px 0' } }, __('No AI crawler has read this page yet.')); }
		return el('div', { style: { margin: '10px 0' } },
			el('strong', null, sprintf(__('AI crawlers on this page: %d requests'), c.hits)),
			el('ul', { style: { margin: '4px 0 0' } }, c.by_bot.slice(0, 3).map(function (b) {
				return el('li', { key: b.name }, b.name + ' — ' + b.hits);
			})),
			c.referrals ? el('div', { style: { color: '#00702a' } }, sprintf(__('%d visits from AI assistants in 30 days'), c.referrals)) : null);
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
		useEffect(function () { if (auto) { go(); } }, []);
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

	function SidebarBody() {
		var chk = useCheck(true);
		return el('div', null,
			chk.error ? el(C.Notice, { status: 'warning', isDismissible: false }, chk.error) : null,
			chk.result ? el(Results, { result: chk.result, onChange: chk.run }) : (chk.busy ? el(C.Spinner) : null),
			chk.result ? el(Crawl, { crawl: chk.result.crawl }) : null,
			chk.result ? el(Redirects, { items: chk.result.redirects }) : null,
			el(C.Button, { variant: 'secondary', onClick: chk.run, isBusy: chk.busy, disabled: chk.busy }, __('Check now')));
	}

	function title(res) {
		if (!res) { return __('AI crawler check'); }
		return res.counts.fail ? sprintf(__('AI crawler check: %d problem(s)'), res.counts.fail) : __('AI crawler check');
	}

	wp.plugins.registerPlugin('rfaib-guard', {
		render: function () {
			return el(wp.element.Fragment, null,
				PrePublish ? el(PrePublish, { title: title(null), initialOpen: true, className: 'rfaib-guard' }, el(PrePublishBody)) : null,
				DocPanel ? el(DocPanel, { name: 'rfaib-guard', title: __('AI crawler check'), className: 'rfaib-guard' }, el(SidebarBody)) : null);
		}
	});

	// After an update of an already published post (no pre-publish step), say so when a check fails.
	var wasSaving = false;
	wp.data.subscribe(function () {
		var ed = editor();
		if (!ed || !ed.getCurrentPostId) { return; }
		var saving = ed.isSavingPost() && !ed.isAutosavingPost();
		if (wasSaving && !saving && ed.didPostSaveRequestSucceed() && ed.getCurrentPostAttribute('status') === 'publish') {
			runCheck().then(function (res) {
				var notices = wp.data.dispatch('core/notices');
				var bad = res.checks.filter(function (c) { return c.status === 'fail' || c.status === 'warn'; });
				if (!bad.length) { notices.removeNotice('rfaib-guard'); return; }
				notices.createNotice(res.counts.fail ? 'error' : 'warning', sprintf(__('AI crawler check: %s'), bad.map(function (c) { return c.title; }).join(' · ')), { id: 'rfaib-guard', isDismissible: true });
			}).catch(function () {});
		}
		wasSaving = saving;
	});
})(window.wp);
