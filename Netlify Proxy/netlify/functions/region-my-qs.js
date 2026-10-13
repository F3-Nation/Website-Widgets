// F3 My Qs Proxy — Netlify Serverless Function
// Works with Netlify Drop (no CLI or GitHub required)
//
// Calls the F3 Nation API and returns past events where the requested
// user is listed as Q or Co-Q, filtered to a given calendar year.
//
// Query params:
//   regionOrgId  (required)  — the F3 Nation region ID
//   userId       (required)  — the F3 Nation user ID whose Qs to pull
//   year         (optional)  — 4-digit year. Default = current year.
//                              Use 0 for "all time" (no year filter).

exports.handler = async function(event, context) {
  const regionId = event.queryStringParameters?.regionOrgId;
  const userId   = event.queryStringParameters?.userId;

  if (!regionId) {
    return {
      statusCode: 400,
      body: JSON.stringify({ error: 'regionOrgId parameter is required' })
    };
  }
  if (!userId) {
    return {
      statusCode: 400,
      body: JSON.stringify({ error: 'userId parameter is required' })
    };
  }

  const headers = event.headers || {};
  const userToken = headers['x-f3-token'] || headers['X-F3-Token'];
  const token = (typeof userToken === 'string' && userToken.trim()) || process.env.F3_BEARER_TOKEN;
  if (!token) {
    return {
      statusCode: 503,
      body: JSON.stringify({ error: 'No bearer token available. Set F3_BEARER_TOKEN or pass one in the X-F3-Token header.' })
    };
  }
  const tokenSource = (typeof userToken === 'string' && userToken.trim()) ? 'user' : 'env';

  const today = new Date();
  const todayStr = today.toISOString().split('T')[0];

  const yearRaw = parseInt(event.queryStringParameters?.year, 10);
  const year = Number.isFinite(yearRaw) ? yearRaw : today.getFullYear();

  const url = `https://api.f3nation.com/v1/event-instance/past-qs`
    + `?userId=${encodeURIComponent(userId)}`
    + `&regionOrgId=${encodeURIComponent(regionId)}`
    + `&notPostedOnly=false`;

  try {
    const response = await fetch(url, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'client': 'f3-my-qs',
      }
    });

    if (!response.ok) {
      return {
        statusCode: response.status === 401 || response.status === 403 ? response.status : 502,
        headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
        body: JSON.stringify({
          error: `F3 Nation API returned ${response.status}`,
          tokenSource,
          status: response.status,
        })
      };
    }

    const raw = await response.json();
    const all = Array.isArray(raw)
      ? raw
      : Array.isArray(raw.eventInstances) ? raw.eventInstances : [];

    let filtered = all.filter(e => e.startDate && e.startDate < todayStr);
    if (year > 0) {
      const lo = `${year}-01-01`;
      const hi = `${year + 1}-01-01`;
      filtered = filtered.filter(e => e.startDate >= lo && e.startDate < hi);
    }
    filtered.sort((a, b) => b.startDate.localeCompare(a.startDate));

    // past-qs returns a slim projection. Hydrate each instance via
    // /v1/event-instance/id/{id} so we get backblast text/image/meta.
    // Whitelist the fields that leave this function — anything F3 adds in
    // future (PII or otherwise) won't ride along unless we explicitly add it.
    const PUBLIC_FIELDS = [
      'id', 'name', 'description',
      'startDate', 'startTime', 'endDate', 'endTime',
      'orgId', 'orgName', 'locationId', 'seriesId',
      'backblast', 'backblastRich', 'backblastTs',
      'paxCount', 'fngCount',
      'imageUrl', 'image', 'headerImage', 'coverImage', 'bannerUrl',
      'meta',
    ];
    function pickPublic(obj, extras) {
      const out = {};
      for (const k of PUBLIC_FIELDS) if (k in obj) out[k] = obj[k];
      if (extras) for (const k in extras) out[k] = extras[k];
      return out;
    }

    const HYDRATE_HEADERS = {
      'Authorization': `Bearer ${token}`,
      'client': 'f3-my-qs',
    };
    const BATCH = 8;
    for (let i = 0; i < filtered.length; i += BATCH) {
      const slice = filtered.slice(i, i + BATCH);
      const fulls = await Promise.all(slice.map(async ev => {
        if (!ev.id) return pickPublic(ev, { _hydrate: 'no-id' });
        try {
          const r = await fetch(`https://api.f3nation.com/v1/event-instance/id/${ev.id}`, { headers: HYDRATE_HEADERS });
          if (!r.ok) {
            return pickPublic(ev, { _hydrate: 'http-' + r.status });
          }
          const text = await r.text();
          let body = {};
          try { body = JSON.parse(text); } catch (_) {
            return pickPublic(ev, { _hydrate: 'parse-error' });
          }
          const full = body && (body.eventInstance || body.data || body.result || body);
          if (!full || typeof full !== 'object') {
            return pickPublic(ev, { _hydrate: 'empty' });
          }
          const merged = Object.assign({}, ev, full);
          return pickPublic(merged, { _hydrate: 'ok' });
        } catch (_) {
          return pickPublic(ev, { _hydrate: 'throw' });
        }
      }));
      for (let j = 0; j < slice.length; j++) filtered[i + j] = fulls[j];
    }

    return {
      statusCode: 200,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'public, max-age=1800',
      },
      body: JSON.stringify({
        regionOrgId: String(regionId),
        userId: String(userId),
        year: year > 0 ? year : null,
        today: todayStr,
        total: filtered.length,
        apiReturned: all.length,
        tokenSource,
        events: filtered,
      }),
    };
  } catch (err) {
    console.error('[F3 My Qs] proxy failed:', err && err.message ? err.message : 'unknown');
    return {
      statusCode: 502,
      body: JSON.stringify({ error: 'Could not reach F3 Nation API.' })
    };
  }
};
