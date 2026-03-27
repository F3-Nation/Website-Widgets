<?php
/**
 * Plugin Name: F3 Schedule Widget
 * Description: Displays your region's upcoming workout schedule pulled live from the F3 Nation API. Use the shortcode [f3_schedule] on any page or widget area. Requires the F3 Nation API plugin.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * INSTALLATION
 * ============================================================
 * 1. Install and activate the "F3 Nation API" plugin first
 * 2. Upload this folder to /wp-content/plugins/f3-schedule-widget/
 * 3. Activate "F3 Schedule Widget" in WordPress Admin → Plugins
 * 4. Add [f3_schedule] to any page, post, or widget
 *
 * SHORTCODE OPTIONS
 * ============================================================
 * [f3_schedule]                        — default (14 days)
 * [f3_schedule days="7"]               — show 1 week
 * [f3_schedule days="30"]              — show 1 month
 * [f3_schedule title="Our Workouts"]   — custom header title
 * [f3_schedule show_q="false"]         — hide Q column
 * [f3_schedule max_width="600"]        — widget width in px
 *
 * WIDGET AREA
 * ============================================================
 * Go to Appearance → Widgets and add the "F3 Schedule" widget
 * to any sidebar or widget area.
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Dependency check ──────────────────────────────────────────────────────
add_action( 'admin_notices', function() {
    if ( ! function_exists('f3api_request') ) {
        echo '<div class="notice notice-error"><p>'
           . '⚠️ <strong>F3 Schedule Widget</strong> requires the <strong>F3 Nation API</strong> plugin to be installed and activated.'
           . '</p></div>';
    }
});

// ── REST endpoint (proxy) ─────────────────────────────────────────────────
add_action( 'rest_api_init', function() {
    register_rest_route( 'f3/v1', '/region-schedule', [
        'methods'             => 'GET',
        'callback'            => 'f3sw_get_schedule',
        'permission_callback' => '__return_true',
    ]);
});

function f3sw_get_schedule( WP_REST_Request $req ): WP_REST_Response {
    if ( ! function_exists('f3api_request') ) {
        return new WP_REST_Response(['error' => 'F3 Nation API plugin not active'], 503);
    }

    $region_id = intval( $req->get_param('regionOrgId') ) ?: f3api_region_id();
    if ( ! $region_id ) {
        return new WP_REST_Response(['error' => 'regionOrgId required'], 400);
    }

    // Rate limit: 60 requests/hour per region
    $rate_key = 'f3_sw_rate_' . $region_id;
    $rate     = (int) get_transient($rate_key);
    if ( $rate > 60 ) return new WP_REST_Response(['error' => 'Rate limit exceeded'], 429);
    set_transient($rate_key, $rate + 1, HOUR_IN_SECONDS);

    $data = f3api_cached_request(
        'f3_sw_schedule_' . $region_id,
        '/v1/event-instance/calendar-home-schedule?regionOrgId=' . $region_id . '&userId=1&startDate=' . date('Y-m-d') . '&limit=150',
        30
    );

    return rest_ensure_response($data);
}

// ── Shortcode ─────────────────────────────────────────────────────────────
add_shortcode( 'f3_schedule', 'f3sw_shortcode' );

function f3sw_shortcode( $atts ): string {
    if ( ! function_exists('f3api_available') || ! f3api_available() ) {
        return f3api_not_configured_notice();
    }

    $atts = shortcode_atts([
        'days'      => 14,
        'title'     => f3api_setting('region_name', 'F3 Nation'),
        'show_q'    => 'true',
        'max_width' => '480',
    ], $atts, 'f3_schedule');

    $proxy_url = rest_url('f3/v1');
    $region_id = f3api_region_id();
    $uid       = 'f3sw_' . uniqid();

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="f3-schedule-widget" style="max-width:<?php echo intval($atts['max_width']); ?>px;width:100%">
      <div class="f3sw-loading">Loading schedule…</div>
    </div>

    <style>
    .f3-schedule-widget { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif; border:1px solid #ddd; background:#fff; color:#111; }
    .f3sw-header { background:#111; color:#fff; padding:14px 18px; display:flex; align-items:center; gap:14px; border-bottom:3px solid #c8102e; }
    .f3sw-logo { width:36px; height:36px; background:#c8102e; color:#fff; font-weight:900; font-size:14px; display:flex; align-items:center; justify-content:center; border-radius:3px; flex-shrink:0; }
    .f3sw-title { font-size:16px; font-weight:700; color:#fff; line-height:1; }
    .f3sw-tag { font-size:10px; color:#aaa; letter-spacing:.12em; text-transform:uppercase; margin-top:3px; }
    .f3sw-day-hdr { background:#f7f7f7; padding:6px 16px; font-size:11px; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:#555; border-bottom:1px solid #eee; border-top:1px solid #eee; }
    .f3sw-today .f3sw-day-hdr { background:#c8102e; color:#fff; border-color:#a50d24; }
    .f3sw-row { padding:11px 16px; border-bottom:1px solid #f0f0f0; display:grid; grid-template-columns:52px 1fr auto; gap:12px; align-items:center; }
    .f3sw-row:hover { background:#fafafa; }
    .f3sw-time-val { font-size:17px; font-weight:800; color:#c8102e; text-align:right; line-height:1; }
    .f3sw-time-ap { font-size:9px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:#aaa; text-align:right; }
    .f3sw-name { font-size:15px; font-weight:700; color:#111; text-transform:uppercase; line-height:1.1; }
    .f3sw-pills { display:flex; gap:5px; margin-top:4px; flex-wrap:wrap; }
    .f3sw-pill { font-size:9px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; padding:2px 8px; border-radius:20px; }
    .f3sw-pill-bc { background:#f0f0f0; color:#555; }
    .f3sw-pill-run { background:#dcf5e6; color:#1a6b35; }
    .f3sw-pill-ruck { background:#fef3dc; color:#7a5010; }
    .f3sw-pill-bike { background:#dceeff; color:#1a4a8a; }
    .f3sw-q-lbl { font-size:9px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:#aaa; text-align:right; }
    .f3sw-q-name { font-size:13px; font-weight:700; color:#111; text-align:right; }
    .f3sw-q-open { font-size:10px; font-weight:700; color:#c8102e; background:#fff0f2; border:1px solid #c8102e; border-radius:3px; padding:3px 7px; }
    .f3sw-footer { background:#f7f7f7; border-top:1px solid #eee; padding:9px 16px; display:flex; justify-content:space-between; }
    .f3sw-footer a { font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#888; text-decoration:none; }
    .f3sw-loading, .f3sw-error { padding:32px 16px; text-align:center; color:#aaa; font-size:13px; }
    </style>

    <script>
    (function() {
      var uid       = <?php echo json_encode($uid); ?>;
      var proxyUrl  = <?php echo json_encode($proxy_url); ?>;
      var regionId  = <?php echo intval($region_id); ?>;
      var daysAhead = <?php echo intval($atts['days']); ?>;
      var title     = <?php echo json_encode(esc_js($atts['title'])); ?>;
      var showQ     = <?php echo $atts['show_q'] === 'false' ? 'false' : 'true'; ?>;
      var wrap      = document.getElementById(uid);

      var today = new Date();
      var tStr  = today.toISOString().split('T')[0];
      var eDate = new Date(today); eDate.setDate(eDate.getDate() + daysAhead);
      var eStr  = eDate.toISOString().split('T')[0];

      fetch(proxyUrl + '/region-schedule?regionOrgId=' + regionId)
        .then(function(r){ return r.json(); })
        .then(function(data){
          var events = (data.events || []).filter(function(e){
            return e.startDate >= tStr && e.startDate <= eStr && e.startTime;
          });
          if (!events.length) { wrap.innerHTML = '<div class="f3sw-error">No workouts scheduled in the next ' + daysAhead + ' days.</div>'; return; }

          var byDate = {};
          events.forEach(function(e){ if(!byDate[e.startDate]) byDate[e.startDate]=[]; byDate[e.startDate].push(e); });

          var days  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
          var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          var html  = '<div class="f3sw-header"><div class="f3sw-logo">F3</div><div><div class="f3sw-title">' + title + '</div><div class="f3sw-tag">Fitness &middot; Fellowship &middot; Faith</div></div></div>';

          Object.keys(byDate).sort().forEach(function(ds){
            var d = new Date(ds + 'T12:00:00');
            var isTdy = ds === tStr;
            html += '<div class="' + (isTdy ? 'f3sw-today' : '') + '">';
            html += '<div class="f3sw-day-hdr">' + (isTdy ? 'Today' : days[d.getDay()]) + ' &nbsp; ' + months[d.getMonth()] + ' ' + d.getDate() + '</div>';
            byDate[ds].sort(function(a,b){return(a.startTime||'').localeCompare(b.startTime||'');}).forEach(function(e){
              var h=parseInt((e.startTime||'0530').slice(0,2)), m=(e.startTime||'0530').slice(2);
              var ap=h>=12?'PM':'AM', h12=h>12?h-12:(h===0?12:h);
              var types=e.eventTypes||[], tn=types.length?types[0].name.toLowerCase():'bc';
              var tc=tn.includes('run')?'run':tn.includes('ruck')?'ruck':(tn.includes('bike')||tn.includes('cycl'))?'bike':'bc';
              var pills=types.map(function(t){return '<span class="f3sw-pill f3sw-pill-'+tc+'">'+t.name+'</span>';}).join('');
              var qHtml=!showQ?'':e.plannedQs?'<div class="f3sw-q-lbl">Q</div><div class="f3sw-q-name">'+e.plannedQs+'</div>':'<div class="f3sw-q-open">Q Open</div>';
              html+='<div class="f3sw-row"><div><div class="f3sw-time-val">'+h12+':'+m+'</div><div class="f3sw-time-ap">'+ap+'</div></div><div><div class="f3sw-name">'+(e.orgName||e.name)+'</div><div class="f3sw-pills">'+pills+'</div></div><div>'+qHtml+'</div></div>';
            });
            html += '</div>';
          });

          html += '<div class="f3sw-footer"><a href="https://f3nation.com" target="_blank">F3Nation.com</a><a href="<?php echo esc_js(f3api_setting("region_url","#")); ?>" target="_blank">Our Site &rarr;</a></div>';
          wrap.innerHTML = html;
        })
        .catch(function(){ wrap.innerHTML = '<div class="f3sw-error">Could not load schedule.</div>'; });
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ── WordPress widget ───────────────────────────────────────────────────────
add_action( 'widgets_init', function() {
    register_widget('F3_Schedule_WP_Widget');
});

class F3_Schedule_WP_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('f3_schedule_widget', 'F3 Schedule', ['description' => 'Live F3 workout schedule from F3 Nation API']);
    }
    public function widget( $args, $instance ) {
        echo $args['before_widget'];
        echo do_shortcode('[f3_schedule days="' . intval($instance['days'] ?? 14) . '"]');
        echo $args['after_widget'];
    }
    public function form( $instance ) {
        $days = intval($instance['days'] ?? 14);
        echo '<p><label>Days ahead: <input class="tiny-text" type="number" name="' . $this->get_field_name('days') . '" value="' . $days . '" min="1" max="60"></label></p>';
    }
    public function update( $new, $old ) {
        return ['days' => intval($new['days'] ?? 14)];
    }
}
