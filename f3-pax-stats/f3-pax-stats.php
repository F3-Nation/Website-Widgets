<?php
/**
 * Plugin Name: F3 PAX Stats
 * Description: Displays PAX leaderboards, career stats, and AO breakdowns from your region's Supabase analytics database. Use [f3_pax_stats] for leaderboards or [f3_pax handle="Deflated"] for individual PAX cards. Requires F3 Nation API plugin and Supabase database.
 * Version: 1.0.0
 * Author: F3 Nation
 * Requires Plugins: f3-nation-api
 *
 * ============================================================
 * INSTALLATION
 * ============================================================
 * 1. Install and activate the "F3 Nation API" plugin first
 * 2. Set up your Supabase database with PAX stats tables
 *    (see DATABASE SETUP below)
 * 3. Upload this folder to /wp-content/plugins/f3-pax-stats/
 * 4. Activate in WordPress Admin → Plugins
 * 5. Go to F3 Nation → PAX Stats Settings and enter your
 *    Supabase URL and anon key
 * 6. Add shortcodes to your pages
 *
 * DATABASE SETUP (Supabase SQL)
 * ============================================================
 * Your Supabase database needs these tables populated by your
 * analytics pipeline (BigQuery → Supabase sync):
 *
 * pax_stats         — handle, pax_id, total_posts, total_qs,
 *                     first_post_date, last_post_date, avatar_url
 * pax_ao_breakdown  — pax_id, handle, ao_name, post_count, q_count
 * ao_stats          — ao_name, total_posts, avg_pax, active_pax_count
 * stats_leaders     — category, rank, handle, value, pax_id
 *
 * SHORTCODES
 * ============================================================
 * [f3_pax_stats]                      — top 10 leaderboard
 * [f3_pax_stats category="posts"]     — filter by category
 * [f3_pax_stats limit="25"]           — show more entries
 * [f3_pax handle="Deflated"]          — individual PAX card
 * [f3_pax handle="Deflated" show_chart="true"] — with activity chart
 * [f3_ao_stats]                       — AO health table
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_notices', function() {
    if ( ! function_exists('f3api_request') ) {
        echo '<div class="notice notice-error"><p>⚠️ <strong>F3 PAX Stats</strong> requires the <strong>F3 Nation API</strong> plugin.</p></div>';
    }
});

// ── Settings ───────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_submenu_page('f3-nation', 'PAX Stats', 'PAX Stats', 'manage_options', 'f3-pax-stats', 'f3ps_settings_page');
});

function f3ps_settings_page() {
    if (isset($_POST['f3ps_save']) && check_admin_referer('f3ps_settings')) {
        update_option('f3ps_supabase_url', esc_url_raw($_POST['f3ps_supabase_url'] ?? ''));
        update_option('f3ps_supabase_anon_key', sanitize_text_field($_POST['f3ps_supabase_anon_key'] ?? ''));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>F3 PAX Stats Settings</h1>
      <p>PAX Stats reads from your Supabase analytics database. Use the <strong>anon key</strong> (public, read-only) — not the service key.</p>
      <form method="post">
        <?php wp_nonce_field('f3ps_settings'); ?>
        <table class="form-table">
          <tr>
            <th>Supabase Project URL</th>
            <td><input type="url" name="f3ps_supabase_url" value="<?php echo esc_attr(get_option('f3ps_supabase_url','')); ?>" style="width:420px" placeholder="https://xxxx.supabase.co"></td>
          </tr>
          <tr>
            <th>Supabase Anon Key</th>
            <td>
              <input type="password" name="f3ps_supabase_anon_key" value="<?php echo esc_attr(get_option('f3ps_supabase_anon_key','')); ?>" style="width:420px;font-family:monospace">
              <p class="description">The public "anon" key from Supabase Settings → API. This is safe to use on the front-end.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save Settings', 'primary', 'f3ps_save'); ?>
      </form>
    </div>
    <?php
}

// ── Supabase helper ────────────────────────────────────────────────────────
function f3ps_sb(string $path): array {
    $url  = trailingslashit(get_option('f3ps_supabase_url')) . 'rest/v1' . $path;
    $key  = get_option('f3ps_supabase_anon_key');
    $resp = wp_remote_get($url, ['headers'=>['apikey'=>$key,'Authorization'=>'Bearer '.$key],'timeout'=>10]);
    return json_decode(wp_remote_retrieve_body($resp), true) ?? [];
}

// ── Leaderboard shortcode ──────────────────────────────────────────────────
add_shortcode('f3_pax_stats', 'f3ps_leaderboard_shortcode');
function f3ps_leaderboard_shortcode($atts): string {
    $sb_url = get_option('f3ps_supabase_url', '');
    if (!$sb_url) return '<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107">⚠️ Configure Supabase in <a href="' . admin_url('admin.php?page=f3-pax-stats') . '">F3 Nation → PAX Stats Settings</a>.</div>';
    $atts = shortcode_atts(['limit'=>10,'category'=>''], $atts, 'f3_pax_stats');

    $path = '/pax_stats?select=handle,total_posts,total_qs,last_post_date,avatar_url&order=total_posts.desc&limit=' . intval($atts['limit']);
    $cached = get_transient('f3ps_leaders_' . md5($path));
    if ($cached === false) { $cached = f3ps_sb($path); set_transient('f3ps_leaders_' . md5($path), $cached, 30 * MINUTE_IN_SECONDS); }

    if (empty($cached)) return '<p style="color:#888">No PAX stats available.</p>';

    ob_start();
    ?>
    <div class="f3-pax-stats" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="border-bottom:2px solid #c8102e">
            <th style="text-align:left;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">#</th>
            <th style="text-align:left;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">PAX</th>
            <th style="text-align:center;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">Posts</th>
            <th style="text-align:center;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">Qs</th>
            <th style="text-align:right;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">Last Post</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cached as $i => $pax):
            $last = $pax['last_post_date'] ? (new DateTime($pax['last_post_date']))->format('M j, Y') : '—';
            $days_ago = $pax['last_post_date'] ? floor((time() - strtotime($pax['last_post_date'])) / 86400) : null;
            $recency_color = $days_ago === null ? '#aaa' : ($days_ago <= 7 ? '#27ae60' : ($days_ago <= 30 ? '#e67e22' : '#c8102e'));
          ?>
          <tr style="border-bottom:1px solid #f0f0f0" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
            <td style="padding:10px 12px;font-size:14px;font-weight:700;color:<?php echo $i===0?'#c8a020':($i===1?'#aaa':($i===2?'#b87333':'#aaa')); ?>"><?php echo $i+1; ?></td>
            <td style="padding:10px 12px">
              <div style="display:flex;align-items:center;gap:10px">
                <?php if (!empty($pax['avatar_url'])): ?>
                  <img src="<?php echo esc_url($pax['avatar_url']); ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover" onerror="this.style.display='none'">
                <?php endif; ?>
                <strong style="font-size:14px"><?php echo esc_html($pax['handle']); ?></strong>
              </div>
            </td>
            <td style="padding:10px 12px;text-align:center;font-size:16px;font-weight:700;color:#111"><?php echo number_format($pax['total_posts']); ?></td>
            <td style="padding:10px 12px;text-align:center;font-size:14px;color:#555"><?php echo number_format($pax['total_qs']); ?></td>
            <td style="padding:10px 12px;text-align:right;font-size:12px;color:<?php echo $recency_color; ?>"><?php echo esc_html($last); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
}

// ── Individual PAX card shortcode ──────────────────────────────────────────
add_shortcode('f3_pax', 'f3ps_pax_card_shortcode');
function f3ps_pax_card_shortcode($atts): string {
    $sb_url = get_option('f3ps_supabase_url', '');
    if (!$sb_url) return f3api_not_configured_notice();
    $atts = shortcode_atts(['handle'=>'','show_chart'=>'false'], $atts, 'f3_pax');
    if (!$atts['handle']) return '<p style="color:#888">Specify a handle: [f3_pax handle="YourHandle"]</p>';

    $handle = sanitize_text_field($atts['handle']);
    $cached = get_transient('f3ps_pax_' . strtolower($handle));
    if ($cached === false) {
        $cached = f3ps_sb('/pax_stats?handle=ilike.' . urlencode($handle) . '&select=*&limit=1');
        set_transient('f3ps_pax_' . strtolower($handle), $cached, 30 * MINUTE_IN_SECONDS);
    }

    if (empty($cached[0])) return '<p style="color:#888">PAX "' . esc_html($handle) . '" not found.</p>';
    $p = $cached[0];

    $first = $p['first_post_date'] ? (new DateTime($p['first_post_date']))->format('M j, Y') : '—';
    $last  = $p['last_post_date']  ? (new DateTime($p['last_post_date']))->format('M j, Y')  : '—';

    ob_start();
    ?>
    <div class="f3-pax-card" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;border:1px solid #ddd;max-width:400px;overflow:hidden">
      <div style="background:#111;padding:16px 20px;display:flex;align-items:center;gap:14px">
        <?php if (!empty($p['avatar_url'])): ?>
          <img src="<?php echo esc_url($p['avatar_url']); ?>" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid #c8102e">
        <?php else: ?>
          <div style="width:52px;height:52px;border-radius:50%;background:#c8102e;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:700;flex-shrink:0"><?php echo strtoupper(substr($p['handle'],0,1)); ?></div>
        <?php endif; ?>
        <div>
          <div style="font-size:20px;font-weight:700;color:#fff;text-transform:uppercase"><?php echo esc_html($p['handle']); ?></div>
          <div style="font-size:11px;color:#aaa;letter-spacing:.1em;text-transform:uppercase;margin-top:2px">F3 PAX</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid #eee">
        <?php foreach ([['Posts', number_format($p['total_posts']??0)], ['Qs Led', number_format($p['total_qs']??0)], ['Years', $p['first_post_date'] ? round((time()-strtotime($p['first_post_date']))/31536000,1) : '—']] as $stat): ?>
        <div style="padding:14px;text-align:center;border-right:1px solid #eee">
          <div style="font-size:22px;font-weight:800;color:#c8102e;line-height:1"><?php echo $stat[1]; ?></div>
          <div style="font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#888;margin-top:3px"><?php echo $stat[0]; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="padding:14px 16px;display:flex;justify-content:space-between;font-size:12px;color:#888">
        <span>First post: <strong><?php echo esc_html($first); ?></strong></span>
        <span>Last post: <strong><?php echo esc_html($last); ?></strong></span>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ── AO Stats shortcode ─────────────────────────────────────────────────────
add_shortcode('f3_ao_stats', 'f3ps_ao_stats_shortcode');
function f3ps_ao_stats_shortcode($atts): string {
    $sb_url = get_option('f3ps_supabase_url', '');
    if (!$sb_url) return f3api_not_configured_notice();
    $atts = shortcode_atts(['limit'=>20], $atts, 'f3_ao_stats');
    $cached = get_transient('f3ps_ao_stats');
    if ($cached === false) {
        $cached = f3ps_sb('/ao_stats?select=ao_name,total_posts,avg_pax,active_pax_count&order=total_posts.desc&limit=' . intval($atts['limit']));
        set_transient('f3ps_ao_stats', $cached, 30 * MINUTE_IN_SECONDS);
    }
    if (empty($cached)) return '<p style="color:#888">No AO stats available.</p>';
    ob_start();
    ?>
    <div class="f3-ao-stats" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif">
      <table style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="border-bottom:2px solid #c8102e">
            <th style="text-align:left;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">AO</th>
            <th style="text-align:center;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">Total Posts</th>
            <th style="text-align:center;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">Avg PAX</th>
            <th style="text-align:center;padding:8px 12px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#888">Active PAX</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cached as $ao): ?>
          <tr style="border-bottom:1px solid #f0f0f0" onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
            <td style="padding:10px 12px;font-weight:700;font-size:14px;text-transform:uppercase"><?php echo esc_html($ao['ao_name']); ?></td>
            <td style="padding:10px 12px;text-align:center;font-size:15px;font-weight:700;color:#c8102e"><?php echo number_format($ao['total_posts']); ?></td>
            <td style="padding:10px 12px;text-align:center;font-size:14px;color:#555"><?php echo round($ao['avg_pax'] ?? 0, 1); ?></td>
            <td style="padding:10px 12px;text-align:center;font-size:14px;color:#555"><?php echo intval($ao['active_pax_count'] ?? 0); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
}
