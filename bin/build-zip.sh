#!/usr/bin/env bash
# Builds the installable release ZIP: dist/terminarz-<version>.zip with a single top-level `terminarz/` directory
# (ADR-049). Requires built assets (`npm ci && npm run build`) and compiled translations (committed in languages/).
#
# The working tree is copied without the paths listed in .distignore; production Composer dependencies are installed
# into the copy (`composer install --no-dev -o`), so the developer's vendor/ stays untouched.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' terminarz.php | head -n 1 | tr -d '[:space:]')"
if [ -z "$version" ]; then
	echo "Cannot read the plugin version from terminarz.php." >&2
	exit 1
fi
for required in build/booking/block.json build/booking/view.js build/booking/index.js; do
	if [ ! -f "$required" ]; then
		echo "Missing $required — run 'npm ci && npm run build' first." >&2
		exit 1
	fi
done

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT
target="$staging/terminarz"
mkdir -p "$target" dist

# .distignore: one pattern per line; lines starting with "/" are anchored at the plugin root.
excludes=()
while IFS= read -r line || [ -n "$line" ]; do
	case "$line" in '' | \#*) continue ;; esac
	excludes+=("--exclude=$line")
done < .distignore
rsync -a "${excludes[@]}" ./ "$target/"

cp composer.json composer.lock "$target/"
composer install --working-dir="$target" --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet
rm -f "$target/composer.json" "$target/composer.lock"

zip_file="$root/dist/terminarz-$version.zip"
rm -f "$zip_file"
(cd "$staging" && zip -qr -X "$zip_file" terminarz)

echo "$zip_file"
