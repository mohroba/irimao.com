<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\SmartcardIssueForm;

class SmartcardIssueFormTest extends TestCase {
    public function test_render_contains_title(): void {
        if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'is_user_logged_in' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        if ( ! is_user_logged_in() ) {
            $this->markTestSkipped( 'User not logged in.' );
        }
        $form = new SmartcardIssueForm();
        $this->assertStringContainsString( 'کارت عضویت', $form->render() );
    }
}
