<?php
/**
 * Removes everything this plugin stored when it is deleted.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
foreach (array('fecwf_connection', 'fecwf_store_secret', 'fecwf_restrict_sites', 'fecwf_allowed_sites', 'fecwf_seen_sites') as $fecwf_option) {
    delete_option($fecwf_option);
}
delete_transient('fecwf_challenge');
delete_transient('fecwf_sites');
