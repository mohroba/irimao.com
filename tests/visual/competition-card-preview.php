<?php

define( 'IMAO_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/imao-custom-plugin.php' );

function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return (string) $value; }

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$service = new IMAOCustom\Services\CompetitionCards();
$method = new ReflectionMethod( $service, 'card_document' );
$method->setAccessible( true );

echo $method->invoke( $service, [
    'name' => 'رضا محب علی',
    'insurance_date' => '۱۴۰۵/۰۵/۱۷',
    'weight' => '۶۵ KG',
    'age' => 'بزرگسالان',
    'city' => 'تهران - الف',
    'photo' => '',
    'background' => '/assets/images/competition-card-template.png',
    'verification_url' => 'https://irimao.com/?verify=preview',
    'qr_data_uri' => IMAOCustom\Services\CompetitionCards::qr_data_uri( 'https://irimao.com/?verify=preview' ),
] );
