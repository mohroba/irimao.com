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
        echo '<p>ثبت‌نام‌های پرداخت‌شده فعلی: <strong>' . (int) $count . '</strong></p>';
        echo '<p><button type="submit" class="button button-primary" name="imao_bracket_action" value="generate">' . ( $snapshot ? 'تولید مجدد جدول' : 'تولید جدول' ) . '</button> ';
        if ( $snapshot ) {
            echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::page_url( $post->ID ) ) . '">مشاهده جدول</a></p>';
            $divisions = self::snapshot_divisions( $snapshot );
            echo '<p>تعداد جدول‌ها: ' . count( $divisions ) . ' | معیار جداسازی: ' . esc_html( self::criterion_label( (string) $snapshot['criterion'] ) ) . ' | تولید: ' . esc_html( (string) $snapshot['generated_at'] ) . '</p>';
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
            echo '<h4>' . esc_html( (string) $division['label'] ) . ' — جدول ' . (int) $division['size'] . ' نفره</h4>';
            echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>مرحله</th><th>مسابقه</th><th>قرمز</th><th>آبی</th><th>برنده</th></tr></thead><tbody>';
            foreach ( $rounds as $round_index => $matches ) {
                foreach ( $matches as $match_index => $match ) {
                    $key = $round_index . ':' . $match_index;
                $a = $match['a'];
                $b = $match['b'];
                    echo '<tr><td>' . esc_html( self::round_label( count( $division['slots'] ), $round_index ) ) . '</td><td>' . ( $match_index + 1 ) . '</td><td>' . esc_html( self::entry_name( $a ) ) . '</td><td>' . esc_html( self::entry_name( $b ) ) . '</td><td>';
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
                'version' => 2,
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
        ob_start(); ?>
<style>.imao-bracket-shell{direction:rtl;background:#fff;border:1px solid #e2e5ea;border-radius:14px;overflow:hidden;font-family:Tahoma,Arial,sans-serif}.imao-bracket-toolbar{display:flex;gap:8px;align-items:center;padding:10px 14px;background:#17134b;color:#fff}.imao-bracket-toolbar button{border:1px solid #ffffff66;border-radius:6px;background:#fff;color:#17134b;padding:5px 11px;cursor:pointer}.imao-bracket-viewport{direction:ltr;overflow:auto;cursor:grab;min-height:420px;padding:24px}.imao-bracket-tree{position:relative;display:flex;gap:54px;transform-origin:top left;width:max-content;transition:transform .15s}.imao-bracket-connectors{position:absolute;inset:0;z-index:0;overflow:visible;pointer-events:none}.imao-bracket-connectors path{fill:none;stroke:#8993a3;stroke-width:2;vector-effect:non-scaling-stroke}.imao-bracket-round{position:relative;z-index:1;display:flex;flex-direction:column;justify-content:space-around;width:220px;gap:18px}.imao-bracket-round h3{direction:rtl;text-align:center;font-size:14px;margin:0 0 8px;color:#555}.imao-bracket-match{position:relative;direction:rtl;border:1px solid #ccd2dd;border-radius:8px;background:#fff;box-shadow:0 2px 8px #0000000d;overflow:visible}.imao-bracket-player{display:flex;justify-content:space-between;gap:8px;padding:8px 10px;min-height:35px;border-bottom:1px solid #edf0f4;font-size:12px}.imao-bracket-player:last-child{border:0}.imao-bracket-player.winner{background:#eaf8ee;color:#116b2d;font-weight:700}.imao-bracket-player.bye{color:#9299a5;font-style:italic;direction:ltr}.imao-bracket-champion{border-color:#d7a900;box-shadow:0 0 0 2px #ffd84d55}.imao-bracket-button{display:inline-block;padding:10px 18px;border-radius:7px;background:#17134b;color:#fff!important;text-decoration:none}.imao-bracket-empty{text-align:center;color:#777}</style>
<?php foreach ( self::snapshot_divisions( $snapshot ) as $division ) : $rounds = self::build_rounds( $division['slots'], $division['winners'] ?? [] ); ?><h2 style="font:700 18px Tahoma;text-align:center;margin:26px 0 10px"><?php echo esc_html( (string) $division['label'] ); ?></h2><div class="imao-bracket-shell" data-imao-bracket><div class="imao-bracket-toolbar"><strong>جدول <?php echo (int) $division['size']; ?> نفره</strong><button type="button" data-zoom="in">+</button><button type="button" data-zoom="out">−</button><button type="button" data-zoom="reset">۱۰۰٪</button></div><div class="imao-bracket-viewport"><div class="imao-bracket-tree">
<?php foreach ( $rounds as $round_index => $matches ) : ?><section class="imao-bracket-round"><h3><?php echo esc_html( self::round_label( (int) $division['size'], $round_index ) ); ?></h3><?php foreach ( $matches as $match ) : ?><div class="imao-bracket-match <?php echo $round_index === count( $rounds ) - 1 && $match['winner'] ? 'imao-bracket-champion' : ''; ?>"><?php foreach ( [ $match['a'], $match['b'] ] as $entry ) : ?><div class="imao-bracket-player <?php echo ! $entry ? 'bye' : ( $match['winner'] && $entry['entry_id'] === $match['winner']['entry_id'] ? 'winner' : '' ); ?>"><span><?php echo esc_html( self::entry_name( $entry ) ); ?><?php if ( $entry && ! empty( $entry['seed_rank'] ) ) : ?> <b title="رتبه در زمان جدول‌بندی">#<?php echo (int) $entry['seed_rank']; ?></b><?php endif; ?></span><?php if ( $entry && ! empty( $entry['group_label'] ) ) : ?><small><?php echo esc_html( $entry['group_label'] ); ?></small><?php endif; ?></div><?php endforeach; ?></div><?php endforeach; ?></section><?php endforeach; ?>
</div></div></div><?php endforeach; ?><script>(function(){var ns='http://www.w3.org/2000/svg';document.querySelectorAll('[data-imao-bracket]').forEach(function(root){if(root.dataset.ready)return;root.dataset.ready='1';var view=root.querySelector('.imao-bracket-viewport'),tree=root.querySelector('.imao-bracket-tree'),scale=1,drag=false,x=0,y=0,sx=0,sy=0;function zoom(){tree.style.transform='scale('+scale+')'}function connectors(){var old=tree.querySelector('.imao-bracket-connectors');if(old)old.remove();var svg=document.createElementNS(ns,'svg'),rounds=tree.querySelectorAll('.imao-bracket-round');svg.setAttribute('class','imao-bracket-connectors');svg.setAttribute('width',tree.scrollWidth);svg.setAttribute('height',tree.scrollHeight);rounds.forEach(function(round,ri){if(ri>=rounds.length-1)return;var matches=round.querySelectorAll('.imao-bracket-match'),parents=rounds[ri+1].querySelectorAll('.imao-bracket-match');matches.forEach(function(match,mi){var parent=parents[Math.floor(mi/2)];if(!parent)return;var x1=round.offsetLeft+match.offsetLeft+match.offsetWidth,y1=round.offsetTop+match.offsetTop+match.offsetHeight/2,x2=rounds[ri+1].offsetLeft+parent.offsetLeft,y2=rounds[ri+1].offsetTop+parent.offsetTop+parent.offsetHeight/2,xm=x1+(x2-x1)/2,path=document.createElementNS(ns,'path');path.setAttribute('d','M'+x1+' '+y1+' H'+xm+' V'+y2+' H'+x2);svg.appendChild(path)})});tree.insertBefore(svg,tree.firstChild)}root.addEventListener('click',function(e){var z=e.target.dataset.zoom;if(!z)return;scale=z==='reset'?1:Math.max(.4,Math.min(2,scale+(z==='in'?.15:-.15)));zoom()});view.addEventListener('mousedown',function(e){drag=true;x=e.clientX;y=e.clientY;sx=view.scrollLeft;sy=view.scrollTop});window.addEventListener('mouseup',function(){drag=false});view.addEventListener('mousemove',function(e){if(!drag)return;view.scrollLeft=sx-(e.clientX-x);view.scrollTop=sy-(e.clientY-y)});view.addEventListener('wheel',function(e){if(!e.ctrlKey)return;e.preventDefault();scale=Math.max(.4,Math.min(2,scale+(e.deltaY<0?.1:-.1)));zoom()},{passive:false});connectors();if(window.ResizeObserver)new ResizeObserver(connectors).observe(tree)})})();</script>
<?php return (string) ob_get_clean();
    }

    private static function snapshot( int $competition_id ): array {
        $value = get_post_meta( $competition_id, self::META_BRACKET, true );
        return is_array( $value ) && ( ! empty( $value['divisions'] ) || ! empty( $value['slots'] ) ) ? $value : [];
    }

    private static function snapshot_divisions( array $snapshot ): array {
        if ( ! empty( $snapshot['divisions'] ) && is_array( $snapshot['divisions'] ) ) return $snapshot['divisions'];
        return ! empty( $snapshot['slots'] ) ? [ 'legacy' => [ 'label' => 'جدول مسابقه', 'size' => count( $snapshot['slots'] ), 'slots' => $snapshot['slots'], 'winners' => $snapshot['winners'] ?? [] ] ] : [];
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

    public static function seed_participants( array $participants, int $size, string $criterion, string $seed ): array {
        $slots = array_fill( 0, $size, null );
        $seed_positions = [ 1 => 0, 2 => (int) ( $size / 2 ), 3 => (int) ( $size / 4 ), 4 => (int) ( 3 * $size / 4 ) ];
        $remaining = [];
        foreach ( $participants as $participant ) {
            $rank = (int) ( $participant['seed_rank'] ?? 0 );
            if ( $rank >= 1 && $rank <= 4 && isset( $seed_positions[ $rank ] ) && $slots[ $seed_positions[ $rank ] ] === null ) {
                $participant['group_label'] = (string) ( $participant[ $criterion ] ?? '' );
                $slots[ $seed_positions[ $rank ] ] = $participant;
            } else {
                $remaining[] = $participant;
            }
        }
        $participants = $remaining;
        usort( $participants, static function ( array $a, array $b ) use ( $criterion, $seed ): int {
            $ga = (string) ( $a[ $criterion ] ?? '' );
            $gb = (string) ( $b[ $criterion ] ?? '' );
            if ( $ga !== $gb ) return strcmp( $ga, $gb );
            return strcmp( hash( 'sha256', $seed . '|' . $a['entry_id'] ), hash( 'sha256', $seed . '|' . $b['entry_id'] ) );
        } );
        $groups = [];
        foreach ( $participants as $participant ) $groups[ (string) ( $participant[ $criterion ] ?? '' ) ][] = $participant;
        uasort( $groups, static fn( array $a, array $b ): int => count( $b ) <=> count( $a ) );
        foreach ( $groups as $group => $members ) {
            $used = [];
            foreach ( $slots as $slot => $placed ) if ( $placed && (string) ( $placed[ $criterion ] ?? '' ) === $group ) $used[] = $slot;
            foreach ( $members as $member ) {
                $best = [];
                $best_score = -1;
                for ( $slot = 0; $slot < $size; $slot++ ) {
                    if ( $slots[ $slot ] !== null ) continue;
                    $score = $used ? min( array_map( static fn( int $other ): int => self::encounter_round( $slot, $other ), $used ) ) : PHP_INT_MAX;
                    if ( $score > $best_score ) { $best_score = $score; $best = [ $slot ]; }
                    elseif ( $score === $best_score ) $best[] = $slot;
                }
                usort( $best, static fn( int $a, int $b ): int => strcmp( hash( 'sha256', $seed . '|' . $member['entry_id'] . '|' . $a ), hash( 'sha256', $seed . '|' . $member['entry_id'] . '|' . $b ) ) );
                $chosen = $best[0];
                $member['group_label'] = $group;
                $slots[ $chosen ] = $member;
                $used[] = $chosen;
            }
        }
        return $slots;
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
                $matches[] = [ 'a' => $a, 'b' => $b, 'winner' => $winner ];
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
                    $entry = $this->participant( (int) $order->get_customer_id(), 'order-' . $order->get_id() . '-item-' . $item->get_id(), $item );
                    if ( $entry ) $entries[] = $entry;
                }
            }
        }
        $seen_users = array_fill_keys( array_column( $entries, 'user_id' ), true );
        foreach ( array_map( 'intval', (array) get_post_meta( $competition_id, self::META_MANUAL, true ) ) as $user_id ) {
            if ( isset( $seen_users[ $user_id ] ) ) continue;
            $entry = $this->participant( $user_id, 'manual-' . $user_id, null );
            if ( $entry ) $entries[] = $entry;
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
            'club' => $club ?: ( $club_id ? 'باشگاه ' . $club_id : 'بدون باشگاه' ),
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
        if ( strpos( $level, 'بین‌الملل' ) !== false || strpos( $level, 'international' ) !== false ) return 'country';
        if ( strpos( $level, 'کشور' ) !== false || strpos( $level, 'national' ) !== false ) return 'province';
        return 'club';
    }

    private static function criterion_label( string $criterion ): string {
        return [ 'club' => 'باشگاه', 'province' => 'استان', 'country' => 'کشور' ][ $criterion ] ?? $criterion;
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
