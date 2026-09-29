#!/usr/bin/env bash
# Installs the release ZIP on a clean WordPress (wp-env, .wp-env.zip.json, port 8890, no WooCommerce) and checks that
# it activates, works and uninstalls without PHP notices (ADR-049).
# Usage: bin/test-zip-install.sh dist/terminarz-<version>.zip
set -euo pipefail

zip_file="${1:?Usage: bin/test-zip-install.sh <zip>}"
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"
case "$(cd "$(dirname "$zip_file")" && pwd)" in
	"$root/dist") ;;
	*) echo "The ZIP must be in dist/ (mapped into the container)." >&2; exit 1 ;;
esac
name="$(basename "$zip_file")"
version="${name#terminarz-}"
version="${version%.zip}"
config=.wp-env.zip.json

wp() {
	npx wp-env run --config="$config" cli wp "$@"
}

npx wp-env start --config="$config"
wp plugin delete terminarz >/dev/null 2>&1 || true
npx wp-env run --config="$config" cli bash -c 'rm -f wp-content/debug.log'

wp plugin install "wp-content/terminarz-dist/$name" --activate
wp plugin is-active terminarz
wp eval "
if ( TRMZ_VERSION !== '$version' ) { WP_CLI::error( 'Version ' . TRMZ_VERSION ); }
if ( ! Terminarz\Plugin::instance()->is_booted() ) { WP_CLI::error( 'Plugin not booted.' ); }
if ( ! current_user_can( 'exist' ) && ! get_role( 'administrator' )->has_cap( 'trmz_manage_bookings' ) ) { WP_CLI::error( 'Capability missing.' ); }
global \$wpdb;
foreach ( Terminarz\Infrastructure\Database\Schema::from_globals()->tables() as \$table ) {
	if ( \$table !== \$wpdb->get_var( \$wpdb->prepare( 'SHOW TABLES LIKE %s', \$table ) ) ) { WP_CLI::error( 'Missing table ' . \$table ); }
}
\$response = rest_do_request( new WP_REST_Request( 'GET', '/terminarz/v1/services' ) );
if ( 200 !== \$response->get_status() ) { WP_CLI::error( 'GET /services: ' . \$response->get_status() ); }
if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'terminarz/booking' ) ) { WP_CLI::error( 'Block not registered.' ); }
WP_CLI::success( 'Terminarz ' . TRMZ_VERSION . ' works.' );
"
# Front end and admin render without notices (block page and settings screen).
page_id="$(wp post create --post_type=page --post_status=publish --post_title='ZIP test' --post_content='<!-- wp:terminarz/booking /-->' --porcelain | tr -dc '0-9')"
page="$(curl -fsSL "http://localhost:8890/?page_id=$page_id")"
if ! printf '%s' "$page" | grep -q 'class="trmz-booking__config"'; then
	echo "The booking block is not rendered on the front end." >&2
	exit 1
fi
if ! printf '%s' "$page" | grep -q 'plugins/terminarz/build/booking/view.js'; then
	echo "The block script is not enqueued." >&2
	exit 1
fi

# Uninstall with data removal: files, tables and options are gone.
wp option patch insert trmz_settings delete_data_on_uninstall 1 >/dev/null 2>&1 || wp option update trmz_settings '{"delete_data_on_uninstall":true}' --format=json
wp plugin deactivate terminarz
wp plugin uninstall terminarz
wp eval "
global \$wpdb;
if ( \$wpdb->get_var( \"SHOW TABLES LIKE '{\$wpdb->prefix}trmz_%'\" ) ) { WP_CLI::error( 'Tables left after uninstall.' ); }
if ( false !== get_option( 'trmz_settings' ) ) { WP_CLI::error( 'Options left after uninstall.' ); }
WP_CLI::success( 'Uninstalled cleanly.' );
"

log="$(npx wp-env run --config="$config" cli bash -c 'cat wp-content/debug.log 2>/dev/null || true')"
if printf '%s\n' "$log" | grep -E 'PHP (Fatal|Warning|Notice|Deprecated|Parse)' >/dev/null; then
	printf '%s\n' "$log" >&2
	echo "PHP problems in debug.log." >&2
	exit 1
fi
echo "ZIP install test passed ($name)."
