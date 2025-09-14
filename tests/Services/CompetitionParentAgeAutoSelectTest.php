<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

require_once __DIR__ . '/stubs.php';

if (!class_exists('WP_Post')) {
    class WP_Post { public $post_type; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return false; }
}

class CompetitionParentAgeAutoSelectTest extends TestCase {
    public function test_parent_term_added_when_child_selected(): void {
        $GLOBALS['mock_post_terms_return'] = [10];
        $GLOBALS['mock_terms'] = [
            10 => ['term_id' => 10, 'parent' => 5, 'name' => 'Child'],
            5  => ['term_id' => 5, 'parent' => 0, 'name' => 'Parent'],
        ];
        $GLOBALS['wp_set_post_terms_last'] = null;

        $svc  = new Competitions();
        $post = new WP_Post();
        $post->post_type = 'competition';
        $svc->save_meta(123, $post, false);

        $this->assertEquals([123, [10, 5], 'age_category'], $GLOBALS['wp_set_post_terms_last']);

        unset($GLOBALS['mock_post_terms_return'], $GLOBALS['mock_terms'], $GLOBALS['wp_set_post_terms_last']);
    }
}
