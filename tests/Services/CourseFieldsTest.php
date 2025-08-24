<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;
use IMAOCustom\Services\Endpoints\CourseDetails;

class CourseFieldsTest extends TestCase {
    public function test_detail_fields_have_expected_keys(): void {
        $expected = [
            'course_code','start_date','end_date','exam_date','registration_start','registration_end','attendance','course_time','organizer','organizer_tel','instructor','examiner','supervisor','address','min_degree','points','price'
        ];
        $this->assertSame($expected, array_keys(Courses::detail_fields()));
    }

    public function test_details_fields_have_expected_keys(): void {
        $expected = [
            'course_code','course_type','course_name','start_date','end_date','exam_date','registration_start','registration_end','board','gender','level','attendance','course_time','organizer','organizer_tel','instructor','examiner','supervisor','address','min_degree','points','price'
        ];
        $this->assertSame($expected, array_keys(CourseDetails::fields()));
    }
}
