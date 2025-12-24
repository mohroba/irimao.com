<?php
namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\FieldLabel;

class CoachStudentsList {
    /**
     * Render the list of athletes grouped by the coach's clubs.
     */
    public function render(): string {
        if ( ! function_exists( '\\is_user_logged_in' ) || ! \is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">لطفاً وارد شوید.</p>';
        }

        $coach_id = function_exists( '\\get_current_user_id' ) ? (int) \get_current_user_id() : 0;
        if ( $coach_id <= 0 ) {
            return '<p style="text-align:center;color:#c00;">شناسه کاربر نامعتبر است.</p>';
        }

        $students = $this->fetch_students( $coach_id );
        if ( empty( $students ) ) {
            return '<p style="text-align:center;">شاگردی برای شما ثبت نشده است.</p>';
        }

        $groups = $this->group_by_club( $coach_id, $students );
        $this->enqueue_assets();

        ob_start();
        echo '<div class="sd-container"><div class="sd-header" style="margin-bottom:20px">شاگردان تحت مربیگری شما</div>';
        foreach ( $groups as $club_id => $data ) {
            $club   = $data['club'];
            $people = $data['students'];
            $header = $club_id === 0
                ? 'شاگردان بدون باشگاه'
                : sprintf( 'شاگردان باشگاه %s', $club['name'] ?? '' );
            echo '<div class="sd-table-responsive" style="margin-bottom:25px;">';
            echo '<div class="sd-header" style="margin-bottom:10px;text-align:right;">' . esc_html( $header ) . '</div>';
            if ( ! empty( $club['address'] ) ) {
                echo '<p style="margin:0 0 10px;text-align:right;">آدرس باشگاه: ' . esc_html( $club['address'] ) . '</p>';
            }
            echo '<table class="table table-striped table-hover table-responsive-sm custom-table rtl tbl-loader" style="width:100%;">';
            echo '<thead style="background:#ff92008f;color:#000;"><tr>';
            echo '<th class="text-center" style="font-size:12px;text-align:center">ردیف</th>';
            echo '<th class="text-center" style="font-size:12px;text-align:center">نام کاربر</th>';
            echo '<th class="text-center" style="font-size:12px;text-align:center">باشگاه</th>';
            echo '<th class="text-center" style="font-size:12px;text-align:center">موبایل</th>';
            echo '<th class="text-center" style="font-size:12px;text-align:center">ایمیل</th>';
            echo '<th class="text-center" style="font-size:12px;text-align:center">اقدام</th>';
            echo '</tr></thead><tbody>';
            $i = 1;
            foreach ( $people as $person ) {
                $row_id      = 'coach-student-' . $person['id'];
                $club_label  = $club_id === 0 ? '—' : ( $club['name'] ?? '—' );
                $phone_label = $person['meta']['billing_phone'] ?? '';
                $email_label = $person['meta']['billing_email'] ?? $person['email'];
                echo '<tr>';
                echo '<td class="text-center" style="font-size:12px;text-align:center">' . $i . '</td>';
                echo '<td class="text-center" style="font-size:12px;text-align:center">' . esc_html( $person['name'] ) . '</td>';
                echo '<td class="text-center" style="font-size:12px;text-align:center">' . esc_html( $club_label ) . '</td>';
                echo '<td class="text-center" style="font-size:12px;text-align:center">' . esc_html( $phone_label ?: '—' ) . '</td>';
                echo '<td class="text-center" style="font-size:12px;text-align:center">' . esc_html( $email_label ?: '—' ) . '</td>';
                echo '<td class="text-center" style="font-size:12px;">';
                echo '<button type="button" class="imao-btn sm coach-student-toggle" data-target="' . esc_attr( $row_id ) . '" data-name="' . esc_attr( $person['name'] ) . '">نمایش جزئیات</button>';
                echo '</td>';
                echo '</tr>';
                echo '<tr id="' . esc_attr( $row_id ) . '" class="coach-student-details" style="display:none;">';
                echo '<td colspan="6"><div class="coach-student-details-inner">' . $this->render_details( $person, $club ) . '</div></td>';
                echo '</tr>';
                $i++;
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array{club: array<string,string>, students: array<int,array<string,mixed>>}>
     */
    private function group_by_club( int $coach_id, array $students ): array {
        $clubs   = $this->fetch_coach_clubs( $coach_id );
        $groups  = [];
        foreach ( $students as $student ) {
            $club_id = (int) ( $student['meta']['club_id'] ?? 0 );
            if ( $club_id !== 0 && ! isset( $clubs[ $club_id ] ) ) {
                $clubs[ $club_id ] = $this->club_info_from_post( $club_id );
            }
            $groups[ $club_id ][] = $student;
        }

        $ordered = [];
        foreach ( $clubs as $club_id => $club ) {
            if ( empty( $groups[ $club_id ] ) ) {
                continue;
            }
            $ordered[ $club_id ] = [
                'club'     => $club,
                'students' => $groups[ $club_id ],
            ];
            unset( $groups[ $club_id ] );
        }

        if ( ! empty( $groups[0] ) ) {
            $ordered[0] = [
                'club'     => [ 'name' => 'شاگردان بدون باشگاه', 'address' => '' ],
                'students' => $groups[0],
            ];
            unset( $groups[0] );
        }

        foreach ( $groups as $club_id => $list ) {
            $ordered[ $club_id ] = [
                'club'     => $clubs[ $club_id ] ?? $this->club_info_from_post( $club_id ),
                'students' => $list,
            ];
        }

        return $ordered;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function fetch_coach_clubs( int $coach_id ): array {
        if ( ! function_exists( '\\get_posts' ) ) {
            return [];
        }
        $posts = \get_posts( [
            'post_type'   => 'club_application',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'author'      => $coach_id,
        ] );
        $clubs = [];
        foreach ( $posts as $post ) {
            $id    = (int) ( $post->ID ?? 0 );
            $clubs[ $id ] = [
                'name'    => (string) ( $post->post_title ?? '' ),
                'address' => function_exists( '\\get_post_meta' ) ? (string) \get_post_meta( $id, 'club_address', true ) : '',
            ];
        }
        return $clubs;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch_students( int $coach_id ): array {
        if ( ! function_exists( '\\get_users' ) ) {
            return [];
        }
        $users = \get_users( [
            'meta_key'   => 'coach_id',
            'meta_value' => (string) $coach_id,
            'fields'     => [ 'ID', 'display_name', 'user_email' ],
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ] );
        $students = [];
        foreach ( $users as $user ) {
            $user_id = (int) ( $user->ID ?? 0 );
            if ( $user_id <= 0 ) {
                continue;
            }
            $meta = [];
            foreach ( $this->detail_fields() as $key => $_label ) {
                $meta[ $key ] = function_exists( '\\get_user_meta' ) ? \get_user_meta( $user_id, $key, true ) : '';
            }
            if ( empty( $meta['billing_email'] ) ) {
                $meta['billing_email'] = (string) ( $user->user_email ?? '' );
            }
            $enrollments = $this->student_enrollments( $user_id );
            $students[] = [
                'id'          => $user_id,
                'name'        => (string) ( $user->display_name ?? '' ),
                'email'       => (string) ( $user->user_email ?? '' ),
                'meta'        => $meta,
                'enrollments' => $enrollments,
            ];
        }
        return $students;
    }

    private function enqueue_assets(): void {
        if ( function_exists( '\\wp_enqueue_script' ) ) {
            \wp_enqueue_script( 'jquery' );
        }
        if ( function_exists( '\\wp_add_inline_script' ) ) {
            $script = <<<'JS'
            jQuery(function ($) {
                $('.coach-student-toggle').on('click', function () {
                    var $btn = $(this);
                    var targetId = $btn.data('target');
                    var $row = $('#' + targetId);
                    if (!targetId || !$row.length) {
                        return;
                    }
                    var title = $btn.data('name') || 'جزئیات شاگرد';
                    var content = $row.find('.coach-student-details-inner').first().html();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: title,
                            html: content,
                            width: '70%',
                            customClass: { popup: 'coach-student-modal' },
                            confirmButtonText: 'بستن'
                        });
                    } else {
                        alert('جزئیات در این مرورگر قابل نمایش نیست.');
                    }
                });
            });
            JS;
            \wp_add_inline_script( 'jquery', $script );
        }
    }

    /**
     * @return array<string,string>
     */
    private function detail_fields(): array {
        return [
            'billing_phone'      => 'شماره موبایل',
            'billing_email'      => 'ایمیل',
            'national_id'        => 'کد ملی',
            'first_name_fa'      => 'نام (فارسی)',
            'last_name_fa'       => 'نام خانوادگی (فارسی)',
            'first_name_en'      => 'نام (En)',
            'last_name_en'       => 'نام خانوادگی (En)',
            'gender'             => 'جنسیت',
            'father_name'        => 'نام پدر',
            'birth_date'         => 'تاریخ تولد',
            'birth_province'     => 'استان محل تولد',
            'birth_city'         => 'شهرستان محل تولد',
            'marital_status'     => 'وضعیت تأهل',
            'education_status'   => 'وضعیت تحصیلی',
            'military_status'    => 'وضعیت خدمت',
            'residence_province' => 'استان محل سکونت',
            'residence_city'     => 'شهرستان محل سکونت',
            'postal_code'        => 'کد پستی',
            'residence_address'  => 'آدرس محل سکونت',
            'iban'               => 'شبا',
            'card_number'        => 'شماره کارت',
            'club_id'            => 'باشگاه',
            'coach_id'           => 'مربی',
        ];
    }

    /**
     * @param array<string,mixed> $student
     * @param array<string,string> $club
     */
    private function render_details( array $student, array $club ): string {
        $fields = $this->detail_fields();
        $club_map = [ 0 => [ 'name' => '—' ] ];
        if ( ! empty( $club['name'] ) ) {
            $club_map[ (int) ( $student['meta']['club_id'] ?? 0 ) ] = [ 'name' => $club['name'] ];
        }
        ob_start();
        echo '<div class="coach-student-details-wrap">';
        echo '<table class="table table-striped table-hover" style="width:100%;margin:0;">';
        echo '<tbody>';
        foreach ( $fields as $key => $label ) {
            $raw = $student['meta'][ $key ] ?? '';
            if ( $key === 'club_id' ) {
                $club_id = (int) $raw;
                $raw     = $this->club_label( $club_id, $club_map );
            } elseif ( $key === 'coach_id' ) {
                $raw = $this->coach_label( (int) $raw );
            } elseif ( $key === 'residence_address' ) {
                $raw = nl2br( esc_html( (string) $raw ) );
                echo '<tr><th style="text-align:right;font-size:12px;width:160px;">' . esc_html( $label ) . '</th><td style="font-size:12px;line-height:1.8;">' . ( $raw !== '' ? $raw : '—' ) . '</td></tr>';
                continue;
            } else {
                $raw = FieldLabel::get( $key, $raw );
            }
            $value = $raw !== '' ? esc_html( (string) $raw ) : '—';
            echo '<tr><th style="text-align:right;font-size:12px;width:160px;">' . esc_html( $label ) . '</th><td style="font-size:12px;">' . $value . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $enrollments = $student['enrollments'] ?? [ 'competitions' => [], 'courses' => [] ];
        echo '<div class="coach-details-section">';
        echo '<h5>مسابقات دانشجو</h5>';
        if ( empty( $enrollments['competitions'] ) ) {
            echo '<p class="coach-details-empty">رکوردی یافت نشد.</p>';
        } else {
            echo '<table class="coach-details-table"><thead><tr><th>نام</th><th>کد</th><th>شماره سفارش</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
            foreach ( $enrollments['competitions'] as $comp ) {
                echo '<tr>';
                echo '<td>' . esc_html( $comp['title'] ) . '</td>';
                echo '<td>' . esc_html( $comp['code'] ) . '</td>';
                echo '<td>#' . esc_html( (string) $comp['order_id'] ) . '</td>';
                echo '<td>' . esc_html( $comp['date'] ) . '</td>';
                echo '<td>' . esc_html( $comp['amount'] ) . '</td>';
                echo '<td>' . esc_html( $comp['status'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '<div class="coach-details-section">';
        echo '<h5>دوره‌های خریداری‌شده</h5>';
        if ( empty( $enrollments['courses'] ) ) {
            echo '<p class="coach-details-empty">رکوردی یافت نشد.</p>';
        } else {
            echo '<table class="coach-details-table"><thead><tr><th>نام</th><th>کد</th><th>شماره سفارش</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
            foreach ( $enrollments['courses'] as $course ) {
                echo '<tr>';
                echo '<td>' . esc_html( $course['title'] ) . '</td>';
                echo '<td>' . esc_html( $course['code'] ) . '</td>';
                echo '<td>#' . esc_html( (string) $course['order_id'] ) . '</td>';
                echo '<td>' . esc_html( $course['date'] ) . '</td>';
                echo '<td>' . esc_html( $course['amount'] ) . '</td>';
                echo '<td>' . esc_html( $course['status'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * @param array<int,array<string,string>> $club_map
     */
    private function club_label( int $club_id, array $club_map ): string {
        if ( isset( $club_map[ $club_id ] ) ) {
            return (string) ( $club_map[ $club_id ]['name'] ?? '' );
        }
        $info = $this->club_info_from_post( $club_id );
        return $info['name'];
    }

    private function coach_label( int $coach_id ): string {
        if ( $coach_id <= 0 || ! function_exists( '\\get_userdata' ) ) {
            return '';
        }
        $user = \get_userdata( $coach_id );
        return $user && isset( $user->display_name ) ? (string) $user->display_name : '';
    }

    /**
     * @return array{name:string,address:string}
     */
    private function club_info_from_post( int $club_id ): array {
        if ( $club_id <= 0 ) {
            return [ 'name' => 'باشگاه نامشخص', 'address' => '' ];
        }
        if ( function_exists( '\\get_post' ) ) {
            $post = \get_post( $club_id );
            if ( $post ) {
                $name = (string) ( $post->post_title ?? '' );
                $addr = function_exists( '\\get_post_meta' ) ? (string) \get_post_meta( $club_id, 'club_address', true ) : '';
                return [
                    'name'    => $name ?: sprintf( 'باشگاه #%d', $club_id ),
                    'address' => $addr,
                ];
            }
        }
        return [ 'name' => sprintf( 'باشگاه #%d', $club_id ), 'address' => '' ];
    }

    /**
     * @return array{competitions:array<int,array<string,string>>,courses:array<int,array<string,string>>}
     */
    private function student_enrollments( int $user_id ): array {
        if ( ! function_exists( '\\wc_get_orders' ) ) {
            return [ 'competitions' => [], 'courses' => [] ];
        }
        $orders = \wc_get_orders( [
            'customer_id' => $user_id,
            'limit'       => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => [ 'completed', 'processing', 'pending', 'on-hold' ],
        ] );
        $competitions = [];
        $courses      = [];
        foreach ( $orders as $order ) {
            $order_id   = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
            $order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d' ) : '';
            $status     = \wc_get_order_status_name( $order->get_status() );
            foreach ( $order->get_items() as $item ) {
                $product_id = (int) $item->get_product_id();
                $linked_id  = (int) \get_post_meta( $product_id, '_linked_post_id', true );
                if ( ! $linked_id ) {
                    continue;
                }
                $type  = get_post_type( $linked_id );
                $entry = [
                    'title'    => (string) get_the_title( $linked_id ),
                    'code'     => '',
                    'order_id' => (string) $order_id,
                    'date'     => $order_date,
                    'amount'   => \wc_price( $item->get_total() ),
                    'status'   => $status,
                ];
                if ( $type === 'competition' ) {
                    $entry['code'] = (string) get_post_meta( $linked_id, 'competition_code', true );
                    $competitions[] = $entry;
                } elseif ( $type === 'course' ) {
                    $entry['code'] = (string) get_post_meta( $linked_id, 'course_code', true );
                    $courses[] = $entry;
                }
            }
        }
        return [
            'competitions' => $competitions,
            'courses'      => $courses,
        ];
    }
}
