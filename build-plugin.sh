#!/usr/bin/env bash
#
# Build an installable ZIP of the Marketing Department plugin, plus checksums.
#
# Usage:  ./build-plugin.sh
# Output: dist/abc-marketing-department-<version>.zip (+ .sha256)
#
# The installable ZIP excludes developer-only files (tests, phpunit config,
# build tooling). The full source tree — including tests — lives in the repo.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SLUG="abc-marketing-department"
SRC="$ROOT/$SLUG"
DIST="$ROOT/dist"

if [ ! -d "$SRC" ]; then
	echo "ERROR: plugin source not found at $SRC" >&2
	exit 1
fi

# Read version from the plugin header.
VERSION="$(grep -m1 -oE 'Version:\s*[0-9.]+' "$SRC/$SLUG.php" | grep -oE '[0-9.]+')"
if [ -z "${VERSION:-}" ]; then
	echo "ERROR: could not read version from plugin header" >&2
	exit 1
fi
echo "Building $SLUG v$VERSION"

# 1) PHP syntax check on every file.
echo "==> PHP syntax check"
FAILED=0
while IFS= read -r -d '' f; do
	if ! php -l "$f" >/dev/null 2>&1; then
		echo "  SYNTAX ERROR: $f"
		php -l "$f" || true
		FAILED=1
	fi
done < <(find "$SRC" -name '*.php' -print0)
if [ "$FAILED" -ne 0 ]; then
	echo "ERROR: syntax errors found; aborting build." >&2
	exit 1
fi
echo "  all PHP files OK"

# 2) Run the no-WordPress test suites.
echo "==> Running standalone test suites"
php "$SRC/tests/run-tests.php"
php "$SRC/tests/run-functional.php"
php "$SRC/tests/run-openai.php"
php "$SRC/tests/smoke-render.php"

# 3) Build the ZIP (with a top-level plugin folder), excluding dev files.
echo "==> Packaging ZIP"
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"

cd "$ROOT"
zip -rq "$ZIP" "$SLUG" \
	-x "$SLUG/tests/*" \
	-x "$SLUG/phpunit.xml.dist" \
	-x "$SLUG/.gitignore" \
	-x "*/.DS_Store" \
	-x "__MACOSX/*" \
	-x "*/node_modules/*"

# 4) Checksums + manifest.
cd "$DIST"
sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256"

echo "==> Done"
echo "  ZIP:      $ZIP"
echo "  SHA-256:  $(cat "$(basename "$ZIP").sha256")"
echo "  Size:     $(du -h "$ZIP" | cut -f1)"
