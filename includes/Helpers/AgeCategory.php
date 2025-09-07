<?php

namespace IMAOCustom\Helpers;

class AgeCategory {
    /**
     * Determine age_category term slug for a given age.
     */
    public static function slug_from_age(int $age): string {
        if (! function_exists('get_terms') || ! function_exists('get_term_meta')) {
            return '';
        }
        $terms = get_terms([
            'taxonomy'   => 'age_category',
            'hide_empty' => false,
            'parent'     => 0,
        ]);
        if (! is_array($terms)) {
            return '';
        }
        foreach ($terms as $t) {
            $term_id = is_object($t) ? (int)($t->term_id ?? 0) : 0;
            $slug    = is_object($t) ? (string)($t->slug ?? '') : '';
            if (! $term_id || ! $slug) {
                continue;
            }
            $min = (int) get_term_meta($term_id, 'age_start', true);
            $max = (int) get_term_meta($term_id, 'age_end', true);
            if ($min && $max && $age >= $min && $age <= $max) {
                return $slug;
            }
        }
        return '';
    }
}

