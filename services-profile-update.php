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
                                                <a download="<?= basename(SERVICES_PROFILE_UPDATE_CSV_FILE) ?>" class="button button-primary" href="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_ACTIVE ?>" style="text-decoration:none;">Export Current Field Mapping</a>
                                            <?php endif ?>
                                            <a download="<?= basename(SERVICES_PROFILE_UPDATE_CSV_FILE_SAMPLE) ?>" class="button button-primary" href="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_SAMPLE ?>" style="text-decoration:none;">Download Empty CSV Sample File</a>
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
    $lines = array_filter($lines, function ($line) use ($form_id) {
        return $line['Trigger'] == $form_id;
    });
    if (empty($lines)) return true;

    $entry_user = services_profile_update_get_entry_user_id($entry_id);
    $line_fields = [];
    foreach ($lines as $line) $line_fields = array_merge($line_fields, services_profile_update_line_extract_fields($line));
    $line_fields = array_values(array_unique($line_fields));
    $answers = services_profile_update_collect_answers($entry_user, $line_fields);

    foreach ($lines as $line) services_profile_update_execute_line($entry_user, $line, $answers);
}

function services_profile_update_read_csv()
{
    $lines = [];
    if (!file_exists(SERVICES_PROFILE_UPDATE_CSV_FILE)) return $lines;

    $headers = [];
    if (($open = fopen(SERVICES_PROFILE_UPDATE_CSV_FILE, 'r')) !== FALSE) {
        $line_number = 0;
        while (($data = fgetcsv($open, 100000, ",")) !== FALSE) {
            if (0 === $line_number) $headers = $data;
            else {
                $line = [];
                for ($col = 0; $col < count($data); $col++) {
                    $line[$headers[$col]] = trim($data[$col]);
                }
                if (services_profile_update_validate_csv_line($line)) $lines[] = $line;
            }
            $line_number++;
        }
        fclose($open);
    }

    return $lines;
}

function services_profile_update_validate_csv_line($line)
{
    if ('' === $line['Trigger']) return false;
    if ('' === $line['Target']) return false;
    $fields_conditions = explode(' equals ', $line['Conditions']);
    if ('' === $fields_conditions[0]) return false;
    if (isset($fields_conditions[1]) && '' === $fields_conditions[1]) return false;
    return true;
}

function services_profile_update_get_entry_user_id($entry_id)
{
    global $wpdb;
    return $wpdb->get_var($wpdb->prepare("SELECT {$wpdb->prefix}frm_items.user_id FROM {$wpdb->prefix}frm_items WHERE id = %d", $entry_id));
}

function services_profile_update_line_extract_fields($line)
{
    $fields = [];
    $fields[] = substr($line['Target'], 1, -1);
    if ('[' === $line['Value'][0] && ']' === $line['Value'][strlen($line['Value']) - 1]) {
        $fields[] = substr($line['Value'], 1, -1);
    }
    $conditions = explode(' equals ', $line['Conditions']);
    $fields[] = substr($conditions[0], 1, -1);
    if ('[' === $conditions[1][0] && ']' === $conditions[1][strlen($conditions[1]) - 1]) {
        $fields[] = substr($conditions[1], 1, -1);
    }
    return $fields;
}

function services_profile_update_collect_answers($user_id, $fields)
{
    global $wpdb;
    $fields = implode(',', $fields);
    $answers = $wpdb->get_results($wpdb->prepare("
        SELECT
            {$wpdb->prefix}frm_items.id entry
            , {$wpdb->prefix}frm_item_metas.field_id question
            , {$wpdb->prefix}frm_item_metas.meta_value answer
        FROM {$wpdb->prefix}frm_item_metas
        RIGHT JOIN {$wpdb->prefix}frm_items ON {$wpdb->prefix}frm_items.id = {$wpdb->prefix}frm_item_metas.item_id
        WHERE {$wpdb->prefix}frm_items.user_id = %d AND {$wpdb->prefix}frm_item_metas.field_id IN ($fields)
    ", $user_id));
    return $answers;
}

function services_profile_update_execute_line($entry_user, $line, $answers)
{
    $conditions = explode(' equals ', $line['Conditions']);
    $left_question = substr($conditions[0], 1, -1);
    $right_is_field = '[' === $conditions[1][0];
    $right_question = substr($conditions[1], 1, -1);

    $left_side_answers = services_profile_update_collect_values($left_question, $answers);
    $right_side_answers = $right_is_field ? services_profile_update_collect_values($right_question, $answers) : [$right_question];
    $matching_conditions = array_intersect($left_side_answers, $right_side_answers);

    if (!empty($matching_conditions)) {
        $value = services_profile_update_line_get_value($line, $answers);
        if (is_null($value)) return true;

        global $wpdb;
        return $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}frm_item_metas
            RIGHT JOIN {$wpdb->prefix}frm_items ON {$wpdb->prefix}frm_items.id = {$wpdb->prefix}frm_item_metas.item_id
            SET {$wpdb->prefix}frm_item_metas.meta_value = '{$value}'
            WHERE {$wpdb->prefix}frm_items.user_id = %d
        ", $entry_user));
    }
}

function services_profile_update_collect_values($question, $answers)
{
    $values = [];
    $values = array_filter($answers, function ($answer) use ($question) {
        return $question == $answer->question;
    });
    $values = array_values($values);
    $values = array_map(function ($value) {
        return $value->answer;
    }, $values);
    $values = array_unique($values);
    return $values;
}

function services_profile_update_line_get_value($line, $answers)
{
    $value = $line['Value'];
    if ('[' == $value[0]) {
        $value_answers = array_values(array_filter($answers, function ($answer) use ($value) {
            return $value == $answer->question;
        }));
        if (empty($value_answer)) return null;
        $value_answer = end($value_answers);
        $value = $value_answer->answer;
    }
    return $value;
}

/*
TESTING QUERY
SELECT
    submmitted.id submitted_entry
    -- , submmitted.user_id submitted_user

    -- , target.user_id target_user
    , target.item_id target_entry
    , target.field_id target_field
    , target.meta_value target_answer

    -- , left_logic.user_id logic_user
    , left_logic.item_id left_logic_entry
    , left_logic.field_id left_logic_field
    , left_logic.meta_value left_logic_answer

    -- , rigth_logic.user_id logic_user
    , rigth_logic.item_id rigth_logic_entry
    , rigth_logic.field_id rigth_logic_field
    , rigth_logic.meta_value rigth_logic_answer
FROM wp_frm_items submmitted

LEFT JOIN (
    SELECT
        wp_frm_items.user_id
        , item_id
        , field_id
        , meta_value
    FROM wp_frm_item_metas
    RIGHT JOIN wp_frm_items ON wp_frm_item_metas.item_id = wp_frm_items.id
    WHERE field_id IN (1084, 1085)
) target ON target.user_id = submmitted.user_id

LEFT JOIN (
    SELECT
        wp_frm_items.user_id
        , item_id
        , field_id
        , meta_value
    FROM wp_frm_item_metas
    RIGHT JOIN wp_frm_items ON wp_frm_item_metas.item_id = wp_frm_items.id
    WHERE field_id IN (306)
) left_logic ON left_logic.user_id = submmitted.user_id

LEFT JOIN (
    SELECT
        wp_frm_items.user_id
        , item_id
        , field_id
        , meta_value
    FROM wp_frm_item_metas
    RIGHT JOIN wp_frm_items ON wp_frm_item_metas.item_id = wp_frm_items.id
    WHERE field_id IN (383, 903)
) rigth_logic ON rigth_logic.user_id = submmitted.user_id

WHERE submmitted.id = 4013
*/