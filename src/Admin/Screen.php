<?php
/**
 * Base class of classic admin screens with Post/Redirect/Get form handling.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\DomainError;
use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Services;

/**
 * Write actions go through `admin-post.php?action=trmz_<name>`: {@see Screen::handle()} checks the capability
 * (`trmz_manage_bookings`) and the nonce (`trmz_<name>`), runs the handler and returns the URL to redirect to;
 * messages travel in {@see Notices}. Handlers never print anything.
 */
abstract class Screen implements AdminPage {

	/**
	 * Write actions of the screen: action name (without the `trmz_` prefix) => handler method name.
	 * A handler receives the unslashed request (`$_POST` + `$_GET`) and returns the redirect URL.
	 *
	 * @return array<string, string>
	 */
	abstract protected function actions(): array;

	/**
	 * Prints the body of the current view (inside `.wrap`, after the notices).
	 */
	abstract protected function render_view(): void;

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		foreach ( array_keys( $this->actions() ) as $action ) {
			add_action(
				'admin_post_trmz_' . $action,
				function () use ( $action ): void {
					$this->dispatch( $action );
				}
			);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'terminarz' ), 403 );
		}

		echo '<div class="wrap trmz-admin">';
		$this->render_view();
		echo '</div>';
	}

	/**
	 * `admin_post_trmz_<action>` callback: handles the request and redirects.
	 *
	 * @param string $action Action name.
	 */
	public function dispatch( string $action ): void {
		wp_safe_redirect( $this->handle( $action ) );
		exit;
	}

	/**
	 * Verifies capability and nonce, runs the handler, returns the redirect URL. `wp_die()` on failed checks.
	 *
	 * @param string $action Action name (a key of actions()).
	 */
	public function handle( string $action ): string {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'terminarz' ), 403 );
		}
		check_admin_referer( 'trmz_' . $action );

		$method = $this->actions()[ $action ] ?? null;
		if ( null === $method || ! method_exists( $this, $method ) ) {
			wp_die( esc_html__( 'Unknown action.', 'terminarz' ), 400 );
		}

		// Values are sanitized field by field in the handlers (Input helpers).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Nonce verified above; sanitized per field.
		$request = wp_unslash( array_merge( $_GET, $_POST ) );
		$request = is_array( $request ) ? $request : array();

		try {
			$url = $this->{$method}( $request );
		} catch ( DomainError | DatabaseError $e ) {
			Notices::error( __( 'The change could not be saved. Please check the data and try again.', 'terminarz' ) );
			$url = $this->url();
		}

		return is_string( $url ) ? $url : $this->url();
	}

	/**
	 * URL of this screen with query arguments.
	 *
	 * @param array<string, int|string> $args Query arguments.
	 */
	public function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $this->slug() ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Nonce-protected `admin-post.php` URL of a write action (for links; forms use {@see Screen::form_fields()}).
	 *
	 * @param string                    $action Action name.
	 * @param array<string, int|string> $args   Extra query arguments.
	 */
	public function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => 'trmz_' . $action ), $args ), admin_url( 'admin-post.php' ) ),
			'trmz_' . $action
		);
	}

	/**
	 * Opening tag of a POST form targeting a write action, with its hidden fields.
	 *
	 * @param string $action Action name.
	 * @param string $id     Form element ID.
	 */
	protected function form_open( string $action, string $id = '' ): void {
		printf(
			'<form method="post" action="%1$s"%2$s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			'' === $id ? '' : ' id="' . esc_attr( $id ) . '"'
		);
		$this->form_fields( $action );
	}

	/**
	 * Hidden `action` field and nonce of a write action.
	 *
	 * @param string $action Action name.
	 */
	protected function form_fields( string $action ): void {
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( 'trmz_' . $action ) );
		wp_nonce_field( 'trmz_' . $action );
	}

	/**
	 * Current view (`action` query argument of the screen URL).
	 */
	protected function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		return isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
	}

	/**
	 * Integer query argument of the current request (read-only views).
	 *
	 * @param string $key Name.
	 */
	protected function query_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	/**
	 * Text query argument of the current request (read-only views).
	 *
	 * @param string $key Name.
	 */
	protected function query_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Composition root.
	 */
	protected function services(): Services {
		return Services::instance();
	}

	/**
	 * Prints the page heading and the notices.
	 *
	 * @param string $title  Heading.
	 * @param string $action Optional "Add new" style link: URL.
	 * @param string $label  Link label.
	 */
	protected function heading( string $title, string $action = '', string $label = '' ): void {
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( '' !== $action ) {
			echo ' <a href="' . esc_url( $action ) . '" class="page-title-action">' . esc_html( $label ) . '</a>';
		}
		echo '<hr class="wp-header-end" />';
		Notices::render();
	}

	/**
	 * Loads WP_List_Table (not autoloaded by WordPress).
	 */
	protected static function load_list_table(): void {
		if ( ! class_exists( '\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
	}
}
