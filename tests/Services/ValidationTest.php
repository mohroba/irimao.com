<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Validation;

class ValidationTest extends TestCase {
    public function test_valid_file_passes(): void {
        $file = [ 'type' => 'image/jpeg', 'size' => 500 ];
        $this->assertNull( Validation::file( $file, [ 'image/jpeg' ], 1024, '۱ کیلوبایت' ) );
    }

    public function test_invalid_mime_fails(): void {
        $file = [ 'type' => 'text/plain', 'size' => 500 ];
        $this->assertSame( 'فرمت فایل نامعتبر است.', Validation::file( $file, [ 'image/jpeg' ], 1024, '۱ کیلوبایت' ) );
    }

    public function test_oversize_file_fails(): void {
        $file = [ 'type' => 'image/jpeg', 'size' => 2048 ];
        $this->assertSame( 'حجم فایل باید حداکثر ۱ کیلوبایت باشد.', Validation::file( $file, [ 'image/jpeg' ], 1024, '۱ کیلوبایت' ) );
    }

    public function test_valid_postal_code_passes(): void {
        $this->assertNull( Validation::postal_code( '1234567890' ) );
    }

    public function test_invalid_postal_code_fails(): void {
        $this->assertSame( 'کد پستی باید ۱۰ رقم باشد.', Validation::postal_code( 'abc' ) );
    }
}
