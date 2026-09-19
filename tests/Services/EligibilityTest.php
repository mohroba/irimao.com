<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Eligibility;

if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = '') {
        $GLOBALS['test_notices'][] = [$message, $type];
    }
}

class EligibilityTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['test_post_meta'] = [];
        $GLOBALS['test_user_meta'] = [];
        $GLOBALS['test_notices'] = [];
        $GLOBALS['test_current_user_id'] = 1;
        unset($GLOBALS['mock_post_terms_return']);
    }

    public function test_disallows_course_gender_mismatch(): void {
        $GLOBALS['test_post_meta'][99]['_linked_post_id'] = 10;
        $GLOBALS['test_user_meta'][1]['gender'] = 'male';
        $GLOBALS['test_user_meta'][1]['identity_verified_professional'] = 'approved';
        $GLOBALS['mock_post_terms_return'] = ['gender' => [(object) ['slug' => 'women']]];
        $this->assertFalse((new Eligibility())->validate(true, 99, 1));
        $this->assertSame('این مورد با جنسیت شما سازگار نیست.', $GLOBALS['test_notices'][0][0]);
    }

    public function test_allows_course_gender_match_with_normalization(): void {
        $GLOBALS['test_post_meta'][99]['_linked_post_id'] = 10;
        $GLOBALS['test_user_meta'][1]['gender'] = 'female';
        $GLOBALS['test_user_meta'][1]['identity_verified_professional'] = 'approved';
        $GLOBALS['mock_post_terms_return'] = ['gender' => [(object) ['slug' => 'women']]];
        $this->assertTrue((new Eligibility())->validate(true, 99, 1));
        $this->assertEmpty($GLOBALS['test_notices']);
    }

    public function test_competition_uses_requested_gender_message(): void {
        $GLOBALS['test_post_meta'][99]['_linked_post_id'] = 20;
        $GLOBALS['test_user_meta'][1]['gender'] = 'male';
        $GLOBALS['test_user_meta'][1]['identity_verified_professional'] = 'approved';
        $GLOBALS['mock_post_terms_return'] = ['gender' => [(object) ['slug' => 'women']]];
        $this->assertFalse((new Eligibility())->validate(true, 99, 1));
        $this->assertSame('این مسابقات با جنسیت شما مطابقت ندارد، لطفا در انتخاب مسابقات دقت فرمایید.', $GLOBALS['test_notices'][0][0]);
    }

    public function test_competition_uses_requested_age_message(): void {
        $GLOBALS['test_post_meta'][99]['_linked_post_id'] = 20;
        $GLOBALS['test_post_meta'][20]['start_date'] = '1405/06/28';
        $GLOBALS['test_user_meta'][1]['gender'] = 'male';
        $GLOBALS['test_user_meta'][1]['birth_date'] = '1395/01/01';
        $GLOBALS['test_user_meta'][1]['identity_verified_professional'] = 'approved';
        $GLOBALS['mock_post_terms_return'] = [
            'gender' => [(object) ['slug' => 'men']],
            'age_category' => [(object) ['slug' => 'adults-18-38']],
        ];
        $this->assertFalse((new Eligibility())->validate(true, 99, 1));
        $this->assertSame('این مسابقات با رده ی سنی شما مطابقت ندارد، لطفا در انتخاب مسابقات دقت فرمایید.', $GLOBALS['test_notices'][0][0]);
    }

    public function test_competition_age_is_determined_on_event_date(): void {
        $GLOBALS['test_post_meta'][99]['_linked_post_id'] = 20;
        $GLOBALS['test_post_meta'][20]['start_date'] = '1405/06/28';
        $GLOBALS['test_user_meta'][1] = [
            'gender' => 'male',
            'birth_date' => '1393/06/28',
            'identity_verified_professional' => 'approved',
        ];
        $GLOBALS['mock_post_terms_return'] = [
            'gender' => [(object) ['slug' => 'men']],
            'age_category' => [(object) ['slug' => 'teenagers-12-14']],
        ];

        $this->assertTrue((new Eligibility())->validate(true, 99, 1));

        $GLOBALS['test_post_meta'][20]['start_date'] = '1405/06/27';
        $this->assertFalse((new Eligibility())->validate(true, 99, 1));
    }
}
