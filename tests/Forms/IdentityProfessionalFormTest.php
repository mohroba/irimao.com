<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\IdentityProfessionalForm;

class IdentityProfessionalFormTest extends TestCase {
    public function test_render_requires_login(): void {
        if ( ! function_exists( 'is_user_logged_in' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $form = new IdentityProfessionalForm();
        $this->assertStringContainsString( 'لطفاً وارد شوید', $form->render() );
    }

    public function test_contains_fields_when_logged_in(): void {
        if ( ! function_exists( 'wp_set_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        wp_set_current_user( 1 );
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone' ];
        foreach ( $required as $k ) {
            update_user_meta( 1, $k, 'x' );
        }
        $form   = new IdentityProfessionalForm();
        $output = $form->render();
        $this->assertStringContainsString( 'تصویر پرسنلی', $output );
        $this->assertStringContainsString( 'data-status="', $output );
    }

    public function test_disables_submission_when_approved(): void {
        if ( ! function_exists( 'wp_set_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        wp_set_current_user( 1 );
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone' ];
        foreach ( $required as $k ) {
            update_user_meta( 1, $k, 'x' );
        }
        update_user_meta( 1, 'identity_verified_professional', 'approved' );
        $form   = new IdentityProfessionalForm();
        $output = $form->render();
        $this->assertMatchesRegularExpression( '/<button[^>]*disabled/', $output );
        $this->assertMatchesRegularExpression( '/input[^>]*type="file"[^>]*disabled/', $output );
    }

    public function test_allows_submission_when_status_empty(): void {
        if ( ! function_exists( 'wp_set_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        wp_set_current_user( 1 );
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone' ];
        foreach ( $required as $k ) {
            update_user_meta( 1, $k, 'x' );
        }
        delete_user_meta( 1, 'identity_verified_professional' );
        $form   = new IdentityProfessionalForm();
        $output = $form->render();
        $this->assertDoesNotMatchRegularExpression( '/<button[^>]*disabled/', $output );
        $this->assertDoesNotMatchRegularExpression( '/input[^>]*type="file"[^>]*disabled/', $output );
    }

    public function test_renders_without_billing_email_meta(): void {
        if ( ! function_exists( 'wp_set_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        wp_set_current_user( 1 );
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone' ];
        foreach ( $required as $k ) {
            update_user_meta( 1, $k, 'x' );
        }
        delete_user_meta( 1, 'billing_email' );
        $form   = new IdentityProfessionalForm();
        $output = $form->render();
        $this->assertStringContainsString( 'تصویر پرسنلی', $output );
    }

    public function test_handles_file_upload_via_wp(): void {
        $content = file_get_contents( __DIR__ . '/../../includes/Forms/IdentityProfessionalForm.php' );
        $this->assertStringContainsString('\\wp_handle_upload', $content);
    }

    public function test_pdf_extension_not_allowed(): void {
        if ( ! function_exists( 'wp_set_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        wp_set_current_user( 1 );
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone' ];
        foreach ( $required as $k ) {
            update_user_meta( 1, $k, 'x' );
        }
        $form   = new IdentityProfessionalForm();
        $output = $form->render();
        $this->assertStringNotContainsString('.pdf', $output);
        $content = file_get_contents( __DIR__ . '/../../includes/Forms/IdentityProfessionalForm.php' );
        $this->assertStringNotContainsString('application/pdf', $content);
    }
}
