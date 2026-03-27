<?php
/**
 * Plugin Name: F3 Kotter List
 * Description: Tracks PAX who haven't posted recently (Kotter list) with admin tools for outreach and follow-up. Displays an at-risk PAX dashboard for Site Qs. Requires F3 Nation API plugin and Supabase database.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * WHAT IS THE KOTTER LIST?
 * ============================================================
 * In F3, "Kotter" refers to PAX who haven't posted (worked out)
 * in a while. The term comes from "Welcome Back, Kotter" — the
 * hope is they'll come back. Site Qs use this list to reach out
 * to men who may be struggling or just drifting away.
 *
 * INSTALLATION
 * ============================================================
 * 1. Install and activate "F3 Nation API" plugin first
 * 2. Set up Supabase with the kotter_list table (SQL below)
 * 3. Upload to /wp-content/plugins/f3-kotter-list/
 * 4. Activate in WordPress Admin → Plugins
 * 5. Go to F3 Nation → Kotter Settings to configure
 * 6. Add [f3_kotter] to a private/admin-only page
 *
 * DATABASE SETUP (Supabase SQL)
 * ============================================================
 * CREATE TABLE kotter_list (
 *   pax_id              INTEGER PRIMARY KEY,
 *   handle              TEXT NOT NULL,
 *   last_post_date      DATE,
 *   days_since_post     INTEGER,
 *   home_ao             TEXT,
 *   total_posts         INTEGER DEFAULT 0,
 *   outreach_tagged     BOOLEAN DEFAULT false,
 *   outreach_note       TEXT,
 *   expected_return     DATE,
 *   kotter_status_override TEXT,
 *   updated_at          TIMESTAMPTZ DEFAULT now()
 * );
 *
 * SHORTCODE OPTIONS
 * ============================================================
 * [f3_kotter]                         — full kotter list
 * [f3_kotter threshold="30"]          — PAX missing 30+ days
 * [f3_kotter threshold="60"]          — PAX missing 60+ days
 * [f3_kotter show_admin="false"]      — hide admin controls
 *
 * NOTE: Recommend placing [f3_kotter] on a password-protected
 * page or members-only area. This data is sensitive.
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Settings ───────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_submenu_page('f3-nation', 'Kotter List', 'Kotter List', 'manage_options', 'f3-kotter-list', 'f3kl_settings_page');
});

function f3kl_settings_page() {
    if (isset($_POST['f3kl_save']) && check_admin_referer('f3kl_settings')) {
        update_option('f3kl_supabase_url',     esc_url_raw($_POST['f3kl_supabase_url'] ?? ''));
        update_option('f3kl_supabase_key',     sanitize_text_field($_POST['f3kl_supabase_key'] ?? ''));
        update_option('f3kl_threshold_days',   intval($_POST['f3kl_threshold_days'] ?? 21));
        update_option('f3kl_admin_passcode',   wp_hash_password(sanitize_text_field($_POST['f3kl_admin_passcode'] ?? '')));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>F3 Kotter List Settings</h1>
      <form method="post">
        <?php wp_nonce_field('f3kl_settings'); ?>
        <table class="form-table">
          <tr><th>Supabase URL</th><td><input type="url" name="f3kl_supabase_url" value="<?php echo esc_attr(get_option('f3kl_supabase_url','')); ?>" style="width:420px"></td></tr>
          <tr><th>Supabase Service Key</th><td><input type="password" name="f3kl_supabase_key" value="<?php echo esc_attr(get_option('f3kl_supabase_key','')); ?>" style="width:420px;font-family:monospace"></td></tr>
          <tr>
            <th>Kotter Threshold (days)</th>
            <td>
              <input type="number" name="f3kl_threshold_days" value="<?php echo intval(get_option('f3kl_threshold_days', 21)); ?>" min="7" max="365" style="width:80px">
              <p class="description">PAX who haven't posted in this many days appear on the Kotter list.</p>
            </td>
          </tr>
          <tr><th>Admin Passcode</th><td><input type="password" name="f3kl_admin_passcode" placeholder="Leave blank to keep current" style="width:200px"></td></tr>
        </table>
        <?php submit_button('Save Settings', 'primary', 'f3kl_save'); ?>
      </form>
    </div>
    <?php
}

// ── REST endpoints ─────────────────────────────────────────────────────────
add_action( 'rest_api_init', function() {
    $ns = 'f3/v1';
    register_rest_route($ns, '/kotter', ['methods'=>'GET','callback'=>'f3kl_rest_get','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/kotter/(?P<id>[\d]+)', ['methods'=>'PATCH','callback'=>'f3kl_rest_update','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/kotter/verify-admin', ['methods'=>'POST','callback'=>'f3kl_rest_verify_admin','permission_callback'=>'__return_true']);
});

function f3kl_sb(string $path, string $method = 'GET', array $body = []): array {
    $url  = trailingslashit(get_option('f3kl_supabase_url')) . 'rest/v1' . $path;
    $key  = get_option('f3kl_supabase_key');
    $args = ['method'=>$method,'headers'=>['apikey'=>$key,'Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json','Prefer'=>'return=minimal'],'timeout'=>10];
    if (!empty($body)) $args['body'] = wp_json_encode($body);
    $r = wp_remote_request($url, $args);
    return json_decode(wp_remote_retrieve_body($r), true) ?? [];
}

function f3kl_is_admin(WP_REST_Request $req): bool {
    $token = $req->get_header('X-F3-Admin-Token');
    return $token && (bool) get_transient('f3kl_admin_' . $token);
}

function f3kl_rest_verify_admin(WP_REST_Request $req): WP_REST_Response {
    $passcode = sanitize_text_field($req->get_param('passcode') ?? '');
    $hash = get_option('f3kl_admin_passcode', '');
    if (!$hash || !wp_check_password($passcode, $hash)) return new WP_REST_Response(['error'=>'Invalid passcode'], 401);
    $token = wp_generate_password(48, false);
    set_transient('f3kl_admin_' . $token, 1, HOUR_IN_SECONDS);
    return rest_ensure_response(['token' => $token]);
}

function f3kl_rest_get(WP_REST_Request $req): WP_REST_Response {
    $threshold = intval($req->get_param('threshold') ?? get_option('f3kl_threshold_days', 21));
    $cached    = get_transient('f3kl_list_' . $threshold);
    if ($cached !== false) return rest_ensure_response($cached);
    $data = f3kl_sb('/kotter_list?select=*&days_since_post=gte.' . $threshold . '&order=days_since_post.desc&limit=200');
    set_transient('f3kl_list_' . $threshold, $data, 30 * MINUTE_IN_SECONDS);
    return rest_ensure_response($data);
}

function f3kl_rest_update(WP_REST_Request $req): WP_REST_Response {
    if (!f3kl_is_admin($req)) return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    $pax_id = intval($req->get_param('id'));
    $body   = $req->get_json_params();
    $allowed = ['outreach_tagged','outreach_note','expected_return','kotter_status_override'];
    $update  = array_intersect_key($body, array_flip($allowed));
    $update['updated_at'] = date('c');
    f3kl_sb('/kotter_list?pax_id=eq.' . $pax_id, 'PATCH', $update);
    delete_transient('f3kl_list_' . get_option('f3kl_threshold_days', 21));
    return rest_ensure_response(['updated' => true]);
}

// ── Shortcode ──────────────────────────────────────────────────────────────
add_shortcode('f3_kotter', 'f3kl_shortcode');
function f3kl_shortcode($atts): string {
    $sb_url = get_option('f3kl_supabase_url', '');
    if (!$sb_url) return '<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107">⚠️ Configure Supabase in <a href="' . admin_url('admin.php?page=f3-kotter-list') . '">F3 Nation → Kotter Settings</a>.</div>';
    $atts = shortcode_atts(['threshold' => get_option('f3kl_threshold_days', 21), 'show_admin' => 'true'], $atts, 'f3_kotter');
    $proxy = rest_url('f3/v1');
    ob_start();
    ?>
    <div id="f3-kotter" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif">

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #c8102e">
        <div>
          <h3 style="margin:0;font-size:20px;font-weight:700;text-transform:uppercase">Kotter List</h3>
          <p style="margin:4px 0 0;font-size:13px;color:#888">PAX who haven't posted in <?php echo intval($atts['threshold']); ?>+ days</p>
        </div>
        <?php if ($atts['show_admin'] === 'true'): ?>
        <div id="f3kl-auth">
          <input type="password" id="f3kl-passcode" placeholder="Admin passcode" style="padding:6px 10px;border:1px solid #ddd;font-size:13px">
          <button onclick="f3klLogin()" style="padding:6px 12px;background:#c8102e;color:#fff;border:none;cursor:pointer;font-size:13px">Unlock</button>
        </div>
        <?php endif; ?>
      </div>

      <div id="f3kl-loading" style="text-align:center;padding:32px;color:#888">Loading Kotter list…</div>
      <div id="f3kl-list"></div>
      <div id="f3kl-count" style="font-size:12px;color:#aaa;margin-top:12px;text-align:right"></div>
    </div>

    <style>
    .f3kl-row { display:grid; grid-template-columns:1fr auto auto; gap:12px; align-items:center; padding:12px 0; border-bottom:1px solid #f0f0f0; }
    .f3kl-handle { font-size:15px; font-weight:700; color:#111; text-transform:uppercase; }
    .f3kl-meta { font-size:12px; color:#888; margin-top:3px; }
    .f3kl-days { font-size:20px; font-weight:800; color:#c8102e; text-align:right; }
    .f3kl-days-label { font-size:10px; color:#aaa; text-transform:uppercase; letter-spacing:.08em; text-align:right; }
    .f3kl-outreach { background:#27ae60; color:#fff; font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; padding:2px 8px; border-radius:10px; }
    .f3kl-status { font-size:11px; color:#888; font-style:italic; }
    </style>

    <script>
    (function(){
      var proxy = <?php echo json_encode($proxy); ?>;
      var threshold = <?php echo intval($atts['threshold']); ?>;
      var adminToken = localStorage.getItem('f3kl_admin_token');
      var kotter = [];

      fetch(proxy + '/kotter?threshold=' + threshold).then(r=>r.json()).then(data=>{
        kotter = data;
        document.getElementById('f3kl-loading').style.display='none';
        renderKotter();
      });

      function renderKotter() {
        var el = document.getElementById('f3kl-list');
        var count = document.getElementById('f3kl-count');
        if (!kotter.length) { el.innerHTML='<p style="color:#888">No PAX on the Kotter list. Keep posting!</p>'; return; }
        count.textContent = kotter.length + ' PAX on the Kotter list';
        el.innerHTML = kotter.map(function(p) {
          var statusHtml = p.outreach_tagged ? '<span class="f3kl-outreach">Outreach Done</span>' : '';
          var noteHtml   = p.outreach_note ? '<div class="f3kl-status">' + p.outreach_note + '</div>' : '';
          var returnHtml = p.expected_return ? '<div class="f3kl-status">Expected return: ' + p.expected_return + '</div>' : '';
          var adminHtml  = adminToken
            ? '<div style="display:flex;flex-direction:column;gap:4px">'
            +   '<button onclick="f3klTag('+p.pax_id+','+(!p.outreach_tagged)+')" style="font-size:10px;padding:3px 8px;cursor:pointer;border:1px solid #ddd;background:#f9f9f9">'
            +     (p.outreach_tagged ? '✓ Contacted' : '+ Mark Contacted')
            +   '</button>'
            +   '<button onclick="f3klNote('+p.pax_id+')" style="font-size:10px;padding:3px 8px;cursor:pointer;border:1px solid #ddd;background:#f9f9f9">📝 Note</button>'
            + '</div>'
            : '';
          return '<div class="f3kl-row">'
            + '<div>'
            +   '<div class="f3kl-handle">' + p.handle + '</div>'
            +   '<div class="f3kl-meta">' + (p.home_ao||'') + (p.total_posts?' &middot; '+p.total_posts+' total posts':'') + '</div>'
            +   statusHtml + noteHtml + returnHtml
            + '</div>'
            + '<div><div class="f3kl-days">' + (p.days_since_post||'?') + '</div><div class="f3kl-days-label">days</div></div>'
            + adminHtml
            + '</div>';
        }).join('');
      }

      window.f3klLogin = function() {
        var pw = document.getElementById('f3kl-passcode').value;
        fetch(proxy + '/kotter/verify-admin', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({passcode:pw})})
          .then(r=>r.json()).then(d=>{
            if (d.token) { localStorage.setItem('f3kl_admin_token', d.token); adminToken=d.token; document.getElementById('f3kl-auth').innerHTML='<span style="color:#27ae60;font-size:13px">✓ Admin unlocked</span>'; renderKotter(); }
            else alert('Invalid passcode');
          });
      };

      window.f3klTag = function(id, tagged) {
        fetch(proxy+'/kotter/'+id, {method:'PATCH',headers:{'Content-Type':'application/json','X-F3-Admin-Token':adminToken},body:JSON.stringify({outreach_tagged:tagged})})
          .then(()=>{ var p=kotter.find(k=>k.pax_id===id); if(p) p.outreach_tagged=tagged; renderKotter(); });
      };

      window.f3klNote = function(id) {
        var note = prompt('Outreach note for this PAX:');
        if (note===null) return;
        fetch(proxy+'/kotter/'+id, {method:'PATCH',headers:{'Content-Type':'application/json','X-F3-Admin-Token':adminToken},body:JSON.stringify({outreach_note:note})})
          .then(()=>{ var p=kotter.find(k=>k.pax_id===id); if(p) p.outreach_note=note; renderKotter(); });
      };
    })();
    </script>
    <?php
    return ob_get_clean();
}
