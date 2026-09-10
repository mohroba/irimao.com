<?php

namespace {
    use IMAOCustom\Services\CompetitionCards;
    use PHPUnit\Framework\TestCase;

    class CompetitionCardsTest extends TestCase {
        public function test_card_requires_paid_order_confirmed_weigh_in_and_positive_weight(): void {
            $order = new class {
                public string $status = 'processing';
                public function has_status( array $statuses ): bool { return in_array( $this->status, $statuses, true ); }
            };
            $item = new class {
                public array $meta = [ '_imao_weigh_in_confirmed' => 'yes', '_imao_actual_weight' => 65 ];
                public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
            };
            $this->assertTrue( CompetitionCards::item_is_ready( $order, $item ) );
            $order->status = 'pending';
            $this->assertFalse( CompetitionCards::item_is_ready( $order, $item ) );
            $order->status = 'completed';
            $item->meta['_imao_actual_weight'] = 0;
            $this->assertFalse( CompetitionCards::item_is_ready( $order, $item ) );
        }

        public function test_qr_is_generated_as_an_embedded_svg(): void {
            $uri = CompetitionCards::qr_data_uri( 'https://irimao.com/card/test' );
            $this->assertStringStartsWith( 'data:image/svg+xml;base64,', $uri );
            $this->assertStringContainsString( '<svg', base64_decode( substr( $uri, strlen( 'data:image/svg+xml;base64,' ) ) ) );
        }
    }
}
