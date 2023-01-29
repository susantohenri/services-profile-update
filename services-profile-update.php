<?php

/**
 * Services Profile Update
 *
 * @package     ServicesProfileUpdate
 * @author      services_profile_update Susanto
 * @copyright   2022 services_profile_update Susanto
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Services Profile Update
 * Plugin URI:  https://github.com/susantoservices_profile_update
 * Description: Update user profile base on formidable form submit
 * Version:     1.0.0
 * Author:      services_profile_update Susanto
 * Author URI:  https://github.com/susantoservices_profile_update
 * Text Domain: ServicesProfileUpdate
 * License:     GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

define('SERVICES_PROFILE_UPDATE_CSV_FILE_SAMPLE', plugin_dir_url(__FILE__) . 'services-profile-update-field-mapping-sample.csv');
define('SERVICES_PROFILE_UPDATE_CSV_FILE_ACTIVE', plugin_dir_url(__FILE__) . 'services-profile-update-field-mapping-active.csv');
define('SERVICES_PROFILE_UPDATE_CSV_FILE', plugin_dir_path(__FILE__) . 'services-profile-update-field-mapping-active.csv');
define('SERVICES_PROFILE_UPDATE_CSV_FILE_SUBMIT', 'services-profile-update-field-mapping-submit');
define('SERVICES_PROFILE_UPDATE_LATEST_CSV_OPTION', 'services-profile-update-last-uploaded-csv');

add_action('admin_menu', function () {
    add_menu_page('Services Profile Update', 'Services Profile Update', 'administrator', __FILE__, function () {
        if ($_FILES) {
            if ($_FILES[SERVICES_PROFILE_UPDATE_CSV_FILE_SUBMIT]['tmp_name']) {
                move_uploaded_file($_FILES[SERVICES_PROFILE_UPDATE_CSV_FILE_SUBMIT]['tmp_name'], SERVICES_PROFILE_UPDATE_CSV_FILE);
                update_option(SERVICES_PROFILE_UPDATE_LATEST_CSV_OPTION, $_FILES[SERVICES_PROFILE_UPDATE_CSV_FILE_SUBMIT]['name']);
            }
        }
?>
        <div class="wrap">
            <h1>Services Profile Update</h1>
            <div id="dashboard-widgets-wrap">
                <div id="dashboard-widgets" class="metabox-holder">
                    <div class="">
                        <div class="meta-box-sortables">
                            <div id="dashboard_quick_press" class="postbox ">
                                <div class="postbox-header">
                                    <h2 class="hndle ui-sortable-handle">
                                        <span>Field Mapping CSV</span>
                                        <div>
                                            <?php if (file_exists(SERVICES_PROFILE_UPDATE_CSV_FILE)) : ?>
                                                <a class="button button-primary" href="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_ACTIVE ?>" style="text-decoration:none;">Export Current Field Mapping</a>
                                            <?php endif ?>
                                            <a class="button button-primary" href="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_SAMPLE ?>" style="text-decoration:none;">Download Empty CSV Sample File</a>
                                        </div>
                                    </h2>
                                </div>
                                <div class="inside">
                                    <form name="post" action="" method="post" class="initial-form" enctype="multipart/form-data">
                                        <div class="input-text-wrap" id="title-wrap">
                                            <label> Last Uploaded CSV File Name: </label>
                                            <b><?= get_option(SERVICES_PROFILE_UPDATE_LATEST_CSV_OPTION) ?></b>
                                        </div>
                                        <div class="input-text-wrap" id="title-wrap">
                                            <label for="title"> Choose New Field Mapping CSV File </label>
                                            <input type="file" name="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_SUBMIT ?>">
                                        </div>
                                        <p>
                                            <input type="submit" name="save" class="button button-primary" value="Upload Selected CSV">
                                            <br class="clear">
                                        </p>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php
    }, '');
});

add_action('frm_after_create_entry', 'services_profile_update', 30, 2);
add_action('frm_after_update_entry', 'services_profile_update', 10, 2);

function services_profile_update($entry_id, $form_id)
{
    $target_triggered_lines = [];
    $source_triggered_lines = [];
    foreach (services_profile_update_read_csv() as $line) {
        if ($line['target_form'] === "{$form_id}") $target_triggered_lines[] = $line;
        if ($line['source_form'] === "{$form_id}") $source_triggered_lines[] = $line;
    }
    if (!empty($target_triggered_lines)) services_profile_update_target_submitted($target_triggered_lines, $entry_id);
    if (!empty($source_triggered_lines)) services_profile_update_source_submitted($source_triggered_lines, $entry_id);
}

function services_profile_update_target_submitted($lines, $entry_id)
{
    global $wpdb;
    $target_entry_answers = [];
    $target_entry_answer_ids = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT id, field_id , meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d ", $entry_id)) as $answer) {
        $target_entry_answers[$answer->field_id] = $answer->meta_value;
        $target_entry_answer_ids[$answer->field_id] = $answer->id;
    };

    $source_forms = array_map(function ($line) {
        return $line['source_form'];
    }, $lines);
    $source_forms = implode(',', $source_forms);
    $sources = $wpdb->get_results($wpdb->prepare("
        SELECT
            source_entry.form_id source_form
            , source_entry.id source_entry_id
            , source_answer.id answer_id
            , source_answer.field_id answer_field
            , source_answer.meta_value answer_value
        FROM
            {$wpdb->prefix}frm_item_metas source_answer
            RIGHT JOIN {$wpdb->prefix}frm_items source_entry ON source_answer.item_id = source_entry.id
            RIGHT JOIN {$wpdb->prefix}frm_items target_entry ON source_entry.user_id = target_entry.user_id
        WHERE target_entry.id = %d
        AND source_entry.form_id IN ($source_forms)
    ", $entry_id));

    $source_entries = $wpdb->get_results($wpdb->prepare("
        SELECT
            source_entry.id entry_id
            , source_entry.form_id
        FROM {$wpdb->prefix}frm_items source_entry
        RIGHT JOIN {$wpdb->prefix}frm_items target_entry ON source_entry.user_id = target_entry.user_id
        WHERE source_entry.form_id IN ($source_forms)
        AND target_entry.id = %d
    ", $entry_id));

    foreach ($lines as $line) {
        $target_entry_updated = false;
        $target_answer_id = isset($target_entry_answer_ids[$line['target_field']]) ? $target_entry_answer_ids[$line['target_field']] : null;

        foreach (array_values(array_filter($source_entries, function ($entry) use ($line) {
            return $entry->form_id === $line['source_form'];
        })) as $source_entry) {
            if ($target_entry_updated) continue;
            $is_match = 'AND' === $line['source_logic'];

            $source_entry_answers = [];
            foreach (array_values(array_filter($sources, function ($source) use ($source_entry) {
                return $source->source_entry_id === $source_entry->entry_id;
            })) as $answer) {
                $source_entry_answers[$answer->answer_field] = $answer->answer_value;
            }

            $formula_column = 4;
            if (!isset($line['raw_data'][$formula_column]) || '' === $line['raw_data'][$formula_column]) $is_match = true;
            else {
                while (isset($line['raw_data'][$formula_column]) && '' !== $line['raw_data'][$formula_column]) {
                    $partial_match = services_profile_update_compare($line['raw_data'][$formula_column], $target_entry_answers, $source_entry_answers);
                    switch ($line['source_logic']) {
                        case 'AND':
                            $is_match = $is_match && $partial_match;
                            break;
                        case '':
                        case 'OR':
                            $is_match = $is_match || $partial_match;
                            break;
                    }
                    $formula_column++;
                }
            }

            if ('[' === $line['target_value'][0] && ']' === $line['target_value'][strlen($line['target_value']) - 1]) {
                $source_field_id = substr($line['target_value'], 1, -1);
                $target_answer_value = $source_entry_answers[$source_field_id];
                if (!isset($target_answer_value)) $is_match = false;
            } else $target_answer_value = $line['target_value'];

            if ($is_match) {
                services_profile_update_update($target_answer_value, $target_answer_id, $entry_id, $line['target_field']);
                $target_entry_updated = true;
            }
        }
    }
}

function services_profile_update_source_submitted($lines, $entry_id)
{
    global $wpdb;
    $source_entry_answers = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT field_id , meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d ", $entry_id)) as $answer) {
        $source_entry_answers[$answer->field_id] = $answer->meta_value;
    };

    $target_forms = array_map(function ($line) {
        return $line['target_form'];
    }, $lines);
    $target_forms = implode(',', $target_forms);
    $targets = $wpdb->get_results($wpdb->prepare("
        SELECT
            target_entry.form_id target_form
            , target_entry.id target_entry_id
            , target_answer.id answer_id
            , target_answer.field_id answer_field
            , target_answer.meta_value answer_value
        FROM
            {$wpdb->prefix}frm_item_metas target_answer
            RIGHT JOIN {$wpdb->prefix}frm_items target_entry ON target_answer.item_id = target_entry.id
            RIGHT JOIN {$wpdb->prefix}frm_items source_entry ON target_entry.user_id = source_entry.user_id
        WHERE source_entry.id = %d
        AND target_entry.form_id IN ($target_forms)
    ", $entry_id));

    $target_entries = $wpdb->get_results($wpdb->prepare("
        SELECT
            target_entry.id entry_id
            , target_entry.form_id
        FROM {$wpdb->prefix}frm_items target_entry
        RIGHT JOIN {$wpdb->prefix}frm_items source_entry ON target_entry.user_id = source_entry.user_id
        WHERE target_entry.form_id IN ($target_forms)
        AND source_entry.id = %d
    ", $entry_id));

    foreach ($lines as $line) {
        foreach (array_values(array_filter($target_entries, function ($entry) use ($line) {
            return $entry->form_id === $line['target_form'];
        })) as $target_entry) {
            $is_match = 'AND' === $line['source_logic'];

            $target_entry_answers = [];
            $target_answer_id = null;
            foreach (array_values(array_filter($targets, function ($target) use ($target_entry) {
                return $target->target_entry_id === $target_entry->entry_id;
            })) as $answer) {
                $target_entry_answers[$answer->answer_field] = $answer->answer_value;
                if ($answer->answer_field === $line['target_field']) $target_answer_id = $answer->answer_id;
            }

            // matching
            $formula_column = 4;
            if (!isset($line['raw_data'][$formula_column]) || '' === $line['raw_data'][$formula_column]) $is_match = true;
            else {
                while (isset($line['raw_data'][$formula_column]) && '' !== $line['raw_data'][$formula_column]) {
                    $partial_match = services_profile_update_compare($line['raw_data'][$formula_column], $source_entry_answers, $target_entry_answers);
                    switch ($line['source_logic']) {
                        case 'AND':
                            $is_match = $is_match && $partial_match;
                            break;
                        case '':
                        case 'OR':
                            $is_match = $is_match || $partial_match;
                            break;
                    }
                    $formula_column++;
                }
            }

            // set target value
            if ('[' === $line['target_value'][0] && ']' === $line['target_value'][strlen($line['target_value']) - 1]) {
                $source_field_id = substr($line['target_value'], 1, -1);
                $target_answer_value = $source_entry_answers[$source_field_id];
                if (!isset($target_answer_value)) $is_match = false;
            } else $target_answer_value = $line['target_value'];

            if ($is_match) services_profile_update_update($target_answer_value, $target_answer_id, $target_entry->entry_id, $line['target_field']);
        }
    }
}

function services_profile_update_read_csv()
{
    $lines = [];
    if (!file_exists(SERVICES_PROFILE_UPDATE_CSV_FILE)) return $lines;
    if (($open = fopen(SERVICES_PROFILE_UPDATE_CSV_FILE, 'r')) !== FALSE) {
        while (($data = fgetcsv($open, 100000, ",")) !== FALSE) $rows[] = $data;
        fclose($open);
    }
    unset($rows[0]); // exclude title row
    $rows = array_values($rows);

    global $wpdb;
    $all_fields = $wpdb->get_results($wpdb->prepare("SELECT form_id, id field_id FROM {$wpdb->prefix}frm_fields WHERE %d", true));

    $forms = [];
    foreach ($all_fields as $record) $forms[$record->field_id] = $record->form_id;

    foreach ($rows as $columns) {
        $target_field = substr($columns[1], 1, -1);
        if (!isset($forms[$target_field])) continue; // exclude incomplete/invalid line
        $target_form = $forms[$target_field];
        $target_value = $columns[2];

        $source_column = 4;
        $source_formulas = [];
        while (isset($columns[$source_column]) && '' !== $columns[$source_column]) {
            $source_formulas[] = $columns[$source_column];
            $source_column++;
        }

        $source_form = 0;
        $value_column = 2;
        if ('[' === $columns[$value_column][0] && ']' === $columns[$value_column][strlen($columns[$value_column]) - 1]) {
            $field_id = substr($columns[$value_column], 1, -1);
            $form_id = $forms[$field_id];
            if ($form_id !== $target_form) $source_form = $form_id;
        } else foreach ($source_formulas as $formula) {
            if (0 === $source_form) foreach (explode(' ', $formula) as $formula_part) {
                if (0 !== $source_form) continue;
                if ('[' === $formula_part[0] && ']' === $formula_part[strlen($formula_part) - 1]) {
                    $field_id = substr($formula_part, 1, -1);
                    $form_id = $forms[$field_id];
                    if ($form_id !== $target_form) $source_form = $form_id;
                }
            }
        }

        $line_valid = true;
        // line validation goes here

        if ($line_valid) $lines[] = [
            'target_form' => $target_form,
            'target_field' => $target_field,
            'target_value' => $target_value,
            'source_form' => $source_form,
            'source_logic' => $columns[3],
            'source_formulas' => $source_formulas,
            'raw_data' => $columns
        ];
    }

    return $lines;
}

function services_profile_update_compare($formula, $source, $target)
{
    $result = false;

    $formula = explode(' ', $formula);
    $field_1 = substr($formula[0], 1, -1);
    $operator = $formula[1];
    $field_2 = substr($formula[2], 1, -1);

    $value_1 = null;
    if (isset($source[$field_1])) $value_1 = $source[$field_1];
    else if (isset($target[$field_1])) $value_1 = $target[$field_1];

    $value_2 = null;
    if (isset($source[$field_2])) $value_2 = $source[$field_2];
    else if (isset($target[$field_2])) $value_2 = $target[$field_2];

    switch ($operator) {
        case 'equals':
            $result = $value_1 == $value_2;
            break;
        case 'not-equals':
            $result = $value_1 != $value_2;
            break;
    }

    return $result;
}

function services_profile_update_update($answer_value, $answer_id, $entry_id = null, $field_id = null)
{
    global $wpdb;
    if (is_null($answer_id)) {
        $wpdb->insert("{$wpdb->prefix}frm_item_metas", [
            'meta_value' => $answer_value,
            'field_id' => $field_id,
            'item_id' => $entry_id
        ], [
            '%s',
            '%d',
            '%d'
        ]);
    } else {
        $wpdb->update("{$wpdb->prefix}frm_item_metas", [
            'meta_value' => $answer_value,
        ], [
            'id' => $answer_id
        ], [
            '%s'
        ], [
            '%d'
        ]);
    }
}
