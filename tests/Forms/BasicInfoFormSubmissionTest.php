<?php
namespace IMAOCustom\Forms {
    function is_user_logged_in() { return true; }
    $GLOBALS['current_user_email'] = 'old@example.com';
    function get_current_user_id() { return 1; }
    function get_userdata( $uid ) { return (object) [ 'user_email' => $GLOBALS['current_user_email'] ]; }
    function get_user_meta($uid, $key, $single = true) { return \IMAOCustom\Helpers\get_user_meta($uid, $key, $single); }
    function update_user_meta($uid, $key, $value) { \IMAOCustom\Helpers\update_user_meta($uid, $key, $value); }
    if (!function_exists(__NAMESPACE__.'\\selected')) {
        function selected($selected, $current, $echo = true){ return $selected == $current ? ' selected="selected"' : ''; }
    }
    class WP_Error {
        private string $message;
        public function __construct( $code, $message ) { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
    function wp_update_user( $args ) {
        if ( isset( $GLOBALS['wp_update_user_error'] ) ) {
            return new WP_Error( 'error', $GLOBALS['wp_update_user_error'] );
        }
        $GLOBALS['current_user_email'] = $args['user_email'];
        \IMAOCustom\Helpers\update_user_meta( 0, 'billing_email', $args['user_email'] );
        return 1;
    }
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
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
    function get_users($args = []) { return $GLOBALS['get_users_return'] ?? []; }
    function get_posts($args = []) { return $GLOBALS['get_posts_return'] ?? []; }
}

namespace IMAOCustom\Helpers {
    $GLOBALS['user_meta'] = [];
    if (!function_exists(__NAMESPACE__.'\\get_user_meta')) {
        function get_user_meta($uid, $key, $single = true) {
            return $GLOBALS['user_meta'][$key] ?? '';
        }
    }
    if (!function_exists(__NAMESPACE__.'\\update_user_meta')) {
        function update_user_meta($uid, $key, $value) { $GLOBALS['user_meta'][$key] = $value; }
    }
}

namespace Tests\Forms {
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\BasicInfoForm;

class BasicInfoFormSubmissionTest extends TestCase {
    protected function tearDown(): void {
        $GLOBALS['user_meta']        = [];
        $GLOBALS['current_user_email'] = 'old@example.com';
        $_POST                      = [];
        $_SERVER['REQUEST_METHOD']  = 'GET';
        unset($GLOBALS['get_users_return']);
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
            'birth_date_year'   => '1400',
            'birth_date_month'  => '1',
            'birth_date_day'    => '1',
            'birth_province'    => 'IR-01',
            'birth_city'        => 'شهرستان',
            'marital_status'    => 'single',
            'education_status'  => 'none',
            'military_status'   => 'completed',
            'residence_province'=> 'IR-01',
            'residence_city'    => 'شهرستان',
            'residence_address' => 'آدرس',
            'billing_email'     => 'new@example.com',
        ];
    }

    public function test_successful_submission(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->validPostData();
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringContainsString('اطلاعات شما با موفقیت ذخیره شد', $html);
        $this->assertStringContainsString('name="billing_email" value="new@example.com"', $html);
        $this->assertSame('نام', $GLOBALS['user_meta']['first_name_fa']);
        $this->assertSame('new@example.com', $GLOBALS['user_meta']['billing_email']);
    }

    public function test_empty_billing_email_is_stored(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $data = $this->validPostData();
        $data['billing_email'] = '';
        $_POST = $data;
        $form = new BasicInfoForm();
        $form->render();
        $this->assertArrayHasKey('billing_email', $GLOBALS['user_meta']);
        $this->assertSame('', $GLOBALS['user_meta']['billing_email']);
        $this->assertSame('old@example.com', $GLOBALS['current_user_email']);
    }

    public function test_military_status_optional_for_male(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $data = $this->validPostData();
        $data['military_status'] = '';
        $_POST = $data;
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringNotContainsString('وضعیت خدمت الزامی است', $html);
        $this->assertArrayHasKey('military_status', $GLOBALS['user_meta']);
        $this->assertSame('', $GLOBALS['user_meta']['military_status']);
    }

    public function test_validation_error_and_repopulate(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $data = $this->validPostData();
        $data['first_name_fa'] = '';
        $data['gender']        = 'female';
        $_POST = $data;
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringContainsString('نام الزامی است.', $html);
        $this->assertStringContainsString('name="last_name_fa" value="خانوادگی"', $html);
        $this->assertArrayNotHasKey('last_name_fa', $GLOBALS['user_meta']);
    }

    public function test_locked_submission_shows_message_and_saves_relations(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $data = $this->validPostData();
        $GLOBALS['user_meta']['first_name_fa'] = 'قبلی';
        $data['first_name_fa'] = 'جدید';
        $data['coach_id']      = '2';
        $data['club_id']       = '3';
        $_POST = $data;
        $GLOBALS['user_meta']['identity_verified_professional'] = 'approved';
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringContainsString('ویرایش سایر اطلاعات پس از تأیید امکان‌پذیر نیست.', $html);
        $this->assertStringContainsString('اطلاعات شما با موفقیت ذخیره شد', $html);
        $this->assertSame('2', $GLOBALS['user_meta']['coach_id']);
        $this->assertSame('3', $GLOBALS['user_meta']['club_id']);
        $this->assertSame('قبلی', $GLOBALS['user_meta']['first_name_fa']);
        $this->assertStringContainsString('name="first_name_fa" value="قبلی"', $html);
    }

    public function test_locked_form_shows_notice_on_get(): void {
        $GLOBALS['user_meta']['identity_verified_professional'] = 'approved';
        $form = new BasicInfoForm();
        $html = $form->render();
        $this->assertStringContainsString('اطلاعات پایه شما تأیید شده است', $html);
    }

    public function test_coach_select_excludes_current_user(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['get_users_return'] = [
            (object) ['ID' => 1, 'display_name' => 'خودم'],
            (object) ['ID' => 2, 'display_name' => 'مربی دیگر'],
        ];
        $form = new BasicInfoForm();
        $html = $form->render();
        preg_match('/<select id="coach_id"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertNotEmpty($m[1] ?? '');
        $this->assertStringNotContainsString('value="1"', $m[1]);
        $this->assertStringContainsString('value="2"', $m[1]);
    }
}

}
