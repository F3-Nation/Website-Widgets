# F3 Nation Website Widgets

Community-built tools for F3 region websites. Pull live data from the F3 Nation API, display schedules and stats, and help Site Qs manage their regions.

Built by Deflated — F3 Waxhaw. Contributions welcome.

---

## What's in This Repo

| Folder | What it does | Requires |
|---|---|---|
| `Schedule Widget/` | Standalone HTML schedule widget — works on any website | Bearer token + orgId |
| `proxy-wordpress/` | WordPress plugin proxy — keeps your token off the browser | WordPress |
| `proxy-php/` | Single PHP file proxy — for any PHP web host | PHP hosting |
| `proxy-netlify/` | Netlify proxy + widget — for Google Sites, Squarespace, Wix, static sites | Free Netlify account |
| `f3-nation-api/` | WordPress plugin — shared API client (required by all WP plugins) | WordPress |
| `f3-schedule-widget/` | WordPress plugin — `[f3_schedule]` shortcode | WordPress + f3-nation-api |
| `f3-upcoming-events/` | WordPress plugin — `[f3_events]` shortcode | WordPress + f3-nation-api |
| `f3-backblast-feed/` | WordPress plugin — `[f3_backblasts]` shortcode | WordPress + f3-nation-api |
| `f3-region-dashboard/` | WordPress plugin — admin dashboard stats panel | WordPress + f3-nation-api |
| `f3-cold-cases/` | WordPress plugin — `[f3_cold_cases]` shortcode | WordPress + f3-nation-api + Supabase |
| `f3-pax-stats/` | WordPress plugin — `[f3_pax_stats]` leaderboards | WordPress + f3-nation-api + Supabase |
| `f3-kotter-list/` | WordPress plugin — `[f3_kotter]` at-risk PAX tracking | WordPress + f3-nation-api + Supabase |
| `f3-travel-map/` | WordPress plugin — `[f3_travel_map]` interactive travel map | WordPress + f3-nation-api + Supabase |

---

## Before You Start — What You Need

**Every option requires:**
- Your region's **orgId** — go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions), find your region, click it, look for the ID field
- Your **F3 Nation bearer token** — same admin page → Settings → API. Starts with `f3_`. Treat it like a password.

---

## Option 1 — Simple HTML Widget (Fastest)

**Best for:** Any website. Takes 5 minutes. Your token will be visible in page source (read-only risk — see security note below).

1. Download `Schedule Widget/code.html`
2. Open it in **Notepad** (Windows) or **TextEdit** (Mac)
3. Find the `CONFIG` section near the top and fill in:
   ```javascript
   REGION_ORG_ID : 12345,           // your region's orgId number
   BEARER_TOKEN  : 'f3_xxxxx...',   // your bearer token
   WIDGET_TITLE  : 'F3 Raleigh',    // your region name
   REGION_URL    : 'https://yoursite.com', // your website
   ```
4. Save the file
5. Paste the contents into your website:

| Platform | Where to paste |
|---|---|
| WordPress | Add a "Custom HTML" block |
| Squarespace | Add a "Code Block" |
| Wix | Add an "HTML iFrame" element |
| Weebly | Use the "Embed Code" element |
| Raw HTML | Paste directly into your page |

> ⚠️ **Security note:** Your bearer token is visible in the page source with this method. It's read-only (can't modify any data), so the risk is low for most regions. If you want to keep your token completely private, use Option 2, 3, or 4 below.

---

## Option 2 — Netlify Proxy (Google Sites, Squarespace, Wix, Static Sites)

**Best for:** Sites that can't run server-side code. Free, no coding required. Token stays private.

### Step 1 — Create a Netlify account
Go to [netlify.com](https://netlify.com) and create a free account.

### Step 2 — Add your bearer token
In your Netlify dashboard: **Site Configuration → Environment Variables → Add variable**
- Name: `F3_BEARER_TOKEN`
- Value: your F3 Nation bearer token

### Step 3 — Deploy the widget
1. Download the `proxy-netlify/` folder from this repo
2. Open `proxy-netlify/index.html` in Notepad and update the `CONFIG` section:
   ```javascript
   REGION_ORG_ID : 12345,                  // your region's orgId
   WIDGET_TITLE  : 'F3 Raleigh',           // your region name
   REGION_URL    : 'https://yoursite.com', // your website
   // Leave PROXY_URL as-is
   ```
3. **Drag the entire `proxy-netlify` folder** to [app.netlify.com/drop](https://app.netlify.com/drop)

> ⚠️ **Important:** Drag the **folder**, not a zip file. The folder structure must be intact for the proxy to work.

### Step 4 — Verify it's working
Open this URL in your browser (replace XXXXX with your orgId):
```
https://your-site.netlify.app/.netlify/functions/region-schedule?regionOrgId=XXXXX
```
You should see a JSON response with workout data. If you do, the widget at `https://your-site.netlify.app` is working.

### Step 5 — Embed on Google Sites (or anywhere via iframe)
1. In Google Sites: **Insert → Embed**
2. Paste your Netlify URL:
   ```
   https://your-site.netlify.app/?regionOrgId=XXXXX&title=F3+Raleigh
   ```
3. Set iframe height to 600px

---

## Option 3 — WordPress Plugin Proxy

**Best for:** WordPress sites. Token stays on your server.

1. Upload the `proxy-wordpress/` folder to `/wp-content/plugins/f3-schedule-proxy/`
2. Activate **F3 Schedule Proxy** in WordPress Admin → Plugins
3. Go to **Settings → F3 Schedule Proxy** and paste your bearer token
4. Use `code.html` with this CONFIG:
   ```javascript
   REGION_ORG_ID : 12345,
   PROXY_URL     : 'https://yoursite.com/wp-json/f3/v1',
   // No BEARER_TOKEN needed
   ```

---

## Option 4 — Standalone PHP Proxy

**Best for:** Any PHP web host (DreamHost, Bluehost, SiteGround, etc.).

1. Open `proxy-php/schedule.php` and set your token:
   ```php
   define('F3_BEARER_TOKEN', 'f3_your_token_here');
   ```
2. Upload to your server (e.g. `https://yoursite.com/f3-proxy/schedule.php`)
3. Use `code.html` with this CONFIG:
   ```javascript
   REGION_ORG_ID : 12345,
   PROXY_URL     : 'https://yoursite.com/f3-proxy',
   ```

---

## WordPress Plugins — Full Feature Set

All plugins require the `f3-nation-api` plugin to be installed and active first.

### Step 1 — Install F3 Nation API (required by everything)
1. Upload `f3-nation-api/` to `/wp-content/plugins/`
2. Activate in WordPress Admin → Plugins
3. Go to **WordPress Admin → F3 Nation** and enter your region name, orgId, bearer token, and website URL

### Step 2 — Install the plugins you want

**No database required:**

| Plugin folder | Shortcode | What it does |
|---|---|---|
| `f3-schedule-widget/` | `[f3_schedule]` | Live workout schedule on any page |
| `f3-upcoming-events/` | `[f3_events]` | Convergences, CSAUPs, special events |
| `f3-backblast-feed/` | `[f3_backblasts]` | Recent backblasts; optional WP post import |
| `f3-region-dashboard/` | *(admin dashboard)* | Open Qs, missing backblasts, upcoming events |

**Requires Supabase (free at [supabase.com](https://supabase.com)):**

| Plugin folder | Shortcode | What it does |
|---|---|---|
| `f3-cold-cases/` | `[f3_cold_cases]` | PAX submit missing backblasts for admin review |
| `f3-pax-stats/` | `[f3_pax_stats]` `[f3_pax]` `[f3_ao_stats]` | PAX leaderboards, stat cards, AO health |
| `f3-kotter-list/` | `[f3_kotter]` | At-risk PAX tracking with outreach tools |
| `f3-travel-map/` | `[f3_travel_map]` | Map of everywhere your PAX have posted |

Each plugin folder contains a PHP file with full setup instructions in the comments at the top.

---

## Troubleshooting

**Widget shows "Could not load schedule"**
- Verify your orgId and bearer token are correct
- Open `https://api.f3nation.com/v1/event-instance/calendar-home-schedule?regionOrgId=XXXXX` in your browser to test your token directly

**Netlify shows 404 on the function URL**
- Make sure you dragged the **folder** (not a zip) to Netlify Drop
- The folder must contain: `netlify/functions/region-schedule.js` and `netlify.toml` at the root
- After adding `F3_BEARER_TOKEN` in environment variables, trigger a new deploy: Netlify → Deploys → Trigger deploy

**Token expired or revoked**
- Go to [map.f3nation.com](https://map.f3nation.com) → Settings → API → regenerate your token
- Update it in your proxy (Netlify env var, WordPress settings, or PHP file)

---

## Questions / Support

Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
