<?php

namespace {
    use IMAOCustom\Services\PayoutManager;
    use PHPUnit\Framework\TestCase;

    class PayoutManagerTest extends TestCase {
        public function test_valid_mixed_rules_fit_line_total(): void {
            $rules = [
                [ 'type' => 'percent', 'value' => 10 ],
                [ 'type' => 'percent', 'value' => 5 ],
                [ 'type' => 'fixed', 'value' => 20 ],
            ];
            $this->assertTrue( PayoutManager::rules_fit_base( $rules, 200 ) );
        }

        public function test_overallocated_rules_are_rejected(): void {
            $this->assertFalse( PayoutManager::rules_fit_base( [ [ 'type' => 'percent', 'value' => 101 ] ], 200 ) );
            $this->assertFalse( PayoutManager::rules_fit_base( [ [ 'type' => 'percent', 'value' => 90 ], [ 'type' => 'fixed', 'value' => 30 ] ], 200 ) );
        }
    }
}
