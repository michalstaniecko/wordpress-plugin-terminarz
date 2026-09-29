<?php
/**
 * Bookings list table.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Repository\BookingCriteria;
use Terminarz\Infrastructure\Services;
use WP_List_Table;

/**
 * Paged, filtered and sortable list of bookings. Dates are shown in the site time zone.
 */
final class BookingsListTable extends WP_List_Table {

	public const PER_PAGE = 20;

	/**
	 * Service names keyed by ID.
	 *
	 * @var array<int, string>
	 */
	private array $service_names = array();

	/**
	 * Resource names keyed by ID.
	 *
	 * @var array<int, string>
	 */
	private array $resource_names = array();

	/**
	 * Constructor.
	 *
	 * @param BookingsPage   $page    Screen.
	 * @param BookingFilters $filters Active filters.
	 */
	public function __construct( private readonly BookingsPage $page, private readonly BookingFilters $filters ) {
		parent::__construct(
			array(
				'singular' => 'trmz-booking',
				'plural'   => 'trmz-bookings',
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
			'start'    => __( 'Date', 'terminarz' ),
			'customer' => __( 'Customer', 'terminarz' ),
			'service'  => __( 'Service', 'terminarz' ),
			'resource' => __( 'Resource', 'terminarz' ),
			'status'   => __( 'Status', 'terminarz' ),
			'created'  => __( 'Booked on', 'terminarz' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'start'   => array( BookingCriteria::ORDER_START, false ),
			'created' => array( BookingCriteria::ORDER_CREATED, true ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'start' );

		$services = Services::instance();
		foreach ( $services->services()->all() as $service ) {
			$this->service_names[ (int) $service->id ] = $service->name;
		}
		foreach ( $services->resources()->all() as $resource ) {
			$this->resource_names[ (int) $resource->id ] = $resource->name;
		}

		$timezone = $services->availability_settings()->timezone;
		$page     = $this->get_pagenum();
		$total    = $services->bookings()->count( $this->filters->criteria( $timezone ) );
		$pages    = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page     = min( $page, $pages );

		$this->items = $services->bookings()->search( $this->filters->criteria( $timezone, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => $pages,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e( 'No bookings found.', 'terminarz' );
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
	 * Status links above the table ("All | Pending (3) | …").
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$services = Services::instance();
		$timezone = $services->availability_settings()->timezone;
		$base     = $this->filters->query_args();
		unset( $base['status'] );

		$current = $this->filters->status;
		$views   = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( $this->page->url( $base ) ),
				null === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'terminarz' )
			),
		);
		foreach ( BookingStatus::cases() as $status ) {
			$filters = new BookingFilters( $status, $this->filters->service_id, $this->filters->resource_id, $this->filters->from, $this->filters->to, $this->filters->search );
			$count   = $services->bookings()->count( $filters->criteria( $timezone ) );
			if ( 0 === $count && $status !== $current ) {
				continue;
			}
			$views[ $status->value ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $this->page->url( array_merge( $base, array( 'status' => $status->value ) ) ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( Labels::status( $status ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
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
		echo '<div class="alignleft actions trmz-filters">';
		echo '<label class="screen-reader-text" for="trmz-filter-service">' . esc_html__( 'Service', 'terminarz' ) . '</label>';
		echo '<select id="trmz-filter-service" name="service"><option value="">' . esc_html__( 'All services', 'terminarz' ) . '</option>';
		foreach ( $this->service_names as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $this->filters->service_id, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="trmz-filter-resource">' . esc_html__( 'Resource', 'terminarz' ) . '</label>';
		echo '<select id="trmz-filter-resource" name="resource"><option value="">' . esc_html__( 'All resources', 'terminarz' ) . '</option>';
		foreach ( $this->resource_names as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $this->filters->resource_id, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="trmz-filter-from">' . esc_html__( 'From', 'terminarz' ) . '</label> ';
		echo '<input type="date" id="trmz-filter-from" name="from" value="' . esc_attr( $this->filters->from ) . '" /> ';
		echo '<label for="trmz-filter-to">' . esc_html__( 'To', 'terminarz' ) . '</label> ';
		echo '<input type="date" id="trmz-filter-to" name="to" value="' . esc_attr( $this->filters->to ) . '" /> ';
		if ( null !== $this->filters->status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $this->filters->status->value ) . '" />';
		}
		submit_button( __( 'Filter', 'terminarz' ), '', 'filter_action', false, array( 'id' => 'trmz-filter-submit' ) );
		echo '</div>';
		echo '<div class="alignleft actions">';
		printf(
			'<a class="button" id="trmz-export-csv" href="%1$s">%2$s</a>',
			esc_url( $this->page->action_url( 'export_bookings', $this->filters->query_args() ) ),
			esc_html__( 'Export CSV', 'terminarz' )
		);
		echo '</div>';
	}

	/**
	 * Date column with row actions.
	 *
	 * @param Booking $item Booking.
	 */
	public function column_start( $item ): string {
		$id      = (int) $item->id;
		$details = $this->page->url(
			array(
				'view' => 'view',
				'id'   => $id,
			)
		);
		$actions = array(
			'view' => '<a href="' . esc_url( $details ) . '">' . esc_html__( 'Details', 'terminarz' ) . '</a>',
		);
		if ( $item->status->can_transition_to( BookingStatus::Confirmed ) ) {
			$actions['confirm'] = '<a href="' . esc_url( $this->page->action_url( 'confirm_booking', array( 'id' => $id ) ) ) . '">' . esc_html__( 'Confirm', 'terminarz' ) . '</a>';
		}
		if ( $item->status->is_active() ) {
			$actions['reschedule'] = '<a href="' . esc_url(
				$this->page->url(
					array(
						'view' => 'reschedule',
						'id'   => $id,
					)
				)
			) . '">' . esc_html__( 'Reschedule', 'terminarz' ) . '</a>';
		}
		if ( $item->status->can_transition_to( BookingStatus::Cancelled ) ) {
			$actions['cancel'] = sprintf(
				'<a href="%1$s" class="submitdelete" onclick="return window.confirm(%2$s);">%3$s</a>',
				esc_url( $this->page->action_url( 'cancel_booking', array( 'id' => $id ) ) ),
				esc_attr( (string) wp_json_encode( __( 'Cancel this booking?', 'terminarz' ) ) ),
				esc_html__( 'Cancel', 'terminarz' )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong><br /><code>%3$s</code>',
			esc_url( $details ),
			esc_html( Labels::datetime( $item->range->start ) . '–' . Labels::time( $item->range->end ) ),
			esc_html( substr( (string) $item->public_id, 0, 8 ) )
		) . $this->row_actions( $actions );
	}

	/**
	 * Customer column.
	 *
	 * @param Booking $item Booking.
	 */
	public function column_customer( $item ): string {
		return esc_html( $item->customer->name ) . '<br /><a href="' . esc_url( 'mailto:' . $item->customer->email ) . '">' . esc_html( $item->customer->email ) . '</a>';
	}

	/**
	 * Service column.
	 *
	 * @param Booking $item Booking.
	 */
	public function column_service( $item ): string {
		return esc_html( $this->service_names[ $item->service_id ] ?? '#' . $item->service_id );
	}

	/**
	 * Resource column.
	 *
	 * @param Booking $item Booking.
	 */
	public function column_resource( $item ): string {
		return esc_html( $this->resource_names[ $item->resource_id ] ?? '#' . $item->resource_id );
	}

	/**
	 * Status column.
	 *
	 * @param Booking $item Booking.
	 */
	public function column_status( $item ): string {
		return '<span class="trmz-status trmz-status--' . esc_attr( $item->status->value ) . '">' . esc_html( Labels::status( $item->status ) ) . '</span>';
	}

	/**
	 * Creation date column.
	 *
	 * @param Booking $item Booking.
	 */
	public function column_created( $item ): string {
		return null === $item->created_at ? '&mdash;' : esc_html( Labels::datetime( $item->created_at ) );
	}
}
