<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionCartDataTest extends TestCase {
    public function test_competition_type_cart_item_and_meta(): void {
        if ( ! class_exists( Competitions::class ) ) {
            $this->markTestSkipped( 'Plugin not loaded.' );
        }
        $_REQUEST['competition_type_term'] = '5';
        $_REQUEST['weight_class_term']     = '10';
        $GLOBALS['mock_terms'] = [
            5  => ['term_id' => 5, 'name' => 'TypeName', 'slug' => 'type', 'parent' => 0],
            10 => ['term_id' => 10, 'name' => 'WeightName', 'slug' => 'weight', 'parent' => 20],
            20 => ['term_id' => 20, 'name' => 'AgeName', 'slug' => 'age', 'parent' => 0],
        ];
        $svc  = new Competitions();
        $data = $svc->add_cart_item_data( [], 0 );
        $this->assertSame( 5, $data['competition_type_term'] );
        $this->assertSame( 10, $data['weight_class_term'] );
        $this->assertSame( 20, $data['age_category_term'] );

        $itemData = $svc->add_item_data( [], [
            'weight_class_term'      => 10,
            'age_category_term'      => 20,
            'competition_type_term'  => 5,
        ] );
        $this->assertSame(
            [
                [ 'name' => 'دسته وزنی', 'value' => 'WeightName' ],
                [ 'name' => 'رده سنی', 'value' => 'AgeName' ],
                [ 'name' => 'نوع مسابقه', 'value' => 'TypeName' ],
            ],
            $itemData
        );

        $item = new class {
            public array $meta = [];
            public function add_meta_data( $key, $value, $unique ) { $this->meta[ $key ] = $value; }
        };
        $svc->add_order_line_item_meta( $item, 'abc', [
            'weight_class_term'     => 10,
            'age_category_term'     => 20,
            'competition_type_term' => 5,
        ], null );
        $this->assertSame( 'WeightName', $item->meta['دسته وزنی'] );
        $this->assertSame( 'AgeName', $item->meta['رده سنی'] );
        $this->assertSame( 'TypeName', $item->meta['نوع مسابقه'] );
        unset( $_REQUEST['competition_type_term'], $_REQUEST['weight_class_term'], $GLOBALS['mock_terms'] );
    }

    public function test_restore_cart_item_from_session(): void {
        if ( ! class_exists( Competitions::class ) ) {
            $this->markTestSkipped( 'Plugin not loaded.' );
        }
        $svc      = new Competitions();
        $restored = $svc->restore_cart_item_from_session(
            [ 'existing' => 'value' ],
            [
                'weight_class_term'     => '44',
                'age_category_term'     => '22',
                'competition_type_term' => '11',
            ]
        );
        $this->assertSame( 'value', $restored['existing'] );
        $this->assertSame( 44, $restored['weight_class_term'] );
        $this->assertSame( 22, $restored['age_category_term'] );
        $this->assertSame( 11, $restored['competition_type_term'] );
    }
}
