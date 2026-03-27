<?php
/**
 * Plugin Name: F3 Cold Cases
 * Description: Lets PAX submit backblasts for workouts that happened but were never recorded. Submissions go into a pending queue for admin review before being published to F3 Nation. Requires F3 Nation API plugin and a Supabase database.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * INSTALLATION
 * ============================================================
 * 1. Install and activate the "F3 Nation API" plugin first
 * 2. Create a Supabase project at supabase.com (free tier works)
 * 3. Run the SQL setup below in your Supabase SQL editor
 * 4. Upload this folder to /wp-content/plugins/f3-cold-cases/
 * 5. Activate in WordPress Admin → Plugins
 * 6. Go to F3 Nation → Cold Cases Settings and enter your
 *    Supabase URL and service key
 * 7. Add [f3_cold_cases] to any page
 *
 * SUPABASE SQL SETUP (run once in Supabase SQL editor)
 * ============================================================
 * CREATE TABLE pending_backblasts (
 *   id UUID DEFAULT gen_random_uuid() PRIMARY KEY,
 *   event_instance_id INTEGER NOT NULL,
 *   event_date DATE,
 *   ao_name TEXT,
 *   backblast_text TEXT,
 *   pax_list JSONB,
 *   photo_url TEXT,
 *   submitted_by TEXT,
 *   submitted_at TIMESTAMPTZ DEFAULT now(),
 *   status TEXT DEFAULT 'pending'
 * );
 *
 * CREATE TABLE dismissed_cases (
 *   event_instance_id INTEGER PRIMARY KEY,
 *   dismissed_by TEXT,
 *   reason TEXT,
 *   dismissed_at TIMESTAMPTZ DEFAULT now()
 * );
 *
 * SHORTCODE OPTIONS
 * ============================================================
 * [f3_cold_cases]                     — default display
 * [f3_cold_cases limit="20"]          — show 20 cases max
 * [f3_cold_cases show_suspects="true"] — show likely Q suspects
 *
 * ADMIN FEATURES
 * ============================================================
 * Admins see a "Pending Review" panel with approve/reject buttons.
 * Approving a submission posts it to F3 Nation (requires write token).
 * Dismissing removes the case from the list permanently.
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_notices', function() {
    if ( ! function_exists('f3api_request') ) {
        echo '<div class="notice notice-error"><p>⚠️ <strong>F3 Cold Cases</strong> requires the <strong>F3 Nation API</strong> plugin.</p></div>';
    }
});

// ── Settings ───────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_submenu_page('f3-nation', 'Cold Cases', 'Cold Cases', 'manage_options', 'f3-cold-cases', 'f3cc_settings_page');
});

function f3cc_settings_page() {
    if ( isset($_POST['f3cc_save']) && check_admin_referer('f3cc_settings') ) {
        update_option('f3cc_supabase_url', esc_url_raw($_POST['f3cc_supabase_url'] ?? ''));
        update_option('f3cc_supabase_key', sanitize_text_field($_POST['f3cc_supabase_key'] ?? ''));
        update_option('f3cc_admin_passcode', wp_hash_password(sanitize_text_field($_POST['f3cc_admin_passcode'] ?? '')));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    $sb_url = get_option('f3cc_supabase_url', '');
    $sb_key = get_option('f3cc_supabase_key', '');
    ?>
    <div class="wrap">
      <h1>F3 Cold Cases Settings</h1>
      <p>Cold Cases requires a Supabase database to store pending submissions. Create a free account at <a href="https://supabase.com" target="_blank">supabase.com</a>.</p>
      <form method="post">
        <?php wp_nonce_field('f3cc_settings'); ?>
        <table class="form-table">
          <tr>
            <th>Supabase Project URL</th>
            <td>
              <input type="url" name="f3cc_supabase_url" value="<?php echo esc_attr($sb_url); ?>" style="width:420px" placeholder="https://xxxx.supabase.co">
              <p class="description">Found in your Supabase project under Settings → API</p>
            </td>
          </tr>
          <tr>
            <th>Supabase Service Key</th>
            <td>
              <input type="password" name="f3cc_supabase_key" value="<?php echo esc_attr($sb_key); ?>" style="width:420px;font-family:monospace">
              <p class="description">The "service_role" key from Supabase Settings → API. Keep this private.</p>
            </td>
          </tr>
          <tr>
            <th>Admin Passcode</th>
            <td>
              <input type="password" name="f3cc_admin_passcode" style="width:200px" placeholder="Leave blank to keep current">
              <p class="description">Passcode used to access admin features on the front-end Cold Cases page.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save Settings', 'primary', 'f3cc_save'); ?>
      </form>
    </div>
    <?php
}

// ── REST endpoints ─────────────────────────────────────────────────────────
add_action( 'rest_api_init', function() {
    $ns = 'f3/v1';
    register_rest_route($ns, '/cold-cases', ['methods'=>'GET','callback'=>'f3cc_rest_get_cases','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/cold-cases/submit', ['methods'=>'POST','callback'=>'f3cc_rest_submit','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/cold-cases/pending', ['methods'=>'GET','callback'=>'f3cc_rest_pending','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/cold-cases/approve/(?P<id>[a-f0-9\-]+)', ['methods'=>'POST','callback'=>'f3cc_rest_approve','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/cold-cases/dismiss/(?P<id>[\d]+)', ['methods'=>'POST','callback'=>'f3cc_rest_dismiss','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/cold-cases/verify-admin', ['methods'=>'POST','callback'=>'f3cc_rest_verify_admin','permission_callback'=>'__return_true']);
});

function f3cc_sb_request(string $path, string $method = 'GET', array $body = []): array {
    $url = trailingslashit(get_option('f3cc_supabase_url')) . 'rest/v1' . $path;
    $key = get_option('f3cc_supabase_key');
    $args = ['method'=>$method,'headers'=>['apikey'=>$key,'Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],'timeout'=>10];
    if (!empty($body)) $args['body'] = wp_json_encode($body);
    $r = wp_remote_request($url, $args);
    return json_decode(wp_remote_retrieve_body($r), true) ?? [];
}

function f3cc_is_admin(WP_REST_Request $req): bool {
    $token = $req->get_header('X-F3-Admin-Token');
    if (!$token) return false;
    return (bool) get_transient('f3cc_admin_' . $token);
}

function f3cc_rest_verify_admin(WP_REST_Request $req): WP_REST_Response {
    $passcode = sanitize_text_field($req->get_param('passcode') ?? '');
    $hash     = get_option('f3cc_admin_passcode', '');
    if (!$hash || !wp_check_password($passcode, $hash)) {
        return new WP_REST_Response(['error'=>'Invalid passcode'], 401);
    }
    $token = wp_generate_password(48, false);
    set_transient('f3cc_admin_' . $token, 1, HOUR_IN_SECONDS);
    return rest_ensure_response(['token' => $token]);
}

function f3cc_rest_get_cases(WP_REST_Request $req): WP_REST_Response {
    $cached = get_transient('f3_cc_cases');
    if ($cached !== false) return rest_ensure_response($cached);

    $data   = f3api_request('/v1/event-instance/without-q?regionOrgId=' . f3api_region_id() . '&notPostedOnly=true&limit=50');
    $today  = date('Y-m-d');
    $past   = array_values(array_filter($data['eventInstances'] ?? [], fn($e) => ($e['startDate'] ?? '') < $today));

    // Filter out dismissed cases
    $dismissed = f3cc_sb_request('/dismissed_cases?select=event_instance_id');
    $dismissed_ids = array_column($dismissed, 'event_instance_id');
    $filtered = array_values(array_filter($past, fn($e) => !in_array($e['id'], $dismissed_ids)));

    set_transient('f3_cc_cases', $filtered, 30 * MINUTE_IN_SECONDS);
    return rest_ensure_response($filtered);
}

function f3cc_rest_submit(WP_REST_Request $req): WP_REST_Response {
    $body = $req->get_json_params();
    if (empty($body['event_instance_id']) || empty($body['backblast_text']) || empty($body['pax_list'])) {
        return new WP_REST_Response(['error'=>'event_instance_id, backblast_text, and pax_list required'], 400);
    }
    $result = f3cc_sb_request('/pending_backblasts', 'POST', [
        'event_instance_id' => intval($body['event_instance_id']),
        'event_date'        => sanitize_text_field($body['event_date'] ?? ''),
        'ao_name'           => sanitize_text_field($body['ao_name'] ?? ''),
        'backblast_text'    => sanitize_textarea_field($body['backblast_text']),
        'pax_list'          => $body['pax_list'],
        'photo_url'         => sanitize_url($body['photo_url'] ?? ''),
        'submitted_by'      => sanitize_text_field($body['submitted_by'] ?? 'Anonymous'),
        'status'            => 'pending',
    ]);
    return rest_ensure_response($result);
}

function f3cc_rest_pending(WP_REST_Request $req): WP_REST_Response {
    if (!f3cc_is_admin($req)) return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    $data = f3cc_sb_request('/pending_backblasts?select=*&status=eq.pending&order=submitted_at.desc');
    return rest_ensure_response($data);
}

function f3cc_rest_approve(WP_REST_Request $req): WP_REST_Response {
    if (!f3cc_is_admin($req)) return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    $id   = $req->get_param('id');
    $rows = f3cc_sb_request('/pending_backblasts?id=eq.' . $id . '&select=*');
    if (empty($rows)) return new WP_REST_Response(['error'=>'Not found'], 404);
    $bb = $rows[0];
    $errors = [];
    $type_map = ['PAX'=>1,'Q'=>2,'CoQ'=>3,'FNG'=>4];
    $pax_list = $bb['pax_list'];
    $fng  = count(array_filter($pax_list, fn($p) => ($p['role']??'') === 'FNG'));
    $pax  = count($pax_list);
    $nr = f3api_request('/v1/event-instance', 'POST', ['id'=>intval($bb['event_instance_id']),'backblast'=>$bb['backblast_text'],'paxCount'=>$pax,'fngCount'=>$fng]);
    if (isset($nr['error'])) $errors[] = 'Event update failed';
    foreach ($pax_list as $p) {
        if (!intval($p['pax_id'] ?? 0)) continue;
        $ar = f3api_request('/v1/attendance/actual', 'POST', ['eventInstanceId'=>intval($bb['event_instance_id']),'userId'=>intval($p['pax_id']),'attendanceTypeIds'=>[$type_map[$p['role']??'PAX']??1]]);
        if (isset($ar['error'])) $errors[] = 'Attendance failed: ' . ($p['handle'] ?? $p['pax_id']);
    }
    f3cc_sb_request('/pending_backblasts?id=eq.'.$id, 'PATCH', ['status'=>'approved','approved_at'=>date('c')]);
    delete_transient('f3_cc_cases');
    return rest_ensure_response(['approved'=>true,'errors'=>$errors]);
}

function f3cc_rest_dismiss(WP_REST_Request $req): WP_REST_Response {
    if (!f3cc_is_admin($req)) return new WP_REST_Response(['error'=>'Unauthorized'], 401);
    $event_id = intval($req->get_param('id'));
    $reason   = sanitize_text_field($req->get_json_params()['reason'] ?? 'Dismissed');
    f3cc_sb_request('/dismissed_cases', 'POST', ['event_instance_id'=>$event_id,'dismissed_by'=>'admin','reason'=>$reason,'dismissed_at'=>date('c')]);
    delete_transient('f3_cc_cases');
    return rest_ensure_response(['dismissed'=>true]);
}

// ── Shortcode ──────────────────────────────────────────────────────────────
add_shortcode('f3_cold_cases', 'f3cc_shortcode');
function f3cc_shortcode($atts): string {
    if (!function_exists('f3api_available') || !f3api_available()) return f3api_not_configured_notice();
    if (!get_option('f3cc_supabase_url')) return '<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107">⚠️ Cold Cases requires Supabase configuration. Go to <a href="' . admin_url('admin.php?page=f3-cold-cases') . '">F3 Nation → Cold Cases Settings</a>.</div>';
    $atts = shortcode_atts(['limit'=>50], $atts, 'f3_cold_cases');
    $proxy = rest_url('f3/v1');
    ob_start();
    ?>
    <div id="f3-cold-cases">
      <div id="f3cc-loading" style="text-align:center;padding:40px;color:#888;font-family:sans-serif">🔍 Investigating…</div>
      <div id="f3cc-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px"></div>
      <div id="f3cc-pending" style="display:none;margin-top:32px"></div>
    </div>
    <script>
    (function(){
      var proxy = <?php echo json_encode($proxy); ?>;
      var limit = <?php echo intval($atts['limit']); ?>;
      var adminToken = localStorage.getItem('f3cc_admin_token');
      var cases = [];

      fetch(proxy + '/cold-cases').then(r=>r.json()).then(data=>{
        cases = data.slice(0, limit);
        document.getElementById('f3cc-loading').style.display='none';
        renderCases();
        if (adminToken) loadPending();
      }).catch(()=>{ document.getElementById('f3cc-loading').textContent='Failed to load cases.'; });

      function renderCases() {
        var grid = document.getElementById('f3cc-grid');
        var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var mons = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        grid.innerHTML = cases.map((c,i)=>{
          var d = new Date(c.startDate+'T12:00:00');
          return '<div style="border:1px solid #ddd;cursor:pointer;font-family:sans-serif" onclick="openColdCase('+i+')" onmouseover="this.style.borderColor=\'#c8102e\'" onmouseout="this.style.borderColor=\'#ddd\'">'
            +'<div style="background:#111;color:#fff;padding:8px 12px;display:flex;justify-content:space-between;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase">'
            +'<span style="color:#c8102e">Case #'+String(i+1).padStart(3,'0')+'</span>'
            +(adminToken?'<button onclick="event.stopPropagation();dismissCase('+c.id+',this)" style="background:none;border:1px solid #555;color:#aaa;font-size:9px;padding:1px 6px;cursor:pointer">✕ Dismiss</button>':'<span>Unsolved</span>')
            +'</div>'
            +'<div style="padding:12px">'
            +'<div style="font-size:17px;font-weight:700;text-transform:uppercase;color:#111;margin-bottom:4px">'+(c.orgName||c.name)+'</div>'
            +'<div style="font-size:12px;color:#888;margin-bottom:10px">'+days[d.getDay()]+' &middot; '+mons[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear()+'</div>'
            +'<div style="background:#c8102e;color:#fff;text-align:center;padding:6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">🔍 Crack This Case</div>'
            +'</div></div>';
        }).join('');
      }

      window.openColdCase = function(idx) {
        var c = cases[idx];
        if (!c) return;
        var handle = prompt('Who was there? Enter F3 handles separated by commas:\n(Mark the Q with (Q) e.g. "Deflated(Q), Cowbell, Titan")');
        if (!handle) return;
        var text = prompt('What went down? Paste or type the backblast:');
        if (!text) return;
        var submitter = prompt('Your F3 handle:') || 'Anonymous';
        var pax_list = handle.split(',').map(function(h){
          h = h.trim();
          var isQ = h.includes('(Q)');
          return {handle: h.replace('(Q)','').trim(), role: isQ?'Q':'PAX', pax_id: null};
        });
        fetch(proxy + '/cold-cases/submit', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({event_instance_id:c.id,ao_name:c.orgName,event_date:c.startDate,backblast_text:text,pax_list:pax_list,submitted_by:submitter})
        }).then(r=>r.json()).then(()=>alert('Case filed! An admin will review and submit to F3 Nation.')).catch(()=>alert('Submission failed. Please try again.'));
      };

      window.dismissCase = function(id, btn) {
        if (!confirm('Dismiss this case?')) return;
        var reason = prompt('Reason (optional):') || 'Dismissed';
        btn.textContent='…'; btn.disabled=true;
        fetch(proxy+'/cold-cases/dismiss/'+id, {method:'POST',headers:{'Content-Type':'application/json','X-F3-Admin-Token':adminToken},body:JSON.stringify({reason:reason})})
          .then(r=>r.json()).then(d=>{ if(d.dismissed){ cases=cases.filter(c=>c.id!==id); renderCases(); }});
      };

      function loadPending() {
        fetch(proxy+'/cold-cases/pending',{headers:{'X-F3-Admin-Token':adminToken}})
          .then(r=>r.json()).then(data=>{
            if (!data.length) return;
            var wrap = document.getElementById('f3cc-pending');
            wrap.style.display='block';
            wrap.innerHTML='<h3 style="font-family:sans-serif;border-bottom:2px solid #c8102e;padding-bottom:6px">Pending Admin Review ('+data.length+')</h3>'
              +data.map(bb=>'<div style="border:1px solid #ddd;padding:12px;margin-bottom:8px;font-family:sans-serif">'
                +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
                +'<div><strong>'+bb.ao_name+'</strong> &mdash; '+bb.event_date+' &mdash; Submitted by '+bb.submitted_by+'</div>'
                +'<div><button onclick="approveCase(\''+bb.id+'\')" style="background:#c8102e;color:#fff;border:none;padding:5px 12px;cursor:pointer;margin-right:6px">✓ Approve</button>'
                +'<button onclick="rejectCase(\''+bb.id+'\')" style="background:#f0f0f0;border:1px solid #ddd;padding:5px 12px;cursor:pointer">✕ Reject</button></div>'
                +'</div>'
                +'<div style="font-size:13px;color:#555;max-height:80px;overflow:hidden">'+bb.backblast_text.slice(0,300)+'</div>'
                +'<div style="font-size:11px;color:#888;margin-top:6px">PAX: '+(bb.pax_list||[]).map(p=>p.handle+'('+p.role+')').join(', ')+'</div>'
                +'</div>').join('');
          });
      }

      window.approveCase = function(id) {
        if (!confirm('Approve and submit to F3 Nation?')) return;
        fetch(proxy+'/cold-cases/approve/'+id,{method:'POST',headers:{'Content-Type':'application/json','X-F3-Admin-Token':adminToken}})
          .then(r=>r.json()).then(d=>{ alert(d.approved?'Approved!':'Failed.'); location.reload(); });
      };
      window.rejectCase = function(id) {
        fetch(proxy+'/cold-cases/reject/'+id,{method:'POST',headers:{'Content-Type':'application/json','X-F3-Admin-Token':adminToken}});
        location.reload();
      };
    })();
    </script>
    <?php
    return ob_get_clean();
}
