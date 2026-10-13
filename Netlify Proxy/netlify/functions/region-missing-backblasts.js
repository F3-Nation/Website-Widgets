// F3 Missing Backblasts Proxy — Netlify Serverless Function
// Works with Netlify Drop (no CLI or GitHub required)
//
// Calls the F3 Nation API and returns a list of past scheduled events
// that do NOT yet have a backblast posted (empty backblastTs).
//
// Query params:
//   regionOrgId  (required)  — the F3 Nation region ID
//   daysBack     (optional)  — how many days back to include. Default 90.
//                              Use 0 for "since Jan 1 of the current year".
//   limit        (optional)  — max rows pulled from F3 Nation. Default 500.
//                              Cap is enforced server-side (500).

exports.handler = async function(event, context) {
  const regionId = event.queryStringParameters?.regionOrgId;
  if (!regionId) {
    return {
      statusCode: 400,
      body: JSON.stringify({ error: 'regionOrgId parameter is required' })
    };
  }

  const token = process.env.F3_BEARER_TOKEN;
  if (!token) {
    return {
      statusCode: 503,
      body: JSON.stringify({ error: 'F3_BEARER_TOKEN environment variable not set in Netlify' })
    };
  }

  const daysBackRaw = parseInt(event.queryStringParameters?.daysBack, 10);
  const daysBack    = Number.isFinite(daysBackRaw) ? Math.max(0, daysBackRaw) : 90;
  const limitRaw    = parseInt(event.queryStringParameters?.limit, 10);
  const limit       = Math.min(Math.max(Number.isFinite(limitRaw) ? limitRaw : 500, 1), 500);

  const today = new Date();
  const todayStr = today.toISOString().split('T')[0];

  let cutoff;
  if (daysBack === 0) {
    cutoff = today.getFullYear() + '-01-01';
  } else {
    const c = new Date(today);
    c.setDate(c.getDate() - daysBack);
    cutoff = c.toISOString().split('T')[0];
  }

  const url = `https://api.f3nation.com/v1/event-instance/without-q`
    + `?regionOrgId=${encodeURIComponent(regionId)}`
    + `&notPostedOnly=true`
    + `&limit=${limit}`;

  try {
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'client': 'f3-missing-backblasts',
      }
    });

    if (!response.ok) {
      return {
        statusCode: 502,
        body: JSON.stringify({ error: `F3 Nation API returned ${response.status}` })
      };
    }

    const raw = await response.json();
    const all = Array.isArray(raw.eventInstances) ? raw.eventInstances : [];

    const filtered = all
      .filter(e => e.startDate && e.startDate >= cutoff && e.startDate < todayStr)
      .sort((a, b) => b.startDate.localeCompare(a.startDate));

    return {
      statusCode: 200,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'public, max-age=1800',
      },
      body: JSON.stringify({
        regionOrgId: String(regionId),
        cutoff,
        today: todayStr,
        total: filtered.length,
        apiReturned: all.length,
        apiLimitHit: all.length >= limit,
        events: filtered,
      }),
    };
  } catch (err) {
    return {
      statusCode: 502,
      body: JSON.stringify({ error: 'Could not reach F3 Nation API: ' + err.message })
    };
  }
};
