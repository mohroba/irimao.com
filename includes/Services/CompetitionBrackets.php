<?php

namespace IMAOCustom\Services;

use WC_Order_Item_Product;
use WP_Post;

class CompetitionBrackets {
    private const META_BRACKET = '_imao_competition_bracket';
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_MANUAL = '_manual_attendees';
    private const MAX_SIZE = 256;

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_competition', [ $this, 'save_admin_changes' ], 30, 2 );
        add_action( 'admin_post_imao_competition_bracket', [ $this, 'render_page' ] );
        add_action( 'admin_post_nopriv_imao_competition_bracket', [ $this, 'render_page' ] );
        add_shortcode( 'crm_competition_bracket', [ $this, 'bracket_shortcode' ] );
        add_shortcode( 'crm_competition_bracket_button', [ $this, 'button_shortcode' ] );
    }

    public function add_meta_box(): void {
        add_meta_box( 'imao_competition_bracket', 'جدول حذفی مسابقه', [ $this, 'render_meta_box' ], 'competition', 'normal', 'default' );
    }

    public static function page_url( int $competition_id ): string {
        return add_query_arg( [ 'action' => 'imao_competition_bracket', 'competition_id' => $competition_id ], admin_url( 'admin-post.php' ) );
    }

    public function render_meta_box( WP_Post $post ): void {
        $snapshot = self::snapshot( $post->ID );
        $count = count( $this->participants( $post->ID ) );
        wp_nonce_field( 'imao_save_bracket_' . $post->ID, 'imao_bracket_nonce' );
        echo '<p>شرکت‌کنندگانِ پرداخت‌شده با تأیید وزن‌کشی: <strong>' . (int) $count . '</strong></p>';
        echo '<p class="description">فقط ورزشکارانی که تأیید وزن‌کشی آن‌ها فعال است وارد جدول حذفی می‌شوند.</p>';
        echo '<p><button type="submit" class="button button-primary" name="imao_bracket_action" value="generate">' . ( $snapshot ? 'تولید مجدد جدول' : 'تولید جدول' ) . '</button> ';
        if ( $snapshot ) {
            echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::page_url( $post->ID ) ) . '">مشاهده جدول</a></p>';
            $divisions = self::snapshot_divisions( $snapshot );
            echo '<p>تعداد جدول‌ها: ' . count( $divisions ) . ' | معیار جداسازی: ' . esc_html( self::criterion_label( (string) $snapshot['criterion'] ) ) . ' | تولید: ' . esc_html( (string) $snapshot['generated_at'] ) . '</p>';
            if ( (string) ( $snapshot['criterion'] ?? '' ) !== $this->criterion( $post->ID ) ) {
                echo '<p class="notice notice-warning" style="padding:10px">معیار جداسازی این جدول با سطح فعلی مسابقه متفاوت است. برای اعمال معیار جدید، جدول باید پس از بررسی نتایج ثبت‌شده توسط مدیر تولید مجدد شود.</p>';
            }
            foreach ( $divisions as $division ) {
                $empty_matches = self::empty_opening_matches( $division['slots'] ?? [] );
                if ( $empty_matches > 0 ) {
                    echo '<p class="notice notice-warning" style="padding:10px">در جدول «' . esc_html( (string) $division['label'] ) . '» تعداد ' . (int) $empty_matches . ' مسابقهٔ دور اول کاملاً خالی است. پیش از تولید مجدد، نتایج ثبت‌شده و تأیید وزن‌کشی شرکت‌کنندگان را بررسی کنید؛ تولید مجدد جایگاه‌ها را تغییر می‌دهد.</p>';
                }
            }
            $this->render_result_fields( $snapshot );
            echo '<p><button type="submit" class="button button-primary" name="imao_bracket_action" value="results">ذخیره نتایج</button></p>';
            if ( ! empty( $snapshot['audit'] ) ) {
                echo '<details><summary>تاریخچه تغییرات (' . count( $snapshot['audit'] ) . ')</summary><ol>';
                foreach ( array_reverse( $snapshot['audit'] ) as $row ) {
                    echo '<li>' . esc_html( (string) ( $row['at'] ?? '' ) . ' — ' . (string) ( $row['message'] ?? '' ) ) . '</li>';
                }
                echo '</ol></details>';
            }
        } else {
            echo '</p><p>پس از قطعی‌شدن ثبت‌نام‌ها جدول را تولید کنید. جدول تا زمان «تولید مجدد» ثابت می‌ماند.</p>';
        }
    }

    private function render_result_fields( array $snapshot ): void {
        foreach ( self::snapshot_divisions( $snapshot ) as $division_key => $division ) {
            $rounds = self::build_rounds( $division['slots'], $division['winners'] ?? [] );
            $bout_numbers = CompetitionBracketView::match_numbers( $division['slots'] );
            $entrant_count = self::division_entrant_count( $division );
            echo '<h4>' . esc_html( (string) $division['label'] ) . ' — جدول ' . (int) $division['size'] . ' نفره — تعداد شرکت‌کنندگان: ' . (int) $entrant_count . '</h4>';
            echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>مرحله</th><th>مسابقه</th><th>قرمز</th><th>آبی</th><th>برنده</th></tr></thead><tbody>';
            foreach ( $rounds as $round_index => $matches ) {
                foreach ( $matches as $match_index => $match ) {
                    if ( $match['empty'] ) continue;
                    $key = $round_index . ':' . $match_index;
                    $a = $match['a'];
                    $b = $match['b'];
                    echo '<tr><td>' . esc_html( self::round_label( count( $division['slots'] ), $round_index ) ) . '</td><td>' . ( $bout_numbers[ $round_index ][ $match_index ] ?? 'صعود مستقیم' ) . '</td><td>' . esc_html( $a ? self::entry_name( $a ) : ( $match['a_pending'] ? 'در انتظار نتیجه' : ( $round_index === 0 ? 'استراحت' : 'بدون حریف' ) ) ) . '</td><td>' . esc_html( $b ? self::entry_name( $b ) : ( $match['b_pending'] ? 'در انتظار نتیجه' : ( $round_index === 0 ? 'استراحت' : 'بدون حریف' ) ) ) . '</td><td>';
                    if ( $a && $b ) {
                        echo '<select name="imao_bracket_winners[' . esc_attr( $division_key ) . '][' . esc_attr( $key ) . ']"><option value="">— انتخاب —</option>';
                        foreach ( [ $a, $b ] as $entry ) {
                            echo '<option value="' . esc_attr( $entry['entry_id'] ) . '" ' . selected( $match['winner']['entry_id'] ?? '', $entry['entry_id'], false ) . '>' . esc_html( $entry['name'] ) . '</option>';
                        }
                        echo '</select>';
                    } elseif ( $match['winner'] ) {
                        echo 'صعود خودکار: ' . esc_html( $match['winner']['name'] );
                    } else {
                        echo '—';
                    }
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table></div>';
        }
    }


    public function save_admin_changes( int $competition_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $competition_id ) ) return;
        $nonce = $_POST['imao_bracket_nonce'] ?? '';
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'imao_save_bracket_' . $competition_id ) ) return;
        $action = sanitize_key( $_POST['imao_bracket_action'] ?? '' );
        if ( $action === 'generate' ) {
            $participants = $this->participants( $competition_id );
            if ( ! $participants ) return;
            $criterion = $this->criterion( $competition_id );
            $seed = bin2hex( random_bytes( 16 ) );
            $grouped = [];
            foreach ( $participants as $participant ) $grouped[ $participant['division_key'] ][] = $participant;
            $divisions = [];
            foreach ( $grouped as $division_key => $division_participants ) {
                if ( count( $division_participants ) > self::MAX_SIZE ) continue;
                $division_participants = $this->apply_ranking_seeds( $division_participants );
                $slots = self::seed_participants( $division_participants, self::bracket_size( count( $division_participants ) ), $criterion, $seed . '|' . $division_key );
                $divisions[ $division_key ] = [
                    'label' => $division_participants[0]['division_label'],
                    'weight_term_id' => (int) $division_participants[0]['weight_term_id'],
                    'size' => count( $slots ),
                    'slots' => $slots,
                    'winners' => [],
                ];
            }
            if ( ! $divisions ) return;
            update_post_meta( $competition_id, self::META_BRACKET, [
                'version' => 3,
                'allocation' => 'recursive-balanced-v1',
                'criterion' => $criterion,
                'seed' => $seed,
                'divisions' => $divisions,
                'generated_at' => current_time( 'mysql' ),
                'generated_by' => get_current_user_id(),
                'audit' => [ [ 'at' => current_time( 'mysql' ), 'user_id' => get_current_user_id(), 'message' => count( $divisions ) . ' جدول وزنی با ' . count( $participants ) . ' ثبت‌نام تولید شد؛ رتبه‌های زمان تولید ذخیره شدند.' ] ],
            ] );
        } elseif ( $action === 'results' ) {
            $snapshot = self::snapshot( $competition_id );
            if ( ! $snapshot ) return;
            $submitted = isset( $_POST['imao_bracket_winners'] ) && is_array( $_POST['imao_bracket_winners'] ) ? wp_unslash( $_POST['imao_bracket_winners'] ) : [];
            $changed = false;
            foreach ( self::snapshot_divisions( $snapshot ) as $division_key => $division ) {
                $requested = isset( $submitted[ $division_key ] ) && is_array( $submitted[ $division_key ] ) ? $submitted[ $division_key ] : [];
                $winners = [];
                foreach ( $requested as $key => $entry_id ) {
                    if ( preg_match( '/^\d+:\d+$/', (string) $key ) && is_scalar( $entry_id ) && $entry_id !== '' ) $winners[ (string) $key ] = sanitize_text_field( (string) $entry_id );
                }
                self::build_rounds( $division['slots'], $winners, $winners );
                if ( $winners !== ( $division['winners'] ?? [] ) ) $changed = true;
                $snapshot['divisions'][ $division_key ]['winners'] = $winners;
            }
            if ( $changed ) {
                $snapshot['audit'][] = [ 'at' => current_time( 'mysql' ), 'user_id' => get_current_user_id(), 'message' => 'نتایج جدول به‌روزرسانی شد.' ];
                update_post_meta( $competition_id, self::META_BRACKET, $snapshot );
            }
        }
    }

    /**
     * Remove an unconfirmed order item from an already generated snapshot.
     *
     * The slot is intentionally kept as a BYE so the remaining bracket positions
     * and previously stored results do not shift unexpectedly.
     */
    public static function remove_item_from_snapshot( int $competition_id, int $order_id, int $item_id ): void {
        $snapshot = self::snapshot( $competition_id );
        if ( ! $snapshot ) return;

        $entry_id = 'order-' . $order_id . '-item-' . $item_id;
        $changed = false;
        foreach ( self::snapshot_divisions( $snapshot ) as $division_key => $division ) {
            if ( empty( $division['slots'] ) || ! is_array( $division['slots'] ) ) continue;
            foreach ( $division['slots'] as $slot_index => $entry ) {
                if ( is_array( $entry ) && (string) ( $entry['entry_id'] ?? '' ) === $entry_id ) {
                    $snapshot['divisions'][ $division_key ]['slots'][ $slot_index ] = null;
                    $changed = true;
                }
            }
            $winners = isset( $division['winners'] ) && is_array( $division['winners'] ) ? $division['winners'] : [];
            foreach ( $winners as $match_key => $winner_id ) {
                if ( (string) $winner_id === $entry_id ) {
                    unset( $winners[ $match_key ] );
                    $changed = true;
                }
            }
            $snapshot['divisions'][ $division_key ]['winners'] = $winners;
        }

        if ( ! $changed ) return;
        $snapshot['audit'][] = [
            'at' => current_time( 'mysql' ),
            'user_id' => get_current_user_id(),
            'message' => sprintf( 'شرکت‌کننده سفارش #%d و قلم #%d به‌دلیل لغو تأیید وزن‌کشی از جدول حذف شد و جایگاه او BYE شد.', $order_id, $item_id ),
        ];
        update_post_meta( $competition_id, self::META_BRACKET, $snapshot );
    }

    public function render_page(): void {
        $competition_id = (int) ( $_GET['competition_id'] ?? 0 );
        if ( get_post_type( $competition_id ) !== 'competition' ) wp_die( 'مسابقه یافت نشد.', 'مسابقه یافت نشد', [ 'response' => 404 ] );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( get_the_title( $competition_id ) ) . ' - جدول مسابقات</title></head><body style="margin:0;background:#f4f5f7"><main style="padding:20px"><h1 style="font:700 24px Tahoma;text-align:center">جدول مسابقات ' . esc_html( get_the_title( $competition_id ) ) . '</h1>' . $this->bracket_html( $competition_id ) . '</main></body></html>';
        exit;
    }

    public function bracket_shortcode( $atts ): string {
        $atts = shortcode_atts( [ 'competition_id' => 0 ], $atts, 'crm_competition_bracket' );
        $competition_id = (int) $atts['competition_id'] ?: (int) get_the_ID();
        return $this->bracket_html( $competition_id );
    }

    public function button_shortcode( $atts ): string {
        $atts = shortcode_atts( [ 'competition_id' => 0, 'label' => 'جدول مسابقات' ], $atts, 'crm_competition_bracket_button' );
        $competition_id = (int) $atts['competition_id'] ?: (int) get_the_ID();
        if ( ! self::snapshot( $competition_id ) ) return '';
        return '<a class="imao-bracket-button" target="_blank" rel="noopener" href="' . esc_url( self::page_url( $competition_id ) ) . '">' . esc_html( (string) $atts['label'] ) . '</a>';
    }

    private function bracket_html( int $competition_id ): string {
        $snapshot = self::snapshot( $competition_id );
        if ( ! $snapshot ) return '<p class="imao-bracket-empty">جدول این مسابقه هنوز منتشر نشده است.</p>';
        $criterion = (string) ( $snapshot['criterion'] ?? $this->criterion( $competition_id ) );
        return CompetitionBracketView::render( self::snapshot_divisions( $snapshot ), $criterion );
    }

    private static function snapshot( int $competition_id ): array {
        $value = get_post_meta( $competition_id, self::META_BRACKET, true );
        return is_array( $value ) && ( ! empty( $value['divisions'] ) || ! empty( $value['slots'] ) ) ? $value : [];
    }

    private static function snapshot_divisions( array $snapshot ): array {
        if ( ! empty( $snapshot['divisions'] ) && is_array( $snapshot['divisions'] ) ) return $snapshot['divisions'];
        return ! empty( $snapshot['slots'] ) ? [ 'legacy' => [ 'label' => 'جدول مسابقه', 'size' => count( $snapshot['slots'] ), 'slots' => $snapshot['slots'], 'winners' => $snapshot['winners'] ?? [] ] ] : [];
    }

    private static function division_entrant_count( array $division ): int {
        return count( array_filter( $division['slots'] ?? [], 'is_array' ) );
    }

    public static function bracket_size( int $count ): int {
        $size = 2;
        while ( $size < $count && $size < self::MAX_SIZE ) $size *= 2;
        return $size;
    }

    public static function encounter_round( int $slot_a, int $slot_b ): int {
        $xor = $slot_a ^ $slot_b;
        $round = 0;
        while ( $xor > 0 ) { $round++; $xor >>= 1; }
        return max( 1, $round );
    }

    public static function empty_opening_matches( array $slots ): int {
        $empty = 0;
        for ( $i = 0; $i + 1 < count( $slots ); $i += 2 ) {
            if ( empty( $slots[ $i ] ) && empty( $slots[ $i + 1 ] ) ) ++$empty;
        }
        return $empty;
    }

    public static function seed_participants( array $participants, int $size, string $criterion, string $seed ): array {
        return CompetitionBracketSeeding::seed( $participants, $size, $criterion, $seed );
    }

    public static function build_rounds( array $slots, array $requested_winners, ?array &$accepted_winners = null ): array {
        $rounds = [];
        $current = array_map( static fn( $entry ): array => [ 'entry' => $entry, 'decided' => true ], $slots );
        $accepted = [];
        $round_index = 0;
        while ( count( $current ) >= 2 ) {
            $matches = [];
            $next = [];
            for ( $i = 0; $i < count( $current ); $i += 2 ) {
                $left = $current[ $i ]; $right = $current[ $i + 1 ];
                $a = $left['entry']; $b = $right['entry']; $key = $round_index . ':' . (int) ( $i / 2 );
                $winner = null;
                $decided = false;
                if ( $left['decided'] && $right['decided'] && $a && ! $b ) { $winner = $a; $decided = true; }
                elseif ( $left['decided'] && $right['decided'] && $b && ! $a ) { $winner = $b; $decided = true; }
                elseif ( $left['decided'] && $right['decided'] && ! $a && ! $b ) { $decided = true; }
                elseif ( $left['decided'] && $right['decided'] && $a && $b ) {
                    $requested = (string) ( $requested_winners[ $key ] ?? '' );
                    if ( $requested === (string) $a['entry_id'] ) $winner = $a;
                    elseif ( $requested === (string) $b['entry_id'] ) $winner = $b;
                    if ( $winner ) { $accepted[ $key ] = $winner['entry_id']; $decided = true; }
                }
                $matches[] = [ 'a' => $a, 'b' => $b, 'winner' => $winner, 'empty' => $left['decided'] && $right['decided'] && ! $a && ! $b, 'a_pending' => ! $left['decided'], 'b_pending' => ! $right['decided'] ];
                $next[] = [ 'entry' => $winner, 'decided' => $decided ];
            }
            $rounds[] = $matches;
            $current = $next;
            $round_index++;
        }
        if ( $accepted_winners !== null ) $accepted_winners = $accepted;
        return $rounds;
    }

    private function participants( int $competition_id ): array {
        $product_id = (int) get_post_meta( $competition_id, self::META_LINKED_PRODUCT, true );
        $entries = [];
        if ( $product_id && function_exists( 'wc_get_orders' ) ) {
            global $wpdb;
            $order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items oi INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id=oim.order_item_id WHERE oi.order_item_type='line_item' AND oim.meta_key='_product_id' AND oim.meta_value=%d", $product_id ) );
            foreach ( $order_ids ? wc_get_orders( [ 'limit' => -1, 'status' => [ 'processing', 'completed' ], 'include' => array_map( 'intval', $order_ids ) ] ) : [] as $order ) {
                foreach ( $order->get_items() as $item ) {
                    if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id ) continue;
                    if ( ! CompetitionCards::item_is_bracket_eligible( $item ) ) continue;
                    $entry = $this->participant( (int) $order->get_customer_id(), 'order-' . $order->get_id() . '-item-' . $item->get_id(), $item );
                    if ( $entry ) $entries[] = $entry;
                }
            }
        }
        return $entries;
    }

    private function participant( int $user_id, string $entry_id, ?WC_Order_Item_Product $item ): ?array {
        $user = get_userdata( $user_id );
        if ( ! $user ) return null;
        $first = (string) get_user_meta( $user_id, 'first_name_fa', true );
        $last = (string) get_user_meta( $user_id, 'last_name_fa', true );
        $club_id = (int) get_user_meta( $user_id, 'club_id', true );
        $club = $club_id ? (string) get_user_meta( $club_id, 'club_name', true ) : '';
        $national_id = (string) get_user_meta( $user_id, 'national_id', true );
        $weight_name = $item ? trim( (string) $item->get_meta( 'دسته وزنی', true ) ) : trim( (string) get_user_meta( $user_id, 'weight_class', true ) );
        $age_name = $item ? trim( (string) $item->get_meta( 'رده سنی', true ) ) : trim( (string) get_user_meta( $user_id, 'age_category', true ) );
        $type_name = $item ? trim( (string) $item->get_meta( 'نوع مسابقه', true ) ) : '';
        $weight_term_id = $item ? (int) $item->get_meta( '_imao_weight_class_term', true ) : 0;
        if ( ! $weight_term_id && $weight_name !== '' ) {
            $term = get_term_by( 'name', $weight_name, 'age_category' );
            $weight_term_id = $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
        }
        $gender = (string) get_user_meta( $user_id, 'gender', true );
        $division_parts = array_filter( [ $type_name, $gender, $age_name, $weight_name ?: 'بدون دسته وزنی' ], static fn( string $value ): bool => $value !== '' );
        $division_key = hash( 'sha256', implode( '|', [ $type_name, $gender, $age_name, (string) $weight_term_id, $weight_name ] ) );
        return [
            'entry_id' => $entry_id,
            'user_id' => $user_id,
            'name' => trim( $first . ' ' . $last ) ?: $user->display_name,
            'national_id' => $national_id,
            'club' => $club ?: ( $club_id ? 'باشگاه ' . $club_id : 'بدون باشگاه' ),
            'city' => (string) get_user_meta( $user_id, 'residence_city', true ) ?: 'شهرستان ثبت نشده',
            'province' => (string) get_user_meta( $user_id, 'residence_province', true ) ?: 'بدون استان',
            'country' => (string) ( get_user_meta( $user_id, 'nationality_country', true ) ?: get_user_meta( $user_id, 'billing_country', true ) ?: 'IR' ),
            'weight_term_id' => $weight_term_id,
            'weight_name' => $weight_name,
            'division_key' => $division_key,
            'division_label' => implode( ' — ', $division_parts ),
        ];
    }

    private function apply_ranking_seeds( array $participants ): array {
        $weight_term_id = (int) ( $participants[0]['weight_term_id'] ?? 0 );
        if ( ! $weight_term_id ) return $participants;
        global $wpdb;
        $where = $wpdb->prepare( 'WHERE weight_class=%d', $weight_term_id );
        $expiry_days = (int) $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1" );
        if ( $expiry_days ) $where .= $wpdb->prepare( ' AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)', $expiry_days );
        $rows = $wpdb->get_results( "SELECT user_id, SUM(points) AS pts FROM {$wpdb->prefix}crm_points {$where} GROUP BY user_id ORDER BY pts DESC, user_id ASC" );
        $ranked_users = [];
        foreach ( $rows as $row ) $ranked_users[ (int) $row->user_id ] = (int) $row->pts;
        $rankable = [];
        foreach ( $participants as $index => $participant ) {
            $user_id = (int) $participant['user_id'];
            if ( isset( $ranked_users[ $user_id ] ) ) $rankable[] = [ 'index' => $index, 'user_id' => $user_id, 'points' => $ranked_users[ $user_id ] ];
        }
        usort( $rankable, static fn( array $a, array $b ): int => $b['points'] <=> $a['points'] ?: $a['user_id'] <=> $b['user_id'] );
        foreach ( array_slice( $rankable, 0, 4 ) as $position => $ranked ) {
            $participants[ $ranked['index'] ]['seed_rank'] = $position + 1;
            $participants[ $ranked['index'] ]['seed_points'] = $ranked['points'];
        }
        return $participants;
    }

    private function criterion( int $competition_id ): string {
        $terms = get_the_terms( $competition_id, 'level' );
        $level = $terms && ! is_wp_error( $terms ) ? strtolower( implode( ' ', array_merge( wp_list_pluck( $terms, 'slug' ), wp_list_pluck( $terms, 'name' ) ) ) ) : '';
        return self::criterion_for_level( $level );
    }

    public static function criterion_for_level( string $level ): string {
        $level = str_replace( [ '‌', '-', ' ' ], '', strtolower( $level ) );
        if ( strpos( $level, 'بینالملل' ) !== false || strpos( $level, 'international' ) !== false ) return 'country';
        if ( strpos( $level, 'شهرستان' ) !== false || strpos( $level, 'county' ) !== false ) return 'club';
        if ( strpos( $level, 'کشور' ) !== false || strpos( $level, 'national' ) !== false ) return 'province';
        foreach ( [ 'استان', 'province', 'provincial', 'جایزهبزرگ', 'منطقه', 'grandprix', 'regional' ] as $word ) {
            if ( strpos( $level, $word ) !== false ) return 'club';
        }
        return 'club';
    }

    private static function criterion_label( string $criterion ): string {
        return [ 'club' => 'باشگاه', 'city' => 'شهرستان', 'province' => 'استان', 'country' => 'کشور' ][ $criterion ] ?? $criterion;
    }

    public static function group_label( array $entry, string $criterion ): string {
        $value = trim( (string) ( $entry[ $criterion ] ?? '' ) );
        // Older snapshots predate city labels; resolve only that missing field.
        if ( $criterion === 'city' && $value === '' && ! empty( $entry['user_id'] ) ) {
            $value = (string) get_user_meta( (int) $entry['user_id'], 'residence_city', true );
        }
        if ( $criterion === 'province' ) {
            static $provinces;
            if ( $provinces === null ) {
                $codes = [ 'EAZ','WAZ','ADL','ESF','ABZ','ILM','BHR','THR','CHB','SKH','RKH','NKH','KHZ','ZJN','SMN','SBN','FRS','GZN','QHM','KRD','KRN','KRH','KBD','GLS','LRS','GIL','MZN','MKZ','HRZ','HDN','YZD' ];
                $names = [ 'آذربایجان شرقی','آذربایجان غربی','اردبیل','اصفهان','البرز','ایلام','بوشهر','تهران','چهارمحال و بختیاری','خراسان جنوبی','خراسان رضوی','خراسان شمالی','خوزستان','زنجان','سمنان','سیستان و بلوچستان','فارس','قزوین','قم','کردستان','کرمان','کرمانشاه','کهگیلویه و بویراحمد','گلستان','لرستان','گیلان','مازندران','مرکزی','هرمزگان','همدان','یزد' ];
                $provinces = array_combine( $codes, $names ) + array_combine( array_map( static fn( int $number ): string => sprintf( 'IR-%02d', $number ), range( 1, 31 ) ), $names );
            }
            $value = $provinces[ strtoupper( $value ) ] ?? $value;
        } elseif ( $criterion === 'country' && preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
            static $countries;
            if ( $countries === null ) $countries = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/data/countries-fa.json' ), true ) ?: [];
            $value = $countries[ strtoupper( $value ) ] ?? '';
        }
        return preg_match( '/[\x{0600}-\x{06ff}]/u', $value ) ? $value : self::criterion_label( $criterion ) . ' ثبت نشده';
    }

    /** Placements are derived only from a decided final and its semifinal feeders. */
    public static function placements( array $rounds ): array {
        $result = [ 1 => null, 2 => null, 3 => null, 4 => null ];
        if ( ! $rounds ) return $result;
        $final = $rounds[ count( $rounds ) - 1 ][0];
        if ( ! $final['winner'] ) return $result;
        $result[1] = $final['winner'];
        $result[2] = self::loser( $final );
        if ( count( $rounds ) > 1 && $result[2] ) {
            foreach ( $rounds[ count( $rounds ) - 2 ] as $match ) {
                if ( ! $match['winner'] ) continue;
                $position = $match['winner']['entry_id'] === $result[1]['entry_id'] ? 3 : 4;
                $result[ $position ] = self::loser( $match );
            }
        }
        return $result;
    }

    private static function loser( array $match ): ?array {
        if ( ! $match['winner'] || ! $match['a'] || ! $match['b'] ) return null;
        return $match['winner']['entry_id'] === $match['a']['entry_id'] ? $match['b'] : $match['a'];
    }

    private static function entry_name( ?array $entry ): string { return $entry ? (string) $entry['name'] : 'BYE'; }

    private static function round_label( int $size, int $round_index ): string {
        $remaining = (int) ( $size / ( 2 ** $round_index ) );
        if ( $remaining === 2 ) return 'فینال';
        if ( $remaining === 4 ) return 'نیمه‌نهایی';
        if ( $remaining === 8 ) return 'یک‌چهارم نهایی';
        return 'مرحله ' . $remaining . ' نفره';
    }
}
