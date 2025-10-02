<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\SelfDeclarationData;

class SelfDeclarationDataTest extends TestCase {
    public function test_degree_label_returns_persian_label_when_defined(): void {
        $label = SelfDeclarationData::degree_label(2, '2');
        $this->assertSame('درجه ۳', $label);
    }

    public function test_degree_label_returns_value_when_not_found(): void {
        $label = SelfDeclarationData::degree_label(999, 'foo');
        $this->assertSame('foo', $label);
    }

    public function test_degree_label_returns_dash_when_empty(): void {
        $label = SelfDeclarationData::degree_label(1, '');
        $this->assertSame('—', $label);
    }

    public function test_degree_options_for_type_one_matches_list(): void {
        $this->assertSame(SelfDeclarationData::list_degrees(), SelfDeclarationData::degree_options()[1]);
    }

    public function test_champion_age_map_returns_terms(): void {
        require_once __DIR__ . '/../Services/stubs.php';
        $map = SelfDeclarationData::champion_age_map();
        $this->assertArrayHasKey(1, $map);
        $this->assertSame('Parent Age', $map[1]['label']);
        $this->assertArrayHasKey(2, $map[1]['weights']);
    }
}
