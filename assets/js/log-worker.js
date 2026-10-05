/**
 * Access-log reader (Web Worker). Reads a log file in the site owner's
 * browser — the file is never uploaded — and posts only the lines from
 * automated clients (plus visits referred by AI assistants) back to the page,
 * which sends them to the site in batches. The server re-validates and
 * re-classifies every line; nothing here is trusted.
 *
 * Formats: Apache/Nginx "combined" (optionally with a trailing request time)
 * and JSON lines (Cloudflare Logpush, Fastly, generic). Gzip via
 * DecompressionStream.
 *
 * The parsing core (makeFilter, parseLine) is plain functions so it can be
 * benchmarked in Node against alternatives — see tools/bench/.
 */
(function (global) {
	'use strict';

	var COMBINED = /^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+(?: "((?:[^"\\]|\\.)*)" "((?:[^"\\]|\\.)*)")?(?: (\d+(?:\.\d+)?))?/;
	var MONTHS = { Jan: 0, Feb: 1, Mar: 2, Apr: 3, May: 4, Jun: 5, Jul: 6, Aug: 7, Sep: 8, Oct: 9, Nov: 10, Dec: 11 };
	var DEFAULT_BOT = 'bot\\b|bot/|crawl|spider|slurp|scrap|fetcher|archiver|indexer|python-|python/|curl/|wget|go-http-client|java/|okhttp|axios|node-fetch|undici|headless|phantomjs|httpclient|libwww|scrapy|aiohttp|httpx|guzzle|^mozilla/5\\.0$|^$';

	function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

	/** Which lines are worth sending: crawler user agents, or visits from AI assistants. */
	function makeFilter(registry) {
		var pats = (registry.patterns || []).filter(Boolean).sort(function (a, b) { return b.length - a.length; }).map(escapeRe);
		var tokens = pats.length ? new RegExp(pats.join('|'), 'i') : null;
		var generic = new RegExp((registry.heuristics && registry.heuristics.bot) || DEFAULT_BOT, 'i');
		var hosts = {};
		(registry.referrers || []).forEach(function (h) { hosts[String(h).toLowerCase()] = true; });
		return function (ua, referer) {
			if ((tokens && tokens.test(ua)) || generic.test(ua)) { return true; }
			if (referer) {
				var m = /^https?:\/\/([^/:?#]+)/i.exec(referer);
				if (m && hosts[m[1].toLowerCase()]) { return true; }
			}
			return false;
		};
	}

	function clfTime(s) {
		// 10/Oct/2026:13:55:36 +0000
		var m = /^(\d{2})\/(\w{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-])(\d{2})(\d{2})$/.exec(s);
		if (!m || MONTHS[m[2]] === undefined) { return 0; }
		var t = Date.UTC(+m[3], MONTHS[m[2]], +m[1], +m[4], +m[5], +m[6]) / 1000;
		var off = (+m[8] * 60 + +m[9]) * 60;
		return Math.floor(m[7] === '+' ? t - off : t + off);
	}

	function pick(o, keys) {
		for (var i = 0; i < keys.length; i++) {
			var v = o[keys[i]];
			if (v !== undefined && v !== null && v !== '') { return v; }
		}
		return undefined;
	}

	function jsonTime(v) {
		if (typeof v === 'string' && /^\d+(\.\d+)?$/.test(v)) { v = Number(v); }
		if (typeof v === 'number') {
			// seconds, milliseconds, microseconds or nanoseconds since the epoch
			if (v > 1e17) { return Math.floor(v / 1e9); }
			if (v > 1e14) { return Math.floor(v / 1e6); }
			if (v > 1e11) { return Math.floor(v / 1e3); }
			return Math.floor(v);
		}
		var s = String(v || '');
		if (/^\d{2}\/\w{3}\/\d{4}:/.test(s)) { return clfTime(s); } // nginx $time_local
		var t = Date.parse(s);
		return isNaN(t) ? 0 : Math.floor(t / 1000);
	}

	/** One log line → request record, or null. */
	function parseLine(line) {
		if (!line) { return null; }
		if (line.charCodeAt(0) === 123) { // '{'
			var o;
			try { o = JSON.parse(line); } catch (e) { return null; }
			var uri = pick(o, ['ClientRequestURI', 'ClientRequestPath', 'request_uri', 'uri', 'path', 'url']);
			var rec = {
				ts: jsonTime(pick(o, ['EdgeStartTimestamp', 'timestamp', 'time', '@timestamp', 'time_local'])),
				ip: String(pick(o, ['ClientIP', 'client_ip', 'remote_addr', 'ip', 'clientIp']) || ''),
				method: String(pick(o, ['ClientRequestMethod', 'request_method', 'method']) || 'GET'),
				path: uri ? String(uri).replace(/^https?:\/\/[^/]+/i, '') : '',
				status: +pick(o, ['EdgeResponseStatus', 'OriginResponseStatus', 'status', 'response_status']) || 0,
				ua: String(pick(o, ['ClientRequestUserAgent', 'http_user_agent', 'user_agent', 'userAgent', 'ua']) || ''),
				referer: String(pick(o, ['ClientRequestReferer', 'http_referer', 'referer', 'referrer']) || '')
			};
			var ms = pick(o, ['OriginResponseDurationMs', 'request_time_ms', 'duration_ms']);
			if (ms !== undefined) { rec.ms = Math.round(+ms) || 0; }
			return rec.ts && rec.ip && rec.path ? rec : null;
		}
		var m = COMBINED.exec(line);
		if (!m) { return null; }
		return {
			ts: clfTime(m[2]),
			ip: m[1],
			method: m[3],
			path: m[4],
			status: +m[5],
			referer: m[6] && m[6] !== '-' ? m[6].replace(/\\"/g, '"') : '',
			ua: m[7] ? m[7].replace(/\\"/g, '"') : '',
			// Nginx $request_time is in seconds with a decimal point.
			ms: m[8] && m[8].indexOf('.') > 0 ? Math.round(parseFloat(m[8]) * 1000) : undefined
		};
	}

	var api = { makeFilter: makeFilter, parseLine: parseLine, clfTime: clfTime };
	if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
	if (typeof global.postMessage !== 'function' || typeof global.document !== 'undefined' || typeof module !== 'undefined') { return; }

	// ── worker side ──────────────────────────────────────────────────────────
	var inFlight = 0;
	var waiters = [];
	global.onmessage = function (e) {
		var msg = e.data || {};
		if (msg.type === 'ack') {
			inFlight = Math.max(0, inFlight - 1);
			var w = waiters.shift();
			if (w) { w(); }
			return;
		}
		if (msg.type === 'start') {
			run(msg.file, msg.batch || 2000, msg.registry || {}).catch(function (err) {
				global.postMessage({ type: 'error', message: String(err && err.message || err) });
			});
		}
	};

	function backpressure() {
		// At most three batches waiting on the server; reading pauses meanwhile.
		if (inFlight < 3) { return Promise.resolve(); }
		return new Promise(function (resolve) { waiters.push(resolve); });
	}

	async function run(file, batchSize, registry) {
		var keep = makeFilter(registry);
		var stream = file.stream();
		if (/\.gz$/i.test(file.name)) {
			if (typeof DecompressionStream === 'undefined') { throw new Error('This browser cannot read gzip files. Decompress the file first.'); }
			stream = stream.pipeThrough(new DecompressionStream('gzip'));
		}
		var reader = stream.pipeThrough(new TextDecoderStream()).getReader();
		var rest = '', lines = 0, bytes = 0, batch = [], lastReport = 0, noUa = 0;
		var MAX_LINE = 1 << 20; // a "line" longer than 1 MB is not a log line
		var total = file.size || 1;
		async function flush() {
			if (!batch.length) { return; }
			await backpressure();
			inFlight++;
			global.postMessage({ type: 'batch', lines: batch });
			batch = [];
		}
		for (;;) {
			var r = await reader.read();
			if (r.done) { break; }
			bytes += r.value.length;
			var parts = (rest + r.value).split('\n');
			rest = parts.pop();
			if (rest.length > MAX_LINE) { rest = ''; }
			for (var i = 0; i < parts.length; i++) {
				lines++;
				var rec = parseLine(parts[i].replace(/\r$/, ''));
				if (rec && !rec.ua && !rec.referer) { noUa++; continue; } // nothing to classify by
				if (rec && keep(rec.ua, rec.referer)) {
					batch.push(rec);
					if (batch.length >= batchSize) { await flush(); }
				}
			}
			var now = Date.now();
			if (now - lastReport > 250) {
				lastReport = now;
				// Compressed files: bytes counts decoded text, so progress is approximate.
				global.postMessage({ type: 'progress', pct: Math.min(99, Math.round(100 * bytes / total / (/\.gz$/i.test(file.name) ? 8 : 1))), lines: lines });
			}
		}
		if (rest) {
			lines++;
			var last = parseLine(rest);
			if (last && keep(last.ua, last.referer)) { batch.push(last); }
		}
		await flush();
		global.postMessage({ type: 'done', lines: lines, noUa: noUa });
	}
})(typeof self !== 'undefined' ? self : this);
