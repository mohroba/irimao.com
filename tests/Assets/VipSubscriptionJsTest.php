<?php

use PHPUnit\Framework\TestCase;

class VipSubscriptionJsTest extends TestCase {
    public function test_contains_confirmation_logic(): void {
        $content = file_get_contents(__DIR__ . '/../../assets/js/vip-subscription.js');
        $this->assertStringContainsString('خرید اشتراک', $content);
        $this->assertStringContainsString('confirm(', $content);
    }
}
