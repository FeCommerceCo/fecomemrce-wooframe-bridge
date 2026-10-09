<?php
/**
 * Removes everything this plugin stored when it is deleted.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
foreach (array('fecwf_connection', 'fecwf_restrict_sites', 'fecwf_allowed_sites', 'fecwf_seen_sites') as $option) {
    delete_option($option);
}
delete_transient('fecwf_challenge');
