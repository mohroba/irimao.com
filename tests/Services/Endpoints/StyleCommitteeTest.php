<?php

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\StyleCommittee;

class StyleCommitteeTest extends TestCase {

    protected function setUp(): void {
        if ( ! function_exists( 'do_shortcode' ) ) {
            function do_shortcode( $tag ) {
                return 'processed:' . $tag;
            }
        }
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $flags ) {
                $GLOBALS['rewrite_endpoint'] = compact( 'name', 'flags' );
            }
        }
        if ( ! defined( 'EP_ROOT' ) ) {
            define( 'EP_ROOT', 1 );
        }
        if ( ! defined( 'EP_PAGES' ) ) {
            define( 'EP_PAGES', 1 );
        }
    }

    public function test_render_returns_shortcode_output(): void {
        $service = new StyleCommittee();
        $this->assertSame( 'processed:[elementor-template id="4023"]', $service->render() );
    }

    public function test_add_endpoint_registers_endpoint(): void {
        $service = new StyleCommittee();
        $service->add_endpoint();
        $this->assertSame( 'style-committe', $GLOBALS['rewrite_endpoint']['name'] ?? null );
    }
}

