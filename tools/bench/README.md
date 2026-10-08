# Benchmarks (not shipped)

Results and the decisions they drove are in [DEVELOPMENT.md](../../DEVELOPMENT.md#where-each-piece-runs-and-why).

| Script | Measures |
|---|---|
| `scale.php` | Generates N crawler requests over P pages (time-ordered), then times aggregation and every dashboard query. `RFY_ALLOW_DESTRUCTIVE_TESTS=1 wp eval-file tools/bench/scale.php 1000000 5000 [reuse]` — wipes the plugin's tables. |
| `overhead.py` | Page-view cost, plugin on vs. off, interleaved, plus DB queries per request. Needs a disposable site on `localhost:8095` and a mu-plugin that logs `$wpdb->num_queries` for requests carrying `X-Bench` (see the script). `python3 tools/bench/overhead.py <compose-dir>` |
| `logparse-bench.js` + `wasm-logparse/` | The browser log parser (JS, shipped) against the same parser in Rust compiled to WebAssembly. Build: `(cd wasm-logparse && wasm-pack build --release --target nodejs)`, then `node logparse-bench.js [access.log]` (generates a 1M-line log if none is given). |
