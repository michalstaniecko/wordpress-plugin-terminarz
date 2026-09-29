<?php
/**
 * Concurrency test: no double bookings under simultaneous requests (#14).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Concurrency;

use WP_UnitTestCase;

/**
 * Launches N separate `wp eval-file` processes (each with its own database connection and real InnoDB
 * transactions, outside the PHPUnit transaction) that try to book at the same instant, synchronised by a time
 * barrier. Works against the real plugin tables of the wp-env site the suite runs in.
 *
 * Environment:
 * - TRMZ_CONCURRENCY_WORKERS (default 12) — processes per scenario (≥ 10).
 * - TRMZ_CONCURRENCY_BARRIER (default 8) — seconds given to all processes to bootstrap before the barrier.
 * - TRMZ_SKIP_CONCURRENCY=1 — skip (e.g. outside wp-env, where `wp` is unavailable).
 *
 * @group concurrency
 * @coversNothing
 */
final class DoubleBookingTest extends WP_UnitTestCase {

	/**
	 * Resources and services created by the worker `setup` action.
	 *
	 * @var array{resources: int[], services: int[]}
	 */
	private static array $fixture = array(
		'resources' => array(),
		'services'  => array(),
	);

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		if ( '1' === getenv( 'TRMZ_SKIP_CONCURRENCY' ) ) {
			return;
		}
		$workers = self::workers();
		$result  = self::run_one( array( 'setup', (string) $workers, '3' ) );
		if ( ! isset( $result['resources'], $result['services'] ) ) {
			throw new \RuntimeException( 'Concurrency fixture setup failed: ' . wp_json_encode( $result ) );
		}
		self::$fixture = array(
			'resources' => array_map( 'intval', (array) $result['resources'] ),
			'services'  => array_map( 'intval', (array) $result['services'] ),
		);
	}

	public static function tear_down_after_class(): void {
		if ( array() !== self::$fixture['resources'] ) {
			self::run_one( array( 'cleanup', implode( ',', self::$fixture['resources'] ), implode( ',', self::$fixture['services'] ) ) );
		}
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		if ( '1' === getenv( 'TRMZ_SKIP_CONCURRENCY' ) ) {
			$this->markTestSkipped( 'TRMZ_SKIP_CONCURRENCY=1' );
		}
	}

	public function test_same_slot_is_booked_exactly_once(): void {
		$resource = self::$fixture['resources'][0];
		$service  = self::$fixture['services'][0];
		$jobs     = array_fill( 0, self::workers(), array( $resource, $service, '2031-03-03 10:00', 60, 0 ) );

		$results = $this->run_parallel( $jobs );

		$this->assertOutcome( $results, 1, 'same slot' );
		$this->assertSame( 1, $this->active_bookings( array( $resource ) ) );
	}

	public function test_overlapping_slots_with_different_starts_are_booked_at_most_once(): void {
		$resource = self::$fixture['resources'][1];
		$services = self::$fixture['services'];
		$jobs     = array();
		$count    = self::workers();
		// Starts 10:00, 10:05, … 10:55 (cycling), three services with 60/75/90 min + 0/5/10 min buffer:
		// every pair of requests overlaps, so at most one may succeed.
		for ( $i = 0; $i < $count; $i++ ) {
			$variant = $i % 3;
			$jobs[]  = array( $resource, $services[ $variant ], sprintf( '2031-03-04 10:%02d', ( $i * 5 ) % 60 ), 60 + 15 * $variant, 5 * $variant );
		}

		$results = $this->run_parallel( $jobs );

		$this->assertOutcome( $results, 1, 'overlapping slots' );
		$this->assertSame( 1, $this->active_bookings( array( $resource ) ) );
	}

	public function test_different_resources_do_not_block_each_other(): void {
		$resources = self::$fixture['resources'];
		$service   = self::$fixture['services'][0];
		$jobs      = array();
		foreach ( $resources as $resource ) {
			$jobs[] = array( $resource, $service, '2031-03-05 10:00', 60, 0 );
		}

		$results = $this->run_parallel( $jobs );

		$this->assertOutcome( $results, count( $resources ), 'different resources' );
	}

	/**
	 * Asserts the number of successes and that every failure is a clean SlotUnavailable.
	 *
	 * @param array<int, array<string, mixed>> $results  Worker results.
	 * @param int                              $expected Expected successes.
	 * @param string                           $scenario Scenario name.
	 */
	private function assertOutcome( array $results, int $expected, string $scenario ): void {
		$dump = (string) wp_json_encode( $results );
		$this->assertCount( count( $results ), array_filter( $results, static fn( $r ) => array_key_exists( 'ok', $r ) ), "{$scenario}: every worker reported. {$dump}" );

		$ok     = array_filter( $results, static fn( $r ) => true === $r['ok'] );
		$errors = array_filter( $results, static fn( $r ) => false === $r['ok'] && 'SlotUnavailable' !== $r['error'] );
		$this->assertSame( array(), array_values( $errors ), "{$scenario}: failures other than SlotUnavailable. {$dump}" );
		$this->assertCount( $expected, $ok, "{$scenario}: number of successful bookings. {$dump}" );

		// The workers really raced: none arrived after the barrier, and all started within a short window.
		$late = array_filter( $results, static fn( $r ) => true === ( $r['late'] ?? true ) );
		$this->assertSame( array(), array_values( $late ), "{$scenario}: some workers missed the barrier — raise TRMZ_CONCURRENCY_BARRIER. {$dump}" );
		$starts = array_map( static fn( $r ) => (float) $r['started_at'], $results );
		$this->assertLessThan( 0.5, max( $starts ) - min( $starts ), "{$scenario}: workers did not start together. {$dump}" );
	}

	/**
	 * Runs reserve jobs in parallel processes and returns their decoded results.
	 *
	 * @param array<int, array{int, int, string, int, int}> $jobs Jobs: resource, service, start, minutes, buffer.
	 * @return array<int, array<string, mixed>>
	 */
	private function run_parallel( array $jobs ): array {
		$barrier   = sprintf( '%.6F', microtime( true ) + self::barrier_seconds() );
		$processes = array();
		foreach ( $jobs as $job ) {
			$args        = array_merge( array( 'reserve' ), array_map( 'strval', $job ), array( $barrier ) );
			$processes[] = self::start( $args );
		}

		$results = array();
		foreach ( $processes as $process ) {
			$results[] = self::finish( $process );
		}
		return $results;
	}

	/**
	 * Counts active bookings of resources (through a separate process, i.e. committed data).
	 *
	 * @param int[] $resources Resource IDs.
	 */
	private function active_bookings( array $resources ): int {
		$result = self::run_one( array( 'count', implode( ',', $resources ) ) );
		return (int) ( $result['active'] ?? -1 );
	}

	/**
	 * Runs one worker and waits for it.
	 *
	 * @param string[] $args Worker arguments.
	 * @return array<string, mixed>
	 */
	private static function run_one( array $args ): array {
		return self::finish( self::start( $args ) );
	}

	/**
	 * Starts a worker process.
	 *
	 * @param string[] $args Worker arguments.
	 * @return array{0: resource, 1: array<int, resource>}
	 * @throws \RuntimeException When the process cannot be started.
	 */
	private static function start( array $args ): array {
		$command = array_merge(
			array( 'wp', 'eval-file', dirname( __DIR__, 2 ) . '/concurrency/worker.php' ),
			$args,
			array( '--path=' . ABSPATH, '--skip-plugins=woocommerce', '--skip-themes', '--quiet' )
		);
		$pipes   = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- test spawns WP-CLI workers on purpose.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( false === $process ) {
			throw new \RuntimeException( 'Cannot start worker process (is WP-CLI available?).' );
		}
		return array( $process, $pipes );
	}

	/**
	 * Waits for a worker and decodes its output.
	 *
	 * @param array{0: resource, 1: array<int, resource>} $started Process and pipes.
	 * @return array<string, mixed>
	 */
	private static function finish( array $started ): array {
		list( $process, $pipes ) = $started;
		$stdout                  = (string) stream_get_contents( $pipes[1] );
		$stderr                  = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );

		$lines   = array_filter( array_map( 'trim', explode( "\n", $stdout ) ) );
		$decoded = json_decode( (string) end( $lines ), true );
		if ( 0 !== $code || ! is_array( $decoded ) ) {
			return array(
				'ok'    => false,
				'error' => "worker exit {$code}: " . trim( $stdout . ' ' . $stderr ),
			);
		}
		return $decoded;
	}

	/**
	 * Number of parallel processes.
	 */
	private static function workers(): int {
		$value = getenv( 'TRMZ_CONCURRENCY_WORKERS' );
		return max( 10, false === $value || '' === $value ? 12 : (int) $value );
	}

	/**
	 * Bootstrap time budget before the barrier.
	 */
	private static function barrier_seconds(): float {
		$value = getenv( 'TRMZ_CONCURRENCY_BARRIER' );
		return max( 1.0, false === $value || '' === $value ? 8.0 : (float) $value );
	}
}
