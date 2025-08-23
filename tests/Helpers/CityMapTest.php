<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\CityMap;

class CityMapTest extends TestCase {
    public function test_map_loaded(): void {
        $map = CityMap::get_map();
        $this->assertNotEmpty( $map );
    }

    public function test_tehran_cities_exist(): void {
        $cities = CityMap::get_cities( 'IR-08' );
        $this->assertContains( 'تهران', $cities );
    }
}
