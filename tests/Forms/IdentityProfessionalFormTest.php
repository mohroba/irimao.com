<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\IdentityProfessionalForm;

class IdentityProfessionalFormTest extends TestCase {
    public function test_render_requires_login(): void {
        if ( ! function_exists( 'is_user_logged_in' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new IdentityProfessionalForm();
        $this->assertStringContainsString( 'لطفاً وارد شوید', $form->render() );
    }
}
