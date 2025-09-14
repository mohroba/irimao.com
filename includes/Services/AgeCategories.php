<?php
namespace IMAOCustom\Services;

use WP_Term;

class AgeCategories
{
    public function register(): void
    {
        add_action('age_category_add_form_fields', [$this, 'add_fields']);
        add_action('age_category_edit_form_fields', [$this, 'edit_fields']);
        add_action('created_age_category', [$this, 'save_fields']);
        add_action('edited_age_category', [$this, 'save_fields']);
        add_filter('wp_terms_checklist_args', [$this, 'disable_checked_ontop'], 10, 2);
    }

    public function add_fields(): void
    {
        ?>
        <div class="form-field">
            <label for="age_start">حداقل سن</label>
            <input type="number" name="age_start" id="age_start" min="0" />
        </div>
        <div class="form-field">
            <label for="age_end">حداکثر سن</label>
            <input type="number" name="age_end" id="age_end" min="0" />
        </div>
        <?php
    }

    public function edit_fields(WP_Term $term): void
    {
        $start = get_term_meta($term->term_id, 'age_start', true);
        $end   = get_term_meta($term->term_id, 'age_end', true);
        ?>
        <tr class="form-field">
            <th><label for="age_start">حداقل سن</label></th>
            <td><input type="number" name="age_start" id="age_start" value="<?php echo esc_attr($start); ?>" min="0" /></td>
        </tr>
        <tr class="form-field">
            <th><label for="age_end">حداکثر سن</label></th>
            <td><input type="number" name="age_end" id="age_end" value="<?php echo esc_attr($end); ?>" min="0" /></td>
        </tr>
        <?php
    }

    public function save_fields(int $term_id): void
    {
        if (isset($_POST['age_start'])) {
            update_term_meta($term_id, 'age_start', (int) $_POST['age_start']);
        }
        if (isset($_POST['age_end'])) {
            update_term_meta($term_id, 'age_end', (int) $_POST['age_end']);
        }
    }

    public function disable_checked_ontop(array $args, int $post_id): array
    {
        if (($args['taxonomy'] ?? '') === 'age_category') {
            $args['checked_ontop'] = false;
        }
        return $args;
    }
}
