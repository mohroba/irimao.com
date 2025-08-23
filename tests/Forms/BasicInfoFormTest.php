<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\BasicInfoForm;

class BasicInfoFormTest extends TestCase {
    public function test_skip_without_wp(): void {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new BasicInfoForm();
        $this->assertStringContainsString( 'نام', $form->render() );
    }
}
