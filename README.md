# پلاگین اختصاصی ایمائو

افزونه‌ای برای پیاده‌سازی ورود، ثبت‌نام و داشبورد کاربری.

## توسعه

```bash
composer install
composer test


### Competition administration updates

- The weigh-in metabox renders 20 registrations per page. Search covers all paid registrations and normalizes Persian, Arabic and Latin digits. Save edits before changing pages; navigation prompts when weigh-in fields have changed. Only submitted registration rows are saved, so other pages retain their values.
- The dormitory XLSX button exports all saved dormitory requests for the competition, independent of page and search. The endpoint requires the competition edit capability and a nonce. All cells are explicit strings to preserve identifiers and prevent spreadsheet formula execution.
- New bracket draws place an entrant in every opening match before assigning opponents. Existing snapshots retain their slots and results; empty branches are hidden. Regenerate a draw only when a new allocation is desired.
- Bracket labels follow the competition level: provincial → city, international → country, national/grand prix/regional → province, county → club. Missing names have a Persian placeholder. Country labels in `assets/data/countries-fa.json` come from ICU Persian region names and do not require the production server to have the intl extension.
- The public bracket omits national IDs, uses compact rows, and shows a placements table. After a decided final, its winner is first, its opponent second, and the two semifinal losers third and joint third. A bye never creates a medal recipient.
- Reusable `imao-input-fa` and `imao-input-en` CSS classes control direction and enable the input-language script on inputs/textareas. The script filters typing and pasted text, preserves spaces, apostrophes and hyphens, supports IME composition, and normalizes Arabic ي/ك to Persian ی/ک. Existing Persian/English name fields receive these classes automatically; the basic-info form also declares them in its markup.

Focused checks:

```sh
php vendor/bin/phpunit --filter 'CompetitionBracketsTest|CompetitionCardsTest'
php tests/fixtures/competition-admin.php
node --test tests/js/input-language.test.cjs
```

The disposable admin fixture checks pagination, search beyond the first page, dormitory exports across pages, and preservation of off-page edits. It uses synthetic users/orders rather than a WordPress database. `php tests/fixtures/competition-admin.php export` emits a test XLSX; the `html` argument renders a browser preview.
