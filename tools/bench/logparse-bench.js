/**
 * JS (shipped log-worker) vs Rust→WASM log parsing, same input, same output.
 *   node tools/bench/logparse-bench.js <access.log>   (generates one if missing)
 * Build the WASM side first: (cd tools/bench/wasm-logparse && wasm-pack build --release --target nodejs)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const lw = require('../../assets/js/log-worker.js');
const reg = require('../../config/registry.json');
const file = process.argv[2] || '/tmp/rfaib-bench-access.log';

if (!fs.existsSync(file)) {
	const humans = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1', 'Mozilla/5.0 (X11; Linux x86_64; rv:142.0) Gecko/20100101 Firefox/142.0'];
	const bots = ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot', 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'python-requests/2.32.3', 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'];
	const out = fs.createWriteStream(file);
	let s = '';
	for (let i = 0; i < 1000000; i++) {
		const ua = i % 20 === 0 ? bots[i % bots.length] : humans[i % humans.length];
		s += `203.0.113.${i % 250} - - [05/Oct/2026:13:${String(i % 60).padStart(2, '0')}:${String(i % 60).padStart(2, '0')} +0000] "GET /blog/post-${i % 5000}/?ref=${i} HTTP/1.1" ${i % 37 ? 200 : 404} ${1000 + i % 9000} "https://example.com/" "${ua}"\n`;
		if (s.length > 1 << 20) { out.write(s); s = ''; }
	}
	out.end(s);
	out.on('finish', run);
} else { run(); }

function chunks(text, size) {
	const out = [];
	for (let i = 0; i < text.length;) {
		let j = Math.min(text.length, i + size);
		const nl = text.lastIndexOf('\n', j);
		if (nl > i && j < text.length) { j = nl + 1; }
		out.push(text.slice(i, j));
		i = j;
	}
	return out;
}

function run() {
	const text = fs.readFileSync(file, 'utf8');
	const mb = Buffer.byteLength(text) / 1048576;
	const parts = chunks(text, 8 << 20);
	const patterns = reg.bots.flatMap((b) => b.patterns || []);
	const keep = lw.makeFilter({ patterns, heuristics: reg.heuristics, referrers: [] });
	const js = () => {
		let kept = 0, payload = 0;
		for (const c of parts) {
			const batch = [];
			for (const line of c.split('\n')) {
				const r = lw.parseLine(line);
				if (r && keep(r.ua, '')) { batch.push(r); }
			}
			kept += batch.length;
			payload += JSON.stringify(batch).length; // what postMessage/fetch would carry
		}
		return kept;
	};
	let wasm = null;
	try {
		const { Parser } = require('./wasm-logparse/pkg/wasm_logparse.js');
		const p = new Parser(patterns.join('\n'), reg.heuristics.bot);
		wasm = () => { let kept = 0; for (const c of parts) { kept += JSON.parse(p.parse_chunk(c)).length; } return kept; };
		wasm.countOnly = () => { let kept = 0; for (const c of parts) { kept += p.count_chunk(c); } return kept; };
	} catch (e) { console.log('WASM build not found; JS only.', e.message); }
	const time = (label, fn) => {
		fn(); // warm-up
		const runs = [];
		let kept = 0;
		for (let i = 0; i < 3; i++) { const t = process.hrtime.bigint(); kept = fn(); runs.push(Number(process.hrtime.bigint() - t) / 1e6); }
		runs.sort((a, b) => a - b);
		console.log(`${label.padEnd(26)} median ${runs[1].toFixed(0).padStart(6)} ms   ${(mb / (runs[1] / 1000)).toFixed(0).padStart(4)} MB/s   kept ${kept}`);
		return runs[1];
	};
	console.log(`input: ${(mb).toFixed(0)} MB, ${text.split('\n').length - 1} lines, node ${process.version}`);
	const a = time('JS (log-worker.js)', js);
	if (wasm) {
		const b = time('Rust → WASM (regex crate)', wasm);
		const c = time('WASM, count only', wasm.countOnly);
		console.log(`WASM / JS time ratio: ${(b / a).toFixed(2)} (best case, count only: ${(c / a).toFixed(2)})`);
	}
}
