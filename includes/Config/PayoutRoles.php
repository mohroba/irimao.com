<?php
/**
 * Payout role definitions.
 *
 * @return array<string,array{label:string,resolver:string,meta_key?:string}>
 */
return [
    // Coach role resolves to user meta `coach_id` on the buyer's profile.
    'coach' => [
        'label'    => 'Coach',
        'resolver' => 'user_meta',
        'meta_key' => 'coach_id',
    ],
    // Documentation-only role; payout is skipped.
    'documentation' => [
        'label'    => 'Documentation',
        'resolver' => 'none',
    ],
];
