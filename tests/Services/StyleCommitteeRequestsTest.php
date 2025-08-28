<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\StyleCommitteeRequests;

class StyleCommitteeRequestsTest extends TestCase {
    public function test_register_cpt_sets_read_capabilities(): void {
        if ( ! function_exists( 'register_post_type' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $service = new StyleCommitteeRequests();
        $service->register_cpt();
        $obj = get_post_type_object( 'style_committe_request' );
        $this->assertSame( 'read', $obj->cap->create_posts );
        $this->assertSame( 'read', $obj->cap->edit_posts );
    }
}
