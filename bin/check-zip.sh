#!/usr/bin/env bash
# Checks the contents of a release ZIP (ADR-049): one top-level `terminarz/` directory, runtime files present,
# no development files. Usage: bin/check-zip.sh dist/terminarz-<version>.zip
set -euo pipefail

zip_file="${1:?Usage: bin/check-zip.sh <zip>}"
listing="$(unzip -Z1 "$zip_file")"
status=0

if printf '%s\n' "$listing" | grep -v '^terminarz/' >/dev/null; then
	echo "Entries outside terminarz/:" >&2
	printf '%s\n' "$listing" | grep -v '^terminarz/' >&2
	status=1
fi

for required in \
	terminarz/terminarz.php \
	terminarz/uninstall.php \
	terminarz/readme.txt \
	terminarz/vendor/autoload.php \
	terminarz/src/Plugin.php \
	terminarz/build/booking/block.json \
	terminarz/build/booking/view.js \
	terminarz/build/booking/index.js \
	terminarz/languages/terminarz.pot \
	terminarz/languages/terminarz-pl_PL.mo \
	terminarz/languages/terminarz-pl_PL.l10n.php; do
	if ! printf '%s\n' "$listing" | grep -qx "$required"; then
		echo "Missing: $required" >&2
		status=1
	fi
done
if ! printf '%s\n' "$listing" | grep -q '^terminarz/languages/terminarz-pl_PL-[0-9a-f]\{32\}\.json$'; then
	echo "Missing: JSON translations of the block scripts" >&2
	status=1
fi

forbidden='^terminarz/(tests|node_modules|blocks|docs|bin|artifacts|playwright-report|\.github|\.claude|\.git)/|^terminarz/vendor/(phpunit|phpstan|squizlabs|wp-coding-standards|php-stubs|yoast|wp-cli|szepeviktor|dealerdirect|phpcompatibility)/|/mu-plugins/|^terminarz/(composer\.(json|lock)|package(-lock)?\.json|phpcs\.xml\.dist|phpstan\.neon\.dist|phpunit[^/]*\.xml\.dist|playwright\.config\.js|webpack\.config\.js|eslint\.config\.cjs|CLAUDE\.md|PROGRESS\.md|EPIC_ISSUE\.md|README\.md|\.wp-env[^/]*\.json|\.distignore|\.gitignore|\.editorconfig)$'
if printf '%s\n' "$listing" | grep -E "$forbidden" >/dev/null; then
	echo "Development files in the ZIP:" >&2
	printf '%s\n' "$listing" | grep -E "$forbidden" | head -20 >&2
	status=1
fi

if [ "$status" -eq 0 ]; then
	echo "ZIP OK: $(printf '%s\n' "$listing" | grep -vc '/$') files."
fi
exit "$status"
