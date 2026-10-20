<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VMStudio\SalesOS\Modules\Chat\SpamGuard;

final class SpamGuardTest extends TestCase {

	private SpamGuard $guard;

	protected function setUp(): void {
		$this->guard = new SpamGuard();
	}

	public function testOrdinaryQuestionIsAllowed(): void {
		$verdict = $this->guard->inspect( 'Hi, do you build ecommerce sites and what does it cost?' );

		$this->assertTrue( $verdict['allowed'] );
	}

	public function testKeyboardMashIsBlocked(): void {
		$verdict = $this->guard->inspect( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );

		$this->assertFalse( $verdict['allowed'] );
	}

	public function testEmptyMessageIsBlocked(): void {
		$this->assertFalse( $this->guard->inspect( '   ' )['allowed'] );
	}

	/**
	 * Real buyers write short messages. A guard that blocks "how much?" is a
	 * guard that loses deals, so this case must stay allowed.
	 */
	public function testShortLegitimateQuestionSurvives(): void {
		$this->assertTrue( $this->guard->inspect( 'how much?' )['allowed'] );
	}
}
