<?php
/**
 * Plugin Name: F3 Backblast Feed
 * Description: Displays recent backblasts from your region pulled live from the F3 Nation API. Use [f3_backblasts] on any page. Optionally imports backblasts as WordPress posts. Requires F3 Nation API plugin.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * INSTALLATION
 * ============================================================
 * 1. Install and activate the "F3 Nation API" plugin first
 * 2. Upload this folder to /wp-content/plugins/f3-backblast-feed/
 * 3. Activate in WordPress Admin → Plugins
 * 4. Add [f3_backblasts] to any page or post
 *
 * SHORTCODE OPTIONS
 * ============================================================
 * [f3_backblasts]                     — default (10 most recent)
 * [f3_backblasts count="20"]          — show 20 backblasts
 * [f3_backblasts ao="Cowbell"]        — filter by AO name
 * [f3_backblasts show_content="true"] — show backblast text preview
 * [f3_backblasts show_pax="true"]     — show PAX count
 *
 * AUTO-IMPORT TO WORDPRESS POSTS
 * ============================================================
 * Go to F3 Nation → Backblast Import Settings to enable
 * automatic import of new backblasts as WordPress posts.
 * This runs daily via WordPress cron.
 * Posts are created in the "backblast" category and tagged
 * with the AO name. Duplicate posts are skipped automatically.
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_notices', function() {
    if ( ! function_exists('f3api_request') ) {
        echo '<div class="notice notice-error"><p>⚠️ <strong>F3 Backblast Feed</strong> requires the <strong>F3 Nation API</strong> plugin.</p></div>';
    }
});

// ── Settings subpage ───────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_submenu_page(
        'f3-nation',
        'Backblast Import',
        'Backblast Import',
        'manage_options',
        'f3-backblast-import',
        'f3bb_settings_page'
    );
});

function f3bb_settings_page() {
    if ( isset($_POST['f3bb_save']) && check_admin_referer('f3bb_settings') ) {
        update_option('f3bb_auto_import',  sanitize_text_field($_POST['f3bb_auto_import'] ?? '0'));
        update_option('f3bb_post_status',  sanitize_text_field($_POST['f3bb_post_status'] ?? 'draft'));
        update_option('f3bb_post_author',  intval($_POST['f3bb_post_author'] ?? 1));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    if ( isset($_POST['f3bb_import_now']) && check_admin_referer('f3bb_settings') ) {
        $count = f3bb_import_backblasts();
        echo '<div class="updated"><p>Imported ' . $count . ' new backblasts.</p></div>';
    }
    $auto_import = get_option('f3bb_auto_import', '0');
    $post_status = get_option('f3bb_post_status', 'draft');
    $post_author = get_option('f3bb_post_author', 1);
    ?>
    <div class="wrap">
      <h1>F3 Backblast Import Settings</h1>
      <form method="post">
        <?php wp_nonce_field('f3bb_settings'); ?>
        <table class="form-table">
          <tr>
            <th>Auto-import backblasts</th>
            <td>
              <label>
                <input type="checkbox" name="f3bb_auto_import" value="1" <?php checked($auto_import, '1'); ?>>
                Import new backblasts as WordPress posts daily
              </label>
              <p class="description">When enabled, a daily cron job imports backblasts from the F3 Nation API as WordPress posts.</p>
            </td>
          </tr>
          <tr>
            <th>Post status</th>
            <td>
              <select name="f3bb_post_status">
                <option value="draft" <?php selected($post_status, 'draft'); ?>>Draft (review before publishing)</option>
                <option value="publish" <?php selected($post_status, 'publish'); ?>>Published (go live immediately)</option>
              </select>
            </td>
          </tr>
          <tr>
            <th>Post author</th>
            <td>
              <?php wp_dropdown_users(['name' => 'f3bb_post_author', 'selected' => $post_author]); ?>
            </td>
          </tr>
        </table>
        <p>
          <input type="submit" name="f3bb_save" value="Save Settings" class="button-primary">
          &nbsp;&nbsp;
          <input type="submit" name="f3bb_import_now" value="Import Now" class="button-secondary">
        </p>
      </form>
    </div>
    <?php
}

// ── Cron ───────────────────────────────────────────────────────────────────
add_action( 'init', function() {
    if ( get_option('f3bb_auto_import') === '1' && ! wp_next_scheduled('f3_import_backblasts') ) {
        wp_schedule_event( time(), 'daily', 'f3_import_backblasts' );
    }
});
add_action( 'f3_import_backblasts', 'f3bb_import_backblasts' );

function f3bb_import_backblasts(): int {
    if ( ! function_exists('f3api_request') ) return 0;

    $data   = f3api_request('/v1/event-instance?regionOrgId=' . f3api_region_id() . '&hasBackblast=true&limit=50&orderBy=startDate&order=desc');
    $events = $data['eventInstances'] ?? [];
    $count  = 0;
    $status = get_option('f3bb_post_status', 'draft');
    $author = get_option('f3bb_post_author', 1);

    // Ensure backblast category exists
    $cat = get_category_by_slug('backblast');
    if ( ! $cat ) {
        $cat_id = wp_create_category('Backblast', 0);
    } else {
        $cat_id = $cat->term_id;
    }

    foreach ($events as $e) {
        $event_id = intval($e['id']);
        // Skip if already imported
        $existing = get_posts(['meta_key' => '_f3_event_id', 'meta_value' => $event_id, 'post_status' => 'any', 'numberposts' => 1]);
        if ( $existing ) continue;

        // Fetch full detail to get backblast text
        $detail = f3api_request('/v1/event-instance/id/' . $event_id);
        $backblast = $detail['backblast'] ?? '';
        if ( ! $backblast ) continue;

        $ao_name   = $e['orgName'] ?? $e['name'] ?? 'Unknown AO';
        $date      = $e['startDate'] ?? date('Y-m-d');
        $post_title = $ao_name . ' — ' . date('F j, Y', strtotime($date));

        // Create AO tag
        $tag = get_term_by('name', $ao_name, 'post_tag');
        $tag_id = $tag ? $tag->term_id : wp_create_tag($ao_name)['term_id'] ?? null;

        $post_id = wp_insert_post([
            'post_title'    => $post_title,
            'post_content'  => wp_kses_post($backblast),
            'post_status'   => $status,
            'post_author'   => $author,
            'post_date'     => $date . ' 06:00:00',
            'post_category' => [$cat_id],
            'tags_input'    => [$ao_name],
        ]);

        if ( $post_id && ! is_wp_error($post_id) ) {
            update_post_meta($post_id, '_f3_event_id',  $event_id);
            update_post_meta($post_id, '_f3_ao_name',   $ao_name);
            update_post_meta($post_id, '_f3_event_date',$date);
            $count++;
        }
    }
    return $count;
}

// ── Shortcode ──────────────────────────────────────────────────────────────
add_shortcode( 'f3_backblasts', 'f3bb_shortcode' );

function f3bb_shortcode( $atts ): string {
    if ( ! function_exists('f3api_available') || ! f3api_available() ) {
        return f3api_not_configured_notice();
    }

    $atts = shortcode_atts([
        'count'        => 10,
        'ao'           => '',
        'show_content' => 'false',
        'show_pax'     => 'false',
    ], $atts, 'f3_backblasts');

    $cache_key = 'f3_bb_feed_' . md5(serialize($atts));
    $data      = get_transient($cache_key);

    if ( $data === false ) {
        $path = '/v1/event-instance?regionOrgId=' . f3api_region_id() . '&hasBackblast=true&limit=' . intval($atts['count']) . '&orderBy=startDate&order=desc';
        if ( $atts['ao'] ) $path .= '&orgName=' . urlencode($atts['ao']);
        $data = f3api_request($path);
        set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
    }

    $events  = $data['eventInstances'] ?? [];
    $months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    ob_start();
    ?>
    <div class="f3-backblast-feed">
      <?php if ( empty($events) ): ?>
        <p style="color:#888;font-style:italic">No backblasts found.</p>
      <?php else: foreach ($events as $e):
        $date  = $e['startDate'] ?? '';
        $d     = $date ? new DateTime($date . 'T12:00:00') : null;
        $ao    = $e['orgName'] ?? $e['name'] ?? 'Unknown AO';
        $title = $e['name'] ?? ($ao . ' Backblast');
        ?>
        <div class="f3bb-item">
          <div class="f3bb-header">
            <?php if ($d): ?>
              <div class="f3bb-date">
                <span class="f3bb-day"><?php echo $d->format('j'); ?></span>
                <span class="f3bb-month"><?php echo $months[$d->format('n') - 1]; ?></span>
              </div>
            <?php endif; ?>
            <div class="f3bb-info">
              <div class="f3bb-ao"><?php echo esc_html($ao); ?></div>
              <?php if ($e['startTime'] ?? false):
                $h = intval(substr($e['startTime'], 0, 2));
                $m = substr($e['startTime'], 2, 2);
                $ap = $h >= 12 ? 'PM' : 'AM';
                $h12 = $h > 12 ? $h-12 : ($h===0?12:$h);
              ?>
                <div class="f3bb-time"><?php echo esc_html($h12.':'.$m.' '.$ap); ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($atts['show_content'] === 'true' && !empty($e['backblast'])): ?>
            <div class="f3bb-content"><?php echo wp_kses_post(wp_trim_words($e['backblast'], 40, '…')); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <style>
    .f3-backblast-feed { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif; }
    .f3bb-item { display:flex; flex-direction:column; border-bottom:1px solid #eee; padding:12px 0; }
    .f3bb-item:last-child { border-bottom:none; }
    .f3bb-header { display:flex; gap:14px; align-items:center; }
    .f3bb-date { width:48px; flex-shrink:0; background:#c8102e; color:#fff; text-align:center; padding:8px 0; border-radius:3px; }
    .f3bb-day { display:block; font-size:22px; font-weight:800; line-height:1; }
    .f3bb-month { display:block; font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; opacity:.85; }
    .f3bb-ao { font-size:16px; font-weight:700; color:#111; text-transform:uppercase; }
    .f3bb-time { font-size:12px; color:#888; margin-top:2px; }
    .f3bb-content { font-size:13px; color:#555; margin-top:8px; line-height:1.5; }
    </style>
    <?php
    return ob_get_clean();
}
