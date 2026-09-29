<?php
/**
 * Resources list table.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Model\BookableResource;
use Terminarz\Infrastructure\Services;
use WP_List_Table;

/**
 * Lists all resources (active and inactive) in display order. Load WP_List_Table before instantiating
 * ({@see Screen::load_list_table()}).
 */
final class ResourcesListTable extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param ResourcesPage $page Screen (URLs and actions).
	 */
	public function __construct( private readonly ResourcesPage $page ) {
		parent::__construct(
			array(
				'singular' => 'trmz-resource',
				'plural'   => 'trmz-resources',
				'ajax'     => false,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'name'     => __( 'Name', 'terminarz' ),
			'type'     => __( 'Type', 'terminarz' ),
			'services' => __( 'Services', 'terminarz' ),
			'status'   => __( 'Status', 'terminarz' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
		$this->items           = Services::instance()->resources()->all();
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e( 'No resources yet. Add the first person, room or device that can be booked.', 'terminarz' );
	}

	/**
	 * Name column with row actions.
	 *
	 * @param BookableResource $item Resource.
	 */
	public function column_name( $item ): string {
		$id      = (int) $item->id;
		$edit    = $this->page->url(
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);
		$actions = array(
			'edit' => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'terminarz' ) . '</a>',
		);

		$actions['toggle'] = $item->is_active
			? '<a href="' . esc_url(
				$this->page->action_url(
					'toggle_resource',
					array(
						'id'     => $id,
						'active' => 0,
					)
				)
			) . '">' . esc_html__( 'Deactivate', 'terminarz' ) . '</a>'
			: '<a href="' . esc_url(
				$this->page->action_url(
					'toggle_resource',
					array(
						'id'     => $id,
						'active' => 1,
					)
				)
			) . '">' . esc_html__( 'Activate', 'terminarz' ) . '</a>';

		$actions['delete'] = sprintf(
			'<a href="%1$s" class="submitdelete" onclick="return window.confirm(%2$s);">%3$s</a>',
			esc_url( $this->page->action_url( 'delete_resource', array( 'id' => $id ) ) ),
			esc_attr( (string) wp_json_encode( __( 'Delete this resource permanently?', 'terminarz' ) ) ),
			esc_html__( 'Delete', 'terminarz' )
		);

		$title = '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $item->name ) . '</a></strong>';
		if ( '' !== $item->description ) {
			$title .= '<p class="description">' . esc_html( wp_trim_words( $item->description, 20 ) ) . '</p>';
		}
		return $title . $this->row_actions( $actions );
	}

	/**
	 * Type column.
	 *
	 * @param BookableResource $item Resource.
	 */
	public function column_type( $item ): string {
		return esc_html( Labels::resource_type( $item->type ) );
	}

	/**
	 * Services performed by the resource.
	 *
	 * @param BookableResource $item Resource.
	 */
	public function column_services( $item ): string {
		$services = Services::instance();
		$names    = array();
		foreach ( $services->services()->service_ids_for_resource( (int) $item->id ) as $service_id ) {
			$service = $services->services()->get( $service_id );
			if ( null !== $service ) {
				$names[] = $service->name;
			}
		}
		return array() === $names ? '&mdash;' : esc_html( implode( ', ', $names ) );
	}

	/**
	 * Status column.
	 *
	 * @param BookableResource $item Resource.
	 */
	public function column_status( $item ): string {
		return $item->is_active
			? esc_html__( 'Active', 'terminarz' )
			: '<span class="trmz-inactive">' . esc_html__( 'Inactive', 'terminarz' ) . '</span>';
	}
}
