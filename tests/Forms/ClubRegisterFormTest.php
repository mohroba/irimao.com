<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\ClubRegisterForm;

class ClubRegisterFormTest extends TestCase {
    public function test_render_skips_without_wp(): void {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $form = new ClubRegisterForm();
        $output = $form->render();
        $this->assertStringContainsString( 'نام باشگاه', $output );
    }

    public function test_render_requires_login(): void {
        if ( function_exists( 'is_user_logged_in' ) ) {
            $this->markTestSkipped( 'WordPress environment defines is_user_logged_in.' );
        }
        function is_user_logged_in() { return false; }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $form = new ClubRegisterForm();
        $this->assertStringContainsString( 'لطفاً ابتدا وارد شوید', $form->render() );
    }

    public function test_handles_file_upload_via_wp(): void {
        $content = file_get_contents( __DIR__ . '/../../includes/Forms/ClubRegisterForm.php' );
        $this->assertStringContainsString("require_once ABSPATH . 'wp-admin/includes/file.php'", $content);
        $this->assertStringContainsString('\\wp_handle_upload', $content);
    }

    public function test_license_file_is_optional(): void {
        $content = file_get_contents( __DIR__ . '/../../includes/Forms/ClubRegisterForm.php' );
        $this->assertStringNotContainsString('آپلود تصویر مجوز/قرارداد الزامی است', $content);
        $this->assertStringNotContainsString('<input type="file" name="club_license_image" accept="image/*" required', $content);
        $this->assertStringNotContainsString('تصویر مجوز / قرارداد <span', $content);
    }

    public function test_render_includes_needs_swal_class(): void {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $form   = new ClubRegisterForm();
        $output = $form->render();
        $this->assertStringContainsString( 'class="needs-swal"', $output );
    }
}
