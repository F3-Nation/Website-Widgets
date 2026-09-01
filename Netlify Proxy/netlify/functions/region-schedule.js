// F3 Schedule Proxy — Netlify Serverless Function
// Works with Netlify Drop (no CLI or GitHub required)

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
      body: JSON.stringify({ error: 'F3_BEARER_TOKEN environment variable not set in Netlify dashboard' })
    };
  }

  const today = new Date().toISOString().split('T')[0];
  const headers = {
    'Authorization': `Bearer ${token}`,
    'client': 'f3-schedule-proxy',
  };

  const url = `https://api.f3nation.com/v1/event-instance/calendar-home-schedule`
    + `?regionOrgId=${regionId}&userId=1&startDate=${today}&limit=150`;

  // ── One-off closures and time changes ───────────────────────────────────
  // When a region closes an AO for a day in Slack, F3 Nation stores that on
  // the event instance as seriesException = 'closed' — but the event stays
  // ACTIVE, and the calendar-home-schedule endpoint above does not return the
  // seriesException field. So the schedule feed alone cannot tell a closed
  // workout from a live one, and the widget would advertise a workout that is
  // not happening.
  //
  // The event-instance list endpoint DOES return seriesException (plus the
  // reason, in meta.series_exception_reason), so we read it here and stamp the
  // result onto each event by id. If this lookup fails we still return the
  // schedule — just without the closure flags.
  async function fetchExceptions() {
    const PAGE_SIZE = 100;   // the API caps page size at 100
    const MAX_PAGES = 6;     // safety stop — far more than any region needs
    const map = {};

    for (let i = 0; i < MAX_PAGES; i++) {
      const listUrl = `https://api.f3nation.com/v1/event-instance`
        + `?regionOrgId=${regionId}&startDate=${today}`
        + `&pageIndex=${i}&pageSize=${PAGE_SIZE}`;

      const res = await fetch(listUrl, { headers });
      if (!res.ok) break;

      const rows = (await res.json())?.eventInstances || [];
      for (const row of rows) {
        if (!row.seriesException) continue;   // a normal, running workout
        map[row.id] = {
          seriesException: row.seriesException,
          seriesExceptionReason: row.meta?.series_exception_reason || '',
        };
      }
      if (rows.length < PAGE_SIZE) break;     // ran out of instances
    }
    return map;
  }

  try {
    const response = await fetch(url, { headers });

    if (!response.ok) {
      return {
        statusCode: 502,
        body: JSON.stringify({ error: `F3 Nation API returned ${response.status}` })
      };
    }

    const payload = await response.json();

    // Stamp closure info onto each event. Failure here is not fatal — the
    // schedule is still worth serving, so we fall back to nulls.
    let exceptions = {};
    try {
      exceptions = await fetchExceptions();
    } catch (e) {
      console.warn('[f3-schedule-proxy] closure lookup failed:', e.message);
    }

    for (const ev of (payload.events || [])) {
      const x = exceptions[ev.id];
      ev.seriesException       = x ? x.seriesException : null;
      ev.seriesExceptionReason = x ? x.seriesExceptionReason : '';
    }

    const data = JSON.stringify(payload);
    return {
      statusCode: 200,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
        'Cache-Control': 'public, max-age=1800',
      },
      body: data
    };
  } catch (err) {
    return {
      statusCode: 502,
      body: JSON.stringify({ error: 'Could not reach F3 Nation API: ' + err.message })
    };
  }
};
