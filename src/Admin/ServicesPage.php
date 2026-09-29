<?php
/**
 * Services screen.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\Service;

/**
 * "Terminarz → Services" (`admin.php?page=trmz-services`): list, add/edit form (name, description, duration, price,
 * buffer, assigned resources, order, active), activate/deactivate, delete (refused when the service has bookings).
 */
class ServicesPage extends Screen {

	public const SLUG = 'trmz-services';

	/**
	 * Maximum length of the name (column size).
	 */
	public const NAME_MAX = 191;

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function menu_title(): string {
		return __( 'Services', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Services', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 30;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function actions(): array {
		return array(
			'save_service'   => 'save',
			'toggle_service' => 'toggle',
			'delete_service' => 'delete',
		);
	}

	/**
	 * Handler: create or update a service and its resources.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function save( array $request ): string {
		$id    = Input::absint( $request, 'id' );
		$input = array(
			'name'        => Input::text( $request, 'name' ),
			'description' => Input::textarea( $request, 'description' ),
			'duration'    => Input::text( $request, 'duration_minutes' ),
			'buffer'      => Input::text( $request, 'buffer_after_minutes' ),
			'price'       => Input::text( $request, 'price' ),
			'resources'   => Input::ids( $request, 'resources' ),
			'sort_order'  => Input::int_or_null( $request, 'sort_order' ) ?? 0,
			'is_active'   => Input::flag( $request, 'is_active' ),
		);

		$errors   = array();
		$duration = self::minutes( $input['duration'] );
		$buffer   = '' === $input['buffer'] ? 0 : self::minutes( $input['buffer'] );
		$price    = Money::parse( $input['price'] );

		if ( '' === $input['name'] ) {
			$errors[] = __( 'Enter a name.', 'terminarz' );
		} elseif ( mb_strlen( $input['name'] ) > self::NAME_MAX ) {
			$errors[] = __( 'The name is too long.', 'terminarz' );
		}
		if ( null === $duration || $duration < 1 || $duration > Service::MAX_MINUTES ) {
			$errors[] = __( 'The duration must be a whole number of minutes between 1 and 1440.', 'terminarz' );
		}
		if ( null === $buffer || $buffer > Service::MAX_MINUTES ) {
			$errors[] = __( 'The buffer must be a whole number of minutes between 0 and 1440.', 'terminarz' );
		}
		if ( null === $price ) {
			$errors[] = __( 'The price must be a non-negative amount, e.g. 150 or 150.00.', 'terminarz' );
		}

		$known = array_map( static fn( $bookable ): int => (int) $bookable->id, $this->services()->resources()->all() );
		if ( array() !== array_diff( $input['resources'], $known ) ) {
			$errors[] = __( 'Some of the selected resources do not exist.', 'terminarz' );
		}

		$back = $this->url(
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);
		if ( array() !== $errors || null === $duration || null === $buffer || null === $price ) {
			array_map( array( Notices::class, 'error' ), $errors );
			Notices::keep_input( $input );
			return $back;
		}

		try {
			$saved = $this->services()->services()->save(
				new Service( $id > 0 ? $id : null, $input['name'], $duration, $price, $buffer, $input['is_active'], $input['description'], $input['sort_order'] )
			);
			// Preference order for "any resource" = resource display order (ADR-018).
			$this->services()->services()->assign_resources( (int) $saved->id, array_values( array_intersect( $known, $input['resources'] ) ) );
		} catch ( EntityNotFound $e ) {
			Notices::error( __( 'The service does not exist.', 'terminarz' ) );
			return $this->url();
		} catch ( InvalidValue $e ) {
			Notices::error( __( 'The service data is not valid.', 'terminarz' ) );
			Notices::keep_input( $input );
			return $back;
		}

		Notices::success( $id > 0 ? __( 'Service updated.', 'terminarz' ) : __( 'Service added.', 'terminarz' ) );
		if ( $saved->is_active && array() === $input['resources'] ) {
			Notices::warning( __( 'No resource performs this service yet, so customers cannot book it.', 'terminarz' ) );
		}
		return $this->url(
			array(
				'view' => 'edit',
				'id'   => (int) $saved->id,
			)
		);
	}

	/**
	 * Handler: activate (`active=1`) or deactivate (`active=0`) a service.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function toggle( array $request ): string {
		$id      = Input::absint( $request, 'id' );
		$active  = Input::flag( $request, 'active' );
		$service = $this->services()->services()->get( $id );
		if ( null === $service ) {
			Notices::error( __( 'The service does not exist.', 'terminarz' ) );
			return $this->url();
		}

		$this->services()->services()->save( $service->with_active( $active ) );
		Notices::success( $active ? __( 'Service activated.', 'terminarz' ) : __( 'Service deactivated. It is no longer offered to customers; existing bookings stay valid.', 'terminarz' ) );
		return $this->url();
	}

	/**
	 * Handler: delete a service (refused when it has bookings).
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function delete( array $request ): string {
		$id = Input::absint( $request, 'id' );
		if ( null === $this->services()->services()->get( $id ) ) {
			Notices::error( __( 'The service does not exist.', 'terminarz' ) );
			return $this->url();
		}

		try {
			$this->services()->services()->delete( $id );
		} catch ( EntityInUse $e ) {
			Notices::error( __( 'This service has bookings and cannot be deleted. Deactivate it instead.', 'terminarz' ) );
			return $this->url();
		}

		Notices::success( __( 'Service deleted.', 'terminarz' ) );
		return $this->url();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_view(): void {
		if ( 'edit' === $this->current_view() ) {
			$this->render_form();
			return;
		}

		$this->heading( __( 'Services', 'terminarz' ), $this->url( array( 'view' => 'edit' ) ), __( 'Add service', 'terminarz' ) );
		self::load_list_table();
		$table = new ServicesListTable( $this );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Parses a whole number of minutes.
	 *
	 * @param string $value Input.
	 */
	private static function minutes( string $value ): ?int {
		return 1 === preg_match( '/^\d{1,5}$/', trim( $value ) ) ? (int) $value : null;
	}

	/**
	 * Add/edit form.
	 */
	private function render_form(): void {
		$id      = $this->query_int( 'id' );
		$service = $id > 0 ? $this->services()->services()->get( $id ) : null;
		if ( $id > 0 && null === $service ) {
			$this->heading( __( 'Edit service', 'terminarz' ) );
			echo '<p>' . esc_html__( 'The service does not exist.', 'terminarz' ) . '</p>';
			return;
		}

		$old      = Notices::take_input() ?? array();
		$assigned = null === $service ? array() : $this->services()->services()->resource_ids( (int) $service->id );
		$values   = array(
			'name'        => (string) ( $old['name'] ?? $service->name ?? '' ),
			'description' => (string) ( $old['description'] ?? $service->description ?? '' ),
			'duration'    => (string) ( $old['duration'] ?? $service->duration_minutes ?? '60' ),
			'buffer'      => (string) ( $old['buffer'] ?? $service->buffer_after_minutes ?? '0' ),
			'price'       => (string) ( $old['price'] ?? ( null === $service ? '0' : Money::to_input( $service->price_minor ) ) ),
			'resources'   => isset( $old['resources'] ) && is_array( $old['resources'] ) ? array_map( 'intval', $old['resources'] ) : $assigned,
			'sort_order'  => (int) ( $old['sort_order'] ?? $service->sort_order ?? 0 ),
			'is_active'   => (bool) ( $old['is_active'] ?? $service->is_active ?? true ),
		);

		$this->heading( null === $service ? __( 'Add service', 'terminarz' ) : __( 'Edit service', 'terminarz' ) );
		echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( '&larr; Back to services', 'terminarz' ) . '</a></p>';

		$this->form_open( 'save_service', 'trmz-service-form' );
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $service->id ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="trmz-name">' . esc_html__( 'Name', 'terminarz' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="trmz-name" name="name" required maxlength="' . esc_attr( (string) self::NAME_MAX ) . '" value="' . esc_attr( $values['name'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="trmz-description">' . esc_html__( 'Description', 'terminarz' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="trmz-description" name="description">' . esc_textarea( $values['description'] ) . '</textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="trmz-duration">' . esc_html__( 'Duration (minutes)', 'terminarz' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="trmz-duration" name="duration_minutes" min="1" max="1440" step="1" required value="' . esc_attr( $values['duration'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="trmz-buffer">' . esc_html__( 'Buffer after (minutes)', 'terminarz' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="trmz-buffer" name="buffer_after_minutes" min="0" max="1440" step="1" value="' . esc_attr( $values['buffer'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Time blocked after each appointment (cleaning, travel). Not shown to customers.', 'terminarz' ) . '</p>';
		echo '</td></tr>';

		$currency = Money::currency();
		echo '<tr><th scope="row"><label for="trmz-price">' . esc_html__( 'Price', 'terminarz' ) . '</label></th><td>';
		echo '<input type="text" inputmode="decimal" class="small-text" id="trmz-price" name="price" value="' . esc_attr( $values['price'] ) . '" /> ' . esc_html( $currency );
		echo '<p class="description">' . esc_html(
			function_exists( 'get_woocommerce_currency' )
				? __( 'In the WooCommerce shop currency. 0 = free.', 'terminarz' )
				: __( 'Informational without WooCommerce (no online payments). 0 = free.', 'terminarz' )
		) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Resources', 'terminarz' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Resources', 'terminarz' ) . '</legend>';
		$resources = $this->services()->resources()->all();
		if ( array() === $resources ) {
			echo '<p>' . esc_html__( 'Add resources first.', 'terminarz' ) . '</p>';
		}
		foreach ( $resources as $resource ) {
			printf(
				'<label><input type="checkbox" name="resources[]" value="%1$s"%2$s /> %3$s%4$s</label><br />',
				esc_attr( (string) $resource->id ),
				checked( in_array( (int) $resource->id, $values['resources'], true ), true, false ),
				esc_html( $resource->name ),
				$resource->is_active ? '' : ' <em>(' . esc_html__( 'inactive', 'terminarz' ) . ')</em>'
			);
		}
		echo '<p class="description">' . esc_html__( 'Who or what performs the service. With "any resource", the first free one in the resource order is chosen.', 'terminarz' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="trmz-sort-order">' . esc_html__( 'Order', 'terminarz' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="trmz-sort-order" name="sort_order" step="1" value="' . esc_attr( (string) $values['sort_order'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'terminarz' ) . '</th><td><label>';
		echo '<input type="checkbox" name="is_active" value="1"' . checked( $values['is_active'], true, false ) . ' /> ';
		echo esc_html__( 'Active (offered to customers)', 'terminarz' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( null === $service ? __( 'Add service', 'terminarz' ) : __( 'Save changes', 'terminarz' ) );
		echo '</form>';
	}
}
