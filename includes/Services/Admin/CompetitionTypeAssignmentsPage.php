<?php

namespace IMAOCustom\Services\Admin;

use IMAOCustom\Helpers\CompetitionTypeAssignments;
use WP_Term;

class CompetitionTypeAssignmentsPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=competition',
            'انواع مسابقه در رده‌های سنی',
            'انواع مسابقه در رده‌ها',
            'manage_options',
            'competition-type-assignments',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $message = '';
        if ($this->should_handle_post()) {
            check_admin_referer('competition_type_assignments', 'competition_type_assignments_nonce');
            $map = $this->sanitize_assignments($_POST['assignments'] ?? []);
            CompetitionTypeAssignments::save_map($map);
            $message = 'تنظیمات ذخیره شد.';
        }

        $assignments = CompetitionTypeAssignments::get_map();
        $age_terms   = $this->get_age_categories();
        $type_terms  = $this->get_competition_types();

        echo '<div class="wrap">';
        echo '<h1>انواع مسابقه در رده‌های سنی</h1>';
        if ($message) {
            printf('<div class="updated notice"><p>%s</p></div>', esc_html($message));
        }
        echo '<form method="post">';
        wp_nonce_field('competition_type_assignments', 'competition_type_assignments_nonce');
        echo '<p>برای هر رده‌ی سنی، نوع یا انواع مسابقه قابل انتخاب را مشخص کنید.</p>';
        echo '<table class="widefat striped" style="max-width:800px">';
        echo '<thead><tr><th>رده سنی</th><th>انواع مسابقه مجاز</th></tr></thead><tbody>';
        foreach ($age_terms as $age) {
            $selected = $assignments[$age->term_id] ?? [];
            echo '<tr>';
            echo '<th scope="row">' . esc_html($age->name) . '</th>';
            echo '<td>';
            printf('<select name="assignments[%d][]" multiple style="min-width:300px" size="6">', (int) $age->term_id);
            foreach ($type_terms as $type) {
                $indent = str_repeat('— ', max(0, $type['depth']));
                $term   = $type['term'];
                $selected_attr = in_array($term->term_id, $selected, true) ? ' selected' : '';
                printf(
                    '<option value="%d"%s>%s%s</option>',
                    (int) $term->term_id,
                    $selected_attr,
                    $indent ? esc_html($indent) . ' ' : '',
                    esc_html($term->name)
                );
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">ذخیره تغییرات</button></p>';
        echo '</form>';
        echo '</div>';
    }

    private function should_handle_post(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * @param mixed $input
     * @return array<int,array<int>>
     */
    private function sanitize_assignments($input): array
    {
        $map = [];
        if (!\is_array($input)) {
            return $map;
        }
        foreach ($input as $age_id => $type_ids) {
            $age = (int) $age_id;
            if ($age <= 0) {
                continue;
            }
            $map[$age] = [];
            foreach ((array) $type_ids as $type_id) {
                $tid = (int) $type_id;
                if ($tid > 0) {
                    $map[$age][$tid] = $tid;
                }
            }
            if (!$map[$age]) {
                unset($map[$age]);
            } else {
                $map[$age] = array_values($map[$age]);
            }
        }

        return $map;
    }

    /**
     * @return array<int,WP_Term>
     */
    private function get_age_categories(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'age_category',
            'hide_empty' => false,
            'parent'     => 0,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!\is_array($terms) || empty($terms)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($term) {
            return $term instanceof WP_Term ? $term : null;
        }, $terms)));
    }

    /**
     * Build a flattened list of competition types with depth.
     *
     * @return array<int,array{term:WP_Term,depth:int}>
     */
    private function get_competition_types(): array
    {
        return $this->collect_type_terms();
    }

    /**
     * @param int $parent
     * @param int $depth
     * @return array<int,array{term:WP_Term,depth:int}>
     */
    private function collect_type_terms(int $parent = 0, int $depth = 0): array
    {
        $terms = get_terms([
            'taxonomy'   => 'competition_type',
            'hide_empty' => false,
            'parent'     => $parent,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!\is_array($terms) || empty($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $items[] = ['term' => $term, 'depth' => $depth];
            $items   = array_merge($items, $this->collect_type_terms($term->term_id, $depth + 1));
        }

        return $items;
    }
}
