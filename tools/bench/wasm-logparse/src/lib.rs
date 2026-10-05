//! Benchmark only (not shipped): the log-worker's parse + filter in Rust,
//! compiled to WebAssembly, to measure whether WASM beats the JS worker.
use regex::Regex;
use wasm_bindgen::prelude::*;

#[wasm_bindgen]
pub struct Parser {
    line: Regex,
    tokens: Regex,
    generic: Regex,
}

#[wasm_bindgen]
impl Parser {
    #[wasm_bindgen(constructor)]
    pub fn new(patterns: &str, generic: &str) -> Parser {
        let mut toks: Vec<String> = patterns.split('\n').filter(|s| !s.is_empty()).map(regex::escape).collect();
        toks.sort_by(|a, b| b.len().cmp(&a.len()));
        Parser {
            line: Regex::new(r#"^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3}) \S+(?: "((?:[^"\\]|\\.)*)" "((?:[^"\\]|\\.)*)")?"#).unwrap(),
            tokens: Regex::new(&format!("(?i){}", toks.join("|"))).unwrap(),
            generic: Regex::new(&format!("(?i){generic}")).unwrap(),
        }
    }

    /// Best case for WASM: parse and filter only, return a count (no records cross the boundary).
    pub fn count_chunk(&self, chunk: &str) -> u32 {
        let mut n = 0;
        for l in chunk.split('\n') {
            if let Some(c) = self.line.captures(l) {
                let ua = c.get(7).map(|m| m.as_str()).unwrap_or("");
                if self.tokens.is_match(ua) || self.generic.is_match(ua) {
                    n += 1;
                }
            }
        }
        n
    }

    /// Parse a chunk of complete lines; return the kept records as JSON (what the worker posts).
    pub fn parse_chunk(&self, chunk: &str) -> String {
        let mut out = Vec::new();
        for l in chunk.split('\n') {
            if let Some(c) = self.line.captures(l) {
                let ua = c.get(7).map(|m| m.as_str()).unwrap_or("");
                if self.tokens.is_match(ua) || self.generic.is_match(ua) {
                    out.push(serde_json::json!({
                        "ip": &c[1], "time": &c[2], "method": &c[3], "path": &c[4],
                        "status": c[5].parse::<u16>().unwrap_or(0), "ua": ua,
                        "referer": c.get(6).map(|m| m.as_str()).unwrap_or(""),
                    }));
                }
            }
        }
        serde_json::to_string(&out).unwrap()
    }
}
