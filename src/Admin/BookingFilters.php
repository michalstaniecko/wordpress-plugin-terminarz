<?php
/**
 * Filters of the admin booking list (shared with the CSV export).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingCriteria;

/**
 * Sanitized list filters read from query arguments: `status` (one status or empty), `service`, `resource`,
 * `from`/`to` (local dates of the start, inclusive), `s` (name/e-mail fragment or public ID), `orderby`
 * (`start`|`created`), `order` (`asc`|`desc`).
 */
final class BookingFilters {

	/**
	 * Constructor.
	 *
	 * @param BookingStatus|null $status      Status.
	 * @param int|null           $service_id  Service.
	 * @param int|null           $resource_id Resource.
	 * @param string             $from        First local date (Y-m-d) or ''.
	 * @param string             $to          Last local date (Y-m-d) or ''.
	 * @param string             $search      Search text.
	 * @param string             $order_by    BookingCriteria::ORDER_*.
	 * @param bool               $descending  Descending order.
	 */
	public function __construct(
		public readonly ?BookingStatus $status = null,
		public readonly ?int $service_id = null,
		public readonly ?int $resource_id = null,
		public readonly string $from = '',
		public readonly string $to = '',
		public readonly string $search = '',
		public readonly string $order_by = BookingCriteria::ORDER_START,
		public readonly bool $descending = false
	) {
	}

	/**
	 * Reads the filters from query arguments (unslashed); invalid values are ignored.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 */
	public static function from_query( array $query ): self {
		$service  = Input::absint( $query, 'service' );
		$resource = Input::absint( $query, 'resource' );
		$from     = Input::date( $query, 'from' );
		$to       = Input::date( $query, 'to' );
		if ( '' !== $from && '' !== $to && $to < $from ) {
			[ $from, $to ] = array( $to, $from );
		}
		$order_by = Input::key( $query, 'orderby' );

		return new self(
			BookingStatus::tryFrom( Input::key( $query, 'status' ) ),
			$service > 0 ? $service : null,
			$resource > 0 ? $resource : null,
			$from,
			$to,
			mb_substr( Input::text( $query, 's' ), 0, 100 ),
			BookingCriteria::ORDER_CREATED === $order_by ? BookingCriteria::ORDER_CREATED : BookingCriteria::ORDER_START,
			'desc' === Input::key( $query, 'order' )
		);
	}

	/**
	 * Repository criteria for one page.
	 *
	 * @param DateTimeZone $timezone Site time zone (local dates → UTC range).
	 * @param int          $limit    Page size (1–100).
	 * @param int          $offset   Offset.
	 */
	public function criteria( DateTimeZone $timezone, int $limit = 20, int $offset = 0 ): BookingCriteria {
		return new BookingCriteria(
			statuses: null === $this->status ? array() : array( $this->status ),
			service_id: $this->service_id,
			resource_id: $this->resource_id,
			starts_in: $this->range( $timezone ),
			search: $this->search,
			order_by: $this->order_by,
			descending: $this->descending,
			limit: $limit,
			offset: $offset
		);
	}

	/**
	 * Query arguments reproducing these filters (empty values omitted).
	 *
	 * @return array<string, int|string>
	 */
	public function query_args(): array {
		$args = array(
			'status'   => null === $this->status ? '' : $this->status->value,
			'service'  => $this->service_id ?? 0,
			'resource' => $this->resource_id ?? 0,
			'from'     => $this->from,
			'to'       => $this->to,
			's'        => $this->search,
			'orderby'  => BookingCriteria::ORDER_START === $this->order_by ? '' : $this->order_by,
			'order'    => $this->descending ? 'desc' : '',
		);
		return array_filter( $args, static fn( $value ): bool => '' !== $value && 0 !== $value );
	}

	/**
	 * UTC range of starts covering the local dates: [from 00:00, day after `to` 00:00), open ends far away.
	 *
	 * @param DateTimeZone $timezone Site time zone.
	 */
	private function range( DateTimeZone $timezone ): ?TimeRange {
		if ( '' === $this->from && '' === $this->to ) {
			return null;
		}
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', '' === $this->from ? '1970-01-02' : $this->from, $timezone );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', '' === $this->to ? '9998-12-31' : $this->to, $timezone );
		if ( false === $start || false === $end ) {
			return null;
		}
		return new TimeRange( $start, $end->modify( '+1 day' ) );
	}
}
