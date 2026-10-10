<?php
/**
 * "Your store's security settings are slowing down FeCommerce."
 *
 * Some stores sit behind Cloudflare (or a host firewall) with rate limits or
 * bot rules that also slow down FeCommerce's own requests to the store. When
 * that keeps happening, FeCommerce's API says so in the Connected Framer
 * sites answer (storeLimited, docs/API_CONTRACT.md in fecommerce-cf). This
 * file keeps that answer and shows the store owner one plain notice with the
 * steps to allow FeCommerce.
 *
 * No extra request per page view: the answer comes from the same sites list
 * the FeCommerce screen already loads (kept ten minutes), refreshed in the
 * background at most hourly by WP-Cron while the store is connected.
 *
 * Copyright (C) 2026 FeCommerce (https://fecommerce.co)
 * Licensed under the GNU General Public License v2 or later (GPL-2.0-or-later).
 * See the LICENSE file in the plugin's root folder.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FECWF_LIMITED_OPTION', 'fecwf_store_limited');
define('FECWF_LIMIT_DISMISS_META', 'fecwf_limit_dismissed_until');
define('FECWF_LIMIT_CRON', 'fecwf_check_store_limited');
define('FECWF_ALLOW_RULE', '(cf.worker.upstream_zone eq "fecommerce.co")');

/** storeLimited from the API, cleaned, or null when absent or malformed. */
/**
 * A storeLimited time as a unix timestamp, 0 when unusable. The API sends ISO
 * 8601 strings ("2026-10-10T09:12:00.000Z", contract §5.14); a plain number of
 * seconds is accepted too.
 */
function fecwf_limited_time($value)
{
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        return max(0, (int) $value);
    }
    if (!is_string($value) || $value === '') {
        return 0;
    }
    $time = strtotime($value);
    return $time === false ? 0 : max(0, $time);
}

function fecwf_clean_limited($raw)
{
    if (!is_array($raw)) {
        return null;
    }
    $since = isset($raw['since']) ? fecwf_limited_time($raw['since']) : 0;
    $last = isset($raw['lastAt']) ? fecwf_limited_time($raw['lastAt']) : 0;
    $events = isset($raw['events24h']) ? max(0, (int) $raw['events24h']) : 0;
    if ($since <= 0 && $last <= 0) {
        return null;
    }
    return array('since' => $since, 'lastAt' => $last, 'events24h' => $events);
}

/**
 * Keep what the sites list said: the store-wide value, or the first limited
 * site when only rows carry it. Called with every fresh sites answer.
 */
function fecwf_remember_limited(array $data)
{
    $limited = fecwf_clean_limited(isset($data['storeLimited']) ? $data['storeLimited'] : null);
    if (!$limited && isset($data['sites']) && is_array($data['sites'])) {
        foreach ($data['sites'] as $site) {
            $limited = fecwf_clean_limited(is_array($site) && isset($site['storeLimited']) ? $site['storeLimited'] : null);
            if ($limited) {
                break;
            }
        }
    }
    if ($limited) {
        update_option(FECWF_LIMITED_OPTION, $limited, false);
    } else {
        delete_option(FECWF_LIMITED_OPTION);
    }
}

/** The kept value, or null. Stale after two days without a fresh report. */
function fecwf_store_limited()
{
    $limited = fecwf_clean_limited(get_option(FECWF_LIMITED_OPTION, null));
    if (!$limited) {
        return null;
    }
    $seen = max($limited['lastAt'], $limited['since']);
    return $seen > 0 && $seen < time() - 2 * DAY_IN_SECONDS ? null : $limited;
}

/*
 * Background check: at most hourly, only while connected, reusing the
 * ten-minute sites cache when the screen loaded it recently.
 */
add_action('init', function () {
    if (fecwf_get_connection()) {
        if (!wp_next_scheduled(FECWF_LIMIT_CRON)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', FECWF_LIMIT_CRON);
        }
    } elseif (wp_next_scheduled(FECWF_LIMIT_CRON)) {
        wp_clear_scheduled_hook(FECWF_LIMIT_CRON);
        delete_option(FECWF_LIMITED_OPTION);
    }
});

add_action(FECWF_LIMIT_CRON, function () {
    if (!fecwf_get_connection() || fecwf_cached_sites()) {
        return;
    }
    fecwf_fetch_sites();
});

/*
 * The notice: WooCommerce → FeCommerce and the Dashboard, for store managers,
 * dismissable for seven days.
 */
add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !current_user_can('manage_woocommerce') || !fecwf_get_connection()) {
        return;
    }
    $on_fecwf = $screen->id === 'woocommerce_page_fecwf';
    if (!$on_fecwf && $screen->id !== 'dashboard') {
        return;
    }
    if (!fecwf_store_limited()) {
        return;
    }
    $until = (int) get_user_meta(get_current_user_id(), FECWF_LIMIT_DISMISS_META, true);
    if ($until > time()) {
        return;
    }
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=fecwf_limit_dismiss'), 'fecwf_limit_dismiss');
    ?>
    <div class="notice notice-warning fecwf-limit-notice">
        <p>
            <strong>Your store's security settings are slowing down FeCommerce.</strong>
            Shoppers may see delays at checkout.
            <a href="<?php echo esc_url(fecwf_admin_url() . '#fecwf-allow'); ?>"><strong>Fix it in 2 minutes</strong></a>
            · <a href="<?php echo esc_url($dismiss); ?>">Hide for 7 days</a>
        </p>
    </div>
    <?php
});

add_action('admin_post_fecwf_limit_dismiss', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('You are not allowed to do this.', 403);
    }
    check_admin_referer('fecwf_limit_dismiss');
    update_user_meta(get_current_user_id(), FECWF_LIMIT_DISMISS_META, time() + 7 * DAY_IN_SECONDS);
    $back = wp_get_referer();
    wp_safe_redirect($back ? $back : fecwf_admin_url());
    exit;
});

/** The steps to allow FeCommerce, on the FeCommerce screen while limited. */
function fecwf_render_allow_section()
{
    $limited = fecwf_store_limited();
    if (!$limited) {
        return;
    }
    ?>
    <div class="fecwf-card fecwf-allow" id="fecwf-allow">
        <h2>Allow FeCommerce through your store's security</h2>
        <p>Your store's security settings are limiting FeCommerce, so shoppers on your Framer site may wait or see "please try again" at checkout.
            <?php if ($limited['events24h'] > 0) : ?>
                It happened <?php echo esc_html(number_format_i18n($limited['events24h'])); ?> time<?php echo $limited['events24h'] === 1 ? '' : 's'; ?> in the last 24 hours.
            <?php endif; ?>
            Allowing FeCommerce fixes it and takes about two minutes.</p>
        <h3>If your site uses Cloudflare</h3>
        <ol class="fecwf-steps">
            <li>Open the <strong>Cloudflare dashboard</strong> and choose this site.</li>
            <li>Go to <strong>Security → WAF → Custom rules</strong> and click <strong>Create rule</strong>.</li>
            <li>Name it <strong>Allow FeCommerce</strong>. Choose <strong>Edit expression</strong> and paste:
                <div class="fecwf-copy-row">
                    <code id="fecwf-allow-rule" class="fecwf-copy-text"><?php echo esc_html(FECWF_ALLOW_RULE); ?></code>
                    <button type="button" class="fecwf-btn fecwf-copy" data-fecwf-copy="fecwf-allow-rule">Copy</button>
                </div>
                <span class="fecwf-copy-status" id="fecwf-allow-rule-status" role="status" aria-live="polite"></span>
            </li>
            <li>For <strong>Action</strong>, choose <strong>Skip</strong> and tick <strong>All rate limiting rules</strong>, <strong>Bot Fight Mode / Super Bot Fight Mode</strong> and <strong>All managed rules</strong>.</li>
            <li>Click <strong>Deploy</strong>.</li>
        </ol>
        <p class="fecwf-fine">The rule only lets through requests sent by FeCommerce's own service; Cloudflare adds that marker itself, so other sites can't copy it. This notice goes away on its own once FeCommerce's requests get through again.</p>
        <h3>Not using Cloudflare?</h3>
        <p>Ask your host to allow requests from FeCommerce (<code>api-v2.fecommerce.co</code>) to <code>/wp-json/wc/store/v1/</code> and <code>/wp-json/fecommerce/v1/</code>.</p>
    </div>
    <?php
}
