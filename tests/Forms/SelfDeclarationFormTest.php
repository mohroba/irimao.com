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

    public function test_handles_file_upload_via_wp(): void {
        $content = file_get_contents( __DIR__ . '/../../includes/Forms/SelfDeclarationForm.php' );
        $this->assertStringContainsString("require_once ABSPATH . 'wp-admin/includes/file.php'", $content);
        $this->assertStringContainsString('\\wp_handle_upload', $content);
    }
}
