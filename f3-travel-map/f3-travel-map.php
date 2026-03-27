<?php
/**
 * Plugin Name: F3 Travel Map
 * Description: Displays an interactive map showing where your region's PAX have posted when traveling to other F3 regions. Uses Leaflet.js for the map and Supabase for the travel data. Use [f3_travel_map] on any page. Requires F3 Nation API plugin and Supabase database.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * WHAT IS THE TRAVEL MAP?
 * ============================================================
 * When an F3 PAX travels and posts at another region, that
 * appearance gets recorded. The Travel Map shows all the
 * locations around the country (and world) where your region's
 * PAX have posted. It's a visual celebration of the F3 network.
 *
 * INSTALLATION
 * ============================================================
 * 1. Install and activate "F3 Nation API" plugin first
 * 2. Set up Supabase with the travel_locations table (SQL below)
 * 3. Populate it via your analytics pipeline or BigQuery sync
 * 4. Upload to /wp-content/plugins/f3-travel-map/
 * 5. Activate in WordPress Admin → Plugins
 * 6. Go to F3 Nation → Travel Map Settings to configure
 * 7. Add [f3_travel_map] to any page
 *
 * DATABASE SETUP (Supabase SQL)
 * ============================================================
 * CREATE TABLE travel_locations (
 *   id           SERIAL PRIMARY KEY,
 *   region_name  TEXT NOT NULL,
 *   city         TEXT,
 *   state        TEXT,
 *   country      TEXT DEFAULT 'USA',
 *   latitude     DECIMAL(9,6),
 *   longitude    DECIMAL(9,6),
 *   visit_count  INTEGER DEFAULT 1,
 *   pax_handles  JSONB DEFAULT '[]',
 *   last_visited DATE,
 *   updated_at   TIMESTAMPTZ DEFAULT now()
 * );
 *
 * SHORTCODE OPTIONS
 * ============================================================
 * [f3_travel_map]                     — full map + location list
 * [f3_travel_map height="500"]        — map height in px
 * [f3_travel_map show_list="false"]   — map only, no card list
 * [f3_travel_map center_lat="35.7"]   — custom map center lat
 * [f3_travel_map center_lng="-80.8"]  — custom map center lng
 * [f3_travel_map zoom="5"]            — initial zoom level
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Settings ───────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_submenu_page('f3-nation', 'Travel Map', 'Travel Map', 'manage_options', 'f3-travel-map', 'f3tm_settings_page');
});

function f3tm_settings_page() {
    if (isset($_POST['f3tm_save']) && check_admin_referer('f3tm_settings')) {
        update_option('f3tm_supabase_url',  esc_url_raw($_POST['f3tm_supabase_url'] ?? ''));
        update_option('f3tm_supabase_anon', sanitize_text_field($_POST['f3tm_supabase_anon'] ?? ''));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>F3 Travel Map Settings</h1>
      <p>The Travel Map reads from your Supabase <code>travel_locations</code> table. Use the <strong>anon key</strong> (public, read-only).</p>
      <form method="post">
        <?php wp_nonce_field('f3tm_settings'); ?>
        <table class="form-table">
          <tr><th>Supabase URL</th><td><input type="url" name="f3tm_supabase_url" value="<?php echo esc_attr(get_option('f3tm_supabase_url','')); ?>" style="width:420px"></td></tr>
          <tr><th>Supabase Anon Key</th><td><input type="password" name="f3tm_supabase_anon" value="<?php echo esc_attr(get_option('f3tm_supabase_anon','')); ?>" style="width:420px;font-family:monospace"></td></tr>
        </table>
        <?php submit_button('Save Settings', 'primary', 'f3tm_save'); ?>
      </form>
      <hr>
      <h2>Shortcode</h2>
      <p>Add this to any page to show the travel map:</p>
      <code>[f3_travel_map]</code>
      <p>Options:</p>
      <ul style="list-style:disc;margin-left:20px">
        <li><code>[f3_travel_map height="600"]</code> — taller map</li>
        <li><code>[f3_travel_map show_list="false"]</code> — map only</li>
        <li><code>[f3_travel_map zoom="4"]</code> — zoom out more</li>
      </ul>
    </div>
    <?php
}

// ── REST endpoint ──────────────────────────────────────────────────────────
add_action( 'rest_api_init', function() {
    register_rest_route('f3/v1', '/travel-locations', [
        'methods'             => 'GET',
        'callback'            => 'f3tm_rest_get_locations',
        'permission_callback' => '__return_true',
    ]);
});

function f3tm_rest_get_locations(): WP_REST_Response {
    $cached = get_transient('f3tm_locations');
    if ($cached !== false) return rest_ensure_response($cached);

    $url  = trailingslashit(get_option('f3tm_supabase_url')) . 'rest/v1/travel_locations?select=*&order=visit_count.desc';
    $key  = get_option('f3tm_supabase_anon');
    $resp = wp_remote_get($url, ['headers'=>['apikey'=>$key,'Authorization'=>'Bearer '.$key],'timeout'=>10]);
    $data = json_decode(wp_remote_retrieve_body($resp), true) ?? [];

    set_transient('f3tm_locations', $data, 30 * MINUTE_IN_SECONDS);
    return rest_ensure_response($data);
}

// ── Shortcode ──────────────────────────────────────────────────────────────
add_shortcode('f3_travel_map', 'f3tm_shortcode');
function f3tm_shortcode($atts): string {
    $sb_url = get_option('f3tm_supabase_url', '');
    if (!$sb_url) return '<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107">⚠️ Configure Supabase in <a href="' . admin_url('admin.php?page=f3-travel-map') . '">F3 Nation → Travel Map Settings</a>.</div>';

    $atts = shortcode_atts([
        'height'     => '450',
        'show_list'  => 'true',
        'center_lat' => '37.8',
        'center_lng' => '-96.9',
        'zoom'       => '4',
    ], $atts, 'f3_travel_map');

    $proxy   = rest_url('f3/v1');
    $map_id  = 'f3tm_' . uniqid();
    $region  = f3api_setting('region_name', 'F3');

    // Enqueue Leaflet
    wp_enqueue_style( 'leaflet',  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',  [], '1.9.4');
    wp_enqueue_script('leaflet',  'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',   [], '1.9.4', true);

    ob_start();
    ?>
    <div class="f3-travel-map" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif">

      <div style="margin-bottom:12px">
        <h3 style="margin:0 0 4px;font-size:18px;font-weight:700;text-transform:uppercase"><?php echo esc_html($region); ?> Travel Map</h3>
        <p style="margin:0;font-size:13px;color:#888">Everywhere our PAX have posted across the F3 Nation</p>
      </div>

      <div id="<?php echo esc_attr($map_id); ?>" style="height:<?php echo intval($atts['height']); ?>px;width:100%;border:1px solid #ddd;border-radius:3px"></div>

      <div id="<?php echo esc_attr($map_id); ?>_stats" style="display:flex;gap:24px;margin-top:12px;margin-bottom:16px"></div>

      <?php if ($atts['show_list'] === 'true'): ?>
        <div id="<?php echo esc_attr($map_id); ?>_list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px"></div>
      <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
      var mapId    = <?php echo json_encode($map_id); ?>;
      var proxy    = <?php echo json_encode($proxy); ?>;
      var showList = <?php echo $atts['show_list'] === 'true' ? 'true' : 'false'; ?>;
      var lat      = <?php echo floatval($atts['center_lat']); ?>;
      var lng      = <?php echo floatval($atts['center_lng']); ?>;
      var zoom     = <?php echo intval($atts['zoom']); ?>;

      // Init Leaflet map
      var map = L.map(mapId, {scrollWheelZoom: false}).setView([lat, lng], zoom);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
        maxZoom: 18,
      }).addTo(map);

      // F3 red marker icon
      var f3Icon = L.divIcon({
        className: '',
        html: '<div style="width:12px;height:12px;border-radius:50%;background:#c8102e;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
        iconSize: [12, 12],
        iconAnchor: [6, 6],
      });

      fetch(proxy + '/travel-locations').then(function(r){ return r.json(); }).then(function(locations){
        if (!locations.length) { document.getElementById(mapId).innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#888">No travel data yet.</div>'; return; }

        var total_visits = locations.reduce(function(s,l){ return s + (l.visit_count||0); }, 0);
        var unique_pax   = new Set();
        locations.forEach(function(l){ (l.pax_handles||[]).forEach(function(h){ unique_pax.add(h); }); });

        // Stats bar
        document.getElementById(mapId + '_stats').innerHTML =
          '<div style="text-align:center"><div style="font-size:22px;font-weight:800;color:#c8102e">' + locations.length + '</div><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.08em">Regions Visited</div></div>'
          +'<div style="text-align:center"><div style="font-size:22px;font-weight:800;color:#c8102e">' + total_visits + '</div><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.08em">Total Visits</div></div>'
          +'<div style="text-align:center"><div style="font-size:22px;font-weight:800;color:#c8102e">' + unique_pax.size + '</div><div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.08em">Traveling PAX</div></div>';

        // Map markers
        locations.forEach(function(loc) {
          if (!loc.latitude || !loc.longitude) return;
          var handles = (loc.pax_handles || []).join(', ') || 'Unknown PAX';
          var popup = '<strong>' + loc.region_name + '</strong><br>'
            + (loc.city ? loc.city + (loc.state ? ', ' + loc.state : '') + '<br>' : '')
            + loc.visit_count + ' visit' + (loc.visit_count !== 1 ? 's' : '') + '<br>'
            + '<small>' + handles + '</small>';
          L.marker([loc.latitude, loc.longitude], {icon: f3Icon})
            .addTo(map)
            .bindPopup(popup);
        });

        // Location cards
        if (showList) {
          var listEl = document.getElementById(mapId + '_list');
          if (listEl) {
            listEl.innerHTML = locations.slice(0, 24).map(function(loc){
              return '<div style="border:1px solid #ddd;padding:10px 12px;cursor:pointer" onclick="f3tmFlyTo('+loc.latitude+','+loc.longitude+')" onmouseover="this.style.borderColor=\'#c8102e\'" onmouseout="this.style.borderColor=\'#ddd\'">'
                +'<div style="font-size:13px;font-weight:700;text-transform:uppercase;color:#111;margin-bottom:3px">' + loc.region_name + '</div>'
                +'<div style="font-size:11px;color:#888">' + (loc.city||'') + (loc.state ? ', '+loc.state : '') + '</div>'
                +'<div style="font-size:11px;color:#c8102e;font-weight:700;margin-top:3px">' + loc.visit_count + ' visit' + (loc.visit_count!==1?'s':'') + '</div>'
                +'</div>';
            }).join('');
          }
        }

        window.f3tmFlyTo = function(lat, lng) {
          map.flyTo([lat, lng], 10, {animate: true, duration: 1.2});
        };
      }).catch(function(){ console.warn('[F3 Travel Map] Failed to load locations'); });
    });
    </script>
    <?php
    return ob_get_clean();
}
