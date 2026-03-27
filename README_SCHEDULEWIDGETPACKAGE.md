# F3 Schedule Widget

Built by Deflated — F3 Waxhaw

A live schedule widget you can embed on any F3 region website. Pulls directly from the F3 Nation API — Q names, workout types, open Q slots, preblast status. Color-coded workout type pills, today's workouts highlighted, works on mobile.

---

## ⚠️ Security Warning — Read This First

There are two ways to use this widget. **The difference matters.**

### Option A — Direct API Token (Simple, but has risks)
`code.html` contains a `CONFIG` block where you paste your F3 Nation bearer token directly into the file. This is the simplest setup but has a security tradeoff:

> **Your API token will be visible to anyone who views the source code of your web page.**

For most F3 regions this is an acceptable risk — the token is read-only (it can only read schedule data, not write or delete anything), and your audience is fellow PAX. But you should be aware of it.

**If your token is ever compromised:**
- Someone could scrape your region's schedule data at high volume
- F3 Nation could rate-limit or revoke your token
- Go to the F3 Nation app → Settings → API and regenerate your token

### Option B — Secure Proxy (Recommended)
The `proxy-*` folders contain server-side code that keeps your token completely off the internet. The widget calls your proxy, your proxy calls F3 Nation, and browsers never see the token. See the [Secure Proxy Setup](#secure-proxy-setup) section below.

---

## Quick Start — Simple Embed (Option A)

Good for: WordPress, Squarespace, Wix, Weebly, raw HTML sites

**What you need:**
1. Be an admin for your region
2. Your region's `orgId` — go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions), find your region, click it, look for the ID field
3. Your F3 Nation API bearer token — same admin page, Settings → API. Starts with `f3_`

**Steps:**
1. Download `code.html`
2. Open it in Notepad (Windows) or TextEdit (Mac)
3. Find the `CONFIG` block near the top and change:
   - `REGION_ORG_ID` → your region's orgId number
   - `BEARER_TOKEN` → your F3 Nation token (starts with `f3_`)
   - `WIDGET_TITLE` → your region name (e.g. `'F3 Raleigh'`)
   - `REGION_URL` → your region's website URL
4. Save the file

**Embedding on your site:**

| Platform | How to embed |
|---|---|
| WordPress | Add a "Custom HTML" block, paste the contents of code.html |
| Squarespace | Add a "Code Block", paste the contents |
| Wix | Add an "HTML iFrame" element, paste the contents |
| Weebly | Use the "Embed Code" element |
| Raw HTML | Paste the widget div, style block, and script block into your page |

**Remember:** keep your token private. Don't post the file publicly on GitHub or share it in an open forum.

---

## Secure Proxy Setup (Option B)

The proxy sits between your website and F3 Nation. Your token lives on your server — never in the browser.

```
Visitor's browser → Your proxy → F3 Nation API
                         ↑
                    Token lives here
```

Pick the option that matches your setup:

### WordPress Plugin (`proxy-wordpress/`)

**Best for:** WordPress sites (most common F3 setup)

1. Upload the `proxy-wordpress/` folder to your server at:
   `/wp-content/plugins/f3-schedule-proxy/`

2. Go to **WordPress Admin → Plugins** → activate **F3 Schedule Proxy**

3. Go to **WordPress Admin → Settings → F3 Schedule Proxy** → paste your bearer token

4. Your proxy URL is:
   `https://yoursite.com/wp-json/f3/v1/region-schedule`

5. In `code.html`, replace the `CONFIG` block:
   ```javascript
   REGION_ORG_ID : 25273,       // your orgId
   PROXY_URL     : 'https://yoursite.com/wp-json/f3/v1',
   // Remove BEARER_TOKEN — not needed with proxy
   ```

---

### Standalone PHP (`proxy-php/`)

**Best for:** Any PHP web host — DreamHost, Bluehost, SiteGround, HostGator, etc.

1. Open `proxy-php/schedule.php` and find this line:
   ```php
   define('F3_BEARER_TOKEN', 'f3_YOUR_TOKEN_HERE');
   ```
   Replace with your actual token.

2. Upload the file to your server, e.g.:
   `https://yoursite.com/f3-proxy/schedule.php`

3. In `code.html`, update `CONFIG`:
   ```javascript
   REGION_ORG_ID : 25273,
   PROXY_URL     : 'https://yoursite.com/f3-proxy',
   ```

   Optional — add this to your `.htaccess` for a clean URL:
   ```
   RewriteRule ^f3/v1/region-schedule$ /f3-proxy/schedule.php [QSA,L]
   ```

---

### Netlify Edge Function (`proxy-netlify/`)

**Best for:** Squarespace, Wix, Webflow, Google Sites, GitHub Pages, or anyone without PHP hosting. Free, no server required.

This is the recommended option for non-technical users.

**Deploy steps:**

1. Create a free account at [netlify.com](https://netlify.com)

2. In your Netlify dashboard, create a new site

3. Go to **Site Settings → Environment Variables → Add variable**:
   - Name: `F3_BEARER_TOKEN`
   - Value: your F3 Nation bearer token

4. Upload these files to your Netlify site:
   ```
   proxy-netlify/
     index.html          ← the widget (auto-configures from URL)
     region-schedule.js  ← the proxy function
     netlify.toml        ← routing config
   ```
   You can drag and drop the `proxy-netlify/` folder into the Netlify deploy UI.

5. Your widget is live at:
   `https://your-site.netlify.app/?regionOrgId=XXXXX`

   Replace `XXXXX` with your region's orgId. You can also add:
   - `&title=F3+Raleigh` — sets the region name in the header
   - `&siteUrl=https://f3raleigh.com` — sets the "Our Site" footer link

**Netlify free tier limits:** 125,000 function calls/month — more than enough for any F3 region.

---

### Google Sites

Google Sites does not allow custom JavaScript, so you cannot embed the widget directly. Use the iframe method instead:

1. Set up the Netlify option above (takes about 5 minutes)

2. In Google Sites, click **Insert → Embed**

3. Paste your Netlify URL:
   ```
   https://your-site.netlify.app/?regionOrgId=XXXXX&title=F3+Raleigh
   ```

4. Adjust the iframe height to fit (600px works well)

That's it. The widget loads from Netlify, your token is safe, and Google Sites just shows the iframe.

---

## Finding Your Region's orgId

1. Go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions)
2. Find your region and click on it
3. Look for the **ID** field — that's your `orgId`

Or ask in the F3 Nation tech Slack channel — someone can look it up for you.

---

## Getting Your Bearer Token

1. Go to the F3 Nation admin at [map.f3nation.com](https://map.f3nation.com)
2. Settings → API → Bearer Token
3. It always starts with `f3_` followed by a long string

Treat it like a password. If you're using Option A (direct embed), don't post the file publicly.

---

## What the Widget Shows

- Today's workouts highlighted in red
- All upcoming workouts grouped by day
- Workout type badges: Bootcamp (gray), Run (green), Ruck (tan), Bike (blue), Mobility (purple)
- Q name when assigned, "Q Open" badge when not
- Preblast ✓ indicator when a preblast has been posted
- Spinning loader while data fetches, error message if something goes wrong

---

## File Reference

```
Schedule Widget/
  code.html                          ← The widget (use this for simple embed)
  README.md                          ← This file
  proxy-wordpress/
    f3-schedule-proxy.php            ← WordPress plugin (drop into /wp-content/plugins/)
  proxy-php/
    schedule.php                     ← Standalone PHP proxy
  proxy-netlify/
    index.html                       ← Widget + auto-config from URL params
    region-schedule.js               ← Netlify edge function (the proxy)
    netlify.toml                     ← Netlify routing config
```

---

## Questions / Help

Post in the F3 Nation tech Slack channel or reach out to **Deflated** (F3 Waxhaw).
