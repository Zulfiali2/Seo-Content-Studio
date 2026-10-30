#!/usr/bin/env bash
# Build the installable plugin ZIP (Plugins → Add New → Upload Plugin).
# Usage: bin/build-zip.sh [output-dir]   (default: dist/)
# Excludes tests, development docs, VCS data and build tooling.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="ai-seo-content-studio"
version="$(sed -n "s/^define( 'AISCS_VERSION', '\([^']*\)' );$/\1/p" "$root/$slug.php")"
out="${1:-$root/dist}"
mkdir -p "$out"
stage="$(mktemp -d)"
trap 'rm -rf -- "$stage"' EXIT

mkdir "$stage/$slug"
( cd "$root" && tar cf - \
	--exclude='./.git' --exclude='./dist' --exclude='./tests' --exclude='./bin' \
	--exclude='./node_modules' --exclude='./src' --exclude='./DEVELOPMENT_STATUS.md' \
	--exclude='*.log' --exclude='.DS_Store' . ) | ( cd "$stage/$slug" && tar xf - )

zip_path="$out/$slug-$version.zip"
rm -f -- "$zip_path"
( cd "$stage" && zip -qr "$zip_path" "$slug" )
echo "$zip_path"
