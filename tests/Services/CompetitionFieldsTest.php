<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionFieldsTest extends TestCase {
    public function test_detail_fields_have_expected_keys(): void {
        $expected = [
            'competition_code','start_date','end_date','registration_start','registration_end','organizer','organizer_tel','supervisor','address','min_degree','price'
        ];
        $this->assertSame($expected, array_keys(Competitions::detail_fields()));
    }
}
