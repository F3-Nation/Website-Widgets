# F3 Schedule Proxy — Standalone PHP

A single PHP file that acts as a secure proxy for the F3 schedule widget. Upload it to any PHP web host and your API token stays on your server.

**Best for:** DreamHost, Bluehost, SiteGround, HostGator, or any shared PHP hosting.

## What You Need
- A web host that supports PHP (most do)
- Your **F3 Nation bearer token** — go to [map.f3nation.com](https://map.f3nation.com) → Settings → API. Starts with `f3_`

## Setup
1. Open `schedule.php` in a text editor and find this line:
   ```php
   define('F3_BEARER_TOKEN', 'f3_YOUR_TOKEN_HERE');
   ```
   Replace `f3_YOUR_TOKEN_HERE` with your actual bearer token.

2. Upload `schedule.php` to your server. For example:
   ```
   https://yoursite.com/f3-proxy/schedule.php
   ```

3. Open `Schedule Widget/code.html` and update the CONFIG section:
   ```javascript
   REGION_ORG_ID : 12345,                        // ← your orgId
   PROXY_URL     : 'https://yoursite.com/f3-proxy', // ← your proxy URL (no /schedule.php)
   // Remove or leave blank: BEARER_TOKEN
   ```

## Optional — Clean URL
Add this to your `.htaccess` file to use a cleaner URL:
```
RewriteRule ^f3/v1/region-schedule$ /f3-proxy/schedule.php [QSA,L]
```
Then set `PROXY_URL : 'https://yoursite.com'` in the widget config.

## Questions / Help
Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
