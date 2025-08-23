<?php

namespace IMAOCustom\Helpers;

class Price {
    /**
     * Convert a Rial amount to the store currency.
     *
     * WooCommerce stores prices in the configured currency. For Iranian stores
     * this may be Toman. The legacy code uses Rial amounts internally, so this
     * helper normalises those values based on the selected currency.
     */
    public static function from_rial( int $amount ): float {
        $currency         = get_option( 'woocommerce_currency' );
        $toman_currencies = [ 'IRT', 'TOMAN', 'IRHT', 'IRHR' ];

        return in_array( $currency, $toman_currencies, true )
            ? $amount / 10
            : (float) $amount;
    }
}
