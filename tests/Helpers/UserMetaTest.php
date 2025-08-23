<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\UserMeta;

class UserMetaTest extends TestCase {
    public function test_get_many_returns_array(): void {
        if ( ! function_exists( 'get_user_meta' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $result = UserMeta::get_many( 1, [ 'foo', 'bar' => 'baz' ] );
        $this->assertIsArray( $result );
    }
}
