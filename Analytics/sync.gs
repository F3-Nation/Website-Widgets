// ============================================================
// F3 Nation Analytics Sync — Google Apps Script
// ============================================================
// Pulls data from BigQuery and pushes it to Supabase.
// Run syncAll() on a daily trigger to keep everything fresh.
//
// SETUP ORDER:
//   1. Create a Google Cloud project with BigQuery access
//   2. Create a Supabase project and run supabase-setup.sql
//   3. Create a new Google Apps Script project at script.google.com
//   4. Enable the BigQuery API: Services → BigQuery API → Add
//   5. Paste this entire file into the script editor
//   6. Update the CONFIG section below with your values
//   7. Run syncAll() manually once to verify everything works
//   8. Set up a daily time-based trigger on syncAll()
// ============================================================

// ── CONFIG — update these values ─────────────────────────────────────────
const SUPABASE_URL  = 'https://YOUR_PROJECT.supabase.co';   // ← Supabase project URL
const SUPABASE_KEY  = 'YOUR_SUPABASE_SERVICE_KEY';          // ← service_role key
const BQ_PROJECT    = 'f3data';                              // ← BigQuery project ID
const REGION_ID     = 0;                                     // ← your F3 Nation region orgId
const F3_API_TOKEN  = 'f3_YOUR_BEARER_TOKEN';               // ← F3 Nation bearer token
const WP_BASE_URL   = 'https://yoursite.com';               // ← your WordPress site URL
const WP_PASSCODE   = 'YourAdminPasscode';                  // ← your WP admin passcode
// ── END CONFIG ────────────────────────────────────────────────────────────

// ============================================================
// UTILITIES
// ============================================================

/**
 * Remove parenthetical suffixes and trailing role tags from F3 handles.
 * e.g. "Deflated (Site Q)" → "Deflated"
 */
function cleanHandle(handle) {
  if (!handle) return 'Unknown';
  return handle
    .replace(/\s*\(.*?\)\s*/g, '')
    .replace(/\s*-\s*\S+$/, '')
    .trim();
}

/**
 * Run a BigQuery SQL query and return results as an array of objects.
 * Requires BigQuery API enabled in Apps Script Services.
 */
function bqQuery(sql) {
  const job = BigQuery.Jobs.query(
    { query: sql, useLegacySql: false, timeoutMs: 60000 },
    BQ_PROJECT
  );
  if (!job.jobComplete) throw new Error('BigQuery query timed out after 60 seconds');
  const fields = job.schema.fields.map(f => f.name);
  return (job.rows || []).map(row =>
    Object.fromEntries(fields.map((f, j) => [f, row.f[j]?.v ?? null]))
  );
}

/**
 * Upsert rows into a Supabase table in batches of 500.
 */
function supabaseUpsert(table, rows) {
  if (!rows.length) { Logger.log(table + ': 0 rows, skipping'); return; }
  for (let i = 0; i < rows.length; i += 500) {
    const resp = UrlFetchApp.fetch(`${SUPABASE_URL}/rest/v1/${table}`, {
      method:  'POST',
      headers: {
        'apikey':        SUPABASE_KEY,
        'Authorization': `Bearer ${SUPABASE_KEY}`,
        'Content-Type':  'application/json',
        'Prefer':        'resolution=merge-duplicates',
      },
      payload:            JSON.stringify(rows.slice(i, i + 500)),
      muteHttpExceptions: true,
    });
    Utilities.sleep(500);
    const code = resp.getResponseCode();
    const body = (code !== 200 && code !== 201) ? ' — ' + resp.getContentText().slice(0, 200) : '';
    Logger.log(`${table} batch ${Math.floor(i/500)+1}: HTTP ${code}${body}`);
  }
}

// ============================================================
// SYNC FUNCTIONS
// ============================================================

/**
 * syncPaxStats() → Supabase table: pax_stats
 * Career stats for every active PAX in your region.
 * Used by: F3 PAX Stats plugin leaderboards and PAX cards.
 */
function syncPaxStats() {
  const year = new Date().getFullYear();
  Logger.log('Syncing PAX stats...');

  const rows = bqQuery(`
    SELECT
      a.user_id,
      a.f3_name                                                                            AS handle,
      a.avatar_url,
      COUNT(*)                                                                             AS workout_count,
      COUNT(CASE WHEN EXTRACT(YEAR FROM a.start_date) = ${year} THEN 1 END)               AS workout_count_ytd,
      SUM(a.q_ind)                                                                         AS q_count,
      SUM(CASE WHEN EXTRACT(YEAR FROM a.start_date) = ${year} THEN a.q_ind   ELSE 0 END)  AS q_count_ytd,
      SUM(a.coq_ind)                                                                       AS coq_count,
      MAX(a.start_date)                                                                    AS last_attended,
      APPROX_TOP_COUNT(e.ao_name, 1)[OFFSET(0)].value                                     AS home_ao,
      RANK() OVER (ORDER BY
        SUM(CASE WHEN EXTRACT(YEAR FROM a.start_date) = ${year} THEN a.q_ind ELSE 0 END) DESC,
        SUM(a.q_ind) DESC
      )                                                                                    AS rank
    FROM \`f3data.analytics.attendance_info\` a
    JOIN \`f3data.analytics.event_info\`      e ON e.id = a.event_instance_id
    WHERE e.region_org_id = ${REGION_ID} AND a.user_statusa = 'active'
    GROUP BY a.user_id, a.f3_name, a.avatar_url
    ORDER BY workout_count DESC
  `);

  supabaseUpsert('pax_stats', rows.map(r => ({
    pax_id:            parseInt(r.user_id),
    handle:            cleanHandle(r.handle),
    avatar_url:        r.avatar_url        || null,
    workout_count:     parseInt(r.workout_count)     || 0,
    workout_count_ytd: parseInt(r.workout_count_ytd) || 0,
    q_count:           parseInt(r.q_count)            || 0,
    q_count_ytd:       parseInt(r.q_count_ytd)        || 0,
    coq_count:         parseInt(r.coq_count)          || 0,
    last_attended:     r.last_attended     || null,
    home_ao:           r.home_ao           || null,
    rank:              parseInt(r.rank)     || null,
    is_active_roster:  r.last_attended
                         ? (new Date() - new Date(r.last_attended)) < (548 * 86400000)
                         : false,
    synced_at:         new Date().toISOString(),
  })));
  Logger.log(`PAX stats: ${rows.length} rows`);
}

/**
 * syncRegionStats() → Supabase table: bq_region_stats
 * Region-wide aggregate stats.
 * Used by: region dashboard and homepage stats display.
 */
function syncRegionStats() {
  Logger.log('Syncing region stats...');

  const statsRows = bqQuery(`
    SELECT
      COUNT(DISTINCT e.id)                                               AS total_workouts,
      COUNT(*)                                                           AS total_posts,
      SUM(e.pax_count)                                                   AS total_pax,
      SUM(e.fng_count)                                                   AS total_fngs,
      COUNT(DISTINCT e.ao_org_id)                                        AS ao_count,
      COUNT(DISTINCT a.user_id)                                          AS active_pax,
      ROUND(AVG(e.pax_count), 1)                                         AS avg_pax,
      COUNT(DISTINCT CASE WHEN a.q_ind = 1 THEN a.user_id END)          AS unique_qs
    FROM \`f3data.analytics.event_info\`      e
    JOIN \`f3data.analytics.attendance_info\` a ON a.event_instance_id = e.id
    WHERE e.region_org_id = ${REGION_ID}
  `);

  const dayRows = bqQuery(`
    SELECT
      FORMAT_DATE('%A', e.start_date) AS day_name,
      COUNT(*)                         AS total_posts,
      COUNT(DISTINCT e.id)             AS total_workouts,
      ROUND(AVG(e.pax_count), 1)       AS avg_pax
    FROM \`f3data.analytics.event_info\` e
    WHERE e.region_org_id = ${REGION_ID} AND e.start_date IS NOT NULL
    GROUP BY day_name ORDER BY total_posts DESC
  `);

  const topAoRows = bqQuery(`
    SELECT e.ao_name, COUNT(DISTINCT e.id) AS total_workouts,
      ROUND(AVG(e.pax_count), 1) AS avg_pax, SUM(e.pax_count) AS total_posts
    FROM \`f3data.analytics.event_info\` e
    WHERE e.region_org_id = ${REGION_ID} AND e.ao_name IS NOT NULL
      AND e.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 8 WEEK)
    GROUP BY e.ao_name ORDER BY avg_pax DESC LIMIT 10
  `);

  const topWkRows = bqQuery(`
    SELECT e.start_date, e.ao_name, e.pax_count,
      ARRAY_AGG(a.f3_name ORDER BY a.q_ind DESC LIMIT 1)[OFFSET(0)] AS q_name
    FROM \`f3data.analytics.event_info\`      e
    JOIN \`f3data.analytics.attendance_info\` a ON a.event_instance_id = e.id
    WHERE e.region_org_id = ${REGION_ID} AND e.pax_count IS NOT NULL AND a.q_ind = 1
      AND EXTRACT(YEAR FROM e.start_date) = EXTRACT(YEAR FROM CURRENT_DATE())
      AND LOWER(e.ao_name) NOT LIKE '%convergence%'
    GROUP BY e.start_date, e.ao_name, e.pax_count
    ORDER BY e.pax_count DESC LIMIT 10
  `);

  const r = statsRows[0] || {};
  supabaseUpsert('bq_region_stats', [{
    id:                1,
    total_workouts:    parseInt(r.total_workouts) || 0,
    total_posts:       parseInt(r.total_posts)    || 0,
    total_pax:         parseInt(r.total_pax)      || 0,
    total_fngs:        parseInt(r.total_fngs)     || 0,
    ao_count:          parseInt(r.ao_count)        || 0,
    active_pax:        parseInt(r.active_pax)      || 0,
    avg_pax:           parseFloat(r.avg_pax)        || 0,
    unique_qs:         parseInt(r.unique_qs)        || 0,
    attendance_by_day: JSON.stringify(dayRows.map(d => ({ day: d.day_name, total_posts: parseInt(d.total_posts)||0, total_workouts: parseInt(d.total_workouts)||0, avg_pax: parseFloat(d.avg_pax)||0 }))),
    top_aos:           JSON.stringify(topAoRows.map(a => ({ ao_name: a.ao_name, total_workouts: parseInt(a.total_workouts)||0, avg_pax: parseFloat(a.avg_pax)||0, total_posts: parseInt(a.total_posts)||0 }))),
    top_workouts:      JSON.stringify(topWkRows.map(w => ({ date: w.start_date, ao_name: w.ao_name, pax_count: parseInt(w.pax_count)||0, q_name: cleanHandle(w.q_name||'') }))),
    synced_at:         new Date().toISOString(),
  }]);
  Logger.log('Region stats synced');
}

/**
 * syncAoStats() → Supabase table: ao_stats
 * AO health stats: avg PAX, trend, top Qs, weekly history.
 * Used by: F3 PAX Stats plugin [f3_ao_stats] shortcode.
 */
function syncAoStats() {
  Logger.log('Syncing AO stats...');

  const aoRows = bqQuery(`
    SELECT e.ao_org_id, e.ao_name,
      COUNT(DISTINCT e.id) AS total_workouts,
      ROUND(AVG(e.pax_count), 1) AS avg_pax,
      ROUND(AVG(CASE WHEN e.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 8 WEEK) THEN e.pax_count END), 1) AS avg_pax_recent,
      ROUND(AVG(CASE WHEN e.start_date BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL 16 WEEK) AND DATE_SUB(CURRENT_DATE(), INTERVAL 8 WEEK) THEN e.pax_count END), 1) AS avg_pax_prior,
      SUM(e.pax_count) AS total_pax_posts, MAX(e.start_date) AS last_workout
    FROM \`f3data.analytics.event_info\` e
    WHERE e.region_org_id = ${REGION_ID} AND e.ao_org_id IS NOT NULL AND e.ao_name IS NOT NULL
    GROUP BY e.ao_org_id, e.ao_name ORDER BY avg_pax_recent DESC
  `);

  const qRows = bqQuery(`
    SELECT e.ao_org_id, a.f3_name AS handle, SUM(a.q_ind) AS q_count
    FROM \`f3data.analytics.attendance_info\` a
    JOIN \`f3data.analytics.event_info\`      e ON e.id = a.event_instance_id
    WHERE e.region_org_id = ${REGION_ID} AND a.q_ind = 1 AND e.ao_org_id IS NOT NULL
    GROUP BY e.ao_org_id, a.f3_name
    QUALIFY RANK() OVER (PARTITION BY e.ao_org_id ORDER BY SUM(a.q_ind) DESC) <= 5
  `);

  const weeklyRows = bqQuery(`
    SELECT e.ao_org_id, DATE_TRUNC(e.start_date, WEEK) AS week,
      COUNT(DISTINCT e.id) AS workouts, ROUND(AVG(e.pax_count), 1) AS avg_pax, SUM(e.pax_count) AS total_pax
    FROM \`f3data.analytics.event_info\` e
    WHERE e.region_org_id = ${REGION_ID}
      AND e.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 52 WEEK) AND e.ao_org_id IS NOT NULL
    GROUP BY e.ao_org_id, week ORDER BY e.ao_org_id, week
  `);

  const topQsByAo = {};
  qRows.forEach(r => { if (!topQsByAo[r.ao_org_id]) topQsByAo[r.ao_org_id] = []; topQsByAo[r.ao_org_id].push({ handle: cleanHandle(r.handle), q_count: parseInt(r.q_count) }); });
  const weeklyByAo = {};
  weeklyRows.forEach(r => { if (!weeklyByAo[r.ao_org_id]) weeklyByAo[r.ao_org_id] = []; weeklyByAo[r.ao_org_id].push({ week: r.week, workouts: parseInt(r.workouts)||0, avg_pax: parseFloat(r.avg_pax)||0, total_pax: parseInt(r.total_pax)||0 }); });

  function healthStatus(recent, lastWorkout) {
    const daysSince = lastWorkout ? Math.floor((Date.now() - new Date(lastWorkout)) / 86400000) : 999;
    if (daysSince > 21) return 'attention';
    if (recent >= 15)   return 'thriving';
    if (recent >= 10)   return 'healthy';
    if (recent >= 6)    return 'watch';
    return 'attention';
  }

  supabaseUpsert('ao_stats', aoRows.map(r => {
    const recent = parseFloat(r.avg_pax_recent)||0, prior = parseFloat(r.avg_pax_prior)||0;
    return {
      ao_org_id: parseInt(r.ao_org_id), ao_name: r.ao_name,
      total_workouts: parseInt(r.total_workouts)||0, avg_pax: parseFloat(r.avg_pax)||0,
      avg_pax_recent: recent, avg_pax_prior: prior,
      trend_pct: prior > 0 ? Math.round(((recent-prior)/prior)*100) : 0,
      health_status: healthStatus(recent, r.last_workout),
      total_pax_posts: parseInt(r.total_pax_posts)||0, last_workout: r.last_workout||null,
      top_qs: JSON.stringify(topQsByAo[r.ao_org_id]||[]),
      weekly_data: JSON.stringify(weeklyByAo[r.ao_org_id]||[]),
      synced_at: new Date().toISOString(),
    };
  }));
  Logger.log(`AO stats: ${aoRows.length} AOs`);
}

/**
 * syncPaxAoBreakdown() → Supabase table: pax_ao_breakdown
 * Every PAX's post and Q count at every AO.
 * Used by: PAX detail panels, cold cases suspect logic.
 */
function syncPaxAoBreakdown() {
  Logger.log('Syncing PAX-AO breakdown...');
  const rows = bqQuery(`
    SELECT a.user_id, a.f3_name AS handle, e.ao_org_id, e.ao_name,
      COUNT(*) AS workout_count, SUM(a.q_ind) AS q_count, MAX(e.start_date) AS last_attended
    FROM \`f3data.analytics.attendance_info\` a
    JOIN \`f3data.analytics.event_info\`      e ON e.id = a.event_instance_id
    WHERE e.region_org_id = ${REGION_ID} AND a.user_statusa = 'active'
      AND e.ao_org_id IS NOT NULL AND e.ao_name IS NOT NULL
    GROUP BY a.user_id, a.f3_name, e.ao_org_id, e.ao_name
  `);
  supabaseUpsert('pax_ao_breakdown', rows.map(r => ({
    pax_id: parseInt(r.user_id), ao_org_id: parseInt(r.ao_org_id), ao_name: r.ao_name,
    handle: cleanHandle(r.handle), workout_count: parseInt(r.workout_count)||0,
    q_count: parseInt(r.q_count)||0, last_attended: r.last_attended||null,
    synced_at: new Date().toISOString(),
  })));
  Logger.log(`PAX-AO: ${rows.length} rows`);
}

/**
 * syncStatsLeaders() → Supabase table: stats_leaders
 * Leaderboard records: biggest week, longest streak, etc.
 * Used by: F3 PAX Stats plugin records display.
 */
function syncStatsLeaders() {
  Logger.log('Syncing stats leaders...');
  const all = [];

  bqQuery(`SELECT handle, avatar_url, week, post_count FROM (SELECT a.f3_name AS handle, a.avatar_url, DATE_TRUNC(a.start_date, WEEK) AS week, COUNT(*) AS post_count, RANK() OVER (ORDER BY COUNT(*) DESC) AS rnk FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} GROUP BY a.f3_name, a.avatar_url, week) WHERE rnk <= 10 ORDER BY post_count DESC`)
    .forEach((r,i) => all.push({ category:'most_posts_one_week', rank:i+1, handle:cleanHandle(r.handle), avatar_url:r.avatar_url||null, value:parseInt(r.post_count), detail:JSON.stringify({week:r.week}), synced_at:new Date().toISOString() }));

  bqQuery(`SELECT handle, avatar_url, week, q_count FROM (SELECT a.f3_name AS handle, a.avatar_url, DATE_TRUNC(a.start_date, WEEK) AS week, SUM(a.q_ind) AS q_count, RANK() OVER (ORDER BY SUM(a.q_ind) DESC) AS rnk FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} AND a.q_ind = 1 GROUP BY a.f3_name, a.avatar_url, week HAVING SUM(a.q_ind) > 1) WHERE rnk <= 10 ORDER BY q_count DESC`)
    .forEach((r,i) => all.push({ category:'most_qs_one_week', rank:i+1, handle:cleanHandle(r.handle), avatar_url:r.avatar_url||null, value:parseInt(r.q_count), detail:JSON.stringify({week:r.week}), synced_at:new Date().toISOString() }));

  bqQuery(`SELECT a.f3_name AS handle, a.avatar_url, COUNT(DISTINCT e.ao_org_id) AS unique_aos FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} AND a.q_ind = 1 AND e.ao_org_id IS NOT NULL GROUP BY a.f3_name, a.avatar_url ORDER BY unique_aos DESC LIMIT 10`)
    .forEach((r,i) => all.push({ category:'most_unique_ao_qs', rank:i+1, handle:cleanHandle(r.handle), avatar_url:r.avatar_url||null, value:parseInt(r.unique_aos), detail:'{}', synced_at:new Date().toISOString() }));

  bqQuery(`SELECT handle, avatar_url, ao_name, post_count FROM (SELECT a.f3_name AS handle, a.avatar_url, e.ao_name, COUNT(*) AS post_count, RANK() OVER (ORDER BY COUNT(*) DESC) AS rnk FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} AND e.ao_name IS NOT NULL GROUP BY a.f3_name, a.avatar_url, e.ao_name) WHERE rnk <= 10 ORDER BY post_count DESC`)
    .forEach((r,i) => all.push({ category:'most_posts_single_ao', rank:i+1, handle:cleanHandle(r.handle), avatar_url:r.avatar_url||null, value:parseInt(r.post_count), detail:JSON.stringify({ao_name:r.ao_name}), synced_at:new Date().toISOString() }));

  bqQuery(`SELECT user_id, handle, avatar_url, best_streak FROM (WITH daily AS (SELECT DISTINCT a.user_id, a.f3_name AS handle, a.avatar_url, a.start_date FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID}), gaps AS (SELECT *, DATE_DIFF(start_date, LAG(start_date) OVER (PARTITION BY user_id ORDER BY start_date), DAY) AS gap FROM daily), streak_groups AS (SELECT *, SUM(CASE WHEN gap > 1 OR gap IS NULL THEN 1 ELSE 0 END) OVER (PARTITION BY user_id ORDER BY start_date) AS grp FROM gaps), streak_lengths AS (SELECT user_id, handle, avatar_url, grp, COUNT(*) AS streak_len FROM streak_groups GROUP BY user_id, handle, avatar_url, grp) SELECT user_id, handle, avatar_url, MAX(streak_len) AS best_streak FROM streak_lengths GROUP BY user_id, handle, avatar_url) ORDER BY best_streak DESC LIMIT 10`)
    .forEach((r,i) => all.push({ category:'longest_streak', rank:i+1, handle:cleanHandle(r.handle), avatar_url:r.avatar_url||null, value:parseInt(r.best_streak), detail:'{}', synced_at:new Date().toISOString() }));

  supabaseUpsert('stats_leaders', all);
  Logger.log(`Stats leaders: ${all.length} rows`);
}

/**
 * syncKotterList() → Supabase table: kotter_list
 * At-risk PAX identified by posting patterns.
 * Used by: F3 Kotter List plugin.
 */
function syncKotterList() {
  Logger.log('Syncing Kotter list...');
  const rows = bqQuery(`
    SELECT * FROM (
      WITH
      recent AS (SELECT a.user_id, COUNT(*) AS posts_recent FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} AND a.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 4 WEEK) GROUP BY a.user_id),
      prior  AS (SELECT a.user_id, COUNT(*) AS posts_prior  FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} AND a.start_date BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL 8 WEEK) AND DATE_SUB(CURRENT_DATE(), INTERVAL 4 WEEK) GROUP BY a.user_id),
      totals AS (SELECT a.user_id, a.f3_name AS handle, a.avatar_url, COUNT(*) AS total_posts, MAX(a.start_date) AS last_post, ARRAY_AGG(e.ao_name ORDER BY a.start_date DESC LIMIT 1)[OFFSET(0)] AS last_ao FROM \`f3data.analytics.attendance_info\` a JOIN \`f3data.analytics.event_info\` e ON e.id = a.event_instance_id WHERE e.region_org_id = ${REGION_ID} GROUP BY a.user_id, a.f3_name, a.avatar_url)
      SELECT t.user_id, t.handle, t.avatar_url, t.total_posts, t.last_post, t.last_ao,
        DATE_DIFF(CURRENT_DATE(), t.last_post, DAY) AS days_since,
        COALESCE(r.posts_recent, 0) AS posts_recent_4wk, COALESCE(p.posts_prior, 0) AS posts_prior_4wk,
        CASE
          WHEN t.total_posts BETWEEN 1 AND 5 AND DATE_DIFF(CURRENT_DATE(), t.last_post, DAY) >= 14 THEN 'new_drop'
          WHEN DATE_DIFF(CURRENT_DATE(), t.last_post, DAY) >= 60 AND DATE_DIFF(CURRENT_DATE(), t.last_post, DAY) < 180 AND t.total_posts >= 10 THEN 'inactive'
          WHEN DATE_DIFF(CURRENT_DATE(), t.last_post, DAY) >= 21 AND COALESCE(r.posts_recent, 0) < COALESCE(p.posts_prior, 0) * 0.5 AND COALESCE(p.posts_prior, 0) >= 4 THEN 'fading'
          WHEN DATE_DIFF(CURRENT_DATE(), t.last_post, DAY) <= 14 AND COALESCE(p.posts_prior, 0) = 0 AND t.total_posts >= 5 THEN 'returning'
          ELSE NULL
        END AS kotter_status
      FROM totals t LEFT JOIN recent r ON r.user_id = t.user_id LEFT JOIN prior p ON p.user_id = t.user_id
    ) WHERE kotter_status IS NOT NULL ORDER BY days_since DESC
  `);
  supabaseUpsert('kotter_list', rows.map(r => ({
    pax_id: parseInt(r.user_id), handle: cleanHandle(r.handle), avatar_url: r.avatar_url||null,
    total_posts: parseInt(r.total_posts)||0, last_post: r.last_post||null, last_ao: r.last_ao||null,
    days_since: parseInt(r.days_since)||0, posts_recent_4wk: parseInt(r.posts_recent_4wk)||0,
    posts_prior_4wk: parseInt(r.posts_prior_4wk)||0, kotter_status: r.kotter_status,
    synced_at: new Date().toISOString(),
  })));
  Logger.log(`Kotter list: ${rows.length} PAX flagged`);
}

/**
 * syncScheduledQs() → Supabase table: scheduled_qs
 * Upcoming events with planned Q assignments.
 * Used by: Cold Cases feature to identify Q suspects.
 */
function syncScheduledQs() {
  Logger.log('Syncing scheduled Qs...');
  const today = new Date().toISOString().split('T')[0];
  const resp = UrlFetchApp.fetch(
    `https://api.f3nation.com/v1/event-instance/calendar-home-schedule?regionOrgId=${REGION_ID}&userId=1&startDate=${today}&limit=200`,
    { headers: { 'Authorization': `Bearer ${F3_API_TOKEN}`, 'client': 'f3-analytics-sync' }, muteHttpExceptions: true }
  );
  const events = (JSON.parse(resp.getContentText()).events || []).filter(e => e.plannedQs);
  if (!events.length) { Logger.log('No scheduled Qs found'); return; }
  supabaseUpsert('scheduled_qs', events.map(e => ({
    event_instance_id: e.id, ao_name: e.orgName||e.name, start_date: e.startDate,
    start_time: e.startTime||null, planned_q: e.plannedQs, org_id: e.orgId,
    synced_at: new Date().toISOString(),
  })));
  Logger.log(`Scheduled Qs: ${events.length} events with Qs`);
}

/**
 * syncUpcoming() → Supabase table: upcoming_events (via WordPress proxy)
 * Triggers WordPress to sync special events from F3 Nation calendar.
 * Requires WordPress with F3 Nation API plugin installed.
 */
function syncUpcoming() {
  Logger.log('Syncing upcoming events...');
  const tokenResp = UrlFetchApp.fetch(`${WP_BASE_URL}/wp-json/f3/v1/admin-verify`, {
    method: 'post', contentType: 'application/json',
    payload: JSON.stringify({ passcode: WP_PASSCODE }), muteHttpExceptions: true,
  });
  const tokenData = JSON.parse(tokenResp.getContentText());
  if (!tokenData.token) { Logger.log('syncUpcoming: failed to get admin token'); return; }
  const resp = UrlFetchApp.fetch(`${WP_BASE_URL}/wp-json/f3/v1/sync-upcoming`, {
    method: 'post', contentType: 'application/json',
    headers: { 'X-F3-Admin-Token': tokenData.token }, muteHttpExceptions: true,
  });
  const data = JSON.parse(resp.getContentText());
  Logger.log(`syncUpcoming: ${data.synced ?? 'error'} events synced`);
}

/**
 * syncTravelData() → Supabase table: travel_locations
 * Where your PAX have posted at other regions.
 * Used by: F3 Travel Map plugin.
 */
function syncTravelData() {
  Logger.log('Syncing travel data...');
  const rows = bqQuery(`
    SELECT e.location_name, e.location_latitude AS latitude, e.location_longitude AS longitude,
      e.region_name, COUNT(DISTINCT a.user_id) AS pax_count, COUNT(*) AS total_posts
    FROM \`f3data.analytics.attendance_info\` a
    JOIN \`f3data.analytics.event_info\`      e ON e.id = a.event_instance_id
    WHERE a.user_id IN (
      SELECT DISTINCT a2.user_id FROM \`f3data.analytics.attendance_info\` a2
      JOIN \`f3data.analytics.event_info\` e2 ON e2.id = a2.event_instance_id
      WHERE e2.region_org_id = ${REGION_ID}
    )
    AND e.region_org_id != ${REGION_ID} AND e.location_latitude IS NOT NULL
    AND e.location_name IS NOT NULL AND TRIM(e.location_name) != ''
    GROUP BY e.location_name, e.location_latitude, e.location_longitude, e.region_name
    ORDER BY pax_count DESC
  `);
  if (!rows.length) { Logger.log('No travel rows'); return; }
  UrlFetchApp.fetch(`${SUPABASE_URL}/rest/v1/travel_locations?id=gte.0`, {
    method: 'DELETE', headers: { 'apikey': SUPABASE_KEY, 'Authorization': `Bearer ${SUPABASE_KEY}` }, muteHttpExceptions: true,
  });
  supabaseUpsert('travel_locations', rows.map(r => ({
    location_name: r.location_name, latitude: parseFloat(r.latitude)||0,
    longitude: parseFloat(r.longitude)||0, region_name: r.region_name||'',
    pax_count: parseInt(r.pax_count)||1, total_posts: parseInt(r.total_posts)||1,
    synced_at: new Date().toISOString(),
  })));
  Logger.log(`Travel data: ${rows.length} locations`);
}

/**
 * syncPaxActivity() → Supabase table: pax_activity
 * Daily post counts per PAX for activity heatmap charts.
 */
function syncPaxActivity() {
  Logger.log('Syncing PAX activity...');
  const rows = bqQuery(`
    SELECT a.user_id AS pax_id, e.start_date AS post_date, COUNT(*) AS post_count
    FROM \`f3data.analytics.attendance_info\` a
    JOIN \`f3data.analytics.event_info\`      e ON e.id = a.event_instance_id
    WHERE e.region_org_id = ${REGION_ID}
      AND e.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 YEAR)
    GROUP BY a.user_id, e.start_date
  `);
  if (!rows.length) { Logger.log('No activity rows'); return; }
  supabaseUpsert('pax_activity', rows.map(r => ({
    pax_id: parseInt(r.pax_id), post_date: r.post_date, post_count: parseInt(r.post_count)||1,
  })));
  Logger.log(`PAX activity: ${rows.length} rows`);
}

// ============================================================
// MAIN ENTRY POINT
// ============================================================

/**
 * syncAll() — Run this on a daily trigger.
 * Order matters — pax_stats must run before kotter_list.
 */
function syncAll() {
  Logger.log('=== F3 Analytics Sync starting ===');
  const start = Date.now();
  syncPaxStats();
  syncRegionStats();
  syncAoStats();
  syncPaxAoBreakdown();
  syncStatsLeaders();
  syncKotterList();
  syncScheduledQs();
  syncUpcoming();
  syncTravelData();
  syncPaxActivity();
  Logger.log(`=== All syncs complete in ${Math.round((Date.now()-start)/1000)}s ===`);
}
