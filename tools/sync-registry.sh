#!/usr/bin/env sh
# Copy the crawler registry into the RankyFy backend (one source of truth).
#   tools/sync-registry.sh [path/to/contentai]
# Edit config/registry.json here, bump "version", run this, then run the
# backend's tests (cargo test --lib aibotdetection) and deploy the service.
set -eu
here=$(cd "$(dirname "$0")/.." && pwd)
backend=${1:-"$here/../../contentai"}
target="$backend/src/aibotdetection/registry.json"
[ -f "$target" ] || { echo "not found: $target" >&2; exit 1; }
python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$here/config/registry.json"
cp "$here/config/registry.json" "$target"
echo "copied registry $(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['version'])" "$target") -> $target"
