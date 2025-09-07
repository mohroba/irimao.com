<?php
namespace IMAOCustom\Services;

use WP_Term;

class AgeCategoryWeightSeeder
{
    private const OPTION = 'imao_weight_terms_seeded';

    public function register(): void
    {
        add_action('init', [$this, 'seed']);
    }

    public function seed(): void
    {
        if (get_option(self::OPTION)) {
            return;
        }

        $taxonomy = 'age_category';
        $mapping  = [
            'toddlers-7-11'   => ['20','24','27','30','33','36','40','42','45','48','51','54','57','60','63.5','66.5'],
            'teenagers-12-14' => ['32','35','38','42','45','48','51','55','60','63.5','67','71','71+'],
            'youth-15-17'     => ['48','51','54','57','60','63.5','67','71','75','81','86','86+'],
            'adults-18-38'    => ['54','57','60','63.5','67','71','75','81','86','91','91+'],
        ];

        foreach ($mapping as $parent_slug => $weights) {
            $parent = get_term_by('slug', $parent_slug, $taxonomy);
            if (!($parent instanceof WP_Term)) {
                continue;
            }
            foreach ($weights as $weight) {
                $slug = $this->slugify($weight);
                if (term_exists($slug, $taxonomy, $parent->term_id)) {
                    continue;
                }
                wp_insert_term($weight, $taxonomy, [
                    'slug'   => $slug,
                    'parent' => $parent->term_id,
                ]);
            }
        }

        update_option(self::OPTION, 1);
    }

    private function slugify(string $weight): string
    {
        $slug = str_replace(['+', '.', '/'], ['plus', '-', '-'], $weight);
        return sanitize_title($slug);
    }
}
