<?php
error_reporting(E_ALL & ~E_DEPRECATED);
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
                'registration_start' => '1400/01/01',
                'registration_end'   => '1500/12/29',
            ],
            20  => [
                'competition_code'   => 'COMP20',
                'registration_start' => '1400/01/01',
                'registration_end'   => '1500/12/29',
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
if ( ! function_exists( 'get_terms' ) ) {
    function get_terms( $args ) {
        $taxonomy = is_array( $args ) ? ( $args['taxonomy'] ?? '' ) : '';
        if ( $taxonomy === 'competition_type' ) {
            $all = [
                501 => 'کومیته',
                502 => 'کاتا',
                503 => 'تیمی',
            ];
            $include = isset( $args['include'] ) ? array_map( 'intval', (array) $args['include'] ) : array_keys( $all );
            $terms   = [];
            foreach ( $include as $id ) {
                $name    = $all[ $id ] ?? ( 'نوع ' . $id );
                $terms[] = (object) [ 'term_id' => $id, 'name' => $name, 'slug' => (string) $id, 'parent' => 0 ];
            }
            return $terms;
        }
        $parent = is_array( $args ) && array_key_exists( 'parent', $args ) ? (int) $args['parent'] : null;
        $all    = [
            (object) [ 'term_id' => 1, 'name' => 'Parent Age', 'slug' => 'adults-18-38', 'parent' => 0 ],
            (object) [ 'term_id' => 2, 'name' => 'Child Weight', 'slug' => 'adults-light', 'parent' => 1 ],
            (object) [ 'term_id' => 3, 'name' => 'Teenage', 'slug' => 'teenagers-12-14', 'parent' => 0 ],
            (object) [ 'term_id' => 4, 'name' => 'Junior', 'slug' => 'toddlers-7-11', 'parent' => 0 ],
        ];
        if ( $parent !== null ) {
            $filtered = [];
            foreach ( $all as $term ) {
                if ( $term->parent === $parent ) {
                    $filtered[] = $term;
                }
            }
            return $filtered;
        }
        return $all;
    }
}
if ( ! function_exists( 'get_term_meta' ) ) {
    function get_term_meta( $term_id, $key, $single = true ) {
        $map = [
            1 => [ 'age_start' => 18, 'age_end' => 38 ],
            2 => [ 'age_start' => 15, 'age_end' => 17 ],
            3 => [ 'age_start' => 12, 'age_end' => 14 ],
            4 => [ 'age_start' => 7,  'age_end' => 11 ],
        ];
        return $map[ $term_id ][ $key ] ?? '';
    }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
    function wp_get_post_terms( $id, $tax, $args = [] ) {
        if ( isset( $GLOBALS['mock_post_terms_return'] ) ) {
            $source = $GLOBALS['mock_post_terms_return'];
            if ( is_array( $source ) ) {
                if ( array_key_exists( $tax, $source ) ) {
                    $ret = $source[ $tax ];
                } elseif ( array_key_exists( 0, $source ) ) {
                    $ret = $source;
                } else {
                    $ret = [];
                }
            } else {
                $ret = $source;
            }
        } elseif ( $tax === 'age_category' ) {
            $ret = [
                (object) [ 'term_id' => 1, 'name' => 'Parent Age', 'slug' => 'adults-18-38', 'parent' => 0 ],
                (object) [ 'term_id' => 2, 'name' => 'Child Weight', 'slug' => 'adults-light', 'parent' => 1 ],
            ];
            if ( isset( $args['parent'] ) && (int) $args['parent'] === 0 ) {
                $ret = array_values( array_filter( $ret, static function ( $item ) {
                    if ( is_object( $item ) ) {
                        return (int) ( $item->parent ?? 0 ) === 0;
                    }
                    if ( is_array( $item ) ) {
                        return (int) ( $item['parent'] ?? 0 ) === 0;
                    }
                    return false;
                } ) );
            }
        } elseif ( $tax === 'competition_type' ) {
            $ret = [
                (object) [ 'term_id' => 501, 'name' => 'کومیته', 'slug' => 'kumite', 'parent' => 0 ],
                (object) [ 'term_id' => 502, 'name' => 'کاتا', 'slug' => 'kata', 'parent' => 0 ],
            ];
        } else {
            $ret = [];
        }

        if ( ( $args['fields'] ?? '' ) === 'ids' ) {
            return array_map( 'intval', (array) $ret );
        }
        if ( ( $args['fields'] ?? '' ) === 'names' ) {
            $names = [];
            foreach ( (array) $ret as $item ) {
                if ( is_object( $item ) && isset( $item->name ) ) {
                    $names[] = $item->name;
                } elseif ( is_array( $item ) && isset( $item['name'] ) ) {
                    $names[] = $item['name'];
                } else {
                    $names[] = (string) $item;
                }
            }
            return $names;
        }
        if ( ( $args['fields'] ?? '' ) === 'slugs' ) {
            $slugs = [];
            foreach ( (array) $ret as $item ) {
                if ( is_object( $item ) && isset( $item->slug ) ) {
                    $slugs[] = $item->slug;
                } elseif ( is_array( $item ) && isset( $item['slug'] ) ) {
                    $slugs[] = $item['slug'];
                } else {
                    $slugs[] = (string) $item;
                }
            }
            return $slugs;
        }

        return $ret;
    }
}
if ( ! function_exists( 'get_term' ) ) {
    function get_term( $id, $tax ) {
        if ( isset( $GLOBALS['mock_terms'][ $id ] ) ) {
            return (object) $GLOBALS['mock_terms'][ $id ];
        }
        return (object) [ 'term_id' => $id, 'name' => 'Term', 'parent' => 0 ];
    }
}
if ( ! function_exists( 'wp_set_post_terms' ) ) {
    function wp_set_post_terms( $post_id, $terms, $tax ) {
        $GLOBALS['wp_set_post_terms_last'] = [ $post_id, $terms, $tax ];
        return true;
    }
}
