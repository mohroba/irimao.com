<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\ClubRegisterForm;

class ClubRegisterFormTest extends TestCase {
    public function test_render_skips_without_wp(): void {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new ClubRegisterForm();
        $output = $form->render();
        $this->assertStringContainsString( 'نام باشگاه', $output );
    }
}
