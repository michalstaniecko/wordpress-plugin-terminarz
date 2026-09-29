<?php
/**
 * Resources screen.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use DateInterval;
use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingCriteria;

/**
 * "Terminarz → Resources" (`admin.php?page=trmz-resources`): list, add/edit form, activate/deactivate, delete.
 *
 * A resource that has bookings cannot be deleted (the repository refuses, ADR-013) — it can only be deactivated.
 */
class ResourcesPage extends Screen {

	public const SLUG = 'trmz-resources';

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
		return __( 'Resources', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Resources', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 20;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function actions(): array {
		return array(
			'save_resource'   => 'save',
			'toggle_resource' => 'toggle',
			'delete_resource' => 'delete',
		);
	}

	/**
	 * Handler: create or update a resource.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function save( array $request ): string {
		$id    = Input::absint( $request, 'id' );
		$input = array(
			'name'        => Input::text( $request, 'name' ),
			'type'        => Input::key( $request, 'type' ),
			'description' => Input::textarea( $request, 'description' ),
			'sort_order'  => Input::int_or_null( $request, 'sort_order' ) ?? 0,
			'is_active'   => Input::flag( $request, 'is_active' ),
		);

		$errors = array();
		if ( '' === $input['name'] ) {
			$errors[] = __( 'Enter a name.', 'terminarz' );
		} elseif ( mb_strlen( $input['name'] ) > self::NAME_MAX ) {
			$errors[] = __( 'The name is too long.', 'terminarz' );
		}
		if ( ! in_array( $input['type'], BookableResource::TYPES, true ) ) {
			$errors[] = __( 'Choose a valid resource type.', 'terminarz' );
		}

		$back = $this->url(
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);
		if ( array() !== $errors ) {
			array_map( array( Notices::class, 'error' ), $errors );
			Notices::keep_input( $input );
			return $back;
		}

		try {
			$saved = $this->services()->resources()->save(
				new BookableResource( $id > 0 ? $id : null, $input['name'], $input['is_active'], $input['type'], $input['description'], $input['sort_order'] )
			);
		} catch ( EntityNotFound $e ) {
			Notices::error( __( 'The resource does not exist.', 'terminarz' ) );
			return $this->url();
		} catch ( InvalidValue $e ) {
			Notices::error( __( 'The resource data is not valid.', 'terminarz' ) );
			Notices::keep_input( $input );
			return $back;
		}

		Notices::success( $id > 0 ? __( 'Resource updated.', 'terminarz' ) : __( 'Resource added.', 'terminarz' ) );
		if ( ! $saved->is_active ) {
			$this->warn_about_upcoming_bookings( (int) $saved->id );
		}
		return $this->url(
			array(
				'view' => 'edit',
				'id'   => (int) $saved->id,
			)
		);
	}

	/**
	 * Handler: activate (`active=1`) or deactivate (`active=0`) a resource.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function toggle( array $request ): string {
		$id       = Input::absint( $request, 'id' );
		$active   = Input::flag( $request, 'active' );
		$resource = $this->services()->resources()->get( $id );
		if ( null === $resource ) {
			Notices::error( __( 'The resource does not exist.', 'terminarz' ) );
			return $this->url();
		}

		$this->services()->resources()->save( $resource->with_active( $active ) );
		Notices::success( $active ? __( 'Resource activated.', 'terminarz' ) : __( 'Resource deactivated. It is no longer offered to customers.', 'terminarz' ) );
		if ( ! $active ) {
			$this->warn_about_upcoming_bookings( $id );
		}
		return $this->url();
	}

	/**
	 * Handler: delete a resource (refused when it has bookings).
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function delete( array $request ): string {
		$id = Input::absint( $request, 'id' );
		if ( null === $this->services()->resources()->get( $id ) ) {
			Notices::error( __( 'The resource does not exist.', 'terminarz' ) );
			return $this->url();
		}

		try {
			$this->services()->resources()->delete( $id );
		} catch ( EntityInUse $e ) {
			Notices::error( __( 'This resource has bookings and cannot be deleted. Deactivate it instead.', 'terminarz' ) );
			return $this->url();
		}

		Notices::success( __( 'Resource deleted.', 'terminarz' ) );
		return $this->url();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_view(): void {
		switch ( $this->current_view() ) {
			case 'edit':
				$this->render_form();
				return;
			default:
				$this->render_list();
		}
	}

	/**
	 * Number of upcoming active bookings of a resource.
	 *
	 * @param int $resource_id Resource ID.
	 */
	public function upcoming_bookings( int $resource_id ): int {
		$now = $this->services()->clock()->now();
		return $this->services()->bookings()->count(
			new BookingCriteria(
				statuses: BookingStatus::active(),
				resource_id: $resource_id,
				starts_in: new TimeRange( $now, $now->add( new DateInterval( 'P100Y' ) ) )
			)
		);
	}

	/**
	 * Warns that a deactivated resource still has upcoming bookings (they are not cancelled automatically).
	 *
	 * @param int $resource_id Resource ID.
	 */
	private function warn_about_upcoming_bookings( int $resource_id ): void {
		$count = $this->upcoming_bookings( $resource_id );
		if ( $count > 0 ) {
			Notices::warning(
				sprintf(
					/* translators: %d: number of bookings. */
					_n(
						'The resource still has %d upcoming booking. It stays valid until you cancel or move it.',
						'The resource still has %d upcoming bookings. They stay valid until you cancel or move them.',
						$count,
						'terminarz'
					),
					$count
				)
			);
		}
	}

	/**
	 * List view.
	 */
	private function render_list(): void {
		$this->heading( __( 'Resources', 'terminarz' ), $this->url( array( 'view' => 'edit' ) ), __( 'Add resource', 'terminarz' ) );
		echo '<p class="description">' . esc_html__( 'Resources are the people, rooms or devices that can be booked. Each one has its own working hours.', 'terminarz' ) . '</p>';

		self::load_list_table();
		$table = new ResourcesListTable( $this );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * Add/edit form.
	 */
	private function render_form(): void {
		$id       = $this->query_int( 'id' );
		$resource = $id > 0 ? $this->services()->resources()->get( $id ) : null;
		if ( $id > 0 && null === $resource ) {
			$this->heading( __( 'Edit resource', 'terminarz' ) );
			echo '<p>' . esc_html__( 'The resource does not exist.', 'terminarz' ) . '</p>';
			return;
		}

		$old    = Notices::take_input() ?? array();
		$values = array(
			'name'        => (string) ( $old['name'] ?? $resource->name ?? '' ),
			'type'        => (string) ( $old['type'] ?? $resource->type ?? BookableResource::TYPE_PERSON ),
			'description' => (string) ( $old['description'] ?? $resource->description ?? '' ),
			'sort_order'  => (int) ( $old['sort_order'] ?? $resource->sort_order ?? 0 ),
			'is_active'   => (bool) ( $old['is_active'] ?? $resource->is_active ?? true ),
		);

		$this->heading( null === $resource ? __( 'Add resource', 'terminarz' ) : __( 'Edit resource', 'terminarz' ) );
		echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( '&larr; Back to resources', 'terminarz' ) . '</a>';
		if ( null !== $resource ) {
			echo ' | <a href="' . esc_url( ( new SchedulePage() )->url( array( 'resource' => (int) $resource->id ) ) ) . '">' . esc_html__( 'Working hours', 'terminarz' ) . '</a>';
			echo ' | <a href="' . esc_url( ( new ExceptionsPage() )->url( array( 'scope' => (int) $resource->id ) ) ) . '">' . esc_html__( 'Days off', 'terminarz' ) . '</a>';
		}
		echo '</p>';

		$this->form_open( 'save_resource', 'trmz-resource-form' );
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $resource->id ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="trmz-name">' . esc_html__( 'Name', 'terminarz' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="trmz-name" name="name" required maxlength="' . esc_attr( (string) self::NAME_MAX ) . '" value="' . esc_attr( $values['name'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="trmz-type">' . esc_html__( 'Type', 'terminarz' ) . '</label></th><td><select id="trmz-type" name="type">';
		foreach ( Labels::resource_types() as $type => $label ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $values['type'], $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="trmz-description">' . esc_html__( 'Description', 'terminarz' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="4" id="trmz-description" name="description">' . esc_textarea( $values['description'] ) . '</textarea>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="trmz-sort-order">' . esc_html__( 'Order', 'terminarz' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="trmz-sort-order" name="sort_order" step="1" value="' . esc_attr( (string) $values['sort_order'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Lower numbers are listed first.', 'terminarz' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'terminarz' ) . '</th><td><label>';
		echo '<input type="checkbox" name="is_active" value="1"' . checked( $values['is_active'], true, false ) . ' /> ';
		echo esc_html__( 'Active (offered to customers)', 'terminarz' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( null === $resource ? __( 'Add resource', 'terminarz' ) : __( 'Save changes', 'terminarz' ) );
		echo '</form>';
	}
}
