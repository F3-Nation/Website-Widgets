# F3 Schedule Widget — Netlify Proxy

The secure way to embed the F3 schedule widget on Google Sites, Squarespace, Wix, Webflow, GitHub Pages, or any static site. Your API token never appears in the browser — it stays safely on Netlify's servers.

**Free. No coding required. Works with Netlify's drag-and-drop deploy.**

## What You Need
- A free [Netlify account](https://netlify.com)
- Your region's **orgId** — go to [map.f3nation.com/admin/regions](https://map.f3nation.com/admin/regions), find your region, click it, look for the ID field
- Your **F3 Nation bearer token** — same admin page → Settings → API. Starts with `f3_`

## Setup

### Step 1 — Add your bearer token to Netlify
1. Log into [netlify.com](https://netlify.com) and open your site (or create a new one)
2. Go to **Site Configuration → Environment Variables → Add variable**
   - Name: `F3_BEARER_TOKEN`
   - Value: your F3 Nation bearer token

### Step 2 — Edit the widget config
Open `index.html` in **Notepad** (Windows) or **TextEdit** (Mac) and update the CONFIG section:
```javascript
REGION_ORG_ID : 12345,                  // ← your orgId
WIDGET_TITLE  : 'F3 Raleigh',            // ← your region name
REGION_URL    : 'https://yoursite.com',  // ← your website
// Leave PROXY_URL as-is — it sets itself automatically
```

### Step 3 — Deploy to Netlify
**Drag the entire `Netlify Proxy` folder** to [app.netlify.com/drop](https://app.netlify.com/drop)

> ⚠️ Drag the **folder**, not a zip file. The folder structure must stay intact.

### Step 4 — Verify it's working
Open this URL in your browser (replace XXXXX with your orgId):
```
https://your-site.netlify.app/.netlify/functions/region-schedule?regionOrgId=XXXXX
```
You should see a JSON response with workout data. If you see JSON, everything is working.

Your widget is now live at:
```
https://your-site.netlify.app
```

### Step 5 — Embed on your site (Google Sites, etc.)
Use an iframe embed with your Netlify URL:
```
https://your-site.netlify.app/?regionOrgId=XXXXX&title=F3+Raleigh
```

**Google Sites:** Insert → Embed → paste the URL above → set height to 600px

**Any other platform:** Use an iframe or HTML embed block with the same URL.

## More pages in this folder

The same deploy (same `F3_BEARER_TOKEN`) also serves these pages. Each one takes its settings from the URL, so there is nothing to edit.

| Page | What it shows | Example URL |
|---|---|---|
| `numbers.html` | **Daily Numbers** — PAX (and FNG) count at each AO for a day, from submitted backblasts. Built for the Weasel Shaker to screenshot or copy into Slack. Only AOs with a backblast posted appear. | `/numbers.html?regionOrgId=XXXXX&title=F3+Raleigh` (optional `&date=YYYY-MM-DD`) |
| `missing-backblasts.html` | **Cold Cases** — past workouts that still have no backblast. | `/missing-backblasts.html?regionOrgId=XXXXX` |
| `my-qs.html` | **My Qs** — every workout a PAX has Q'd or Co-Q'd in a year. Can look the user up by email. | `/my-qs.html?regionOrgId=XXXXX&userId=12345` (optional `&year=2025`, `&year=0` = all time) |

Their functions are `region-numbers`, `region-missing-backblasts`, `region-my-qs` and `region-user-by-email`, alongside `region-schedule`.

> The F3 Nation API caps `pageSize` at **100** (since Sep 2026); a larger value returns HTTP 400. `region-numbers` pages through results 100 at a time.

## Troubleshooting

**404 on the function URL**
- Make sure you dragged the folder (not a zip) to Netlify Drop
- After setting `F3_BEARER_TOKEN`, trigger a redeploy: Netlify → Deploys → Trigger deploy

**"Could not load schedule" in the widget**
- Check that `REGION_ORG_ID` is the correct number for your region
- Verify `F3_BEARER_TOKEN` is set in Netlify environment variables

## Questions / Help
Post in the F3 Nation tech Slack or reach out to **Deflated** (F3 Waxhaw).
