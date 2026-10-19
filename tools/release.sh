#!/bin/bash
# Release the current working tree as a new version on WordPress.org.
# Usage: tools/release.sh 1.2.0        (after bumping Version, RFYAI_VERSION,
#                                       Stable tag and the changelog yourself)
# Stages trunk + tags/<ver> in ~/Projects/rankyfy/wordpressplugin/svn, then
# tells you to run commit.sh. Never commits by itself.
set -e
VER="$1"
[ -n "$VER" ] || { echo "usage: tools/release.sh <version>"; exit 1; }
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)"
SVN="$HOME/Projects/rankyfy/wordpressplugin/svn"
grep -q "Version:           $VER" "$PLUGIN/rankyfy-ai-seo.php" || { echo "Version header is not $VER"; exit 1; }
grep -q "Stable tag: $VER" "$PLUGIN/readme.txt" || { echo "Stable tag is not $VER"; exit 1; }
grep -q "= $VER =" "$PLUGIN/readme.txt" || { echo "No changelog entry for $VER"; exit 1; }
DIST="$(mktemp -d)/rankyfy-ai-seo"
mkdir -p "$DIST"
rsync -a --delete --exclude-from="$PLUGIN/.distignore" --exclude=.git "$PLUGIN/" "$DIST/"
docker run --rm -v "$SVN":/svnwork -v "$DIST":/dist:ro debian:stable bash -c "
set -e
apt-get update -qq >/dev/null && apt-get install -y -qq subversion rsync ca-certificates >/dev/null 2>&1
cd /svnwork/rankyfy-ai-seo
svn update -q
rsync -a --delete --exclude='.svn' /dist/ trunk/
svn add -q --force trunk 2>/dev/null || true
svn status trunk | grep '^!' | awk '{print \$2}' | xargs -r svn delete -q
[ -d tags/$VER ] || svn cp -q trunk tags/$VER
svn status | tail -5; echo 'total:' \$(svn status | wc -l) 'changes staged'
chown -R $(id -u):$(id -g) /svnwork"
echo
echo "Staged. Now run:  $SVN/commit.sh"
