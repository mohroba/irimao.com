<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionCartDataTest extends TestCase {
    public function test_competition_type_cart_item_and_meta(): void {
        if ( ! class_exists( Competitions::class ) ) {
            $this->markTestSkipped( 'Plugin not loaded.' );
        }
        $_REQUEST['competition_type_term'] = '5';
        $svc  = new Competitions();
        $data = $svc->add_cart_item_data( [], 0 );
        $this->assertSame( 5, $data['competition_type_term'] );

        if ( ! function_exists( 'get_term' ) ) {
            function get_term( $id, $tax ) { return (object) [ 'name' => 'TypeName' ]; }
        }
        $itemData = $svc->add_item_data( [], [ 'competition_type_term' => 5 ] );
        $this->assertSame( [ [ 'name' => 'نوع مسابقه', 'value' => 'TypeName' ] ], $itemData );

        $item = new class {
            public array $meta = [];
            public function add_meta_data( $key, $value, $unique ) { $this->meta[ $key ] = $value; }
        };
        $svc->add_order_line_item_meta( $item, [ 'competition_type_term' => 5 ] );
        $this->assertSame( 'TypeName', $item->meta['نوع مسابقه'] );
        unset( $_REQUEST['competition_type_term'] );
    }
}
