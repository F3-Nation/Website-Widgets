# F3 Nation WordPress Plugins

A suite of WordPress plugins that bring F3 Nation data to your region's website — live schedules, backblasts, PAX stats, leaderboards, Kotter tracking, and more.

## Install Order — This Matters

**Install `f3-nation-api` first.** All other plugins depend on it.

### Step 1 — F3 Nation API (required by everything)
1. Upload `f3-nation-api/` to `/wp-content/plugins/`
2. Activate **F3 Nation API** in WordPress Admin → Plugins
3. Go to **WordPress Admin → F3 Nation** in the sidebar
4. Enter:
   - **Region Name** (e.g. "F3 Raleigh")
   - **Region orgId** — find at [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions)
   - **Bearer Token** — same admin site → Settings → API. Starts with `f3_`
   - **Region URL** — your website address
5. Click Save, then click **Test Connection** to verify

### Step 2 — Install whichever plugins you want

Upload each plugin folder to `/wp-content/plugins/` and activate individually.

---

## Plugins — No Database Required

These four work immediately after installing `f3-nation-api`. No additional setup.

### f3-schedule-widget
**Shortcode:** `[f3_schedule]`
Live workout schedule on any page or sidebar. Shows time, AO name, workout type, Q name or "Q Open" badge. Also registers a secure proxy endpoint at `/wp-json/f3/v1/region-schedule` that other sites can use.

Options: `[f3_schedule days="7"]` · `[f3_schedule title="Our Workouts"]` · `[f3_schedule max_width="600"]`

### f3-upcoming-events
**Shortcode:** `[f3_events]`
Special events from the F3 Nation calendar — convergences, CSAUPs, trail runs. Automatically filters out regular weekly AO workouts. Refreshes daily.

Options: `[f3_events days="30"]` · `[f3_events title="Upcoming Events"]`

### f3-backblast-feed
**Shortcode:** `[f3_backblasts]`
Recent backblasts from your region. Optional: go to **F3 Nation → Backblast Import** to auto-import new backblasts as WordPress posts on a daily schedule (draft or published).

Options: `[f3_backblasts count="20"]` · `[f3_backblasts ao="Cowbell"]` · `[f3_backblasts show_content="true"]`

### f3-region-dashboard
**No shortcode** — adds a stats panel to the WordPress admin dashboard (visible to admins only).
Shows: workouts this week, open Qs needing coverage, missing backblasts count, upcoming special events. Refreshes every 30 minutes.

---

## Plugins — Require Supabase Database

These four need a database to store analytics data. **Supabase is free** and takes 2 minutes to set up at [supabase.com](https://supabase.com).

Each plugin folder contains setup instructions including the SQL to create the required tables.

### f3-cold-cases
**Shortcode:** `[f3_cold_cases]`
Shows workouts that happened but were never recorded. PAX can submit missing backblasts through your site. Submissions queue for admin review before being published to F3 Nation.

Requires: Supabase URL + service key · Admin passcode
Setup: F3 Nation → Cold Cases Settings

### f3-pax-stats
**Shortcodes:** `[f3_pax_stats]` · `[f3_pax handle="Deflated"]` · `[f3_ao_stats]`
PAX leaderboards, individual stat cards, and AO health tables. Requires your Supabase database to be populated by the analytics sync pipeline.

Requires: Supabase URL + anon key
Setup: F3 Nation → PAX Stats Settings

### f3-kotter-list
**Shortcode:** `[f3_kotter]`
At-risk PAX tracking for Site Qs. Shows PAX who haven't posted recently with admin tools to mark outreach done, add notes, and set expected return dates. Recommend placing on a password-protected page.

Requires: Supabase URL + service key · Admin passcode
Setup: F3 Nation → Kotter List Settings

### f3-travel-map
**Shortcode:** `[f3_travel_map]`
Interactive Leaflet map showing every F3 region where your PAX have posted while traveling. Click any marker to fly to that location.

Requires: Supabase URL + anon key
Setup: F3 Nation → Travel Map Settings

Options: `[f3_travel_map height="600"]` · `[f3_travel_map show_list="false"]`

---

## Database Setup for Supabase Plugins

1. Create a free project at [supabase.com](https://supabase.com)
2. Go to **SQL Editor → New Query**
3. Each plugin's PHP file contains the SQL needed to create its tables — look for the `DATABASE SETUP` comment at the top of each file
4. Enter your Supabase URL and keys in each plugin's settings page under **F3 Nation** in the WordPress admin sidebar

---

## Questions / Help
Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
