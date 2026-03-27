<?php
/**
 * Plugin Name: F3 Schedule Proxy
 * Description: Secure proxy for the F3 Nation schedule API. Allows the F3 Schedule Widget to fetch schedule data without exposing your API token in the browser.
 * Version: 1.0
 * Author: F3 Nation Tech
 *
 * ============================================================
 * SETUP INSTRUCTIONS
 * ============================================================
 * 1. Upload this file to your WordPress site at:
 *    /wp-content/plugins/f3-schedule-proxy/f3-schedule-proxy.php
 *
 * 2. Go to WordPress Admin → Plugins and activate "F3 Schedule Proxy"
 *
 * 3. Go to WordPress Admin → Settings → F3 Schedule Proxy
 *    and enter your F3 Nation bearer token.
 *
 * 4. Your proxy URL will be:
 *    https://yoursite.com/wp-json/f3/v1/region-schedule
 *
 * 5. In your schedule widget, set:
 *    PROXY_URL: 'https://yoursite.com/wp-json/f3/v1'
 *    REGION_ORG_ID: your region's orgId number
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register REST route on WordPress init ──────────────────────────────────
add_action( 'rest_api_init', function() {
    register_rest_route( 'f3/v1', '/region-schedule', [
        'methods'             => 'GET',
        'callback'            => 'f3sp_get_schedule',
        'permission_callback' => '__return_true', // public endpoint — token stays server-side
    ]);
});

// ── Main proxy function ────────────────────────────────────────────────────
function f3sp_get_schedule( WP_REST_Request $req ): WP_REST_Response {
    $region_id = intval( $req->get_param('regionOrgId') );

    if ( ! $region_id ) {
        return new WP_REST_Response(['error' => 'regionOrgId parameter is required'], 400);
    }

    // Rate limit: max 60 requests per hour per region to prevent abuse
    $rate_key   = 'f3sp_rate_' . $region_id;
    $rate_count = (int) get_transient( $rate_key );
    if ( $rate_count > 60 ) {
        return new WP_REST_Response(['error' => 'Rate limit exceeded — try again later'], 429);
    }
    set_transient( $rate_key, $rate_count + 1, HOUR_IN_SECONDS );

    // Cache schedule per region for 30 minutes — reduces API calls significantly
    $cache_key = 'f3sp_schedule_' . $region_id;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return rest_ensure_response( $cached );
    }

    // Get the bearer token from WordPress settings
    // Never hardcoded here — stored in wp_options via the settings page below
    $token = get_option( 'f3sp_bearer_token', '' );
    if ( ! $token ) {
        return new WP_REST_Response(['error' => 'F3 Nation API token not configured. Go to Settings → F3 Schedule Proxy to add your token.'], 503);
    }

    // Make the server-side request to F3 Nation API
    // The browser never sees the token — it only sees this response
    $today    = date('Y-m-d');
    $api_url  = 'https://api.f3nation.com/v1/event-instance/calendar-home-schedule'
              . '?regionOrgId=' . $region_id
              . '&userId=1'
              . '&startDate=' . $today
              . '&limit=150';

    $response = wp_remote_get( $api_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'client'        => 'f3-schedule-proxy',
        ],
        'timeout' => 15,
    ]);

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response(['error' => 'Could not reach F3 Nation API: ' . $response->get_error_message()], 502);
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        return new WP_REST_Response(['error' => 'F3 Nation API returned error ' . $code], 502);
    }

    // Cache the successful response for 30 minutes
    set_transient( $cache_key, $body, 30 * MINUTE_IN_SECONDS );
    return rest_ensure_response( $body );
}

// ── Settings page ──────────────────────────────────────────────────────────
// Provides a simple admin UI to enter the bearer token
// so it never has to be pasted into code

add_action( 'admin_menu', function() {
    add_options_page(
        'F3 Schedule Proxy',
        'F3 Schedule Proxy',
        'manage_options',
        'f3-schedule-proxy',
        'f3sp_settings_page'
    );
});

add_action( 'admin_init', function() {
    register_setting( 'f3sp_settings', 'f3sp_bearer_token', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

function f3sp_settings_page() {
    // Handle form save
    if ( isset($_POST['f3sp_bearer_token']) && check_admin_referer('f3sp_save') ) {
        update_option( 'f3sp_bearer_token', sanitize_text_field($_POST['f3sp_bearer_token']) );
        echo '<div class="updated"><p>Token saved.</p></div>';
    }

    $token        = get_option('f3sp_bearer_token', '');
    $proxy_url    = rest_url('f3/v1/region-schedule');
    $masked_token = $token ? substr($token, 0, 8) . str_repeat('•', max(0, strlen($token) - 8)) : '';
    ?>
    <div class="wrap">
      <h1>F3 Schedule Proxy Settings</h1>
      <p>This plugin lets the F3 Schedule Widget fetch schedule data securely — your API token stays on this server and is never visible to browsers.</p>

      <h2>Your Proxy URL</h2>
      <p>Use this URL in your schedule widget's <code>PROXY_URL</code> config:</p>
      <code style="background:#f0f0f0;padding:8px 12px;display:inline-block;font-size:14px"><?php echo esc_url($proxy_url); ?></code>

      <h2 style="margin-top:24px">F3 Nation Bearer Token</h2>
      <form method="post">
        <?php wp_nonce_field('f3sp_save'); ?>
        <table class="form-table">
          <tr>
            <th>Bearer Token</th>
            <td>
              <input type="password" name="f3sp_bearer_token"
                     value="<?php echo esc_attr($token); ?>"
                     style="width:420px;font-family:monospace"
                     placeholder="f3_xxxxxxxxxxxxxxxxxxxx">
              <?php if ($masked_token): ?>
                <p class="description">Current token: <code><?php echo esc_html($masked_token); ?></code></p>
              <?php endif; ?>
              <p class="description">Get this from the F3 Nation app under Settings → API. Starts with <code>f3_</code></p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save Token'); ?>
      </form>

      <h2>Test Your Proxy</h2>
      <p>Replace <code>25273</code> with your region's orgId and open this URL in your browser:</p>
      <code style="background:#f0f0f0;padding:8px 12px;display:inline-block;font-size:14px"><?php echo esc_url($proxy_url); ?>?regionOrgId=25273</code>
      <p>You should see a JSON response with upcoming workouts. If you see an error, check your token.</p>
    </div>
    <?php
}
