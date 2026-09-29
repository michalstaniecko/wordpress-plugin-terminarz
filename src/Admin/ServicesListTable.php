<?php
/**
 * Services list table.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Model\Service;
use Terminarz\Infrastructure\Services;
use WP_List_Table;

/**
 * Lists all services in display order. Load WP_List_Table before instantiating ({@see Screen::load_list_table()}).
 */
final class ServicesListTable extends WP_List_Table {

	/**
	 * Resource names keyed by ID.
	 *
	 * @var array<int, string>
	 */
	private array $resource_names = array();

	/**
	 * Constructor.
	 *
	 * @param ServicesPage $page Screen (URLs and actions).
	 */
	public function __construct( private readonly ServicesPage $page ) {
		parent::__construct(
			array(
				'singular' => 'trmz-service',
				'plural'   => 'trmz-services',
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
			'name'      => __( 'Name', 'terminarz' ),
			'duration'  => __( 'Duration', 'terminarz' ),
			'price'     => __( 'Price', 'terminarz' ),
			'buffer'    => __( 'Buffer after', 'terminarz' ),
			'resources' => __( 'Resources', 'terminarz' ),
			'status'    => __( 'Status', 'terminarz' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
		$services              = Services::instance();
		$this->items           = $services->services()->all();
		$this->set_pagination_args(
			array(
				'total_items' => count( $this->items ),
				'per_page'    => max( 1, count( $this->items ) ),
			)
		);
		foreach ( $services->resources()->all() as $resource ) {
			$this->resource_names[ (int) $resource->id ] = $resource->name;
		}
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
		esc_html_e( 'No services yet.', 'terminarz' );
	}

	/**
	 * Name column with row actions.
	 *
	 * @param Service $item Service.
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
			'edit'   => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'terminarz' ) . '</a>',
			'toggle' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url(
					$this->page->action_url(
						'toggle_service',
						array(
							'id'     => $id,
							'active' => $item->is_active ? 0 : 1,
						)
					)
				),
				$item->is_active ? esc_html__( 'Deactivate', 'terminarz' ) : esc_html__( 'Activate', 'terminarz' )
			),
			'delete' => sprintf(
				'<a href="%1$s" class="submitdelete" onclick="return window.confirm(%2$s);">%3$s</a>',
				esc_url( $this->page->action_url( 'delete_service', array( 'id' => $id ) ) ),
				esc_attr( (string) wp_json_encode( __( 'Delete this service permanently?', 'terminarz' ) ) ),
				esc_html__( 'Delete', 'terminarz' )
			),
		);

		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Duration column.
	 *
	 * @param Service $item Service.
	 */
	public function column_duration( $item ): string {
		/* translators: %d: number of minutes. */
		return esc_html( sprintf( _n( '%d minute', '%d minutes', $item->duration_minutes, 'terminarz' ), $item->duration_minutes ) );
	}

	/**
	 * Price column.
	 *
	 * @param Service $item Service.
	 */
	public function column_price( $item ): string {
		return esc_html( Money::format( $item->price_minor ) );
	}

	/**
	 * Buffer column.
	 *
	 * @param Service $item Service.
	 */
	public function column_buffer( $item ): string {
		if ( 0 === $item->buffer_after_minutes ) {
			return '&mdash;';
		}
		/* translators: %d: number of minutes. */
		return esc_html( sprintf( _n( '%d minute', '%d minutes', $item->buffer_after_minutes, 'terminarz' ), $item->buffer_after_minutes ) );
	}

	/**
	 * Assigned resources.
	 *
	 * @param Service $item Service.
	 */
	public function column_resources( $item ): string {
		$names = array();
		foreach ( Services::instance()->services()->resource_ids( (int) $item->id ) as $resource_id ) {
			if ( isset( $this->resource_names[ $resource_id ] ) ) {
				$names[] = $this->resource_names[ $resource_id ];
			}
		}
		return array() === $names
			? '<span class="trmz-warning">' . esc_html__( 'None — cannot be booked', 'terminarz' ) . '</span>'
			: esc_html( implode( ', ', $names ) );
	}

	/**
	 * Status column.
	 *
	 * @param Service $item Service.
	 */
	public function column_status( $item ): string {
		return $item->is_active ? esc_html__( 'Active', 'terminarz' ) : esc_html__( 'Inactive', 'terminarz' );
	}
}
