<?php
/**
 * Resources list table.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\BookableResource;
use Terminarz\Infrastructure\Services;
use WP_List_Table;

/**
 * Lists all resources (active and inactive) in display order. Load WP_List_Table before instantiating
 * ({@see Screen::load_list_table()}).
 */
final class ResourcesListTable extends WP_List_Table {

	/**
	 * Service names by ID (loaded once in prepare_items()).
	 *
	 * @var array<int, string>
	 */
	private array $service_names = array();

	/**
	 * Service IDs by resource ID (one query in prepare_items()).
	 *
	 * @var array<int, int[]>
	 */
	private array $service_ids = array();

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
		$services              = Services::instance();
		$this->items           = $services->resources()->all();
		$this->service_names   = array();
		foreach ( $services->services()->all() as $service ) {
			$this->service_names[ (int) $service->id ] = $service->name;
		}
		$this->service_ids = $services->services()->service_ids_by_resource();
		$this->set_pagination_args(
			array(
				'total_items' => count( $this->items ),
				'per_page'    => max( 1, count( $this->items ) ),
			)
		);
	}

	/**
	 * Row actions are printed by the primary column itself.
	 *
	 * @param object|array<mixed> $item        Item.
	 * @param string              $column_name Column.
	 * @param string              $primary     Primary column.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ): string {
		return '';
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
			'edit'     => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'terminarz' ) . '</a>',
			'schedule' => '<a href="' . esc_url( ( new SchedulePage() )->url( array( 'resource' => $id ) ) ) . '">' . esc_html__( 'Working hours', 'terminarz' ) . '</a>',
			'days_off' => '<a href="' . esc_url( ( new ExceptionsPage() )->url( array( 'scope' => $id ) ) ) . '">' . esc_html__( 'Days off', 'terminarz' ) . '</a>',
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
		$names = array();
		foreach ( $this->service_ids[ (int) $item->id ] ?? array() as $service_id ) {
			if ( isset( $this->service_names[ $service_id ] ) ) {
				$names[] = $this->service_names[ $service_id ];
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
