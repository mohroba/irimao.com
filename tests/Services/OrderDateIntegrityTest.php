<?php

use IMAOCustom\Services\OrderDateIntegrity;
use PHPUnit\Framework\TestCase;

final class OrderDateIntegrityTest extends TestCase {
    public function test_only_exact_reproducible_conversion_can_be_repaired(): void {
        $reference = '2026-11-06 11:54:41';
        $this->assertSame( $reference, OrderDateIntegrity::verified_reference( '2648-01-26 11:54:41', $reference, 2026 ) );
        $this->assertNull( OrderDateIntegrity::verified_reference( '2648-01-26 11:54:42', $reference, 2026 ) );
        $this->assertNull( OrderDateIntegrity::verified_reference( $reference, $reference, 2026 ) );
        $this->assertNull( OrderDateIntegrity::verified_reference( '2648-01-26 11:54:41', '2026-08-31 11:54:41', 2026 ) );
        $this->assertNull( OrderDateIntegrity::verified_reference( '2648-01-26 11:54:41', 'invalid', 2026 ) );
    }

    public function test_future_date_is_rejected_before_save(): void {
        $order = new class { public function get_date_created( $context ) { return new DateTimeImmutable( '2648-01-26' ); } };
        $this->expectException( RuntimeException::class );
        ( new OrderDateIntegrity() )->guard( $order );
    }

    public function test_normal_and_missing_dates_are_unchanged(): void {
        $order = new class { public function get_date_created( $context ) { return new DateTimeImmutable( '2026-11-15' ); } };
        ( new OrderDateIntegrity() )->guard( $order );
        ( new OrderDateIntegrity() )->guard( new stdClass() );
        $this->assertSame( '2026-11-15', $order->get_date_created( 'edit' )->format( 'Y-m-d' ) );
    }
}
