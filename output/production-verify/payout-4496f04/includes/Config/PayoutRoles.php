<?php
/**
 * Payout role definitions.
 *
 * @return array<string,array{label:string,resolver:string,meta_key?:string,scope?:string}>
 */
return [
    'coach' => [
        'label'    => 'مربی ورزشکار',
        'resolver' => 'user_meta',
        'meta_key' => 'coach_id',
    ],
    'club_owner' => [
        'label'    => 'مالک باشگاه ورزشکار',
        'resolver' => 'user_meta_post_author',
        'meta_key' => 'club_id',
    ],
    'province_rep' => [
        'label'    => 'نماینده استان ورزشکار',
        'resolver' => 'representative',
        'scope'    => 'province',
    ],
    'city_rep' => [
        'label'    => 'نماینده شهرستان ورزشکار',
        'resolver' => 'representative',
        'scope'    => 'city',
    ],
];
