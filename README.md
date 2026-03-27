# F3 Nation Website Widgets

Community-built tools for F3 region websites. Pull live data from the F3 Nation API, display stats and schedules, and help Site Qs manage their regions.

Built by Deflated — F3 Waxhaw. Contributions welcome.

---

## Repository Structure

```
F3-Nation/Website-Widgets/
│
├── Schedule Widget/
│   └── ← The original standalone HTML schedule widget.
│         Drop it on any website — WordPress, Squarespace, Wix,
│         Google Sites, or raw HTML. Shows upcoming workouts with
│         Q names, workout type badges, and open Q alerts.
│         Includes secure proxy options so your API token never
│         appears in browser source code.
│
├── Analytics/
│   └── f3-analytics-sync/
│         ← BigQuery → Supabase data pipeline (Google Apps Script).
│         Runs nightly. Pulls attendance and event data from the
│         f3data BigQuery project and pushes processed stats into
│         your Supabase database. Required by the four Supabase-
│         dependent WordPress plugins below. Includes full SQL
│         setup script for all database tables.
│
└── WordPress Plugins/
    │
    ├── f3-nation-api/          ← INSTALL THIS FIRST
    │     Shared API client used by all other plugins.
    │     Stores your bearer token and region orgId securely.
    │     Adds an "F3 Nation" menu to your WordPress admin.
    │     Nothing works without this active.
    │
    ├── f3-schedule-widget/     ← No Supabase needed
    │     Shortcode: [f3_schedule]
    │     Live workout schedule on any page or sidebar widget.
    │     Shows time, AO name, workout type, Q name or "Q Open".
    │     Also registers a secure proxy endpoint other sites can
    │     use without exposing their own API tokens.
    │
    ├── f3-upcoming-events/     ← No Supabase needed
    │     Shortcode: [f3_events]
    │     Special events pulled from the F3 Nation calendar —
    │     convergences, CSAUPs, trail runs, etc. Automatically
    │     filters out regular weekly AO workouts. Refreshes daily.
    │
    ├── f3-backblast-feed/      ← No Supabase needed
    │     Shortcode: [f3_backblasts]
    │     Recent backblasts from your region displayed on any page.
    │     Optional: auto-import backblasts as WordPress posts on a
    │     daily schedule (draft or published).
    │
    ├── f3-region-dashboard/    ← No Supabase needed
    │     No shortcode — appears in WordPress Admin Dashboard.
    │     Live stats panel for admins: workouts this week, open Qs
    │     needing coverage, missing backblasts count, and upcoming
    │     special events. Refreshes every 30 minutes.
    │
    ├── f3-cold-cases/          ← Requires PostgreSQL + PostgREST
    │     Shortcode: [f3_cold_cases]
    │     Surfaces workouts that happened but were never recorded.
    │     PAX can submit backblasts through the site. Submissions
    │     queue for admin review before posting to F3 Nation.
    │     Requires a write-enabled F3 Nation API token to publish.
    │
    ├── f3-pax-stats/           ← Requires PostgreSQL + PostgREST + Analytics Sync
    │     Shortcodes: [f3_pax_stats]  [f3_pax handle="Deflated"]  [f3_ao_stats]
    │     PAX leaderboards, individual stat cards, and AO health
    │     tables. Pulls from pax_stats, pax_ao_breakdown, and
    │     ao_stats tables populated by the analytics sync.
    │
    ├── f3-kotter-list/         ← Requires PostgreSQL + PostgREST + Analytics Sync
    │     Shortcode: [f3_kotter]
    │     At-risk PAX tracking for Site Qs. Shows PAX who haven't
    │     posted recently, with admin tools to mark outreach done,
    │     add notes, and set expected return dates. Recommend
    │     placing on a password-protected page.
    │
    └── f3-travel-map/          ← Requires PostgreSQL + PostgREST + Analytics Sync
          Shortcode: [f3_travel_map]
          Interactive Leaflet map showing every F3 region where
          your PAX have posted while traveling. Displays visit
          counts, PAX names, and location cards. Click any marker
          or card to fly to that location on the map.
```

---

## Which Plugins Need What

| Plugin | F3 Nation API plugin | F3 Nation bearer token | PostgreSQL + PostgREST | Analytics Sync |
|---|:---:|:---:|:---:|:---:|
| F3 Schedule Widget | ✅ Required | ✅ Required | — | — |
| F3 Upcoming Events | ✅ Required | ✅ Required | — | — |
| F3 Backblast Feed | ✅ Required | ✅ Required | — | — |
| F3 Region Dashboard | ✅ Required | ✅ Required | — | — |
| F3 Cold Cases | ✅ Required | ✅ Required | ✅ Required | — |
| F3 PAX Stats | ✅ Required | ✅ Required | ✅ Required | ✅ Required |
| F3 Kotter List | ✅ Required | ✅ Required | ✅ Required | ✅ Required |
| F3 Travel Map | ✅ Required | ✅ Required | ✅ Required | ✅ Required |

_Supabase is the recommended PostgreSQL + PostgREST provider — free, zero config, 2-minute setup. See [Database Options](#database-options) below._


---

## Getting Started

### Just want a schedule on your site?
→ See `Schedule Widget/` — no WordPress required, works on any platform.

### WordPress site, want live data with no database setup?
→ Install `f3-nation-api` then any of the top four plugins.

### Want full PAX stats, leaderboards, and Kotter tracking?
→ Follow the `Analytics/f3-analytics-sync/README.md` first to set up BigQuery → your database, then install the bottom four plugins.

---

## Database Options

The four plugins marked "Requires PostgreSQL + PostgREST" need a database with a REST API. They don't require Supabase specifically — any PostgreSQL database exposed via PostgREST works. That said, **Supabase is strongly recommended** because:

- Free tier handles any F3 region's data volume comfortably
- Zero server configuration — create a project and get a URL + API key in 2 minutes
- PostgREST is built-in, so no additional setup is needed
- The `supabase-setup.sql` script in the Analytics folder creates all tables automatically

**Alternatives if you already have infrastructure:**
- Self-hosted PostgreSQL + [PostgREST](https://postgrest.org) — works identically, just point the plugins at your own URL
- Any managed Postgres provider (Neon, Railway, Render) + PostgREST — same deal
- PlanetScale, Turso, or other Postgres-compatible databases with a REST layer

The `supabase-setup.sql` file is standard PostgreSQL — it will run on any Postgres instance regardless of host.

---

## Finding Your Region's orgId and Bearer Token

**orgId:** Go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions), find your region, click it, look for the ID field.

**Bearer token:** Same admin site → Settings → API. Starts with `f3_`. Treat it like a password.

---

## Questions / Support

Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
