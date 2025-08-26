<?php
namespace IMAOCustom\Forms {
    function is_user_logged_in() { return true; }
    function get_current_user_id() { return 1; }
    function wp_get_current_user() { return (object) ['user_email' => 'old@example.com']; }
    function get_user_meta($uid, $key, $single = true) { return \IMAOCustom\Helpers\get_user_meta($uid, $key, $single); }
    function update_user_meta($uid, $key, $value) { \IMAOCustom\Helpers\update_user_meta($uid, $key, $value); }
    function wp_update_user($args) { \IMAOCustom\Helpers\update_user_meta(0, 'billing_email', $args['user_email']); }
    function wp_verify_nonce($nonce, $action) { return true; }
    function sanitize_text_field($str) { return $str; }
    function sanitize_textarea_field($str) { return $str; }
    function sanitize_email($str) { return $str; }
    function is_email($str) { return strpos($str, '@') !== false; }
    function wp_nonce_field($action, $name, $referer = true, $echo = true) { return '<input type="hidden" name="' . $name . '" value="nonce">'; }
    function __($text, $domain = 'default') { return $text; }
    function esc_attr($text) { return $text; }
    function esc_html($text) { return $text; }
    function esc_url($text) { return $text; }
    function esc_textarea($text) { return $text; }
    function get_users($args = []) { return []; }
}

namespace IMAOCustom\Helpers {
    $GLOBALS['user_meta'] = [];
    function get_user_meta($uid, $key, $single = true) {
        if ($key === 'identity_verified_professional') { return 'pending'; }
        return $GLOBALS['user_meta'][$key] ?? '';
    }
    function update_user_meta($uid, $key, $value) { $GLOBALS['user_meta'][$key] = $value; }
}

namespace Tests\Forms {
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\BasicInfoForm;

class BasicInfoFormSubmissionTest extends TestCase {
    protected function tearDown(): void {
        $GLOBALS['user_meta'] = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function validPostData(): array {
        return [
            'imao_nonce'        => 'nonce',
            'billing_phone'     => '09123456789',
            'national_id'       => '1234567890',
            'first_name_fa'     => 'نام',
            'last_name_fa'      => 'خانوادگی',
            'gender'            => 'male',
            'father_name'       => 'پدر',
            'birth_date'        => '1400/01/01',
            'birth_province'    => 'IR-01',
            'birth_city'        => 'شهر',
            'marital_status'    => 'single',
            'education_status'  => 'none',
            'military_status'   => 'completed',
            'residence_province'=> 'IR-01',
            'residence_city'    => 'شهر',
            'residence_address' => 'آدرس',
        ];
    }

    public function test_successful_submission(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPostData();
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringContainsString('اطلاعات شما با موفقیت ذخیره شد', $html);
        $this->assertSame('نام', $GLOBALS['user_meta']['first_name_fa']);
    }

    public function test_validation_error_and_repopulate(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $data = $this->validPostData();
        $data['first_name_fa'] = '';
        $data['gender']        = 'female'; // avoid military requirement
        $_POST = $data;
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringContainsString('نام الزامی است.', $html);
        $this->assertStringContainsString('name="last_name_fa" value="خانوادگی"', $html);
        $this->assertArrayNotHasKey('last_name_fa', $GLOBALS['user_meta']);
    }
}

}
