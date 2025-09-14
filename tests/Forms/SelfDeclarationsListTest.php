<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\SelfDeclarationsList;

class SelfDeclarationsListTest extends TestCase {
    public function test_skip_without_wp(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new SelfDeclarationsList();
        $out = $form->render();
        $this->assertStringContainsString( 'لیست احکام ثبت شده', $out );
        $this->assertStringContainsString( 'sd-table-responsive', $out );
    }
}
