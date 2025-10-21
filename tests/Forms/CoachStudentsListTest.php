<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Forms\CoachStudentsList;

if ( ! function_exists( 'get_users' ) ) {
    function get_users( $args = [] ) {
        $users = $GLOBALS['test_users'] ?? [];
        if ( isset( $args['meta_key'], $args['meta_value'] ) ) {
            $filtered = [];
            foreach ( $users as $user ) {
                $uid  = (int) ( $user->ID ?? 0 );
                $meta = $GLOBALS['test_user_meta'][ $uid ] ?? [];
                if ( isset( $meta[ $args['meta_key'] ] ) && (string) $meta[ $args['meta_key'] ] === (string) $args['meta_value'] ) {
                    $filtered[] = $user;
                }
            }
            $users = $filtered;
        }
        return array_values( $users );
    }
}

if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        $posts = $GLOBALS['test_club_posts'] ?? [];
        if ( isset( $args['author'] ) ) {
            $author = (int) $args['author'];
            $posts  = array_filter(
                $posts,
                static function ( $post ) use ( $author ) {
                    return (int) ( $post->post_author ?? 0 ) === $author;
                }
            );
        }
        return array_values( $posts );
    }
}

if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post_id ) {
        return $GLOBALS['test_post_objects'][ $post_id ] ?? null;
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) {
        return $GLOBALS['test_userdata'][ $user_id ] ?? null;
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle ) {
        $GLOBALS['enqueued_scripts'][] = $handle;
        return true;
    }
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
    function wp_add_inline_script( $handle, $data ) {
        $GLOBALS['inline_scripts'][ $handle ][] = $data;
        return true;
    }
}

class CoachStudentsListTest extends TestCase {
    protected function tearDown(): void {
        unset(
            $GLOBALS['test_is_user_logged_in'],
            $GLOBALS['test_current_user_id'],
            $GLOBALS['test_users'],
            $GLOBALS['test_user_meta'],
            $GLOBALS['test_club_posts'],
            $GLOBALS['test_post_objects'],
            $GLOBALS['test_userdata'],
            $GLOBALS['enqueued_scripts'],
            $GLOBALS['inline_scripts']
        );
    }

    public function test_render_requires_login(): void {
        $GLOBALS['test_is_user_logged_in'] = false;
        $form                             = new CoachStudentsList();
        $html                             = $form->render();
        $this->assertStringContainsString( 'لطفاً وارد شوید', $html );
    }

    public function test_render_outputs_grouped_students(): void {
        $GLOBALS['test_is_user_logged_in'] = true;
        $GLOBALS['test_current_user_id']   = 50;
        $GLOBALS['test_users']             = [
            (object) [ 'ID' => 2, 'display_name' => 'علی رضایی', 'user_email' => 'ali@example.com' ],
            (object) [ 'ID' => 3, 'display_name' => 'سارا محمدی', 'user_email' => 'sara@example.com' ],
        ];
        $GLOBALS['test_user_meta'] = [
            2 => [
                'coach_id'           => 50,
                'club_id'            => 101,
                'billing_phone'      => '9123456789',
                'billing_email'      => '',
                'first_name_fa'      => 'علی',
                'last_name_fa'       => 'رضایی',
                'gender'             => 'male',
                'residence_address'  => "تهران\nخیابان 1",
            ],
            3 => [
                'coach_id'           => 50,
                'club_id'            => 0,
                'billing_phone'      => '9330001122',
                'billing_email'      => 'sara.alt@example.com',
                'first_name_fa'      => 'سارا',
                'last_name_fa'       => 'محمدی',
                'gender'             => 'female',
                'residence_address'  => 'اصفهان، خیابان 2',
            ],
        ];
        $GLOBALS['test_club_posts'] = [
            (object) [ 'ID' => 101, 'post_title' => 'باشگاه الف', 'post_author' => 50 ],
        ];
        $GLOBALS['test_post_objects'] = [
            101 => (object) [ 'ID' => 101, 'post_title' => 'باشگاه الف' ],
        ];
        $GLOBALS['test_post_meta'][101]['club_address'] = 'تهران، میدان آزادی';
        $GLOBALS['test_userdata'][50]                   = (object) [ 'ID' => 50, 'display_name' => 'مربی نمونه' ];

        $form = new CoachStudentsList();
        $html = $form->render();

        $this->assertStringContainsString( 'شاگردان باشگاه باشگاه الف', $html );
        $this->assertStringContainsString( 'شاگردان بدون باشگاه', $html );
        $this->assertStringContainsString( '9123456789', $html );
        $this->assertStringContainsString( 'ali@example.com', $html );
        $this->assertStringContainsString( 'sara.alt@example.com', $html );
        $this->assertStringContainsString( 'coach-student-2', $html );
        $this->assertNotEmpty( $GLOBALS['enqueued_scripts'] ?? [] );
        $this->assertNotEmpty( $GLOBALS['inline_scripts']['jquery'] ?? [] );
    }
}
