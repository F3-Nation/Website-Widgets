# F3 Nation Website Widgets

Community-built tools for F3 region websites. Show live schedules, backblasts, PAX stats, and more — all pulling directly from the F3 Nation API.

Built by Deflated — F3 Waxhaw. Contributions welcome.

---

## What's in This Repo

```
Website-Widgets/
│
├── Schedule Widget/        ← Single HTML file. Drop it on any website.
│                             Fastest way to show your schedule.
│
├── Netlify Proxy/          ← For Google Sites, Squarespace, Wix, and
│                             static sites. Free. No coding required.
│                             Keeps your API token private.
│
├── WordPress Proxy/        ← WordPress plugin proxy. Keeps your token
│                             off the browser. Other regions can use
│                             your proxy too.
│
├── PHP Proxy/              ← Single PHP file for any PHP web host
│                             (DreamHost, Bluehost, SiteGround, etc.)
│
└── WordPress Plugins/      ← Full plugin suite for WordPress sites.
                              Schedules, backblasts, PAX stats,
                              leaderboards, Kotter list, travel map.
```

Each folder has its own README with complete setup instructions.

---

## Which Option Is Right for You?

| Your situation | Use this |
|---|---|
| I just want a schedule on my website | `Schedule Widget/` |
| My site is on Google Sites, Squarespace, or Wix | `Netlify Proxy/` |
| My site runs WordPress | `WordPress Plugins/` |
| I have PHP hosting and want a secure proxy | `PHP Proxy/` |
| I want everything — stats, leaderboards, Kotter list | `WordPress Plugins/` |

---

## Before You Start

Every option requires two things from F3 Nation:

**Your region's orgId**
Go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions), find your region, click it, and look for the ID field. Or ask in the F3 Nation tech Slack.

**Your bearer token**
Same admin site → Settings → API. It starts with `f3_` and is unique to your region. Treat it like a password — don't post it publicly.

---

## Questions / Support

Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
