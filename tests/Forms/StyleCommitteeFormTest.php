<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\StyleCommitteeForm;

class StyleCommitteeFormTest extends TestCase {
    public function test_skip_without_wp(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new StyleCommitteeForm();
        $out  = $form->render();
        $this->assertStringContainsString( 'درخواست عضویت در کمیته‌های سبک', $out );
        $this->assertStringContainsString( 'sd-table-responsive', $out );
    }
}
