-- ============================================================
-- F3 Nation Analytics — Supabase Database Setup
-- ============================================================
-- Run this entire file in your Supabase SQL editor once.
-- Go to: Supabase Dashboard → SQL Editor → New Query
-- Paste everything below and click Run.
--
-- Tables are created in dependency order.
-- Safe to run multiple times (uses IF NOT EXISTS).
-- ============================================================


-- ── 1. pax_stats ─────────────────────────────────────────────
-- Career stats for every PAX in your region.
-- Primary key: pax_id (F3 Nation user ID)
-- Synced by: syncPaxStats()
-- Used by: F3 PAX Stats plugin ([f3_pax_stats], [f3_pax])

CREATE TABLE IF NOT EXISTS pax_stats (
  pax_id            INTEGER PRIMARY KEY,
  handle            TEXT NOT NULL,
  avatar_url        TEXT,
  workout_count     INTEGER DEFAULT 0,
  workout_count_ytd INTEGER DEFAULT 0,
  q_count           INTEGER DEFAULT 0,
  q_count_ytd       INTEGER DEFAULT 0,
  coq_count         INTEGER DEFAULT 0,
  last_attended     DATE,
  home_ao           TEXT,
  rank              INTEGER,
  is_active_roster  BOOLEAN DEFAULT false,
  synced_at         TIMESTAMPTZ DEFAULT now()
);

-- Indexes for common queries
CREATE INDEX IF NOT EXISTS pax_stats_handle_idx       ON pax_stats (handle);
CREATE INDEX IF NOT EXISTS pax_stats_workout_idx      ON pax_stats (workout_count DESC);
CREATE INDEX IF NOT EXISTS pax_stats_last_attended_idx ON pax_stats (last_attended DESC);


-- ── 2. pax_overrides ─────────────────────────────────────────
-- Admin-editable fields for PAX (bio, photo overrides, etc.)
-- Not synced from BigQuery — admin manages these manually.

CREATE TABLE IF NOT EXISTS pax_overrides (
  pax_id     INTEGER PRIMARY KEY REFERENCES pax_stats(pax_id),
  bio        TEXT,
  photo_url  TEXT,
  title      TEXT,
  updated_at TIMESTAMPTZ DEFAULT now()
);


-- ── 3. pax_ao_breakdown ──────────────────────────────────────
-- Per-PAX per-AO post and Q counts.
-- Primary key: (pax_id, ao_org_id) composite
-- Synced by: syncPaxAoBreakdown()
-- Used by: PAX detail panels, cold cases suspect logic

CREATE TABLE IF NOT EXISTS pax_ao_breakdown (
  pax_id        INTEGER NOT NULL,
  ao_org_id     INTEGER NOT NULL,
  ao_name       TEXT,
  handle        TEXT,
  workout_count INTEGER DEFAULT 0,
  q_count       INTEGER DEFAULT 0,
  last_attended DATE,
  synced_at     TIMESTAMPTZ DEFAULT now(),
  PRIMARY KEY (pax_id, ao_org_id)
);

CREATE INDEX IF NOT EXISTS pax_ao_pax_idx ON pax_ao_breakdown (pax_id);
CREATE INDEX IF NOT EXISTS pax_ao_ao_idx  ON pax_ao_breakdown (ao_org_id);


-- ── 4. pax_activity ──────────────────────────────────────────
-- Daily post counts per PAX for the past 12 months.
-- Used for activity heatmap charts.
-- Synced by: syncPaxActivity()

CREATE TABLE IF NOT EXISTS pax_activity (
  pax_id     INTEGER NOT NULL,
  post_date  DATE    NOT NULL,
  post_count INTEGER DEFAULT 1,
  PRIMARY KEY (pax_id, post_date)
);

CREATE INDEX IF NOT EXISTS pax_activity_pax_idx  ON pax_activity (pax_id);
CREATE INDEX IF NOT EXISTS pax_activity_date_idx ON pax_activity (post_date DESC);


-- ── 5. ao_stats ──────────────────────────────────────────────
-- AO health stats: avg PAX, trend, top Qs, weekly history.
-- Primary key: ao_org_id (F3 Nation AO ID)
-- Synced by: syncAoStats()
-- Used by: F3 PAX Stats plugin ([f3_ao_stats])

CREATE TABLE IF NOT EXISTS ao_stats (
  ao_org_id       INTEGER PRIMARY KEY,
  ao_name         TEXT,
  total_workouts  INTEGER DEFAULT 0,
  avg_pax         DECIMAL(5,1),
  avg_pax_recent  DECIMAL(5,1),
  avg_pax_prior   DECIMAL(5,1),
  trend_pct       INTEGER DEFAULT 0,
  health_status   TEXT,   -- 'thriving' | 'healthy' | 'watch' | 'attention'
  total_pax_posts INTEGER DEFAULT 0,
  last_workout    DATE,
  top_qs          JSONB DEFAULT '[]',    -- [{handle, q_count}]
  weekly_data     JSONB DEFAULT '[]',    -- [{week, workouts, avg_pax, total_pax}]
  synced_at       TIMESTAMPTZ DEFAULT now()
);


-- ── 6. bq_region_stats ───────────────────────────────────────
-- Single-row region summary (id=1 always).
-- Synced by: syncRegionStats()
-- Used by: homepage stats, region dashboard

CREATE TABLE IF NOT EXISTS bq_region_stats (
  id                INTEGER PRIMARY KEY DEFAULT 1,
  total_workouts    INTEGER DEFAULT 0,
  total_posts       INTEGER DEFAULT 0,
  total_pax         INTEGER DEFAULT 0,
  total_fngs        INTEGER DEFAULT 0,
  ao_count          INTEGER DEFAULT 0,
  active_pax        INTEGER DEFAULT 0,
  avg_pax           DECIMAL(5,1),
  unique_qs         INTEGER DEFAULT 0,
  attendance_by_day JSONB DEFAULT '[]',  -- [{day, total_posts, total_workouts, avg_pax}]
  top_aos           JSONB DEFAULT '[]',  -- [{ao_name, total_workouts, avg_pax, total_posts}]
  top_workouts      JSONB DEFAULT '[]',  -- [{date, ao_name, pax_count, q_name}]
  synced_at         TIMESTAMPTZ DEFAULT now()
);

INSERT INTO bq_region_stats (id) VALUES (1) ON CONFLICT DO NOTHING;


-- ── 7. stats_leaders ─────────────────────────────────────────
-- Leaderboard records across 5 categories.
-- Primary key: (category, rank) composite
-- Synced by: syncStatsLeaders()
-- Used by: F3 PAX Stats plugin records display

CREATE TABLE IF NOT EXISTS stats_leaders (
  category   TEXT    NOT NULL,
  rank       INTEGER NOT NULL,
  handle     TEXT,
  avatar_url TEXT,
  value      INTEGER,
  detail     JSONB DEFAULT '{}',
  synced_at  TIMESTAMPTZ DEFAULT now(),
  PRIMARY KEY (category, rank)
);

-- Categories: most_posts_one_week | most_qs_one_week |
--             most_unique_ao_qs | most_posts_single_ao | longest_streak


-- ── 8. kotter_list ───────────────────────────────────────────
-- PAX identified as at-risk of dropping out.
-- Primary key: pax_id
-- Synced by: syncKotterList() (BigQuery logic identifies them)
-- Admin fields (outreach_tagged etc.) are preserved on sync
-- Used by: F3 Kotter List plugin

CREATE TABLE IF NOT EXISTS kotter_list (
  pax_id                 INTEGER PRIMARY KEY,
  handle                 TEXT NOT NULL,
  avatar_url             TEXT,
  total_posts            INTEGER DEFAULT 0,
  last_post              DATE,
  last_ao                TEXT,
  days_since             INTEGER DEFAULT 0,
  posts_recent_4wk       INTEGER DEFAULT 0,
  posts_prior_4wk        INTEGER DEFAULT 0,
  kotter_status          TEXT,   -- 'new_drop' | 'inactive' | 'fading' | 'returning'
  -- Admin-only fields — never overwritten by sync:
  outreach_tagged        BOOLEAN DEFAULT false,
  outreach_note          TEXT,
  expected_return        DATE,
  kotter_status_override TEXT,
  synced_at              TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS kotter_days_idx ON kotter_list (days_since DESC);


-- ── 9. scheduled_qs ──────────────────────────────────────────
-- Upcoming events with planned Q assignments.
-- Primary key: event_instance_id
-- Synced by: syncScheduledQs()
-- Used by: Cold Cases feature to identify Q suspects

CREATE TABLE IF NOT EXISTS scheduled_qs (
  event_instance_id INTEGER PRIMARY KEY,
  ao_name           TEXT,
  start_date        DATE,
  start_time        TEXT,
  planned_q         TEXT,
  org_id            INTEGER,
  synced_at         TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS scheduled_qs_date_idx ON scheduled_qs (start_date);


-- ── 10. upcoming_events ──────────────────────────────────────
-- Special events (convergences, CSAUPs, races) from F3 Nation.
-- Synced by: syncUpcoming() via WordPress proxy.
-- Admin enrichment fields are preserved on sync.
-- Used by: F3 Upcoming Events plugin

CREATE TABLE IF NOT EXISTS upcoming_events (
  id                  UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  title               TEXT,
  event_date          DATE,
  event_time          TEXT,
  event_type          TEXT,
  description         TEXT,
  location            TEXT,
  q_name              TEXT,
  is_published        BOOLEAN DEFAULT true,
  -- F3 Nation sync fields (overwritten on each sync):
  f3nation_event_id   INTEGER UNIQUE,
  synced_from_api     BOOLEAN DEFAULT false,
  api_data            JSONB,
  last_synced_at      TIMESTAMPTZ,
  -- Admin enrichment fields (NEVER overwritten by sync):
  custom_title        TEXT,
  detail_override     TEXT,
  photo_urls          JSONB DEFAULT '[]',
  external_url        TEXT,
  featured            BOOLEAN DEFAULT false,
  created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX IF NOT EXISTS upcoming_events_date_idx      ON upcoming_events (event_date);
CREATE INDEX IF NOT EXISTS upcoming_events_f3nation_idx  ON upcoming_events (f3nation_event_id)
  WHERE f3nation_event_id IS NOT NULL;


-- ── 11. travel_locations ─────────────────────────────────────
-- Locations where your PAX have posted at other regions.
-- Rebuilt from scratch on each sync.
-- Used by: F3 Travel Map plugin

CREATE TABLE IF NOT EXISTS travel_locations (
  id            SERIAL PRIMARY KEY,
  location_name TEXT NOT NULL,
  latitude      DECIMAL(9,6),
  longitude     DECIMAL(9,6),
  region_name   TEXT,
  pax_count     INTEGER DEFAULT 1,
  total_posts   INTEGER DEFAULT 1,
  synced_at     TIMESTAMPTZ DEFAULT now()
);


-- ── 12. Cold Cases tables ─────────────────────────────────────
-- Used by: F3 Cold Cases plugin

CREATE TABLE IF NOT EXISTS pending_backblasts (
  id                UUID DEFAULT gen_random_uuid() PRIMARY KEY,
  event_instance_id INTEGER NOT NULL,
  event_date        DATE,
  ao_name           TEXT,
  backblast_text    TEXT,
  pax_list          JSONB DEFAULT '[]',
  photo_url         TEXT,
  submitted_by      TEXT,
  submitted_at      TIMESTAMPTZ DEFAULT now(),
  status            TEXT DEFAULT 'pending',  -- 'pending' | 'approved' | 'rejected'
  approved_at       TIMESTAMPTZ,
  rejection_note    TEXT
);

CREATE TABLE IF NOT EXISTS dismissed_cases (
  event_instance_id INTEGER PRIMARY KEY,
  dismissed_by      TEXT,
  reason            TEXT,
  dismissed_at      TIMESTAMPTZ DEFAULT now()
);


-- ============================================================
-- ROW LEVEL SECURITY (RLS)
-- ============================================================
-- By default Supabase enables RLS on new tables.
-- The service role key bypasses RLS (used by Apps Script).
-- The anon key respects RLS (used by public-facing plugins).
--
-- Enable public read access for tables used by front-end:

ALTER TABLE pax_stats         ENABLE ROW LEVEL SECURITY;
ALTER TABLE ao_stats          ENABLE ROW LEVEL SECURITY;
ALTER TABLE bq_region_stats   ENABLE ROW LEVEL SECURITY;
ALTER TABLE stats_leaders     ENABLE ROW LEVEL SECURITY;
ALTER TABLE travel_locations  ENABLE ROW LEVEL SECURITY;
ALTER TABLE upcoming_events   ENABLE ROW LEVEL SECURITY;
ALTER TABLE pax_ao_breakdown  ENABLE ROW LEVEL SECURITY;
ALTER TABLE pax_activity      ENABLE ROW LEVEL SECURITY;

-- Allow public read on front-facing tables
CREATE POLICY IF NOT EXISTS "Public read" ON pax_stats        FOR SELECT USING (true);
CREATE POLICY IF NOT EXISTS "Public read" ON ao_stats         FOR SELECT USING (true);
CREATE POLICY IF NOT EXISTS "Public read" ON bq_region_stats  FOR SELECT USING (true);
CREATE POLICY IF NOT EXISTS "Public read" ON stats_leaders    FOR SELECT USING (true);
CREATE POLICY IF NOT EXISTS "Public read" ON travel_locations FOR SELECT USING (true);
CREATE POLICY IF NOT EXISTS "Public read" ON upcoming_events  FOR SELECT USING (is_published = true);
CREATE POLICY IF NOT EXISTS "Public read" ON pax_ao_breakdown FOR SELECT USING (true);
CREATE POLICY IF NOT EXISTS "Public read" ON pax_activity     FOR SELECT USING (true);

-- kotter_list, pending_backblasts, dismissed_cases are admin-only
-- (accessed only via service role key from WordPress/Apps Script)
