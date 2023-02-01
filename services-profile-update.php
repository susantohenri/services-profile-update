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
                                                <a download class="button button-primary" href="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_ACTIVE ?>" style="text-decoration:none;">Export Current Field Mapping</a>
                                            <?php endif ?>
                                            <a download class="button button-primary" href="<?= SERVICES_PROFILE_UPDATE_CSV_FILE_SAMPLE ?>" style="text-decoration:none;">Download Empty CSV Sample File</a>
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
