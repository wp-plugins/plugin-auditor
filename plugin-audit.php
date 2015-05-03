<?php
/*
Plugin Name: Plugin Auditor
Plugin URI: http://www.wholegraindigital.com/
Description: A plugin that records who and when installed other plugins and also asks the user to add a short comment to explain why they installed it
Version: 0.1
Author: Wholegrain Digital
Author URI: http://www.wholegraindigital.com/
License: GPL
Copyright: Wholegrain Digital
*/

if (!function_exists('is_admin')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit();
}

define('PLUGIN_AUDIT_VERSION', '0.1');
define('PLUGIN_AUDIT_RELEASE_DATE', date_i18n('F j, Y', '1397937230'));
define('PLUGIN_AUDIT_DIR', plugin_dir_path(__FILE__));
define('PLUGIN_AUDIT_URL', plugin_dir_url(__FILE__));

if (!class_exists("Plugin_Audit")) :

class Plugin_Audit {
    var $settings, $options_page, $db_version = 1;

    function __construct() {

        // Load example settings page
        if (!class_exists("Plugin_Audit_Settings")) {
            require(PLUGIN_AUDIT_DIR . 'settings.php');
        }

        $this->settings = new Plugin_Audit_Settings();

        add_action('init', array($this,'init'));
        add_action('admin_init', array($this,'admin_init'));

        register_activation_hook(__FILE__, array($this,'activate'));
        register_deactivation_hook(__FILE__, array($this,'deactivate'));
    }

    /*
        Propagates pfunction to all blogs within our multisite setup.
        More details -
        http://shibashake.com/wordpress-theme/write-a-plugin-for-wordpress-multi-site

        If not multisite, then we just run pfunction for our single blog.
    */
    function network_propagate($pfunction, $networkwide) {
        global $wpdb;

        if (function_exists('is_multisite') && is_multisite()) {
            // check if it is a network activation - if so, run the activation function
            // for each blog id
            if ($networkwide) {
                $old_blog = $wpdb->blogid;
                // Get all blog ids
                $blogids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
                foreach ($blogids as $blog_id) {
                    switch_to_blog($blog_id);
                    call_user_func($pfunction, $networkwide);
                }
                switch_to_blog($old_blog);
                return;
            }
        }
        call_user_func($pfunction, $networkwide);
    }

    function activate($networkwide) {
        $this->network_propagate(array($this, '_activate'), $networkwide);
    }

    function deactivate($networkwide) {
        $this->network_propagate(array($this, '_deactivate'), $networkwide);
    }

    /*
        Plugin activation code here.
    */
    function _activate() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'plugin_audit';

        $charset_collate = '';

        if ( ! empty( $wpdb->charset ) ) {
          $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
        }

        if ( ! empty( $wpdb->collate ) ) {
          $charset_collate .= " COLLATE {$wpdb->collate}";
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) NOT NULL,
            `action` varchar(255) NOT NULL,
            `note` text,
            `plugin_path` varchar(255) NOT NULL,
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        add_option( 'pa_db_version', $this->db_version );
    }

    /*
        Plugin deactivation code here.
    */
    function _deactivate() {
        global $wpdb;
        global $pa_db_version;

        $table_name = $wpdb->prefix . 'plugin_audit';

        $charset_collate = '';

        if ( ! empty( $wpdb->charset ) ) {
          $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
        }

        if ( ! empty( $wpdb->collate ) ) {
          $charset_collate .= " COLLATE {$wpdb->collate}";
        }

        $sql = "DROP TABLE $table_name;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        delete_option('pa_db_version');
        delete_option('pa_plugins');
        delete_option('pa_active_plugins');
    }


    /*
        Load language translation files (if any) for our plugin.
    */
    function init() {
        load_plugin_textdomain('plugin_audit', PLUGIN_AUDIT_DIR . 'lang', basename(dirname(__FILE__)) . '/lang');
    }

    function admin_init() {
        global $wpdb;

        $user_ID = get_current_user_id();

        $table_name = $wpdb->prefix . 'plugin_audit';

        if(isset($_POST['save_note'])) {
            if(!empty($_POST['note'])) {
                $wpdb->update(
                    $table_name,
                    array('note' => $_POST['note']),
                    array('id' => intval($_POST['log_id'])),
                    array('%s'),
                    array('%d')
                );
            }
        }

        $all_plugins = get_plugins();
        $all_plugins_keys = array_keys($all_plugins);
        $active_plugins = (array) get_option('active_plugins', array());
        $pa_plugins = (array) get_option('pa_plugins', array());
        $pa_plugins_keys = array_keys($pa_plugins);
        $pa_active_plugins = (array) get_option('pa_active_plugins', array());

        foreach($all_plugins as $plugin => $data) {
            $common_data = array(
                'user_id' => $user_ID,
                'plugin_path' => trim($plugin),
                'plugin_data' => $data,
                'old_plugin_data' => $pa_plugins[$plugin],
            );
            if(!in_array($plugin, $pa_plugins_keys)) {
                $this->log_action(array_merge($common_data, array(
                    'action' => 'installed',
                )));
            }
            if(in_array($plugin, $active_plugins) && !in_array($plugin, $pa_active_plugins)) {
                $this->log_action(array_merge($common_data, array(
                    'action' => 'activated',
                )));
            }
            if(!in_array($plugin, $active_plugins) && in_array($plugin, $pa_active_plugins)) {
                $this->log_action(array_merge($common_data, array(
                    'action' => 'deactivated',
                )));
            }
            if(!empty($pa_plugins[$plugin]['Version']) && $data['Version'] != $pa_plugins[$plugin]['Version']) {
                $this->log_action(array_merge($common_data, array(
                    'action' => 'version change',
                )));
            }
        }

        foreach($pa_plugins_keys as $plugin) {
            if(!in_array($plugin, $all_plugins_keys)) {
                $this->log_action(array(
                    'user_id' => $user_ID,
                    'action' => 'uninstalled',
                    'plugin_path' => trim($plugin),
                    'plugin_data' => $pa_plugins[$plugin],
                    'old_plugin_data' => $pa_plugins[$plugin],
                ));
            }
        }

        update_option('pa_plugins', $all_plugins);
        update_option('pa_active_plugins', $active_plugins);

        add_action('admin_notices', array($this, 'add_note_nag'), 99);
        add_action('network_admin_notices', array($this, 'add_note_nag'), 99);
    }

    /*
        Redirect to a different page using javascript. More details-
        http://shibashake.com/wordpress-theme/wordpress-page-redirect
    */
    function javascript_redirect($location) {
        // redirect after header here can't use wp_redirect($location);
        ?>
          <script type="text/javascript">
          <!--
          window.location= <?php echo "'" . $location . "'"; ?>;
          //-->
          </script>
        <?php
        exit;
    }

    /**
     * Add note nag
     */
    function add_note_nag() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'plugin_audit';
        $log = $wpdb->get_row("SELECT * FROM $table_name WHERE `note` IS NULL");

        if($log) {
?>
        <div class="update-nag">
            <form method="post" action="">
                <input type="hidden" name="log_id" value="<?php echo $log->id ?>">
                <p>
                    Plugin "<?php echo $log->plugin_path ?>"
                    <?php if('version change' == $log->action) { ?>
                    had a version change.
                    <?php } else { ?>
                    has been <?php echo $log->action ?>.
                    <?php } ?>
                    <br>
                    Please add a note to explain why you have installed/activated it.
                </p>
                <?php if(isset($_POST['note']) && empty($_POST['note'])) { ?>
                <p style="color: red;">Comment can't be empty!</p>
                <?php } ?>
                <p>
                    <textarea style="width: 100%;" name="note" id="note" cols="30" rows="3" placeholder="add a short comment to explain"></textarea>
                </p>
                <p>
                    <button type="submit" name="save_note" class="button button-primary" style="vertical-align: top;">Save</button>
                </p>
            </form>
        </div>
<?php
        }
    }

    function log_action($data) {
        global $wpdb, $wp_version;

        $table_name = $wpdb->prefix . 'plugin_audit';

        if(!is_string($data['plugin_data'])) {
            $data['plugin_data'] = json_encode($data['plugin_data']);
        }

        if(!is_string($data['old_plugin_data'])) {
            $data['old_plugin_data'] = json_encode($data['old_plugin_data']);
        }

        $data = array_merge($data, array(
            'wp_version' => $wp_version,
        ));

        $wpdb->insert($table_name, $data);
    }

} // end class
endif;

// Initialize our plugin object.
global $plugin_audit;
if (class_exists("Plugin_Audit") && !$plugin_audit) {
    $plugin_audit = new Plugin_Audit();
}
?>