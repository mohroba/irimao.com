<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CompetitionsList;
use IMAOCustom\Services\Endpoints\CompetitionDetails;
use IMAOCustom\Services\Endpoints\UserCompetitionsList;

class CompetitionEndpointsTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $places ) { $GLOBALS['endpoints'][] = $name; }
            function add_filter( $hook, $func ) { $GLOBALS['filters'][$hook][] = $func; }
            function apply_filters( $hook, $value ) { foreach ( $GLOBALS['filters'][$hook] ?? [] as $f ) { $value = $f( $value ); } return $value; }
            function add_action( $hook, $func ) { $GLOBALS['actions'][$hook][] = $func; }
            function do_action( $hook ) { foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) { $f(); } }
            function do_shortcode( $tag ) { return "[{$tag}]"; }
            if ( ! defined( 'EP_ROOT' ) ) { define( 'EP_ROOT', 1 ); }
            if ( ! defined( 'EP_PAGES' ) ) { define( 'EP_PAGES', 1 ); }
        }
        $GLOBALS['endpoints'] = $GLOBALS['filters'] = $GLOBALS['actions'] = [];
    }

    public function test_competitions_list_endpoint(): void {
        $endpoint = new CompetitionsList();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'competitions-list', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'competitions-list', $items );
        ob_start();
        do_action( 'woocommerce_account_competitions-list_endpoint' );
        $out = ob_get_clean();
        $this->assertStringContainsString( '[crm_competitions_list]', $out );
    }

    public function test_competition_details_endpoint(): void {
        $endpoint = new CompetitionDetails();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'competition-details', $GLOBALS['endpoints'] );
        ob_start();
        do_action( 'woocommerce_account_competition-details_endpoint' );
        $out = ob_get_clean();
        $this->assertStringContainsString( '[crm_competition_details]', $out );
    }

    public function test_user_competitions_list_endpoint(): void {
        $endpoint = new UserCompetitionsList();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'user-competitions-list', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'user-competitions-list', $items );
        ob_start();
        do_action( 'woocommerce_account_user-competitions-list_endpoint' );
        $out = ob_get_clean();
        $this->assertStringContainsString( '[crm_user_competitions]', $out );
    }
}
