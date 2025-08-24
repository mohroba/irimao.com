<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\SelfDeclarationForm;

class SelfDeclarationFormTest extends TestCase {
    public function test_skip_without_wp(): void {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new SelfDeclarationForm();
        $out = $form->render();
        $this->assertStringContainsString( 'خوداظهاری', $out );
        $this->assertStringContainsString( 'sd-table-responsive', $out );
    }
}
