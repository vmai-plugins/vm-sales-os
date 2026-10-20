<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VMStudio\SalesOS\Support\SendGovernor;

final class SendGovernorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['vmsos_test_options'] = [];
		$GLOBALS['vmsos_test_now']     = null;
	}

	/** 2026-01-15 at the given UTC hour:minute. */
	private function at( int $hour, int $minute = 0 ): int {
		return (int) gmmktime( $hour, $minute, 0, 1, 15, 2026 );
	}

	public function testMalformedWindowIsRejected(): void {
		$this->assertNull( SendGovernor::parseWindow( 'nonsense' ) );
		$this->assertNull( SendGovernor::parseWindow( '25:00-26:00' ) );
		$this->assertNull( SendGovernor::parseWindow( '09:70-21:00' ) );
	}

	public function testMalformedWindowNeverBlocksSending(): void {
		// A typo in a settings field must not silently stop all outreach.
		$this->assertTrue( SendGovernor::isWithinWindow( $this->at( 3 ), 'nonsense' ) );
	}

	public function testSameDayWindow(): void {
		$this->assertFalse( SendGovernor::isWithinWindow( $this->at( 8, 59 ), '09:00-21:00' ) );
		$this->assertTrue( SendGovernor::isWithinWindow( $this->at( 9, 0 ), '09:00-21:00' ) );
		$this->assertTrue( SendGovernor::isWithinWindow( $this->at( 20, 59 ), '09:00-21:00' ) );
		$this->assertFalse( SendGovernor::isWithinWindow( $this->at( 21, 0 ), '09:00-21:00' ) );
	}

	public function testOvernightWindowWrapsMidnight(): void {
		$this->assertTrue( SendGovernor::isWithinWindow( $this->at( 23, 30 ), '20:00-06:00' ) );
		$this->assertTrue( SendGovernor::isWithinWindow( $this->at( 5, 59 ), '20:00-06:00' ) );
		$this->assertFalse( SendGovernor::isWithinWindow( $this->at( 12, 0 ), '20:00-06:00' ) );
	}

	public function testZeroLengthWindowIsAlwaysOpen(): void {
		$this->assertTrue( SendGovernor::isWithinWindow( $this->at( 4 ), '09:00-09:00' ) );
	}

	public function testNextOpeningIsLaterToday(): void {
		$start = SendGovernor::nextWindowStart( $this->at( 6 ), '09:00-21:00' );

		$this->assertSame( $this->at( 9 ), $start );
	}

	public function testNextOpeningRollsToTomorrowAfterTheWindowCloses(): void {
		$start = SendGovernor::nextWindowStart( $this->at( 22 ), '09:00-21:00' );

		$this->assertSame( $this->at( 9 ) + 86400, $start );
	}

	public function testInsideTheWindowTheTimestampIsUnchanged(): void {
		$now = $this->at( 14, 30 );

		$this->assertSame( $now, SendGovernor::nextWindowStart( $now, '09:00-21:00' ) );
	}

	public function testCapOfZeroMeansUnlimited(): void {
		$GLOBALS['vmsos_test_options']['vmsos_daily_cap_email'] = 0;

		$this->assertTrue( SendGovernor::canSend( 'email' ) );
		$this->assertSame( PHP_INT_MAX, SendGovernor::remainingToday( 'email' ) );
	}

	public function testCapBlocksOnceReached(): void {
		$GLOBALS['vmsos_test_now']                              = $this->at( 12 );
		$GLOBALS['vmsos_test_options']['vmsos_daily_cap_email'] = 2;

		$this->assertTrue( SendGovernor::canSend( 'email' ) );

		SendGovernor::recordSend( 'email' );
		SendGovernor::recordSend( 'email' );

		$this->assertSame( 2, SendGovernor::sentToday( 'email' ) );
		$this->assertFalse( SendGovernor::canSend( 'email' ) );
		$this->assertSame( 0, SendGovernor::remainingToday( 'email' ) );
	}

	public function testCounterResetsOnANewDay(): void {
		$GLOBALS['vmsos_test_now']                              = $this->at( 12 );
		$GLOBALS['vmsos_test_options']['vmsos_daily_cap_email'] = 1;

		SendGovernor::recordSend( 'email' );
		$this->assertFalse( SendGovernor::canSend( 'email' ) );

		$GLOBALS['vmsos_test_now'] = $this->at( 12 ) + 86400;

		$this->assertSame( 0, SendGovernor::sentToday( 'email' ) );
		$this->assertTrue( SendGovernor::canSend( 'email' ) );
	}

	public function testCheckReportsQuietHoursWithARetryDelay(): void {
		$GLOBALS['vmsos_test_now']                                 = $this->at( 6 );
		$GLOBALS['vmsos_test_options']['vmsos_send_window_enabled'] = true;
		$GLOBALS['vmsos_test_options']['vmsos_send_window']         = '09:00-21:00';

		$verdict = SendGovernor::check( 'email' );

		$this->assertFalse( $verdict['allowed'] );
		$this->assertSame( 'quiet_hours', $verdict['reason'] );
		$this->assertSame( 3 * 3600, $verdict['retry_after'] );
	}

	public function testCheckAllowsInsideTheWindow(): void {
		$GLOBALS['vmsos_test_now']                                 = $this->at( 10 );
		$GLOBALS['vmsos_test_options']['vmsos_send_window_enabled'] = true;
		$GLOBALS['vmsos_test_options']['vmsos_send_window']         = '09:00-21:00';

		$this->assertTrue( SendGovernor::check( 'email' )['allowed'] );
	}
}
