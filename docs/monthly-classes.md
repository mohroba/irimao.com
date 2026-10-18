# Monthly classes and tuition

## Administration

- **کلاس‌های ماهانه** is a separate post type, distinct from one-off courses.
- Approved coaches submit classes from their account (**ثبت کلاس ماهانه**) or `[imao_class_submit]`. Coaches select one of their approved clubs. Submitted classes remain pending.
- Administrators choose coach, club, monthly fee in the store currency, and the three percentages. Each percentage must be positive and their sum must equal 100. Publish and check approval to enable payment. Invalid edits revoke approval. Coach edits require approval again.
- The club share belongs to the WordPress user who owns that approved club. The coach and club owner can be the same account; that account receives both shares with separate audit roles.
- Set **معرف کلاس‌ها** on the student's WordPress user-edit page. Only administrators can change it. Basic information displays it read-only. Changes retain old/new IDs, actor and timestamp.
- **کاربران ← گزارش شهریه کلاس‌ها** provides database pagination/search, class/status/Jalali-date/province/city filters, all-filtered-results XLSX and a print view for saving PDF.

## Student payments

The **کلاس‌های ماهانه** account page lists approved classes belonging to the coach selected in basic information and the student's own payment history. Guests and students of other coaches cannot buy the hidden tuition product. All three recipient accounts must exist before checkout.

Payment uses the existing WooCommerce checkout and gateways. Each paid order item creates one tuition ledger entry and atomically credits the three wallets using the agreed checkout snapshot. Duplicate gateway/status callbacks cannot repeat the credits. Distribution uses the order item's actual discounted total, excluding tax, matching existing app payouts. Fractional rounding is allocated to the club share so the three credits equal the paid tuition exactly.

The next due date is the same day of the next **Jalali** month after actual payment. If the next month is shorter, it is clamped to its final day. An early or late payment starts a new month from that payment date; there is no automatic recurring debit. Negative remaining days indicate overdue tuition.

Partial/full WooCommerce refunds reverse the original recipients' shares proportionally. A spent share becomes negative wallet debt rather than silently disappearing. Existing refunds are considered when retrying a failed settlement. Payment/refund failures create private WooCommerce logs and order notes, with up to five scheduled retries through WP-Cron. An administrator can use the order action **تلاش مجدد تسهیم شهریه ماهانه**. WP-Cron must run reliably.

## Shortcodes

| Shortcode | Parameters | Return |
| --- | --- | --- |
| `[imao_class_submit]` | None | Coach class submission/edit forms and class statuses |
| `[imao_monthly_classes]` | None | Current student's eligible classes, due dates and payment buttons |
| `[imao_class_due class_id="123" field="days"]` | `class_id`; `field=days` or `date` | Signed number of days, or Jalali due date; empty before payment |
| `[imao_class_info class_id="123" field="title"]` | `class_id`; `field=title`, `fee`, `coach`, `club` | Text only for a class belonging to the current student's selected coach |
| `[imao_class_payments class_id="123"]` | Optional `class_id` | Paginated current student's own tuition history |

These are also listed in the plugin's shortcode explorer.

## Installation and checks

`CreateMonthlyTuition202610180001` adds the `imao_class_tuition` ledger with unique order-item keys. No previous migration is edited. Installation and account rewrite refresh are versioned. Users, usermeta and tuition tables must use InnoDB; settlement fails safely and logs a private error otherwise.

Before production release, verify an approved coach and club, set a student's coach and referrer, approve a class with three shares, make a sandbox payment, repeat callbacks, check all three wallet balances, then exercise partial/full refunds and exports. Source tests and stubs do not establish real gateway or authenticated WordPress behavior.
