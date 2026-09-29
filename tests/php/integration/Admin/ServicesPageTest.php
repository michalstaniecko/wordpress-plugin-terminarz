<?php
/**
 * Integration tests for the services screen.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use Terminarz\Admin\Money;
use Terminarz\Admin\ServicesPage;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Service;

/**
 * @covers \Terminarz\Admin\ServicesPage
 * @covers \Terminarz\Admin\ServicesListTable
 * @covers \Terminarz\Admin\Money
 */
final class ServicesPageTest extends AdminTestCase {

	/**
	 * Screen under test.
	 *
	 * @var ServicesPage
	 */
	private ServicesPage $page;

	public function set_up(): void {
		parent::set_up();
		$this->page = new ServicesPage();
		add_filter( 'trmz_currency', array( $this, 'currency' ) );
	}

	public function tear_down(): void {
		remove_filter( 'trmz_currency', array( $this, 'currency' ) );
		remove_all_filters( 'trmz_price_decimals' );
		parent::tear_down();
	}

	/**
	 * Test currency.
	 */
	public function currency(): string {
		return 'PLN';
	}

	public function test_creates_a_service_with_resources_in_display_order(): void {
		$b = $this->make_resource( 'B' );
		$a = $this->make_resource( 'A' );

		$this->run_action(
			$this->page,
			'save_service',
			array(
				'name'                 => 'Consultation',
				'description'          => 'First visit',
				'duration_minutes'     => '45',
				'buffer_after_minutes' => '15',
				'price'                => '150,50',
				'resources'            => array( (string) $a, (string) $b ),
				'is_active'            => '1',
			)
		);

		$services = $this->container->services()->all();
		$this->assertCount( 1, $services );
		$service = $services[0];
		$this->assertSame( 'Consultation', $service->name );
		$this->assertSame( 'First visit', $service->description );
		$this->assertSame( 45, $service->duration_minutes );
		$this->assertSame( 15, $service->buffer_after_minutes );
		$this->assertSame( 15050, $service->price_minor );
		$this->assertTrue( $service->is_active );
		$this->assertSame( array( $b, $a ), $this->container->services()->resource_ids( (int) $service->id ), 'Resource order, not click order.' );
		$this->assertSame( array( 'Service added.' ), $this->notices( 'success' ) );
	}

	/**
	 * Invalid inputs.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_input(): array {
		return array(
			'zero duration'     => array( array( 'duration_minutes' => '0' ) ),
			'too long duration' => array( array( 'duration_minutes' => '1441' ) ),
			'fraction duration' => array( array( 'duration_minutes' => '30.5' ) ),
			'negative buffer'   => array( array( 'buffer_after_minutes' => '-5' ) ),
			'negative price'    => array( array( 'price' => '-1' ) ),
			'text price'        => array( array( 'price' => 'free' ) ),
			'too many decimals' => array( array( 'price' => '1.005' ) ),
			'empty name'        => array( array( 'name' => ' ' ) ),
			'unknown resource'  => array( array( 'resources' => array( '999999' ) ) ),
		);
	}

	/**
	 * Validation.
	 *
	 * @dataProvider invalid_input
	 *
	 * @param array<string, mixed> $override Invalid fields.
	 */
	public function test_validation( array $override ): void {
		$url = $this->run_action(
			$this->page,
			'save_service',
			array_merge(
				array(
					'name'                 => 'Consultation',
					'duration_minutes'     => '30',
					'buffer_after_minutes' => '0',
					'price'                => '10',
				),
				$override
			)
		);

		$this->assertSame( array(), $this->container->services()->all() );
		$this->assertNotEmpty( $this->notices( 'error' ) );
		$this->assertStringContainsString( 'view=edit', $url );
	}

	public function test_free_service_and_warning_without_resources(): void {
		$this->run_action(
			$this->page,
			'save_service',
			array(
				'name'             => 'Free call',
				'duration_minutes' => '15',
				'price'            => '',
				'is_active'        => '1',
			)
		);

		$service = $this->container->services()->all()[0];
		$this->assertSame( 0, $service->price_minor );
		$this->assertTrue( $service->is_free() );
		$this->assertCount( 1, $this->notices( 'warning' ) );
	}

	public function test_update_replaces_resources(): void {
		$a       = $this->make_resource( 'A' );
		$b       = $this->make_resource( 'B' );
		$service = $this->make_service( 60, 0, array( $a, $b ) );

		$this->run_action(
			$this->page,
			'save_service',
			array(
				'id'               => (string) $service,
				'name'             => 'Renamed',
				'duration_minutes' => '90',
				'price'            => '200',
				'resources'        => array( (string) $b ),
			)
		);

		$saved = $this->container->services()->get( $service );
		$this->assertSame( 'Renamed', $saved->name );
		$this->assertSame( 90, $saved->duration_minutes );
		$this->assertFalse( $saved->is_active );
		$this->assertSame( array( $b ), $this->container->services()->resource_ids( $service ) );
	}

	public function test_delete_is_refused_with_bookings_and_toggle_works(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->container->bookings()->create( $this->booking( $resource, '2030-01-10 09:00', 60, 0, BookingStatus::Confirmed, null, $service ), $this->clock->now() );

		$this->run_action( $this->page, 'delete_service', array( 'id' => (string) $service ) );
		$this->assertNotNull( $this->container->services()->get( $service ) );
		$this->assertStringContainsString( 'Deactivate it instead', $this->notices( 'error' )[0] );

		$this->run_action(
			$this->page,
			'toggle_service',
			array(
				'id'     => (string) $service,
				'active' => '0',
			)
		);
		$this->assertFalse( $this->container->services()->get( $service )->is_active );
	}

	public function test_delete_without_bookings(): void {
		$service = $this->make_service();

		$this->run_action( $this->page, 'delete_service', array( 'id' => (string) $service ) );

		$this->assertNull( $this->container->services()->get( $service ) );
	}

	public function test_capability_and_nonce_are_required(): void {
		$service = $this->make_service();

		$this->assertDies( fn() => $this->run_action( $this->page, 'delete_service', array( 'id' => (string) $service ), 'bad' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertDies( fn() => $this->run_action( $this->page, 'delete_service', array( 'id' => (string) $service ) ) );
		$this->assertNotNull( $this->container->services()->get( $service ) );
	}

	public function test_list_and_form_escape_output(): void {
		$resource = $this->make_resource( '<b>Anna</b>' );
		$service  = $this->container->services()->save( new Service( null, '<script>x</script>', 30, 12345, 0, true, '"desc"' ) );
		$this->container->services()->assign_resources( (int) $service->id, array( $resource ) );

		$list = $this->render( $this->page, array( 'page' => ServicesPage::SLUG ) );
		$this->assertStringNotContainsString( '<script>x', $list );
		$this->assertStringNotContainsString( '<b>Anna', $list );
		$this->assertStringContainsString( '123.45 PLN', $list );

		$form = $this->render(
			$this->page,
			array(
				'page' => ServicesPage::SLUG,
				'view' => 'edit',
				'id'   => (int) $service->id,
			)
		);
		$this->assertStringContainsString( 'value="123.45"', $form );
		$this->assertStringContainsString( '&lt;b&gt;Anna&lt;/b&gt;', $form );
		$this->assertMatchesRegularExpression( '/name="resources\[\]" value="' . $resource . '" checked/', $form );
	}

	public function test_money_parsing_respects_decimals(): void {
		$this->assertSame( 15000, Money::parse( '150' ) );
		$this->assertSame( 15050, Money::parse( '150.5' ) );
		$this->assertSame( 120000, Money::parse( '1 200,00' ) );
		$this->assertSame( 0, Money::parse( '' ) );
		$this->assertNull( Money::parse( '1e5' ) );
		$this->assertSame( '150.05', Money::to_input( 15005 ) );

		add_filter( 'trmz_price_decimals', static fn(): int => 0 );
		$this->assertSame( 150, Money::parse( '150' ) );
		$this->assertNull( Money::parse( '150.5' ) );
		$this->assertSame( '150', Money::to_input( 150 ) );
	}
}
