<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionFieldsTest extends TestCase {
    public function test_detail_fields_have_expected_keys(): void {
        $expected = [
            'competition_code',
            'start_date',
            'end_date',
            'registration_start',
            'registration_end',
            'sports_insurance_expiry_date',
            'federation_membership_expiry_date',
            'weigh_in_start_date',
            'weigh_in_start',
            'weigh_in_end_date',
            'weigh_in_end',
            'organizer',
            'organizer_tel',
            'address',
            'min_degree',
            'price',
            'competition_card_background'
        ];
        $this->assertSame($expected, array_keys(Competitions::detail_fields()));
    }
}
