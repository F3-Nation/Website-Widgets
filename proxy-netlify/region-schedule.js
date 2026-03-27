// F3 Schedule Proxy — Netlify Edge Function
// ============================================================
// Use this if your site is on Squarespace, Wix, Webflow,
// GitHub Pages, or any static site that can't run PHP.
//
// This runs as a FREE serverless function on Netlify.
//
// SETUP INSTRUCTIONS
// ============================================================
// 1. Create a free account at netlify.com
//
// 2. Create a new site — you can deploy from GitHub or just
//    drag and drop a folder. You don't need a real website,
//    just a Netlify site to host the function.
//
// 3. In your Netlify site dashboard:
//    Go to Site Settings → Environment Variables → Add variable
//    Name:  F3_BEARER_TOKEN
//    Value: your F3 Nation bearer token (starts with f3_)
//    This keeps your token completely off the internet.
//
// 4. Create this folder structure in your project:
//    netlify/
//      edge-functions/
//        region-schedule.js   ← this file
//    netlify.toml             ← the config file (see below)
//
// 5. Deploy to Netlify. Your proxy URL will be:
//    https://your-netlify-site.netlify.app/f3/v1/region-schedule
//
// 6. In your schedule widget, set:
//    PROXY_URL: 'https://your-netlify-site.netlify.app/f3/v1'
//    REGION_ORG_ID: your region's orgId number
//
// netlify.toml contents (create this file in your project root):
// ---------------------------------------------------------------
// [[edge_functions]]
//   path = "/f3/v1/region-schedule"
//   function = "region-schedule"
// ---------------------------------------------------------------
// ============================================================

export default async function handler(request, context) {
  // Only allow GET requests
  if (request.method !== 'GET') {
    return new Response(JSON.stringify({ error: 'Method not allowed' }), {
      status: 405,
      headers: { 'Content-Type': 'application/json' }
    });
  }

  // Get regionOrgId from query string
  const url       = new URL(request.url);
  const regionId  = parseInt(url.searchParams.get('regionOrgId') || '0');

  if (!regionId) {
    return new Response(JSON.stringify({ error: 'regionOrgId parameter is required' }), {
      status: 400,
      headers: { 'Content-Type': 'application/json' }
    });
  }

  // Get bearer token from Netlify environment variable
  // Set this in Netlify Dashboard → Site Settings → Environment Variables
  const token = Netlify.env.get('F3_BEARER_TOKEN');
  if (!token) {
    return new Response(JSON.stringify({
      error: 'Proxy not configured — add F3_BEARER_TOKEN environment variable in Netlify dashboard'
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }

  // Fetch from F3 Nation API server-side
  // The browser never sees the token
  const today  = new Date().toISOString().split('T')[0];
  const apiUrl = `https://api.f3nation.com/v1/event-instance/calendar-home-schedule`
               + `?regionOrgId=${regionId}&userId=1&startDate=${today}&limit=150`;

  try {
    const apiRes = await fetch(apiUrl, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'client': 'f3-schedule-proxy',
      },
    });

    if (!apiRes.ok) {
      return new Response(JSON.stringify({ error: `F3 Nation API returned ${apiRes.status}` }), {
        status: 502,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' }
      });
    }

    const data = await apiRes.text();

    // Return with CORS headers and 30-minute cache
    return new Response(data, {
      status: 200,
      headers: {
        'Content-Type':                'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control':               'public, max-age=1800, s-maxage=1800',
      },
    });

  } catch (err) {
    return new Response(JSON.stringify({ error: 'Could not reach F3 Nation API: ' + err.message }), {
      status: 502,
      headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' }
    });
  }
}
