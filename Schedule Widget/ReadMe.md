# F3 Schedule Widget — Standalone HTML

A single HTML file you can drop on any website to show your region's live workout schedule. No server required. Works on WordPress, Squarespace, Wix, Weebly, or raw HTML.

## What You Need
- Your region's **orgId** — go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions), find your region, click it, look for the ID field
- Your **F3 Nation bearer token** — same admin page → Settings → API. Starts with `f3_`

## Setup
1. Open `code.html` in **Notepad** (Windows) or **TextEdit** (Mac)
2. Find the `CONFIG` section near the top and fill in your values:
   ```javascript
   REGION_ORG_ID : 12345,                  // ← your orgId
   BEARER_TOKEN  : 'f3_xxxxx...',           // ← your bearer token
   WIDGET_TITLE  : 'F3 Raleigh',            // ← your region name
   REGION_URL    : 'https://yoursite.com',  // ← your website
   ```
3. Save the file

## Embed on Your Site

| Platform | Where to paste |
|---|---|
| WordPress | Add a "Custom HTML" block |
| Squarespace | Add a "Code Block" |
| Wix | Add an "HTML iFrame" element |
| Weebly | Use the "Embed Code" element |
| Raw HTML | Paste directly into your page |

## ⚠️ Security Note
Your bearer token is visible in the page source with this method. It's **read-only** (it cannot modify any F3 Nation data), so the risk is low. But if you'd prefer to keep your token completely private, use one of the proxy options instead:
- **Netlify Proxy** — best for Google Sites, Squarespace, Wix, static sites (free)
- **WordPress Proxy** — best for WordPress sites
- **PHP Proxy** — best for any PHP web host

## What the Widget Shows
- Today's workouts highlighted
- Upcoming workouts grouped by day
- Workout type badges: Bootcamp, Run, Ruck, Bike, Mobility
- Q name when assigned, "Q Open" badge when not
- Preblast indicator when posted

## Questions / Help
Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
