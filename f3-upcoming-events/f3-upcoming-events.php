<?php
/**
 * Plugin Name: F3 Upcoming Events
 * Description: Displays upcoming special events (Convergences, CSAUPs, races) pulled from the F3 Nation API. Filters out regular weekly AOs automatically. Use [f3_events] on any page. Requires F3 Nation API plugin.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * INSTALLATION
 * ============================================================
 * 1. Install and activate the "F3 Nation API" plugin first
 * 2. Upload this folder to /wp-content/plugins/f3-upcoming-events/
 * 3. Activate in WordPress Admin → Plugins
 * 4. Add [f3_events] to any page or post
 *
 * SHORTCODE OPTIONS
 * ============================================================
 * [f3_events]                         — default (60 days ahead)
 * [f3_events days="30"]               — look 30 days ahead
 * [f3_events title="Special Events"]  — custom section title
 * [f3_events show_q="true"]           — show planned Q
 * [f3_events empty_message="Nothing coming up yet!"]
 *
 * HOW IT WORKS
 * ============================================================
 * This plugin fetches your region's full event calendar from
 * F3 Nation, then filters out any event whose seriesId or
 * orgId matches a regular AO in your region. What remains
 * are the special events — convergences, CSAUPs, races, etc.
 *
 * Results are cached for 30 minutes. A WordPress cron job
 * refreshes the list daily.
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_notices', function() {
    if ( ! function_exists('f3api_request') ) {
        echo '<div class="notice notice-error"><p>⚠️ <strong>F3 Upcoming Events</strong> requires the <strong>F3 Nation API</strong> plugin.</p></div>';
    }
});

// ── Cron: daily cache refresh ──────────────────────────────────────────────
add_action( 'init', function() {
    if ( ! wp_next_scheduled('f3_refresh_upcoming_events') ) {
        wp_schedule_event( time(), 'daily', 'f3_refresh_upcoming_events' );
    }
});
add_action( 'f3_refresh_upcoming_events', 'f3ue_refresh_cache' );

function f3ue_refresh_cache() {
    delete_transient('f3_ue_ao_ids');
    delete_transient('f3_ue_events');
    f3ue_get_events(); // rebuild
}

// ── Core: fetch and filter events ─────────────────────────────────────────

function f3ue_get_ao_ids(): array {
    $cached = get_transient('f3_ue_ao_ids');
    if ( $cached !== false ) return $cached;

    $data = f3api_request('/v1/event?regionIds=' . f3api_region_id());
    $ids  = array_column($data['events'] ?? [], 'id');
    set_transient('f3_ue_ao_ids', $ids, 6 * HOUR_IN_SECONDS);
    return $ids;
}

function f3ue_get_events(): array {
    $cached = get_transient('f3_ue_events');
    if ( $cached !== false ) return $cached;

    $ao_ids  = f3ue_get_ao_ids();
    $today   = date('Y-m-d');
    $data    = f3api_request('/v1/event-instance/calendar-home-schedule?regionOrgId=' . f3api_region_id() . '&userId=1&startDate=' . $today . '&limit=200');
    $events  = $data['events'] ?? [];

    // Filter to non-standard events only
    $special = array_values(array_filter($events, function($e) use ($ao_ids) {
        $series_id = intval($e['seriesId'] ?? 0);
        $org_id    = intval($e['orgId']    ?? 0);
        return ! in_array($series_id, $ao_ids) && ! in_array($org_id, $ao_ids);
    }));

    set_transient('f3_ue_events', $special, 30 * MINUTE_IN_SECONDS);
    return $special;
}

// ── Shortcode ──────────────────────────────────────────────────────────────
add_shortcode( 'f3_events', 'f3ue_shortcode' );

function f3ue_shortcode( $atts ): string {
    if ( ! function_exists('f3api_available') || ! f3api_available() ) {
        return f3api_not_configured_notice();
    }

    $atts = shortcode_atts([
        'days'          => 60,
        'title'         => 'Upcoming Events',
        'show_q'        => 'true',
        'empty_message' => 'No special events scheduled. Check back soon!',
    ], $atts, 'f3_events');

    $events  = f3ue_get_events();
    $today   = date('Y-m-d');
    $end     = date('Y-m-d', strtotime('+' . intval($atts['days']) . ' days'));
    $events  = array_filter($events, fn($e) => ($e['startDate'] ?? '') >= $today && ($e['startDate'] ?? '') <= $end);
    $events  = array_values($events);

    $days_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $months     = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    ob_start();
    ?>
    <div class="f3-upcoming-events">
      <h3 class="f3ue-title"><?php echo esc_html($atts['title']); ?></h3>

      <?php if ( empty($events) ): ?>
        <p class="f3ue-empty"><?php echo esc_html($atts['empty_message']); ?></p>
      <?php else: ?>
        <div class="f3ue-list">
          <?php foreach ($events as $e):
            $d       = new DateTime($e['startDate'] . 'T12:00:00');
            $isToday = $e['startDate'] === $today;
            $dayName = $isToday ? 'Today' : $days_names[$d->format('w')];
            $dateStr = $months[$d->format('n') - 1] . ' ' . $d->format('j') . ', ' . $d->format('Y');
            $raw     = $e['startTime'] ?? '0530';
            $h       = intval(substr($raw, 0, 2));
            $m       = substr($raw, 2, 2);
            $ap      = $h >= 12 ? 'PM' : 'AM';
            $h12     = $h > 12 ? $h - 12 : ($h === 0 ? 12 : $h);
            $types   = $e['eventTypes'] ?? [];
            $type    = $types[0]['name'] ?? 'Event';
          ?>
          <div class="f3ue-card <?php echo $isToday ? 'f3ue-today' : ''; ?>">
            <div class="f3ue-date-block">
              <div class="f3ue-day"><?php echo esc_html($d->format('j')); ?></div>
              <div class="f3ue-month"><?php echo esc_html($months[$d->format('n') - 1]); ?></div>
            </div>
            <div class="f3ue-body">
              <div class="f3ue-name"><?php echo esc_html($e['name'] ?? $e['orgName'] ?? 'Event'); ?></div>
              <div class="f3ue-meta">
                <span><?php echo esc_html($dayName . ' · ' . $dateStr); ?></span>
                <?php if ($e['startTime']): ?>
                  <span><?php echo esc_html($h12 . ':' . $m . ' ' . $ap); ?></span>
                <?php endif; ?>
                <span class="f3ue-type"><?php echo esc_html($type); ?></span>
                <?php if ($atts['show_q'] === 'true' && !empty($e['plannedQs'])): ?>
                  <span>Q: <strong><?php echo esc_html($e['plannedQs']); ?></strong></span>
                <?php endif; ?>
                <?php if ($e['hasPreblast'] ?? false): ?>
                  <span class="f3ue-preblast">Preblast ✓</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <style>
    .f3-upcoming-events { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif; }
    .f3ue-title { font-size:20px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; border-bottom:3px solid #c8102e; padding-bottom:8px; margin-bottom:16px; }
    .f3ue-list { display:flex; flex-direction:column; gap:8px; }
    .f3ue-card { display:flex; gap:0; border:1px solid #ddd; overflow:hidden; transition:border-color .15s; }
    .f3ue-card:hover { border-color:#c8102e; }
    .f3ue-date-block { width:64px; flex-shrink:0; background:#c8102e; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:12px 0; }
    .f3ue-today .f3ue-date-block { background:#111; }
    .f3ue-day { font-size:26px; font-weight:800; line-height:1; }
    .f3ue-month { font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; opacity:.85; }
    .f3ue-body { padding:12px 16px; flex:1; }
    .f3ue-name { font-size:17px; font-weight:700; color:#111; text-transform:uppercase; line-height:1.1; margin-bottom:6px; }
    .f3ue-meta { display:flex; flex-wrap:wrap; gap:8px; font-size:13px; color:#666; }
    .f3ue-type { background:#f0f0f0; color:#444; font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; padding:2px 8px; border-radius:12px; }
    .f3ue-preblast { background:#dcf5e6; color:#1a6b35; font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; padding:2px 8px; border-radius:12px; }
    .f3ue-empty { color:#888; font-style:italic; }
    @media (max-width:480px) { .f3ue-meta { flex-direction:column; gap:4px; } }
    </style>
    <?php
    return ob_get_clean();
}
