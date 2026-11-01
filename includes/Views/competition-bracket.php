<?php
use IMAOCustom\Services\CompetitionBrackets;

/** @var array $division Saved competition division; included by CompetitionBracketView. */
?>
<section class="imao-bracket-shell" data-imao-bracket dir="rtl">
    <header class="imao-bracket-header">
        <div>
            <span class="imao-bracket-eyebrow">جدول حذفی مسابقات · مسیر قهرمانی</span>
            <h2><?php echo esc_html( (string) $division['label'] ); ?></h2>
            <p>جداسازی بر اساس <?php echo esc_html( $criterion_label ); ?><?php if ( $protected_count > 0 ) : ?> · <?php echo (int) $protected_count; ?> رتبهٔ محافظت‌شده<?php endif; ?> · جایگاه‌های ثابت و قابل پیگیری</p>
        </div>
        <span class="imao-bracket-capacity"><?php echo (int) count( $slots ); ?><small>جایگاه</small></span>
    </header>
    <div class="imao-bracket-toolbar" role="group" aria-label="نمایش جدول">
        <strong><?php echo (int) $summary['entrants']; ?> ورزشکار <span> / <?php echo (int) $summary['contests']; ?> مبارزهٔ واقعی</span></strong>
        <div>
            <button type="button" data-zoom="out" aria-label="کوچک‌نمایی">−</button>
            <button type="button" data-zoom="in" aria-label="بزرگ‌نمایی">+</button>
            <button type="button" data-zoom="fit">اندازهٔ صفحه</button>
            <button type="button" data-zoom="reset">۱۰۰٪</button>
            <button type="button" data-print>چاپ</button>
        </div>
    </div>
    <div class="imao-bracket-layout">
        <div class="imao-bracket-viewport" tabindex="0" aria-label="نمودار مسابقات؛ برای مشاهدهٔ ادامهٔ جدول پیمایش کنید">
            <div class="imao-bracket-canvas"><div class="imao-bracket-tree" style="--tree-height:<?php echo (int) ( count( $slots ) / 2 * 140 ); ?>px">
                <?php foreach ( $rounds as $round_index => $matches ) : ?>
                <section class="imao-bracket-round">
                    <h3><?php echo esc_html( self::round_label( count( $slots ), $round_index ) ); ?></h3>
                    <div class="imao-bracket-matches">
                    <?php foreach ( $matches as $match_index => $match ) :
                        $number = $numbers[ $round_index ][ $match_index ];
                        $top = ( $match_index + .5 ) / count( $matches ) * 100;
                        ?>
                    <article class="imao-bracket-match <?php echo $match['empty'] ? 'imao-bracket-match-empty' : ''; ?> <?php echo $round_index === count( $rounds ) - 1 ? 'imao-bracket-final' : ''; ?>" style="top:<?php echo (float) $top; ?>%" data-round="<?php echo (int) $round_index; ?>" data-match="<?php echo (int) $match_index; ?>">
                        <div class="imao-bracket-match-heading">
                            <?php echo $number ? 'مبارزهٔ ' . (int) $number : ( $match['empty'] ? 'جایگاه‌های خالی' : 'صعود مستقیم' ); ?>
                            <?php if ( $match['winner'] && $number ) : ?><span>نتیجه ثبت شد</span><?php endif; ?>
                        </div>
                        <?php foreach ( [ 'a' => $match['a'], 'b' => $match['b'] ] as $side => $entry ) :
                            $winner = $entry && $match['winner'] && $entry['entry_id'] === $match['winner']['entry_id'];
                            $province = $entry ? self::province( $entry ) : '';
                            ?>
                        <div data-side="<?php echo esc_html( $side ); ?>" <?php if ( $entry ) : ?>data-province="<?php echo esc_html( substr( hash( 'sha256', $province ), 0, 12 ) ); ?>" title="<?php echo esc_html( $criterion_label . ': ' . CompetitionBrackets::group_label( $entry, $criterion ) ); ?>"<?php endif; ?> class="imao-bracket-player <?php echo ! $entry ? 'bye' : ( $winner ? 'winner' : '' ); ?>">
                            <span class="imao-bracket-slot"><?php echo $entry ? (int) ( $entry_slots[ (string) $entry['entry_id'] ] ?? 0 ) : ( $round_index === 0 ? (int) ( $match_index * 2 + ( $side === 'a' ? 1 : 2 ) ) : '—' ); ?></span>
                            <span class="imao-bracket-player-name">
                                <?php echo esc_html( $entry ? (string) $entry['name'] : ( $match[ $side . '_pending' ] ? self::pending_label( $round_index, $match_index, $side, $numbers ) : ( $round_index === 0 ? 'استراحت' : 'بدون حریف' ) ) ); ?>
                                <?php if ( $entry && ! empty( $entry['seed_rank'] ) ) : ?>
                                <small title="رتبه در زمان تولید جدول">رتبهٔ <?php echo (int) $entry['seed_rank']; ?></small>
                                <?php endif; ?>
                            </span>
                            <?php if ( $entry ) : ?><span class="imao-bracket-province" style="--province-color:<?php echo esc_html( self::province_color( $province ) ); ?>"><?php echo esc_html( $province ); ?></span><?php elseif ( $round_index === 0 ) : ?><span class="imao-bracket-bye-badge">استراحت</span><?php endif; ?>
                            <?php if ( $winner ) : ?><span class="imao-bracket-winner-mark" title="صعود کرده" aria-label="صعود کرده">✓</span><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </article>
                    <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
                <section class="imao-bracket-champion-column">
                    <h3>قهرمان</h3>
                    <div class="imao-bracket-champion">
                        <span aria-hidden="true">🏆</span>
                        <strong><?php echo esc_html( $champion['name'] ?? 'در انتظار فینال' ); ?></strong>
                        <?php if ( $champion ) : ?>
                        <span class="imao-bracket-province" style="--province-color:<?php echo esc_html( self::province_color( self::province( $champion ) ) ); ?>"><?php echo esc_html( self::province( $champion ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </section>
            </div></div>
        </div>
        <aside class="imao-bracket-sidebar">
            <section class="imao-bracket-panel">
                <h3>مشخصات جدول</h3>
                <dl>
                    <div><dt>تعداد واقعی ورزشکاران</dt><dd><?php echo (int) $summary['entrants']; ?> نفر</dd></div>
                    <div><dt>ظرفیت جدول</dt><dd><?php echo (int) $summary['capacity']; ?> جایگاه</dd></div>
                    <div><dt>تعداد استراحت‌ها</dt><dd><?php echo count( $summary['byes'] ); ?></dd></div>
                    <div><dt>مبارزات تا قهرمانی</dt><dd><?php echo (int) $summary['contests']; ?></dd></div>
                </dl>
                <p class="imao-bracket-fine"><?php echo (int) $summary['opening_contests']; ?> مبارزه در دور اول و <?php echo (int) ( $summary['contests'] - $summary['opening_contests'] ); ?> مبارزه در مراحل بعدی</p>
            </section>
            <section class="imao-bracket-panel">
                <h3>رنگ استان‌ها و تعداد ورزشکاران</h3>
                <p class="imao-bracket-fine">استان را انتخاب کنید تا ورزشکارانش برجسته شوند.</p>
                <ul class="imao-bracket-province-list">
                    <?php foreach ( $summary['provinces'] as $province => $count ) : ?>
                    <li><button type="button" data-province-filter="<?php echo esc_html( substr( hash( 'sha256', $province ), 0, 12 ) ); ?>" aria-pressed="false" title="<?php echo esc_html( 'کد رنگ: ' . self::province_color( $province ) ); ?>">
                        <span class="imao-bracket-province" style="--province-color:<?php echo esc_html( self::province_color( $province ) ); ?>"><?php echo esc_html( $province ); ?></span>
                        <span><?php echo (int) $count; ?> نفر</span>
                    </button></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <section class="imao-bracket-panel">
                <h3>جایگاه‌های استراحت</h3>
                <?php if ( ! $summary['byes'] ) : ?>
                <p class="imao-bracket-fine">این جدول استراحت ندارد.</p>
                <?php else : ?>
                <ul class="imao-bracket-bye-list">
                    <?php foreach ( $summary['byes'] as $bye ) : ?>
                    <li><span>خانهٔ <?php echo (int) $bye['slot']; ?></span><span>مقابل <?php echo esc_html( $bye['opponent'] ); ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
            <section class="imao-bracket-panel"><h3>راهنمای نمادها</h3><ul class="imao-bracket-symbols"><li><span class="imao-bracket-bye-badge">استراحت</span> جایگاه خالی در دور اول</li><li><b class="imao-bracket-symbol-winner">✓</b> صعود خودکار یا برندهٔ ثبت‌شده</li><li><b>رتبهٔ ۱–۴</b> رتبهٔ محافظت‌شده در قرعه‌کشی</li><li><span>خط آبی</span> مسیر صعود به مرحلهٔ بعد</li></ul></section>
            <section class="imao-bracket-panel"><h3>نفرات برتر</h3><table class="imao-bracket-medals"><thead><tr><th>رتبه</th><th>ورزشکار</th></tr></thead><tbody><?php foreach ( CompetitionBrackets::placements( $rounds ) as $position => $entry ) : ?><tr><td><?php echo esc_html( [ 1 => 'اول', 2 => 'دوم', 3 => 'سوم', 4 => 'سوم مشترک' ][ $position ] ); ?></td><td><?php echo esc_html( $entry['name'] ?? 'در انتظار نتیجه' ); ?></td></tr><?php endforeach; ?></tbody></table></section>
        </aside>
    </div>
    <footer class="imao-bracket-footer">
        <section class="imao-bracket-notes">
            <h3>نکات جدول</h3>
            <ul>
                <li>استراحت، مبارزه محسوب نمی‌شود؛ ورزشکار مقابل آن مستقیم صعود می‌کند.</li>
                <li>معیار اصلی جداسازی «<?php echo esc_html( $criterion_label ); ?>» است؛ در تراکم گروه‌ها، برخورد زودتر ممکن است اجتناب‌ناپذیر باشد.</li>
                <li><?php echo $opening_province_conflicts === 0 ? 'بر اساس استان‌های ثبت‌شده، در دور اول برخورد هم‌استانی وجود ندارد.' : 'برخورد هم‌استانی در دور اولِ قرعهٔ ذخیره‌شده: ' . (int) $opening_province_conflicts . ' دیدار.'; ?></li>
                <li>رنگ هر استان در تمام مراحل ثابت است؛ نتیجهٔ مبارزه فقط با تأیید مدیر پیش می‌رود.</li>
                <li>شمارهٔ کنار نام، جایگاه اولیهٔ ورزشکار است. جایگاه‌ها و نتایج از قرعهٔ ذخیره‌شده خوانده می‌شوند.</li>
            </ul>
        </section>
        <details class="imao-bracket-paths">
            <summary>مسیر صعود ورزشکاران تا قهرمانی</summary>
            <div class="imao-bracket-table-scroll">
                <table>
                    <thead><tr><th>خانه</th><th>ورزشکار</th><th>استان</th><th>مسیر صعود</th></tr></thead>
                    <tbody>
                    <?php foreach ( $slots as $slot => $entry ) :
                        if ( ! $entry ) continue;
                        $path = [];
                        foreach ( $numbers as $round => $matches ) {
                            $number = $matches[ (int) floor( $slot / ( 2 ** ( $round + 1 ) ) ) ] ?? null;
                            $path[] = $number ? 'برد مبارزهٔ ' . $number : 'صعود مستقیم';
                        }
                        ?>
                        <tr>
                            <td><?php echo (int) $slot + 1; ?></td>
                            <td><?php echo esc_html( $entry['name'] ); ?></td>
                            <td><?php echo esc_html( self::province( $entry ) ); ?></td>
                            <td><?php echo esc_html( implode( ' ← ', $path ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </footer>
</section>
