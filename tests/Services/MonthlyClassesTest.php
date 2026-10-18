<?php

use IMAOCustom\Services\MonthlyClasses;
use PHPUnit\Framework\TestCase;

class MonthlyClassesTest extends TestCase {
    public function test_shares_accept_exact_decimal_total(): void {
        $this->assertSame(
            [ 'referrer' => 33.33, 'coach' => 33.33, 'club' => 33.34 ],
            MonthlyClasses::validate_shares( [ 'referrer' => '33.33', 'coach' => '33.33', 'club' => '33.34' ] )
        );
    }

    /** @dataProvider invalid_shares */
    public function test_invalid_shares_are_rejected( $shares ): void {
        $this->assertNull( MonthlyClasses::validate_shares( $shares ) );
    }

    public function invalid_shares(): array {
        return [
            'missing configuration' => [ null ],
            'zero recipient' => [ [ 'referrer' => 0, 'coach' => 50, 'club' => 50 ] ],
            'negative recipient' => [ [ 'referrer' => -10, 'coach' => 60, 'club' => 50 ] ],
            'under total' => [ [ 'referrer' => 30, 'coach' => 30, 'club' => 30 ] ],
            'over total' => [ [ 'referrer' => 40, 'coach' => 40, 'club' => 40 ] ],
            'extra recipient' => [ [ 'referrer' => 30, 'coach' => 30, 'club' => 40, 'other' => 1 ] ],
            'missing recipient' => [ [ 'referrer' => 50, 'coach' => 50 ] ],
            'nested input' => [ [ 'referrer' => [], 'coach' => 50, 'club' => 50 ] ],
            'excess precision' => [ [ 'referrer' => '33.333', 'coach' => '33.333', 'club' => '33.334' ] ],
            'scientific notation' => [ [ 'referrer' => '1e1', 'coach' => 40, 'club' => 50 ] ],
        ];
    }
}
