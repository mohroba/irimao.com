<?php
namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Forms\CoachStudentsList;

class CoachStudents {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_coach-students_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_coach_students', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'coach-students', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return $items;
        }
        $user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
        if ( $user && ! empty( $user->roles ) && in_array( 'coach', (array) $user->roles, true ) ) {
            $items['coach-students'] = 'شاگردان من';
        }
        return $items;
    }

    public function content(): void {
        echo do_shortcode( '[crm_coach_students]' );
    }

    public function shortcode(): string {
        $form = new CoachStudentsList();
        return $form->render();
    }
}
