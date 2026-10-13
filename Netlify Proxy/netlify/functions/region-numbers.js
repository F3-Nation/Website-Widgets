// F3 Daily Numbers Proxy — Netlify Serverless Function
// Works with Netlify Drop (no CLI or GitHub required)
//
// Calls the F3 Nation API and returns the PAX counts for each AO on each
// day, pulled from submitted backblasts. This is what a region's
// Weasel Shaker screenshots to post the daily numbers to Slack / social.
//
// On the F3 Nation event-instance list, a non-null paxCount is the signal
// that a backblast has been submitted (the count is entered as part of the
// backblast). Events with no count yet are omitted, so the numbers can't
// be posted before the backblasts are actually in.
//
// Query params:
//   regionOrgId  (required)  — the F3 Nation region ID
//   date         (optional)  — a single YYYY-MM-DD day to return. If given,
//                              only that day's numbers come back.
//   daysBack     (optional)  — how many days back to include when no single
//                              date is requested. Default 7. Max 120.
//   limit        (optional)  — max rows pulled from F3 Nation. Default 500.

const API = 'https://api.f3nation.com/v1';

exports.handler = async function (event) {
  const regionId = event.queryStringParameters?.regionOrgId;
  if (!regionId) {
    return json(400, { error: 'regionOrgId parameter is required' });
  }

  const token = process.env.F3_BEARER_TOKEN;
  if (!token) {
    return json(503, { error: 'F3_BEARER_TOKEN environment variable not set in Netlify' });
  }
  const authHeaders = { Authorization: `Bearer ${token}`, client: 'f3-daily-numbers' };
  const rid = encodeURIComponent(regionId);

  // --- work out the date window -------------------------------------------
  const today = new Date();
  const todayStr = today.toISOString().split('T')[0];

  const singleDate = (event.queryStringParameters?.date || '').trim();
  const isSingle = /^\d{4}-\d{2}-\d{2}$/.test(singleDate);

  const daysBackRaw = parseInt(event.queryStringParameters?.daysBack, 10);
  const daysBack = Math.min(Math.max(Number.isFinite(daysBackRaw) ? daysBackRaw : 7, 0), 120);

  const limitRaw = parseInt(event.queryStringParameters?.limit, 10);
  const limit = Math.min(Math.max(Number.isFinite(limitRaw) ? limitRaw : 500, 1), 500);

  let startDate, endDate;
  if (isSingle) {
    startDate = endDate = singleDate;
  } else {
    endDate = todayStr;
    const c = new Date(today);
    c.setDate(c.getDate() - daysBack);
    startDate = c.toISOString().split('T')[0];
  }

  // --- pull data ----------------------------------------------------------
  // 1) The event-instance list from `startDate` forward. IMPORTANT: do NOT
  //    pass a `sorting` param — with it, the API returns future recurring
  //    projections (all paxCount null) instead of the real dated instances.
  //    The list carries paxCount / fngCount but NOT orgName, so we also…
  // 2) …pull the region's recent events via without-q, which DOES carry
  //    orgName, to build an orgId -> AO-name lookup for clean labels.
  //    Since F3-Nation/f3-nation #912 (30 Sep 2026) pageSize is capped at
  //    100 — anything larger is a 400 — so page through in steps of 100.
  //    startDateTo bounds the window server-side.
  const listUrl =
    `${API}/event-instance?regionOrgId=${rid}` +
    `&startDate=${encodeURIComponent(startDate)}` +
    `&startDateTo=${encodeURIComponent(endDate)}`;
  const namesUrl = `${API}/event-instance/without-q?regionOrgId=${rid}&notPostedOnly=false&limit=500`;
  const schedUrl = `${API}/event-instance/calendar-home-schedule?regionOrgId=${rid}&userId=1&startDate=${todayStr}&limit=150`;

  let rows, namesRaw, schedRaw;
  try {
    [rows, namesRaw, schedRaw] = await Promise.all([
      fetchAllPages(listUrl, authHeaders, limit),
      fetchJson(namesUrl, authHeaders).catch(() => null), // names are a nicety, not required
      fetchJson(schedUrl, authHeaders).catch(() => null),
    ]);
  } catch (err) {
    return json(502, { error: 'Could not reach F3 Nation API: ' + err.message });
  }

  // orgId -> AO name map, merged from both the past (without-q) and the
  // upcoming schedule so nearly every active AO resolves to a clean name.
  const orgName = {};
  for (const r of [...pickRows(namesRaw), ...pickRows(schedRaw)]) {
    if (r && r.orgId != null && r.orgName) orgName[r.orgId] = r.orgName;
  }

  // --- keep only counted (backblasted) events in the window ---------------
  const posted = rows.filter((e) => {
    if (!e || !e.startDate) return false;
    if (e.startDate < startDate || e.startDate > endDate) return false;
    return numOrNull(e.paxCount) != null; // a count means a backblast is in
  });

  // --- shape into days -> AO rows -----------------------------------------
  const byDate = {};
  for (const e of posted) {
    const pax = numOrNull(e.paxCount);
    const fng = numOrNull(e.fngCount);
    const ao = orgName[e.orgId] || cleanName(e.name) || 'Unknown AO';
    (byDate[e.startDate] = byDate[e.startDate] || []).push({
      id: e.id,
      ao,
      orgId: e.orgId ?? null,
      startTime: e.startTime || null,
      pax,
      fng: fng || 0,
      hasCount: pax != null,
    });
  }

  const days = Object.keys(byDate)
    .sort((a, b) => b.localeCompare(a))
    .map((d) => {
      const events = byDate[d].sort((a, b) =>
        (b.pax || 0) - (a.pax || 0) ||                         // most PAX first
        (a.startTime || '').localeCompare(b.startTime || '') || // then earliest
        a.ao.localeCompare(b.ao)                                // then A–Z
      );
      return {
        date: d,
        aoCount: events.length,
        paxTotal: events.reduce((s, e) => s + (e.pax || 0), 0),
        fngTotal: events.reduce((s, e) => s + (e.fng || 0), 0),
        allCounted: true,
        events,
      };
    });

  return json(
    200,
    {
      regionOrgId: String(regionId),
      today: todayStr,
      startDate,
      endDate,
      single: isSingle,
      dayCount: days.length,
      apiReturned: rows.length,
      days,
    },
    1800
  );
};

function pickRows(raw) {
  if (!raw) return [];
  return Array.isArray(raw) ? raw : (raw.eventInstances || raw.events || raw.items || raw.data || []);
}

// Some backblast instances are titled (e.g. "Backblast! Stampede 7/13/2026"
// or "Turning Point Wheel of Death"). When we have no orgName to fall back
// on, tidy the raw event name into something closer to an AO label.
function cleanName(name) {
  if (!name) return null;
  return String(name)
    .replace(/^back\s*blast[:!\s-]*/i, '')      // drop a leading "Backblast!"
    .replace(/\s+\d{1,2}\/\d{1,2}\/\d{2,4}\s*$/, '') // drop a trailing date
    .trim() || null;
}

// F3 Nation caps pageSize at 100 (pageIndex is zero-based). Pull pages until
// a short page, the reported totalCount, or `max` rows — whichever is first.
const PAGE_SIZE = 100;
async function fetchAllPages(url, headers, max) {
  const all = [];
  for (let pageIndex = 0; all.length < max; pageIndex++) {
    const raw = await fetchJson(`${url}&pageSize=${PAGE_SIZE}&pageIndex=${pageIndex}`, headers);
    const page = pickRows(raw);
    all.push(...page);
    const total = raw && Number.isFinite(raw.totalCount) ? raw.totalCount : null;
    if (page.length < PAGE_SIZE || (total != null && all.length >= total)) break;
  }
  return all.slice(0, max);
}

async function fetchJson(url, headers) {
  const r = await fetch(url, { headers });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

function numOrNull(v) {
  const n = typeof v === 'number' ? v : parseInt(v, 10);
  return Number.isFinite(n) ? n : null;
}

function json(statusCode, body, maxAge) {
  const headers = {
    'Content-Type': 'application/json',
    'Access-Control-Allow-Origin': '*',
  };
  if (maxAge) headers['Cache-Control'] = `public, max-age=${maxAge}`;
  return { statusCode, headers, body: JSON.stringify(body) };
}
