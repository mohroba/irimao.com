<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\FieldLabel;

class FieldLabelTest extends TestCase {
    public function test_gender_label(): void {
        $this->assertSame('مرد', FieldLabel::get('gender', 'male'));
    }

    public function test_education_label(): void {
        $this->assertSame('کارشناسی', FieldLabel::get('education_status', 'bachelor'));
    }

    public function test_middle_school_label(): void {
        $this->assertSame('محصل - راهنمایی', FieldLabel::get('education_status', 'student_middle_school'));
    }

    public function test_diploma_label(): void {
        $this->assertSame('دیپلم', FieldLabel::get('education_status', 'diploma'));
    }

    public function test_province_label(): void {
        $this->assertSame('آذربایجان غربی', FieldLabel::get('birth_province', 'IR-02'));
    }

    public function test_unknown_value_returns_original(): void {
        $this->assertSame('unknown', FieldLabel::get('education_status', 'unknown'));
    }
}
