<?php
namespace IMAOCustom\Forms {
    if ( ! function_exists( __NAMESPACE__ . '\\get_user_meta' ) ) {
        function get_user_meta( $uid, $key, $single = true ) {
            return $GLOBALS['user_meta'][$key] ?? '';
        }
    }
}

namespace Tests\Forms {
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\IdentityProfessionalForm;
use ReflectionMethod;

class IdentityProfessionalFormSubmissionTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['user_meta'] = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function fillRequired(): void {
        $required = [
            'national_id','first_name_fa','last_name_fa','gender','father_name',
            'birth_date','birth_province','birth_city','marital_status',
            'education_status','residence_province','residence_city',
            'residence_address','billing_phone'
        ];
        foreach ( $required as $k ) {
            $GLOBALS['user_meta'][$k] = 'x';
        }
    }

    public function test_has_basic_info_without_email(): void {
        $this->fillRequired();
        $form = new IdentityProfessionalForm();
        $ref  = new ReflectionMethod( IdentityProfessionalForm::class, 'has_basic_info' );
        $ref->setAccessible( true );
        $this->assertTrue( $ref->invoke( $form, 1 ) );
    }
}
}
