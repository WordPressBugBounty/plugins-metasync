<?php

/**
 * Instant Indexing Settings page contents.
 *
 * @package Google Instant Indexing
 */
?>

<div id="add-redirection-form">
    <h1> Add Redirection </h1>

    <table class="form-table add-form-table">
        <tr valign="top">
            <th scope="row">
                Source From:
            </th>
            <td>
                <?php

                $record = [];
                $id = '';
                $source_form = ['' => 'exact'];
                $url_redirect_to = '';
                $http_code = '301';
                $status = 'active';
                $uri = '';

                $get_data =  sanitize_post($_GET);
                if (isset($get_data['action'])) {
                    if (isset($get_data['uri']) && ($get_data['action'] == 'redirect' && !empty($get_data['uri']))) {
                        $uri = sanitize_title($get_data['uri']);
                    }

                    if (isset($get_data['id']) && $get_data['action'] == 'edit') {

                        $record = $this->db_redirection->find(sanitize_title($get_data['id']));

                        $id = isset($record->id) ? esc_attr($record->id) : '';
                        $source_form = isset($record->sources_from) ? unserialize($record->sources_from) : [];
                        $url_redirect_to = isset($record->url_redirect_to) ? esc_attr($record->url_redirect_to) : '';
                        $http_code = isset($record->http_code) ? esc_attr($record->http_code) : '';
                        $status = isset($record->status) ? esc_attr($record->status) : '';
                    }
                }

                $search_type = [
                    ['name' => 'Exact', 'value' => 'exact'],
                    ['name' => 'Contain', 'value' => 'contain'],
                    ['name' => 'Start With', 'value' => 'start'],
                    ['name' => 'End With', 'value' => 'end'],
                ];

                ?>
                <ul id="source_urls">

                    <?php

                    foreach ($source_form as $source_name => $source_type) {

                    ?>
                        <li>
                            <input type="text" class="regular-text" name="source_url[]" value="<?php echo $uri ? esc_attr($uri) : esc_attr($source_name) ?>">
                            <select name="search_type[]">
                                <?php
                                foreach ($search_type as $type) {
                                    printf('<option value="%s" %s >%s</option>', esc_attr($type['value']), selected(esc_attr($type['value']), esc_attr($source_type)), esc_attr($type['name']));
                                }
                                ?>
                            </select>
                            <button id="source_url_delete">Remove</button>
                        </li>
                    <?php } ?>

                </ul>

                <?php
                printf(' <input type="hidden" name="redirect_id" value="%s"/>', esc_attr($id));
                printf(' <input class="button-secondary" type="button" id="addNewSourceUrl" value="Add Another">');
                ?>

            </td>
        </tr>

        <tr valign="top" id="destination" class="<?php if ($http_code == '410' || $http_code == '451') {
                                                        echo esc_attr('hide');
                                                    } ?>">
            <th scope="row">
                Destination URL:
            </th>
            <td>
                <input type="text" class="regular-text" name="destination_url" id="destination_url" value="<?php echo esc_url($url_redirect_to) ?>">
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">
                Redirection Type:
            </th>
            <td>
                <ul>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="301" <?php checked($http_code, '301'); ?>>
                            301 Permanent Move
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="302" <?php checked($http_code, '302'); ?>>
                            302 Temprary Move
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="307" <?php checked($http_code, '307'); ?>>
                            307 Temprary Redirect
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="410" <?php checked($http_code, '410'); ?>>
                            410 Content Deleted
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="redirect_type" class="redirect_type" value="451" <?php checked($http_code, '451'); ?>>
                            451 Content Unavailabel for Legal Reasons
                        </label>
                    </li>
                </ul>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row">
                Status:
            </th>
            <td>
                <label class="pr">
                    <input type="radio" name="status" value="active" <?php checked($status, 'active'); ?>>
                    Active
                </label>
                <label class="pr">
                    <input type="radio" name="status" value="inactive" <?php checked($status, 'inactive'); ?>>
                    Inactive
                </label>
            </td>
        </tr>

        <tr>
            <td colspan="2">
                <table width="100%">
                    <tr>
                        <td><input type="submit" class="button button-primary" value="Save"></td>
                        <td align="right"><input type="button" id="cancel-redirection" class="button button-secondary" value="Cancel"></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

</div>

<?php
$get_data =  sanitize_post($_GET);
if (isset($get_data['action']) && ($get_data['action'] == 'edit' || $get_data['action'] == 'redirect' || $get_data['action'] == 'add')) {
?>
    <script>
        if (document.getElementById('add-redirection-form')) {
            var element = document.getElementById('add-redirection-form');
            element.style.display = 'block';
        }
    </script>
<?php } ?>