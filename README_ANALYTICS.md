# F3 Nation Analytics Sync

BigQuery → Supabase data pipeline for F3 region websites.
Built by Deflated — F3 Waxhaw.

This sync powers the Supabase-dependent WordPress plugins:
**F3 PAX Stats**, **F3 Kotter List**, **F3 Travel Map**, and **F3 Cold Cases**.

---

## How It Works

```
F3 Nation API ──────────────────────────────────┐
                                                 ▼
BigQuery (f3data.analytics.*) ──► Apps Script ──► Supabase ──► WordPress Plugins
                                  (sync.gs)        (tables)     (shortcodes)
```

1. **BigQuery** holds all historical F3 data — attendance, events, PAX info
2. **Apps Script** runs SQL queries against BigQuery and pushes results to Supabase
3. **Supabase** stores the processed data in structured tables
4. **WordPress plugins** read from Supabase to display stats, leaderboards, maps, etc.

---

## Prerequisites

Before you start, you need:

- [ ] Access to the `f3data` BigQuery project (contact F3 Nation)
- [ ] A Google account (for Apps Script)
- [ ] A Supabase account (free at supabase.com)
- [ ] A WordPress site with the F3 Nation API plugin installed
- [ ] Your F3 Nation region orgId and bearer token

---

## Setup — Step by Step

### Step 1 — Create a Supabase Project

1. Go to [supabase.com](https://supabase.com) and create a free account
2. Create a new project (any name, pick a region close to you)
3. Wait for the project to finish provisioning (~2 minutes)
4. Go to **Settings → API** and note down:
   - **Project URL** — looks like `https://xxxx.supabase.co`
   - **service_role key** — long JWT string, starts with `eyJ...` (keep this private)
   - **anon key** — another JWT string (safe for public use)

### Step 2 — Create the Database Tables

1. In your Supabase dashboard, go to **SQL Editor → New Query**
2. Open `supabase-setup.sql` from this folder
3. Paste the entire contents into the SQL editor
4. Click **Run**
5. You should see "Success" — all 12 tables are now created

### Step 3 — Set Up Google Apps Script

1. Go to [script.google.com](https://script.google.com)
2. Click **New Project**
3. Name it "F3 Analytics Sync"
4. Delete the default `function myFunction()` code
5. Paste the entire contents of `sync.gs` into the editor

### Step 4 — Enable the BigQuery API

1. In the Apps Script editor, click **Services** (+ icon in left sidebar)
2. Find **BigQuery API** and click **Add**
3. The `BigQuery` object is now available in your script

### Step 5 — Update the CONFIG Section

At the top of `sync.gs`, update these values:

```javascript
const SUPABASE_URL  = 'https://YOUR_PROJECT.supabase.co';   // from Step 1
const SUPABASE_KEY  = 'YOUR_SUPABASE_SERVICE_KEY';          // service_role key from Step 1
const BQ_PROJECT    = 'f3data';                              // keep as-is
const REGION_ID     = 25273;                                 // ← YOUR region's orgId
const F3_API_TOKEN  = 'f3_YOUR_BEARER_TOKEN';               // your F3 Nation bearer token
const WP_BASE_URL   = 'https://yoursite.com';               // your WordPress URL
const WP_PASSCODE   = 'YourAdminPasscode';                   // your WP admin passcode
```

### Step 6 — Run the Sync Manually (First Time)

1. In Apps Script, select `syncAll` from the function dropdown
2. Click **Run**
3. You'll be asked to authorize the script — click through the permissions
4. Watch the **Execution Log** at the bottom — each sync function logs its progress
5. Full sync takes about 3-5 minutes the first time

**Expected output:**
```
=== F3 Analytics Sync starting ===
Syncing PAX stats...
PAX stats: 385 rows
Syncing region stats...
Region stats synced
Syncing AO stats...
AO stats: 27 AOs
PAX-AO: 4,231 rows
Stats leaders: 50 rows across 5 categories
Kotter list: 23 PAX flagged
Scheduled Qs: 14 events with Qs
syncUpcoming: 3 events synced
Travel data: 69 locations
PAX activity: 12,847 rows
=== All syncs complete in 187s ===
```

### Step 7 — Verify Data in Supabase

1. Go to your Supabase dashboard → **Table Editor**
2. Check that each table has rows:
   - `pax_stats` — should have one row per PAX
   - `ao_stats` — one row per AO
   - `bq_region_stats` — exactly 1 row (id=1)
   - `stats_leaders` — 50 rows (10 per category × 5 categories)
   - `kotter_list` — varies (0 is fine if no one is at risk)
   - `travel_locations` — varies by how much your PAX travel

### Step 8 — Set Up Daily Trigger

1. In Apps Script, click **Triggers** (clock icon in left sidebar)
2. Click **+ Add Trigger**
3. Configure:
   - Function: `syncAll`
   - Event source: `Time-driven`
   - Type: `Day timer`
   - Time: `2am to 3am` (or any low-traffic time)
4. Click **Save**

The sync will now run automatically every day.

---

## What Each Function Syncs

| Function | Table | What It Does |
|---|---|---|
| `syncPaxStats()` | `pax_stats` | Career stats for every PAX (posts, Qs, last workout, home AO) |
| `syncRegionStats()` | `bq_region_stats` | Region-wide totals, attendance by day, top AOs, biggest workouts |
| `syncAoStats()` | `ao_stats` | AO health: avg PAX, trend, top Qs, 52-week weekly history |
| `syncPaxAoBreakdown()` | `pax_ao_breakdown` | Every PAX's post count at every AO |
| `syncStatsLeaders()` | `stats_leaders` | All-time records: biggest week, longest streak, etc. |
| `syncKotterList()` | `kotter_list` | At-risk PAX identified by BigQuery logic |
| `syncScheduledQs()` | `scheduled_qs` | Upcoming events with planned Q assignments |
| `syncUpcoming()` | `upcoming_events` | Special events via WordPress proxy |
| `syncTravelData()` | `travel_locations` | Where your PAX have posted at other regions |
| `syncPaxActivity()` | `pax_activity` | Daily post counts for activity heatmaps |

**Run order matters** — `syncAll()` runs them in the correct order.

---

## Run Order Explained

```
syncPaxStats()        ← Must run first. Everything else references PAX data.
syncRegionStats()     ← Independent. Can run any time.
syncAoStats()         ← Independent. Needs BigQuery AO data.
syncPaxAoBreakdown()  ← Independent. Needs both PAX and AO data.
syncStatsLeaders()    ← Independent. Pure BigQuery aggregation.
syncKotterList()      ← Uses pax_stats as base. Run after syncPaxStats.
syncScheduledQs()     ← Calls F3 Nation API directly. Independent.
syncUpcoming()        ← Calls WordPress. Needs WP plugin active.
syncTravelData()      ← Independent. Rebuilds from scratch each time.
syncPaxActivity()     ← Independent. Large table, runs last.
```

---

## Supabase Keys — Which to Use Where

| Key | What It Is | Where to Use |
|---|---|---|
| `service_role` key | Full admin access, bypasses RLS | Apps Script (sync.gs), WordPress server-side only |
| `anon` key | Public read-only, respects RLS | WordPress plugins that display public data (PAX Stats, Travel Map) |

**Never put the service_role key in front-end JavaScript.** It grants full database access.

---

## Troubleshooting

**"Query timed out"**
BigQuery queries have a 60-second timeout. Some queries (especially `syncStatsLeaders` streak calculation) can be slow on large datasets. If this happens, run the individual function again — it usually succeeds on retry.

**"HTTP 401" from Supabase**
Your service_role key is wrong or expired. Double-check it in Supabase → Settings → API.

**"HTTP 401" from WordPress** (syncUpcoming)
Your WP admin passcode is wrong, or the F3 Nation API plugin isn't configured. Check the passcode in your WordPress F3 Nation settings.

**Table has 0 rows after sync**
Check the Apps Script execution log for that function. Look for HTTP error codes in the Supabase batch output. Common causes: wrong table name, RLS blocking writes (service_role key should bypass this), or BigQuery returning 0 rows (check your REGION_ID).

**"Access denied" on BigQuery**
Your Google account doesn't have access to the `f3data` BigQuery project. Contact F3 Nation tech team to request access.

---

## Running Individual Syncs

You don't have to run `syncAll()` every time. Run individual functions when you need to:

```javascript
syncPaxStats()        // Refresh PAX data only
syncKotterList()      // Update at-risk PAX list
syncTravelData()      // Rebuild travel map
syncScheduledQs()     // Refresh Q signup data
```

Select the function from the dropdown in Apps Script and click Run.

---

## Files in This Package

```
f3-analytics-sync/
  sync.gs              ← Paste into Google Apps Script
  supabase-setup.sql   ← Run once in Supabase SQL Editor
  README.md            ← This file
```

---

## Questions / Support

Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
