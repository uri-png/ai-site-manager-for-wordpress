#!/usr/bin/env bash
# Build the distributable plugin ZIP (excludes tests/, docs and repo files).
# Usage: ./build-zip.sh   ->   dist/ai-site-manager-<version>.zip
set -euo pipefail

cd "$(dirname "$0")"

VERSION=$(grep -m1 'Version:' ai-site-manager.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
	echo "Could not read plugin version from ai-site-manager.php" >&2
	exit 1
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/ai-site-manager" dist

# Plugin files only - no tests/, no repo metadata.
cp ai-site-manager.php uninstall.php readme.txt README.md "$STAGE/ai-site-manager/"
cp -r admin assets includes "$STAGE/ai-site-manager/"

OUT="dist/ai-site-manager-${VERSION}.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OLDPWD/$OUT" ai-site-manager )

echo "Built $OUT"
