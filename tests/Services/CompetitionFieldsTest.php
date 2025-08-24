<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionFieldsTest extends TestCase {
    public function test_detail_fields_have_expected_keys(): void {
        $expected = [
            'course_code','start_date','end_date','exam_date','registration_start','registration_end','attendance','course_time','organizer','organizer_tel','instructor','examiner','supervisor','address','min_degree','points','price'
        ];
        $this->assertSame($expected, array_keys(Competitions::detail_fields()));
    }
}
