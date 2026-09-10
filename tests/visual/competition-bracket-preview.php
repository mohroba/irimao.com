<?php

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$participants = [];
for ( $i = 1; $i <= 13; $i++ ) {
    $participants[] = [
        'entry_id' => 'entry-' . $i,
        'user_id' => $i,
        'name' => 'شرکت‌کننده ' . $i,
        'club' => 'باشگاه ' . ( ( $i % 4 ) + 1 ),
    ];
}
$slots = IMAOCustom\Services\CompetitionBrackets::seed_participants( $participants, 16, 'club', 'visual-preview-seed' );
$GLOBALS['preview_bracket'] = [
    'version' => 1,
    'size' => 16,
    'criterion' => 'club',
    'seed' => 'visual-preview-seed',
    'slots' => $slots,
    'winners' => [],
    'generated_at' => '1405/06/19 12:00',
    'audit' => [],
];

function get_post_meta( $post_id, $key, $single = true ) { return $key === '_imao_competition_bracket' ? $GLOBALS['preview_bracket'] : ''; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

$service = new IMAOCustom\Services\CompetitionBrackets();
$method = new ReflectionMethod( $service, 'bracket_html' );
$method->setAccessible( true );
echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bracket Preview</title></head><body style="margin:20px;background:#f4f5f7">';
echo $method->invoke( $service, 20 );
echo '</body></html>';
