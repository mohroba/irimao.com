<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\CourseData;

class CourseDataTest extends TestCase {
    public function test_columns_have_expected_keys(): void {
        $cols = CourseData::columns();
        $expected = ['course_code','course_type','level','board','age_category','attendance','gender'];
        $this->assertSame($expected, array_keys($cols));
    }
}
