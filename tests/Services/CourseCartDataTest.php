<?php

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;

class CourseCartDataTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_REQUEST['weight_class_term'], $_REQUEST['age_category_term'], $GLOBALS['mock_terms']);
    }

    public function test_cart_item_meta_handling(): void
    {
        if (!class_exists(Courses::class)) {
            $this->markTestSkipped('Plugin not loaded.');
        }

        $_REQUEST['weight_class_term'] = '10';
        $GLOBALS['mock_terms'] = [
            10 => ['term_id' => 10, 'name' => 'WeightName', 'slug' => 'weight', 'parent' => 20],
            20 => ['term_id' => 20, 'name' => 'AgeName', 'slug' => 'age', 'parent' => 0],
        ];

        $svc  = new Courses();
        $data = $svc->add_cart_item_data([], 0);

        $this->assertSame(10, $data['weight_class_term']);
        $this->assertSame(20, $data['age_category_term']);

        $item_data = $svc->add_item_data([], $data);
        $this->assertSame([
            ['name' => 'دسته وزنی', 'value' => 'WeightName'],
            ['name' => 'رده سنی', 'value' => 'AgeName'],
        ], $item_data);

        $item = new class {
            public array $meta = [];
            public function add_meta_data($key, $value, $unique)
            {
                $this->meta[$key] = $value;
            }
        };

        $svc->add_order_line_item_meta($item, 'abc', $data, null);
        $this->assertSame('WeightName', $item->meta['دسته وزنی'] ?? '');
        $this->assertSame('AgeName', $item->meta['رده سنی'] ?? '');
    }
}
