<?php
/**
 * Connected Framer sites: every Framer project this store is connected to,
 * with Disconnect for each one.
 *
 * FeCommerce keeps the list (which project is connected, on which addresses,
 * when it was last used). Reading it and disconnecting one site both carry a
 * fresh domain proof, like Approve, so only this store can see or change its
 * own sites. Each call makes FeCommerce read /wp-json/fecommerce/v1/challenge
 * once, so the list is fetched when the admin asks for it and kept for ten
 * minutes, not on every page load.
 *
 * Disconnecting one site stops that site at once and leaves the others
 * running. Disconnect all Framer sites (includes/connect.php) still stops
 * every site without asking FeCommerce.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** One site from the API, cleaned for display, or null when malformed. */
function fecwf_clean_site($raw)
{
    if (!is_array($raw) || !isset($raw['bid']) || !is_string($raw['bid']) || !preg_match('/^[a-z2-7]{26}$/D', $raw['bid'])) {
        return null;
    }
    $origins = array();
    foreach ((isset($raw['origins']) && is_array($raw['origins'])) ? $raw['origins'] : array() as $origin) {
        $origin = fecwf_normalize_origin(is_string($origin) ? $origin : '');
        if ($origin !== '' && strpos($origin, 'https://') === 0) {
            $origins[] = $origin;
        }
    }
    return array(
        'bid' => $raw['bid'],
        'projectName' => fecwf_clean_project_name(isset($raw['projectName']) ? $raw['projectName'] : ''),
        'origins' => array_slice(array_values(array_unique($origins)), 0, 20),
        'connectedAt' => isset($raw['connectedAt']) ? (int) $raw['connectedAt'] : 0,
        'lastUsedAt' => isset($raw['lastUsedAt']) && $raw['lastUsedAt'] !== null ? (int) $raw['lastUsedAt'] : null,
    );
}

/** Fetch the list from FeCommerce and keep it for ten minutes. The list, or WP_Error. */
function fecwf_fetch_sites()
{
    $origin = fecwf_store_origin();
    if (is_wp_error($origin)) {
        return $origin;
    }
    $data = fecwf_api_post('/v1/sites/list', array('store' => $origin), true);
    if (is_wp_error($data)) {
        return $data;
    }
    if (!isset($data['sites']) || !is_array($data['sites'])) {
        return new WP_Error('fecwf_bad_response', 'FeCommerce returned an unexpected answer. Try again in a few minutes.');
    }
    $sites = array_values(array_filter(array_map('fecwf_clean_site', array_slice($data['sites'], 0, 200))));
    $cache = array('fetched_at' => time(), 'sites' => $sites);
    set_transient(FECWF_SITES_TRANSIENT, $cache, 10 * MINUTE_IN_SECONDS);
    return $cache;
}

/** The kept list: array('fetched_at', 'sites'), or null when it needs loading. */
function fecwf_cached_sites()
{
    $cache = get_transient(FECWF_SITES_TRANSIENT);
    return (is_array($cache) && isset($cache['sites']) && is_array($cache['sites'])) ? $cache : null;
}

add_action('admin_post_fecwf_sites_refresh', function () {
    fecwf_guard_action('fecwf_sites_refresh');
    if (fecwf_get_connection()) {
        $result = fecwf_fetch_sites();
        if (is_wp_error($result)) {
            fecwf_flash('error', 'Couldn\'t load your connected Framer sites. ' . $result->get_error_message());
        }
    }
    wp_safe_redirect(fecwf_admin_url() . '#fecwf-sites-list');
    exit;
});

add_action('admin_post_fecwf_site_disconnect', function () {
    fecwf_guard_action('fecwf_site_disconnect');
    $bid = isset($_POST['fecwf_bid']) ? sanitize_text_field(wp_unslash($_POST['fecwf_bid'])) : '';
    $origin = fecwf_store_origin();
    if (!preg_match('/^[a-z2-7]{26}$/D', $bid) || is_wp_error($origin) || !fecwf_get_connection()) {
        wp_safe_redirect(fecwf_admin_url());
        exit;
    }

    $cache = fecwf_cached_sites();
    $name = 'That Framer site';
    if ($cache) {
        foreach ($cache['sites'] as $site) {
            if ($site['bid'] === $bid) {
                $name = '"' . $site['projectName'] . '"';
            }
        }
    }

    $data = fecwf_api_post('/v1/sites/disconnect', array('store' => $origin, 'bid' => $bid), true);
    // Already gone at FeCommerce: the list was just out of date.
    $gone = is_wp_error($data) && $data->get_error_code() === 'fecwf_service_unknown_site';
    if (is_wp_error($data) && !$gone) {
        fecwf_flash('error', 'Couldn\'t disconnect ' . $name . '. ' . $data->get_error_message());
    } else {
        if ($cache) {
            $cache['sites'] = array_values(array_filter($cache['sites'], function ($site) use ($bid) {
                return $site['bid'] !== $bid;
            }));
            set_transient(FECWF_SITES_TRANSIENT, $cache, 10 * MINUTE_IN_SECONDS);
        }
        fecwf_flash('success', $gone
            ? $name . ' was already disconnected.'
            : 'Disconnected ' . $name . '. It stops showing your products, cart and checkout right away. Your other Framer sites keep working.');
    }
    wp_safe_redirect(fecwf_admin_url() . '#fecwf-sites-list');
    exit;
});

/** "3 hours ago", or a date for anything older than a week. */
function fecwf_when($ts)
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return '';
    }
    if (time() - $ts < WEEK_IN_SECONDS) {
        return sprintf('%s ago', human_time_diff($ts, time()));
    }
    return wp_date(get_option('date_format'), $ts);
}

function fecwf_render_sites_section()
{
    $cache = fecwf_cached_sites();
    ?>
    <div class="fecwf-card" id="fecwf-sites-list">
        <h2>Connected Framer sites</h2>
        <?php if (!$cache) : ?>
            <p>See every Framer site connected to this store, and disconnect one without affecting the others.</p>
            <div class="fecwf-actions">
                <?php fecwf_action_form('fecwf_sites_refresh', 'Show connected sites', 'fecwf-btn'); ?>
            </div>
            <p class="fecwf-fine">Loading the list asks FeCommerce, which confirms the request comes from this site by reading <code>/wp-json/fecommerce/v1/challenge</code> once.</p>
        <?php elseif (!$cache['sites']) : ?>
            <p>No Framer sites are connected right now. A site appears here once its owner has confirmed the store in Framer.</p>
            <div class="fecwf-actions">
                <?php fecwf_action_form('fecwf_sites_refresh', 'Refresh', 'fecwf-btn'); ?>
            </div>
        <?php else : ?>
            <ul class="fecwf-sites-list">
                <?php foreach ($cache['sites'] as $site) : ?>
                    <li class="fecwf-site">
                        <div class="fecwf-site-main">
                            <strong class="fecwf-site-name"><?php echo esc_html($site['projectName']); ?></strong>
                            <?php if ($site['origins']) : ?>
                                <span class="fecwf-site-origins"><?php echo esc_html(implode(', ', $site['origins'])); ?></span>
                            <?php endif; ?>
                            <span class="fecwf-site-dates">
                                <?php
                                $parts = array();
                                if ($site['connectedAt'] > 0) {
                                    $parts[] = 'Connected ' . wp_date(get_option('date_format'), $site['connectedAt']);
                                }
                                $parts[] = $site['lastUsedAt'] ? 'last used ' . fecwf_when($site['lastUsedAt']) : 'not used yet';
                                echo esc_html(implode(' · ', $parts));
                                ?>
                            </span>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-fecwf-confirm="<?php echo esc_attr('Disconnect "' . $site['projectName'] . '"? It stops showing your products, cart and checkout right away. Your other Framer sites keep working.'); ?>">
                            <input type="hidden" name="action" value="fecwf_site_disconnect" />
                            <input type="hidden" name="fecwf_bid" value="<?php echo esc_attr($site['bid']); ?>" />
                            <?php wp_nonce_field('fecwf_site_disconnect'); ?>
                            <button type="submit" class="fecwf-btn fecwf-btn-danger" aria-label="<?php echo esc_attr('Disconnect ' . $site['projectName']); ?>">Disconnect</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="fecwf-sites-foot">
                <span class="fecwf-fine">Updated <?php echo esc_html(human_time_diff((int) $cache['fetched_at'], time())); ?> ago.</span>
                <?php fecwf_action_form('fecwf_sites_refresh', 'Refresh', 'fecwf-btn'); ?>
            </div>
            <p class="fecwf-fine">Don't recognise a site? Disconnect it. Only sites whose owner had a code approved here can appear.</p>
        <?php endif; ?>
    </div>
    <?php
}
