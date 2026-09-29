<?php
/**
 * Schedule exceptions list table.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Model\ScheduleExceptionPeriod;
use Terminarz\Infrastructure\Services;
use WP_List_Table;

/**
 * Upcoming exceptions (or all, with `past=1`), optionally only of one scope (`scope=global|<resource id>`).
 */
final class ExceptionsListTable extends WP_List_Table {

	/**
	 * Resource names keyed by ID.
	 *
	 * @var array<int, string>
	 */
	private array $resource_names = array();

	/**
	 * Constructor.
	 *
	 * @param ExceptionsPage $page Screen.
	 */
	public function __construct( private readonly ExceptionsPage $page ) {
		parent::__construct(
			array(
				'singular' => 'trmz-exception',
				'plural'   => 'trmz-exceptions',
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
			'dates' => __( 'Dates', 'terminarz' ),
			'scope' => __( 'Applies to', 'terminarz' ),
			'hours' => __( 'Hours', 'terminarz' ),
			'note'  => __( 'Note', 'terminarz' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'dates' );
		$services              = Services::instance();
		foreach ( $services->resources()->all() as $resource ) {
			$this->resource_names[ (int) $resource->id ] = $resource->name;
		}

		$today   = $services->clock()->now()->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		$periods = $services->schedule_exceptions()->periods( $this->show_past() ? null : $today );
		$scope   = $this->scope();
		if ( 'global' === $scope ) {
			$periods = array_filter( $periods, static fn( ScheduleExceptionPeriod $p ): bool => $p->is_global() );
		} elseif ( '' !== $scope ) {
			$periods = array_filter( $periods, static fn( ScheduleExceptionPeriod $p ): bool => $p->resource_id === (int) $scope );
		}
		$this->items = array_values( $periods );
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
		esc_html_e( 'No exceptions.', 'terminarz' );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which Position.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$scope = $this->scope();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="trmz-filter-scope">' . esc_html__( 'Applies to', 'terminarz' ) . '</label>';
		echo '<select id="trmz-filter-scope" name="scope">';
		echo '<option value="">' . esc_html__( 'All', 'terminarz' ) . '</option>';
		echo '<option value="global"' . selected( $scope, 'global', false ) . '>' . esc_html__( 'Global only', 'terminarz' ) . '</option>';
		foreach ( $this->resource_names as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $scope, (string) $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';
		echo '<label><input type="checkbox" name="past" value="1"' . checked( $this->show_past(), true, false ) . ' /> ' . esc_html__( 'Include past', 'terminarz' ) . '</label> ';
		submit_button( __( 'Filter', 'terminarz' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Dates column with row actions.
	 *
	 * @param ScheduleExceptionPeriod $item Period.
	 */
	public function column_dates( $item ): string {
		$id      = (int) $item->id;
		$edit    = $this->page->url(
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);
		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'terminarz' ) . '</a>',
			'delete' => sprintf(
				'<a href="%1$s" class="submitdelete" onclick="return window.confirm(%2$s);">%3$s</a>',
				esc_url( $this->page->action_url( 'delete_exception', array( 'id' => $id ) ) ),
				esc_attr( (string) wp_json_encode( __( 'Delete this exception?', 'terminarz' ) ) ),
				esc_html__( 'Delete', 'terminarz' )
			),
		);
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( ExceptionsPage::dates( $item ) ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Scope column.
	 *
	 * @param ScheduleExceptionPeriod $item Period.
	 */
	public function column_scope( $item ): string {
		if ( $item->is_global() ) {
			return esc_html__( 'All resources', 'terminarz' );
		}
		return esc_html( $this->resource_names[ (int) $item->resource_id ] ?? '#' . $item->resource_id );
	}

	/**
	 * Hours column.
	 *
	 * @param ScheduleExceptionPeriod $item Period.
	 */
	public function column_hours( $item ): string {
		if ( $item->is_closed() ) {
			return esc_html__( 'Closed', 'terminarz' );
		}
		return esc_html( implode( ', ', array_map( array( ScheduleForm::class, 'range' ), $item->windows ) ) );
	}

	/**
	 * Note column.
	 *
	 * @param ScheduleExceptionPeriod $item Period.
	 */
	public function column_note( $item ): string {
		return esc_html( $item->note );
	}

	/**
	 * Scope filter: '', 'global' or a resource ID.
	 */
	private function scope(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : '';
		return 'global' === $scope || ctype_digit( $scope ) ? $scope : '';
	}

	/**
	 * Whether past exceptions are listed.
	 */
	private function show_past(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		return isset( $_GET['past'] ) && '1' === $_GET['past'];
	}
}
