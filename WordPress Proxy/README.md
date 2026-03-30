# F3 Schedule Proxy — WordPress Plugin

A WordPress plugin that acts as a secure proxy for the F3 schedule widget. Your API token stays on your server — it never appears in browser source code.

**Best for:** WordPress sites that want to host the schedule widget securely, or share their proxy with other regions.

## What You Need
- A WordPress site
- Your **F3 Nation bearer token** — go to [map.f3nation.com](https://map.f3nation.com) → Settings → API. Starts with `f3_`

## Installation
1. Upload `f3-schedule-proxy.php` to your server at:
   ```
   /wp-content/plugins/f3-schedule-proxy/f3-schedule-proxy.php
   ```
2. Go to **WordPress Admin → Plugins** and activate **F3 Schedule Proxy**
3. Go to **WordPress Admin → Settings → F3 Schedule Proxy**
4. Enter your F3 Nation bearer token and click Save

## Using with the Schedule Widget
Open `Schedule Widget/code.html` and update the CONFIG section:
```javascript
REGION_ORG_ID : 12345,                              // ← your orgId
PROXY_URL     : 'https://yoursite.com/wp-json/f3/v1', // ← your WordPress URL
// Remove or leave blank: BEARER_TOKEN (not needed with proxy)
```

## Sharing Your Proxy
Other regions can point their widget at your proxy URL by setting:
```javascript
PROXY_URL : 'https://yoursite.com/wp-json/f3/v1',
```
The proxy accepts any valid `regionOrgId` parameter, so other regions just pass their own orgId and get their own schedule. Rate limiting is built in (60 requests/hour per region).

## Questions / Help
Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
