<?php
declare(strict_types=1);

namespace VMStudio\SalesOS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use VMStudio\SalesOS\Modules\Automation\AutomationModule;

final class AutomationRuleTest extends TestCase {

	public function testNoConditionsAlwaysMatches(): void {
		$this->assertTrue( AutomationModule::matches( [], [ 'score' => 10 ] ) );
	}

	public function testAllConditionsMustHold(): void {
		$conditions = [
			[ 'field' => 'score', 'op' => '>', 'value' => 70 ],
			[ 'field' => 'stage', 'op' => '=', 'value' => 'hot' ],
		];

		$this->assertTrue( AutomationModule::matches( $conditions, [ 'score' => 80, 'stage' => 'hot' ] ) );
		$this->assertFalse( AutomationModule::matches( $conditions, [ 'score' => 80, 'stage' => 'new' ] ) );
		$this->assertFalse( AutomationModule::matches( $conditions, [ 'score' => 40, 'stage' => 'hot' ] ) );
	}

	public function testMissingFieldFailsRatherThanThrows(): void {
		$conditions = [ [ 'field' => 'email', 'op' => 'not_empty' ] ];

		$this->assertFalse( AutomationModule::matches( $conditions, [] ) );
		$this->assertTrue( AutomationModule::matches( $conditions, [ 'email' => 'a@b.test' ] ) );
	}

	public function testUnknownOperatorFailsClosed(): void {
		$conditions = [ [ 'field' => 'score', 'op' => 'regex', 'value' => '.*' ] ];

		$this->assertFalse( AutomationModule::matches( $conditions, [ 'score' => 90 ] ) );
	}
}
