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
    $lines = services_profile_update_read_csv();
    if (!in_array($form_id, array_map(function ($line) {
        return $line['source_form'];
    }, $lines))) return true;

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

    $target_entries = $wpdb->get_results($wpdb->prepare("SELECT id entry_id, form_id FROM {$wpdb->prefix}frm_items WHERE {$wpdb->prefix}frm_items.form_id IN ($target_forms)"));

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
                // $target_entry_answers[] = ['id' => $answer->answer_id, 'field' => $answer->answer_field, 'value' => $answer->answer_value];
                if ($answer->answer_field === $line['target_field']) $target_answer_id = $answer->answer_id;
            }

            // matching
            $formula_column = 4;
            if (!isset($line['raw_data'][$formula_column]) || '' === $line['raw_data'][$formula_column]) $is_match = true;
            else {
                while (isset($line['raw_data'][$formula_column])) {
                    $partial_match = services_profile_update_compare($line['raw_data'][$formula_column], $source_entry_answers, $target_entry_answers);
                    switch ($line['source_logic']) {
                        case 'AND':
                            $is_match = $is_match && $partial_match;
                            break;
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

function services_profile_update_v1($entry_id, $form_id)
{
    if (!file_exists(SERVICES_PROFILE_UPDATE_CSV_FILE)) return true;
    $rows = [];
    if (($open = fopen(SERVICES_PROFILE_UPDATE_CSV_FILE, 'r')) !== FALSE) {
        while (($data = fgetcsv($open, 100000, ",")) !== FALSE) $rows[] = $data;
        fclose($open);
    }

    $rows = array_filter($rows, function ($col) use ($form_id) {
        return $col[2] == $form_id;
    });

    foreach ($rows as $lineNumber => $columns) {
        if (0 === $lineNumber) continue; // SKIP TITLE ROW

        $user_profile_form_id = $columns[0];
        $user_profile_field_id = $columns[1];
        $trigger_field_id = $columns[3];

        global $wpdb;
        $prefix = $wpdb->prefix;

        // COLLECT TRIGGER DATA
        $trigger_entry = $wpdb->prepare("
            SELECT
                answer.meta_value answer_value,
                entry.user_id
            FROM {$prefix}frm_item_metas answer
            LEFT JOIN {$prefix}frm_items entry ON answer.item_id = entry.id
            WHERE TRUE
                AND entry.id = %d
                AND answer.field_id = %d
        ", $entry_id, $trigger_field_id);
        $trigger_entry = $wpdb->get_row($trigger_entry);
        $user_id = $trigger_entry->user_id;
        $trigger_entry = $trigger_entry->answer_value;

        // COLLECT PROFILE DATA
        $profile_value = $wpdb->prepare("
            SELECT
                entry.id entry_id,
                entry.user_id,
                answer.id answer_id,
                answer.meta_value answer_value
            FROM {$prefix}frm_forms user_profile_form
            LEFT JOIN {$prefix}frm_items entry ON entry.form_id = user_profile_form.id
            LEFT JOIN {$prefix}frm_fields user_profile_field ON user_profile_field.form_id = user_profile_form.id
            LEFT JOIN {$prefix}frm_item_metas answer ON answer.field_id = user_profile_field.id AND answer.item_id = entry.id
            WHERE TRUE
                AND user_profile_form.id = %d
                AND user_profile_field.id = %d
                AND entry.user_id = %d
            ORDER BY entry.id DESC
            LIMIT 1
        ", $user_profile_form_id, $user_profile_field_id, $user_id);
        $profile_value = $wpdb->get_row($profile_value);

        /** APPLY TRIGGER DATA INTO PROFILE DATA **/
        if (is_null($profile_value)) {
            // USER PROFILE NOT EXISTS, SKIP
        } else if (is_null($profile_value->answer_id)) {
            // USER PROFILE FIELD NOT ANSWERED YET, CREATE ANSWER
            $wpdb->insert("{$prefix}frm_item_metas", [
                'meta_value' => $trigger_entry,
                'field_id' => $user_profile_field_id,
                'item_id' => $profile_value->entry_id
            ], [
                '%s',
                '%d',
                '%d'
            ]);
        } else {
            // UPDATE USER PROFILE FIELD ANSWER
            $wpdb->update("{$prefix}frm_item_metas", [
                'meta_value' => $trigger_entry,
            ], [
                'id' => $profile_value->answer_id
            ], [
                '%s'
            ], [
                '%d'
            ]);
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
        if (!isset ($forms[$target_field])) continue;// exclude incomplete/invalid line
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
