<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\Price;

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name ) {
        return $GLOBALS['test_currency'] ?? 'IRR';
    }
}

class PriceTest extends TestCase {
    public function test_from_rial_with_toman_currency(): void {
        $GLOBALS['test_currency'] = 'IRT';
        $this->assertSame( 1000.0, Price::from_rial( 10000 ) );
    }

    public function test_from_rial_with_rial_currency(): void {
        $GLOBALS['test_currency'] = 'IRR';
        $this->assertSame( 10000.0, Price::from_rial( 10000 ) );
    }
}
