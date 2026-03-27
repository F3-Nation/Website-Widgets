<?php
/**
 * Plugin Name: F3 Region Dashboard
 * Description: Adds an F3 Nation stats panel to the WordPress admin dashboard showing open Qs, missing backblasts, upcoming events, and recent activity for your region. Requires F3 Nation API plugin.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * INSTALLATION
 * ============================================================
 * 1. Install and activate the "F3 Nation API" plugin first
 * 2. Upload this folder to /wp-content/plugins/f3-region-dashboard/
 * 3. Activate in WordPress Admin → Plugins
 * 4. Go to WordPress Admin → Dashboard to see your F3 stats panel
 *
 * The dashboard widget refreshes automatically every 30 minutes
 * via WordPress cron. You can also refresh manually by clicking
 * the "Refresh Now" button in the widget.
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_notices', function() {
    if ( ! function_exists('f3api_request') ) {
        echo '<div class="notice notice-error"><p>⚠️ <strong>F3 Region Dashboard</strong> requires the <strong>F3 Nation API</strong> plugin.</p></div>';
    }
});

// ── Register dashboard widget ─────────────────────────────────────────────
add_action( 'wp_dashboard_setup', 'f3rd_register_widget' );

function f3rd_register_widget() {
    wp_add_dashboard_widget(
        'f3_region_dashboard',
        '⚡ F3 Nation — Region Overview',
        'f3rd_render_widget'
    );
}

// ── AJAX refresh ──────────────────────────────────────────────────────────
add_action( 'wp_ajax_f3rd_refresh', function() {
    check_ajax_referer('f3rd_refresh');
    delete_transient('f3_rd_stats');
    wp_send_json_success(f3rd_get_stats());
});

// ── Stats fetcher ─────────────────────────────────────────────────────────
function f3rd_get_stats(): array {
    $cached = get_transient('f3_rd_stats');
    if ( $cached !== false ) return $cached;

    if ( ! function_exists('f3api_request') || ! f3api_region_id() ) {
        return ['error' => 'F3 Nation API not configured'];
    }

    $region_id = f3api_region_id();
    $today     = date('Y-m-d');
    $stats     = [];

    // Upcoming schedule — count open Qs in next 7 days
    $cal = f3api_request('/v1/event-instance/calendar-home-schedule?regionOrgId=' . $region_id . '&userId=1&startDate=' . $today . '&limit=100');
    $events  = $cal['events'] ?? [];
    $week_end = date('Y-m-d', strtotime('+7 days'));
    $this_week = array_filter($events, fn($e) => ($e['startDate'] ?? '') <= $week_end);
    $open_qs   = array_filter($this_week, fn($e) => empty($e['plannedQs']));
    $stats['total_this_week'] = count($this_week);
    $stats['open_qs']         = count($open_qs);
    $stats['open_q_list']     = array_slice(array_values($open_qs), 0, 5);

    // Missing backblasts
    $missing = f3api_request('/v1/event-instance/without-q?regionOrgId=' . $region_id . '&notPostedOnly=true&limit=50');
    $past_missing = array_filter(
        $missing['eventInstances'] ?? [],
        fn($e) => ($e['startDate'] ?? '') < $today
    );
    $stats['missing_backblasts'] = count($past_missing);

    // Next 3 upcoming special events
    $ao_data = f3api_request('/v1/event?regionIds=' . $region_id);
    $ao_ids  = array_column($ao_data['events'] ?? [], 'id');
    $special = array_filter($events, function($e) use ($ao_ids) {
        return ! in_array(intval($e['seriesId'] ?? 0), $ao_ids)
            && ! in_array(intval($e['orgId']    ?? 0), $ao_ids);
    });
    $stats['special_events'] = array_slice(array_values($special), 0, 3);

    $stats['last_updated'] = current_time('g:i a');

    set_transient('f3_rd_stats', $stats, 30 * MINUTE_IN_SECONDS);
    return $stats;
}

// ── Widget render ─────────────────────────────────────────────────────────
function f3rd_render_widget() {
    if ( ! function_exists('f3api_available') || ! f3api_available() ) {
        echo '<p>⚠️ <a href="' . admin_url('admin.php?page=f3-nation') . '">Configure F3 Nation API</a> to see your region stats.</p>';
        return;
    }

    $stats     = f3rd_get_stats();
    $region    = f3api_setting('region_name', 'Your Region');
    $months    = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    if ( isset($stats['error']) ) {
        echo '<p style="color:#c8102e">Error: ' . esc_html($stats['error']) . '</p>';
        return;
    }

    $open_qs    = intval($stats['open_qs']    ?? 0);
    $missing_bb = intval($stats['missing_backblasts'] ?? 0);
    $total_wk   = intval($stats['total_this_week']    ?? 0);
    ?>
    <div id="f3rd-wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif">

      <!-- Stat boxes -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
        <div style="background:#f9f9f9;border:1px solid #ddd;border-top:3px solid #c8102e;padding:10px;text-align:center;border-radius:2px">
          <div style="font-size:28px;font-weight:800;color:#c8102e;line-height:1"><?php echo $total_wk; ?></div>
          <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.08em;margin-top:3px">Workouts<br>This Week</div>
        </div>
        <div style="background:#f9f9f9;border:1px solid #ddd;border-top:3px solid <?php echo $open_qs > 0 ? '#e67e22' : '#27ae60'; ?>;padding:10px;text-align:center;border-radius:2px">
          <div style="font-size:28px;font-weight:800;color:<?php echo $open_qs > 0 ? '#e67e22' : '#27ae60'; ?>;line-height:1"><?php echo $open_qs; ?></div>
          <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.08em;margin-top:3px">Open Qs<br>This Week</div>
        </div>
        <div style="background:#f9f9f9;border:1px solid #ddd;border-top:3px solid <?php echo $missing_bb > 5 ? '#c8102e' : '#888'; ?>;padding:10px;text-align:center;border-radius:2px">
          <div style="font-size:28px;font-weight:800;color:<?php echo $missing_bb > 5 ? '#c8102e' : '#555'; ?>;line-height:1"><?php echo $missing_bb; ?></div>
          <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.08em;margin-top:3px">Missing<br>Backblasts</div>
        </div>
      </div>

      <!-- Open Qs list -->
      <?php if ( ! empty($stats['open_q_list']) ): ?>
        <div style="margin-bottom:14px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#888;margin-bottom:6px">Open Qs Needing Coverage</div>
          <?php foreach ($stats['open_q_list'] as $e):
            $d = new DateTime(($e['startDate'] ?? date('Y-m-d')) . 'T12:00:00');
          ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0">
            <div>
              <strong style="font-size:13px"><?php echo esc_html($e['orgName'] ?? $e['name'] ?? ''); ?></strong>
            </div>
            <div style="font-size:12px;color:#888">
              <?php echo esc_html($months[$d->format('n')-1] . ' ' . $d->format('j')); ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Upcoming special events -->
      <?php if ( ! empty($stats['special_events']) ): ?>
        <div style="margin-bottom:14px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#888;margin-bottom:6px">Upcoming Special Events</div>
          <?php foreach ($stats['special_events'] as $e):
            $d = new DateTime(($e['startDate'] ?? date('Y-m-d')) . 'T12:00:00');
          ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0">
            <div>
              <strong style="font-size:13px"><?php echo esc_html($e['name'] ?? $e['orgName'] ?? ''); ?></strong>
              <?php if (!empty($e['eventTypes'][0]['name'])): ?>
                <span style="font-size:10px;background:#f0f0f0;color:#555;padding:1px 6px;border-radius:10px;margin-left:4px"><?php echo esc_html($e['eventTypes'][0]['name']); ?></span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:#888"><?php echo esc_html($months[$d->format('n')-1] . ' ' . $d->format('j')); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Footer -->
      <div style="display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid #eee">
        <span style="font-size:11px;color:#aaa">Updated <?php echo esc_html($stats['last_updated'] ?? ''); ?></span>
        <button onclick="f3rdRefresh()" style="font-size:11px;padding:4px 10px;background:#f0f0f0;border:1px solid #ddd;cursor:pointer;border-radius:2px">↻ Refresh</button>
      </div>
    </div>

    <script>
    function f3rdRefresh() {
      var btn = document.querySelector('#f3rd-wrap button');
      btn.textContent = 'Refreshing…'; btn.disabled = true;
      fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=f3rd_refresh&_wpnonce=<?php echo wp_create_nonce('f3rd_refresh'); ?>')
        .then(function(r){ return r.json(); })
        .then(function(){ window.location.reload(); })
        .catch(function(){ btn.textContent = '↻ Refresh'; btn.disabled = false; });
    }
    </script>
    <?php
}
