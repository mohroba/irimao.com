<?php

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$divisions = [];
foreach ( [ '۶۵ کیلوگرم' => 8, '۷۰ کیلوگرم' => 6 ] as $weight => $count ) {
    $participants = [];
    for ( $i = 1; $i <= $count; $i++ ) {
        $participants[] = [
            'entry_id' => $weight . '-entry-' . $i,
            'user_id' => $i,
            'name' => 'شرکت‌کننده ' . $i,
            'club' => 'باشگاه ' . ( ( $i % 4 ) + 1 ),
            'seed_rank' => $i <= 4 ? $i : 0,
        ];
    }
    $key = hash( 'sha256', $weight );
    $slots = IMAOCustom\Services\CompetitionBrackets::seed_participants( $participants, 8, 'club', 'visual-preview-seed|' . $key );
    $winners = [];
    if ( $count === 8 ) {
        for ( $round = 0; $round < 3; $round++ ) {
            $rounds = IMAOCustom\Services\CompetitionBrackets::build_rounds( $slots, $winners );
            foreach ( $rounds[ $round ] as $index => $match ) {
                if ( $match['a'] && $match['b'] ) $winners[ $round . ':' . $index ] = $match['a']['entry_id'];
            }
        }
    }
    $divisions[ $key ] = [ 'label' => 'بزرگسالان — ' . $weight, 'weight_term_id' => 100 + count( $divisions ), 'size' => 8, 'slots' => $slots, 'winners' => $winners ];
}
$GLOBALS['preview_bracket'] = [
    'version' => 2,
    'criterion' => 'club',
    'seed' => 'visual-preview-seed',
    'divisions' => $divisions,
    'generated_at' => '1405/06/19 12:00',
    'audit' => [],
];

function get_post_meta( $post_id, $key, $single = true ) { return $key === '_imao_competition_bracket' ? $GLOBALS['preview_bracket'] : ''; }
function get_the_terms( $id, $taxonomy ) { return []; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }

$service = new IMAOCustom\Services\CompetitionBrackets();
$method = new ReflectionMethod( $service, 'bracket_html' );
$method->setAccessible( true );
echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bracket Preview</title></head><body style="margin:20px;background:#f4f5f7">';
echo $method->invoke( $service, 20 );
echo '</body></html>';
