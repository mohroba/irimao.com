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

    public function test_contains_fields_when_logged_in(): void {
        if ( ! function_exists( 'wp_set_current_user' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        wp_set_current_user( 1 );
        $required = [ 'national_id','first_name_fa','last_name_fa','gender','father_name','birth_date','birth_province','birth_city','marital_status','education_status','residence_province','residence_city','residence_address','billing_phone','billing_email' ];
        foreach ( $required as $k ) {
            update_user_meta( 1, $k, 'x' );
        }
        $form   = new IdentityProfessionalForm();
        $output = $form->render();
        $this->assertStringContainsString( 'تصویر پرسنلی', $output );
    }
}
