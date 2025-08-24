<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionsTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'register_post_type' ) ) {
            function register_post_type( $post_type, $args ) { $GLOBALS['registered_post_types'][$post_type] = $args; }
            function register_taxonomy( $taxonomy, $object_type, $args ) { $GLOBALS['registered_taxonomies'][$taxonomy] = $args; }
            function add_shortcode( $tag, $func ) { $GLOBALS['shortcodes'][$tag] = $func; }
            function add_action( $hook, $func ) { $GLOBALS['actions'][$hook][] = $func; }
            function add_filter( $hook, $func ) { $GLOBALS['filters'][$hook][] = $func; }
            function do_action( $hook ) { foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) { $f(); } }
            function add_rewrite_endpoint( $name, $places ) {}
            function taxonomy_exists( $tax ) { return isset( $GLOBALS['registered_taxonomies'][$tax] ); }
            function register_taxonomy_for_object_type( $tax, $obj ) { $GLOBALS['registered_taxonomies'][$tax]['object_type'][] = $obj; }
        }
    }

    public function test_register_sets_up_post_type_and_shortcodes(): void {
        $service = new Competitions();
        $service->register();
        do_action( 'init' );
        $this->assertArrayHasKey( 'competition', $GLOBALS['registered_post_types'] );
        $this->assertArrayHasKey( 'crm_competitions_list', $GLOBALS['shortcodes'] );
        $this->assertArrayHasKey( 'crm_competition_details', $GLOBALS['shortcodes'] );
        $this->assertArrayHasKey( 'crm_user_competitions', $GLOBALS['shortcodes'] );
        $this->assertArrayHasKey( 'weight_class', $GLOBALS['registered_taxonomies'] );
        $this->assertArrayHasKey( 'age_category', $GLOBALS['registered_taxonomies'] );
        $this->assertArrayHasKey( 'competition_type', $GLOBALS['registered_taxonomies'] );
        $this->assertArrayHasKey( 'manage_competition_posts_columns', $GLOBALS['filters'] );
        $this->assertArrayHasKey( 'admin_post_export_competition_attendees', $GLOBALS['actions'] );
    }
}
