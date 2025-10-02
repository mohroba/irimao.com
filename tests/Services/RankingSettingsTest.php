<?php

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Ranking;

class RankingSettingsTest extends TestCase
{
    public function test_sanitize_expiry_days_handles_localized_digits(): void
    {
        $service = new Ranking();
        $method  = new ReflectionMethod(Ranking::class, 'sanitize_expiry_days');
        $method->setAccessible(true);

        $this->assertSame(365, $method->invoke($service, '۳۶۵'));
        $this->assertSame(45, $method->invoke($service, '٤٥'));
        $this->assertSame(12, $method->invoke($service, ['۱۲']));
        $this->assertSame(0, $method->invoke($service, 'abc'));
    }
}
