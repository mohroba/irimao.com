<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\PasswordChangeForm;

class PasswordChangeFormTest extends TestCase {
    public function test_skip_without_wp(): void {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form   = new PasswordChangeForm();
        $output = $form->render();
        $this->assertStringContainsString( 'name="current_pass"', $output );
        $this->assertStringContainsString( 'disabled', $output );
    }
}
