<?php
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() { return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 1; }
}
if ( ! class_exists( 'Test_Date' ) ) {
    class Test_Date {
        public function date_i18n( $format ) { return '2024/01/01'; }
    }
}
if ( ! class_exists( 'Test_Item' ) ) {
    class Test_Item {
        private int $pid;
        private float $total;
        private string $weight;
        public function __construct( int $pid, float $total, string $weight = '' ) {
            $this->pid   = $pid;
            $this->total = $total;
            $this->weight = $weight;
        }
        public function get_product_id() { return $this->pid; }
        public function get_total() { return $this->total; }
        public function get_meta( $key, $single = true ) { return $this->weight; }
    }
}
if ( ! class_exists( 'Test_Order' ) ) {
    class Test_Order {
        /** @return array<int,Test_Item> */
        public function get_items() {
            return [ new Test_Item( 100, 1000, 'Light' ), new Test_Item( 200, 2000, 'Heavy' ) ];
        }
        public function get_id() { return 1; }
        public function get_date_created() { return new Test_Date(); }
        public function get_status() { return 'completed'; }
    }
}
if ( ! function_exists( 'wc_get_orders' ) ) {
    /** @return array<int,Test_Order> */
    function wc_get_orders( $args ) { return [ new Test_Order() ]; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single = true ) {
        $map = [
            100 => [ '_linked_post_id' => 10 ],
            200 => [ '_linked_post_id' => 20 ],
            10  => [
                'course_code'        => 'C123',
                'start_date'         => '2024-01-01',
                'registration_start' => '1402/01/01',
                'registration_end'   => '1402/12/29',
            ],
            20  => [
                'competition_code'   => 'COMP20',
                'registration_start' => '1402/01/01',
                'registration_end'   => '1402/12/29',
            ],
        ];
        return $map[ $id ][ $key ] ?? '';
    }
}
if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $id ) {
        return $id === 10 ? 'course' : ( $id === 20 ? 'competition' : '' );
    }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $id ) {
        return $id === 10 ? 'Course Title' : ( $id === 20 ? 'Competition Title' : '' );
    }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return '/?p=' . $id; }
}
if ( ! function_exists( 'wc_get_order_status_name' ) ) {
    function wc_get_order_status_name( $status ) { return 'Completed'; }
}
if ( ! function_exists( 'wc_price' ) ) {
    function wc_price( $amount ) { return (string) $amount; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) { return $s; }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $s ) { return $s; }
}
