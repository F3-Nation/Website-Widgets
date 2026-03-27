<?php
/**
 * F3 Nation API — Shared Library
 * ============================================================
 * This file is required by all F3 Nation WordPress plugins.
 * It provides a shared API client, settings management, and
 * caching utilities so each plugin doesn't duplicate this code.
 *
 * INSTALLATION
 * ============================================================
 * Upload this file to:
 *   /wp-content/plugins/f3-nation-api/f3-nation-api.php
 *
 * Activate "F3 Nation API" in WordPress Admin → Plugins.
 * All other F3 plugins depend on this one being active.
 * ============================================================
 *
 * Plugin Name: F3 Nation API
 * Description: Shared API client and settings for all F3 Nation WordPress plugins. Required by all other F3 plugins. Configure your region's orgId and bearer token here.
 * Version: 1.0.0
 * Author: F3 Nation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'F3_API_VERSION', '1.0.0' );
define( 'F3_API_BASE',    'https://api.f3nation.com' );
define( 'F3_API_OPTION',  'f3_nation_settings' );

// ── Settings page ─────────────────────────────────────────────────────────

add_action( 'admin_menu', 'f3api_admin_menu' );
function f3api_admin_menu() {
    add_menu_page(
        'F3 Nation',
        'F3 Nation',
        'manage_options',
        'f3-nation',
        'f3api_settings_page',
        'dashicons-groups',
        30
    );
}

add_action( 'admin_init', 'f3api_register_settings' );
function f3api_register_settings() {
    register_setting( 'f3_nation_settings_group', F3_API_OPTION, [
        'sanitize_callback' => 'f3api_sanitize_settings',
    ]);
}

function f3api_sanitize_settings( $input ): array {
    return [
        'region_org_id' => intval( $input['region_org_id'] ?? 0 ),
        'bearer_token'  => sanitize_text_field( $input['bearer_token'] ?? '' ),
        'region_name'   => sanitize_text_field( $input['region_name'] ?? '' ),
        'region_url'    => esc_url_raw( $input['region_url'] ?? '' ),
    ];
}

function f3api_settings_page() {
    $saved   = get_option( F3_API_OPTION, [] );
    $token   = $saved['bearer_token'] ?? '';
    $masked  = $token ? substr($token, 0, 8) . str_repeat('•', max(0, strlen($token) - 8)) : '';
    $test_ok = false;

    // Handle test connection
    if ( isset($_POST['f3_test_connection']) && check_admin_referer('f3api_test') ) {
        $test = f3api_request('/v1/event-instance/calendar-home-schedule?regionOrgId=' . intval($saved['region_org_id']) . '&userId=1&startDate=' . date('Y-m-d') . '&limit=1');
        $test_ok = isset($test['events']);
    }
    ?>
    <div class="wrap">
      <h1>⚡ F3 Nation Settings</h1>
      <p>Configure your region's connection to the F3 Nation API. These settings are shared by all F3 Nation plugins.</p>

      <?php if ( $test_ok ): ?>
        <div class="notice notice-success"><p>✅ Connection successful — F3 Nation API is responding for your region.</p></div>
      <?php endif; ?>

      <form method="post" action="options.php">
        <?php settings_fields('f3_nation_settings_group'); ?>
        <table class="form-table">
          <tr>
            <th>Region Name</th>
            <td>
              <input type="text" name="<?php echo F3_API_OPTION; ?>[region_name]"
                     value="<?php echo esc_attr($saved['region_name'] ?? ''); ?>"
                     placeholder="F3 Waxhaw" style="width:300px">
              <p class="description">Your region's display name (e.g. "F3 Raleigh")</p>
            </td>
          </tr>
          <tr>
            <th>Region orgId</th>
            <td>
              <input type="number" name="<?php echo F3_API_OPTION; ?>[region_org_id]"
                     value="<?php echo esc_attr($saved['region_org_id'] ?? ''); ?>"
                     placeholder="25273" style="width:150px">
              <p class="description">
                Find this at <a href="https://map.f3nation.com/admin/regions" target="_blank">map.f3nation.com/admin/regions</a>
                — click your region and look for the ID field.
              </p>
            </td>
          </tr>
          <tr>
            <th>Bearer Token</th>
            <td>
              <input type="password" name="<?php echo F3_API_OPTION; ?>[bearer_token]"
                     value="<?php echo esc_attr($token); ?>"
                     placeholder="f3_xxxxxxxxxxxxxxxxxxxx" style="width:420px;font-family:monospace">
              <?php if ($masked): ?>
                <p class="description">Current: <code><?php echo esc_html($masked); ?></code></p>
              <?php endif; ?>
              <p class="description">
                Get this from the F3 Nation admin at Settings → API. Starts with <code>f3_</code>.
                Treat it like a password — it's stored securely on your server.
              </p>
            </td>
          </tr>
          <tr>
            <th>Region Website URL</th>
            <td>
              <input type="url" name="<?php echo F3_API_OPTION; ?>[region_url]"
                     value="<?php echo esc_attr($saved['region_url'] ?? ''); ?>"
                     placeholder="https://f3waxhaw.com" style="width:300px">
              <p class="description">Used in widget footers and links.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save Settings'); ?>
      </form>

      <hr>
      <h2>Test Connection</h2>
      <p>After saving your settings, test that your token and orgId are working:</p>
      <form method="post">
        <?php wp_nonce_field('f3api_test'); ?>
        <input type="hidden" name="f3_test_connection" value="1">
        <?php submit_button('Test F3 Nation API Connection', 'secondary'); ?>
      </form>

      <hr>
      <h2>Installed F3 Plugins</h2>
      <p>The following F3 Nation plugins are available:</p>
      <table class="widefat" style="max-width:700px">
        <thead><tr><th>Plugin</th><th>Status</th><th>Shortcode</th></tr></thead>
        <tbody>
          <?php
          $plugins = [
            ['F3 Schedule Widget',    'f3-schedule-widget/f3-schedule-widget.php',    '[f3_schedule]'],
            ['F3 Upcoming Events',    'f3-upcoming-events/f3-upcoming-events.php',    '[f3_events]'],
            ['F3 Backblast Feed',     'f3-backblast-feed/f3-backblast-feed.php',      '[f3_backblasts]'],
            ['F3 Region Dashboard',   'f3-region-dashboard/f3-region-dashboard.php',  'WP Dashboard Widget'],
            ['F3 Cold Cases',         'f3-cold-cases/f3-cold-cases.php',              '[f3_cold_cases]'],
            ['F3 PAX Stats',          'f3-pax-stats/f3-pax-stats.php',                '[f3_pax_stats]'],
            ['F3 Kotter List',        'f3-kotter-list/f3-kotter-list.php',            '[f3_kotter]'],
            ['F3 Travel Map',         'f3-travel-map/f3-travel-map.php',              '[f3_travel_map]'],
          ];
          foreach ($plugins as $p):
            $active = is_plugin_active($p[1]);
          ?>
          <tr>
            <td><?php echo esc_html($p[0]); ?></td>
            <td><?php echo $active ? '✅ Active' : '○ Not active'; ?></td>
            <td><code><?php echo esc_html($p[2]); ?></code></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
}

// ── Core API client ────────────────────────────────────────────────────────

/**
 * Make a request to the F3 Nation API.
 *
 * @param string $path    API path, e.g. '/v1/event-instance/calendar-home-schedule?...'
 * @param string $method  HTTP method (GET, POST, PATCH, DELETE)
 * @param array  $body    Request body for POST/PATCH requests
 * @return array          Decoded JSON response, or ['error' => '...'] on failure
 */
function f3api_request( string $path, string $method = 'GET', array $body = [] ): array {
    $settings = get_option( F3_API_OPTION, [] );
    $token    = $settings['bearer_token'] ?? '';

    if ( ! $token ) {
        return ['error' => 'F3 Nation bearer token not configured. Go to F3 Nation → Settings.'];
    }

    $args = [
        'method'  => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'client'        => 'f3-wp-plugin-' . F3_API_VERSION,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 15,
    ];

    if ( ! empty($body) ) {
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request( F3_API_BASE . $path, $args );

    if ( is_wp_error($response) ) {
        return ['error' => $response->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode( wp_remote_retrieve_body($response), true );

    if ( $code >= 400 ) {
        return ['error' => 'API returned ' . $code, 'data' => $data];
    }

    return $data ?? [];
}

/**
 * Get the configured region orgId.
 */
function f3api_region_id(): int {
    $settings = get_option( F3_API_OPTION, [] );
    return intval( $settings['region_org_id'] ?? 0 );
}

/**
 * Get a setting value by key.
 */
function f3api_setting( string $key, $default = '' ) {
    $settings = get_option( F3_API_OPTION, [] );
    return $settings[$key] ?? $default;
}

/**
 * Cache-wrapped API request.
 * Results are stored in WordPress transients for $cache_minutes minutes.
 *
 * @param string $cache_key     Unique cache key for this request
 * @param string $path          API path
 * @param int    $cache_minutes How long to cache (default 30)
 * @return array
 */
function f3api_cached_request( string $cache_key, string $path, int $cache_minutes = 30 ): array {
    $cached = get_transient( $cache_key );
    if ( $cached !== false ) return $cached;

    $data = f3api_request( $path );
    if ( ! isset($data['error']) ) {
        set_transient( $cache_key, $data, $cache_minutes * MINUTE_IN_SECONDS );
    }
    return $data;
}

/**
 * Clear all F3 API caches.
 * Called when settings are saved.
 */
function f3api_clear_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_f3_%' OR option_name LIKE '_transient_timeout_f3_%'");
}
add_action( 'update_option_' . F3_API_OPTION, 'f3api_clear_cache' );

/**
 * Check if the F3 Nation API library is available.
 * Other plugins call this to verify the dependency is met.
 */
function f3api_available(): bool {
    return function_exists('f3api_request') && f3api_region_id() > 0;
}

/**
 * Display a notice if F3 Nation API plugin is not configured.
 * Other plugins call this in their shortcode output.
 */
function f3api_not_configured_notice(): string {
    return '<div style="padding:16px;background:#fff3cd;border:1px solid #ffc107;font-family:sans-serif;font-size:14px">'
         . '⚠️ <strong>F3 Nation API not configured.</strong> '
         . 'Go to <a href="' . admin_url('admin.php?page=f3-nation') . '">F3 Nation → Settings</a> '
         . 'and enter your region orgId and bearer token.'
         . '</div>';
}
