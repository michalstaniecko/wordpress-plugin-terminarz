<?php
/**
 * Booking search criteria.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Repository;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;

/**
 * Filters, ordering and paging for BookingRepository::search() / count() (admin listings).
 */
final class BookingCriteria {

	public const ORDER_START   = 'start';
	public const ORDER_CREATED = 'created';

	public const MAX_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param BookingStatus[] $statuses    Only these statuses; empty = any.
	 * @param int|null        $service_id  Only this service.
	 * @param int|null        $resource_id Only this resource.
	 * @param TimeRange|null  $starts_in   Only bookings starting within this range.
	 * @param string          $search      Substring of the customer name or e-mail, or the exact public ID; '' = none.
	 * @param string          $order_by    ORDER_START or ORDER_CREATED.
	 * @param bool            $descending  Descending order.
	 * @param int             $limit       Page size (1–100).
	 * @param int             $offset      Offset (>= 0).
	 * @throws InvalidValue On invalid values.
	 */
	public function __construct(
		public readonly array $statuses = array(),
		public readonly ?int $service_id = null,
		public readonly ?int $resource_id = null,
		public readonly ?TimeRange $starts_in = null,
		public readonly string $search = '',
		public readonly string $order_by = self::ORDER_START,
		public readonly bool $descending = false,
		public readonly int $limit = 20,
		public readonly int $offset = 0
	) {
		foreach ( $statuses as $status ) {
			if ( ! $status instanceof BookingStatus ) {
				throw new InvalidValue( 'Statuses must be BookingStatus cases.' );
			}
		}
		if ( ( null !== $service_id && $service_id <= 0 ) || ( null !== $resource_id && $resource_id <= 0 ) ) {
			throw new InvalidValue( 'Ids must be positive integers.' );
		}
		if ( ! in_array( $order_by, array( self::ORDER_START, self::ORDER_CREATED ), true ) ) {
			throw new InvalidValue( 'Unknown order.' );
		}
		if ( $limit < 1 || $limit > self::MAX_LIMIT || $offset < 0 ) {
			throw new InvalidValue( 'Invalid page.' );
		}
	}
}
