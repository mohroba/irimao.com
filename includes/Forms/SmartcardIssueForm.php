<?php

namespace IMAOCustom\Forms;

use Exception;
use WC_Order_Item_Fee;
use function wc_create_order;
use function wc_price;
use function wc_get_endpoint_url;
use function wc_get_page_permalink;
use function wc_add_notice;
use IMAOCustom\Helpers\Wallet;
use IMAOCustom\Helpers\Price;

class SmartcardIssueForm extends BaseForm {
    protected string $nonce_action = 'imao_smartcard_issue';

    protected function fields(): array {
        return [];
    }

    protected function submit(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id   = get_current_user_id();
        $wallet    = Wallet::get( $user_id );
        $want_post = ! empty( $_POST['want_post'] );
        $coupon    = sanitize_text_field( $_POST['coupon'] ?? '' );

        $base_amount = Price::from_rial( 1000000 );
        $post_amount = $want_post ? Price::from_rial( 300000 ) : 0;

        try {
            $order = wc_create_order();

            $fee = new WC_Order_Item_Fee();
            $fee->set_name( 'هزینه صدور / تمدید کارت' );
            $fee->set_amount( $base_amount );
            $fee->set_total( $base_amount );
            $order->add_item( $fee );

            if ( $post_amount ) {
                $post_fee = new WC_Order_Item_Fee();
                $post_fee->set_name( 'هزینه پست' );
                $post_fee->set_amount( $post_amount );
                $post_fee->set_total( $post_amount );
                $order->add_item( $post_fee );
                $order->update_meta_data( 'smartcard_post', true );
            }

            if ( $coupon ) {
                try {
                    $order->apply_coupon( $coupon );
                } catch ( Exception $e ) {
                    wc_add_notice( 'کد تخفیف نامعتبر است.', 'error' );
                }
            }

            $order->calculate_totals();
            $payable = $order->get_total();

            if ( $wallet >= $payable ) {
                Wallet::deduct( $user_id, $payable );
                $order->set_total( 0 );
                $order->payment_complete();
                $order->add_order_note( 'پرداخت کامل با کیف پول انجام شد.' );
                wc_add_notice( 'هزینه از کیف پول شما کسر و کارت در حال صدور است.', 'success' );
                wp_safe_redirect( wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) ) );
                exit;
            } elseif ( $wallet > 0 ) {
                Wallet::deduct( $user_id, $wallet );
                $order->set_total( $payable - $wallet );
                $order->add_order_note( "مبلغ {$wallet} ریال از کیف پول کسر شد." );
            }

            $order->set_customer_id( $user_id );
            $order->update_status( 'pending', 'Created by self-service' );
            $order->save();

            wp_safe_redirect( $order->get_checkout_payment_url() );
            exit;
        } catch ( Exception $e ) {
            wc_add_notice( 'خطا در ایجاد سفارش: ' . $e->getMessage(), 'error' );
        }
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">' . __( 'لطفاً وارد شوید.', 'imao-custom-plugin' ) . '</p>';
        }

        $wallet = Wallet::get();

        ob_start();
        ?>
        <div class="sc-container">
            <div class="sc-header">صدور/تمدید کارت عضویت</div>
            <p class="sc-balance">موجودی کیف پول شما: <span><?php echo wc_price( $wallet ); ?></span></p>
            <p class="sc-instruction">برای دریافت کارت عضویت می توانید با پرداخت هزینه آن بصورت آنلاین نسبت به دریافت کارت اقدام کنید.</p>
            <p class="sc-warning">
                - قبل از درخواست صدور فیزیکی کارت، از بارگذاری صحیح تصویر پرسنلی خود اطمینان حاصل فرمایید.<br>
                - بر روی درخواست های صدور کارتی که فاقد تصویر پرسنلی باشند، اقدامی صورت نخواهد گرفت.
            </p>
            <form class="needs-swal" method="post">
                <?php wp_nonce_field( $this->nonce_action, $this->nonce_name ); ?>
                <div class="sc-grid">
                    <div class="sc-field">
                        <input type="text" name="coupon" placeholder="کد تخفیف را اینجا وارد کنید" value="<?php echo esc_attr( $_POST['coupon'] ?? '' ); ?>">
                        <button class="sc-apply" type="submit" formaction="#">اعمال کد</button>
                    </div>
                    <div class="sc-field sc-price"><?php echo wc_price( Price::from_rial( 1000000 ) ); ?></div>
                </div>
                <label class="sc-post">
                    <input type="checkbox" name="want_post" value="1"> ارسال کارت فیزیکی (+30,000 تومان)
                </label>
                <button class="sc-button" type="submit">پرداخت آنلاین / استفاده از کیف پول</button>
                <p class="sc-disclaimer">پس از پرداخت آنلاین، کارت شما آماده‌سازی و برای شما ارسال خواهد شد.</p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
