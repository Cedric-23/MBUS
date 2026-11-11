# Backend API Specifications — Netflix Clone

*Source: [[Netflix Research]] (raw_sources/netflix-research.md) · Related: [[Index]] · [[Log]] · [[UI Components]]*

---

## 1. Database Schema (PostgreSQL + Supabase)

### 1.1 Core Tables

```sql
-- ============================================
-- EXTENSIONS
-- ============================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";  -- for fuzzy search
-- CREATE EXTENSION IF NOT EXISTS "vector"; -- for pgvector (recommendations Phase 3)

-- ============================================
-- USERS (extends Supabase auth.users)
-- ============================================
CREATE TABLE public.users (
  id uuid PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  email text UNIQUE NOT NULL,
  stripe_customer_id text UNIQUE,
  email_verified boolean DEFAULT false,
  marketing_consent boolean DEFAULT false,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

CREATE INDEX idx_users_stripe_customer ON public.users(stripe_customer_id);
CREATE INDEX idx_users_email ON public.users(email);

-- ============================================
-- PROFILES (per-user, max 5 per account)
-- ============================================
CREATE TABLE public.profiles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  name text NOT NULL,
  avatar_url text,
  maturity_rating text NOT NULL DEFAULT 'PG-13' 
    CHECK (maturity_rating IN ('G','PG','PG-13','R','NC-17')),
  is_kids boolean DEFAULT false,
  kids_pin_hash text,  -- bcrypt hash for kids mode exit
  language text DEFAULT 'en',
  autoplay_next boolean DEFAULT true,
  skip_intro boolean DEFAULT true,
  data_saver boolean DEFAULT false,  -- mobile only
  subtitle_appearance jsonb DEFAULT '{}',  -- custom subtitle styling
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now(),
  UNIQUE (user_id, name)
);

CREATE INDEX idx_profiles_user ON public.profiles(user_id);
CREATE INDEX idx_profiles_kids ON public.profiles(user_id) WHERE is_kids = true;

-- ============================================
-- CONTENT (movies, series, specials)
-- ============================================
CREATE TABLE public.content (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title text NOT NULL,
  original_title text,  -- for international content
  type text NOT NULL CHECK (type IN ('movie','series','special')),
  description text NOT NULL,
  short_description text,  -- max 200 chars for cards
  poster_url text NOT NULL,  -- 2:3 aspect, CDN URL
  backdrop_url text NOT NULL,  -- 16:9 aspect, CDN URL
  logo_url text,  -- transparent logo for hero
  trailer_url text,  -- HLS manifest for preview
  release_year int NOT NULL,
  release_date date,  -- precise release date
  duration_seconds int,  -- movies only
  maturity_rating text NOT NULL 
    CHECK (maturity_rating IN ('G','PG','PG-13','R','NC-17')),
  maturity_descriptors text[],  -- "Violence", "Language", etc.
  country_of_origin text[],  -- ISO 3166-1 alpha-2
  original_language text,  -- ISO 639-1
  tags text[],  -- "Oscar Winner", "Trending", "Netflix Original"
  cast jsonb DEFAULT '[]',  -- [{id, name, role, character, profile_url, order}]
  crew jsonb DEFAULT '[]',  -- [{id, name, role, department, order}]
  -- SEO / Discovery
  slug text UNIQUE NOT NULL,  -- URL-friendly: "stranger-things"
  search_vector tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
    setweight(to_tsvector('english', coalesce(description, '')), 'B') ||
    setweight(to_tsvector('english', coalesce(original_title, '')), 'B')
  ) STORED,
  -- Metadata
  is_published boolean DEFAULT false,
  published_at timestamptz,
  view_count bigint DEFAULT 0,
  completion_rate numeric(5,4) DEFAULT 0,  -- 0.0000 to 1.0000
  trending_score numeric(10,4) DEFAULT 0,  -- computed nightly
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

CREATE INDEX idx_content_type ON public.content(type);
CREATE INDEX idx_content_published ON public.content(is_published) WHERE is_published = true;
CREATE INDEX idx_content_release_year ON public.content(release_year DESC);
CREATE INDEX idx_content_maturity ON public.content(maturity_rating);
CREATE INDEX idx_content_trending ON public.content(trending_score DESC);
CREATE INDEX idx_content_search ON public.content USING GIN(search_vector);
CREATE INDEX idx_content_slug ON public.content(slug);
CREATE INDEX idx_content_tags ON public.content USING GIN(tags);

-- ============================================
-- GENRES
-- ============================================
CREATE TABLE public.genres (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text UNIQUE NOT NULL,
  slug text UNIQUE NOT NULL,
  description text,
  icon_name text,  -- for UI icons
  display_order int DEFAULT 0,
  is_active boolean DEFAULT true,
  created_at timestamptz DEFAULT now()
);

-- ============================================
-- CONTENT-GENRES (many-to-many)
-- ============================================
CREATE TABLE public.content_genres (
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  genre_id uuid NOT NULL REFERENCES public.genres(id) ON DELETE CASCADE,
  PRIMARY KEY (content_id, genre_id)
);

CREATE INDEX idx_content_genres_genre ON public.content_genres(genre_id);

-- ============================================
-- SEASONS (for series)
-- ============================================
CREATE TABLE public.seasons (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  season_number int NOT NULL,
  title text,  -- e.g., "Season 1", "Final Season"
  overview text,
  poster_url text,
  release_date date,
  episode_count int DEFAULT 0,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now(),
  UNIQUE (content_id, season_number)
);

CREATE INDEX idx_seasons_content ON public.seasons(content_id);

-- ============================================
-- EPISODES
-- ============================================
CREATE TABLE public.episodes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  season_id uuid NOT NULL REFERENCES public.seasons(id) ON DELETE CASCADE,
  episode_number int NOT NULL,
  title text NOT NULL,
  overview text,
  duration_seconds int NOT NULL,
  video_url text NOT NULL,  -- signed HLS manifest URL (generated at request time)
  thumbnail_url text,  -- 16:9 thumbnail for scrubber preview
  release_date date,
  skip_intro_start int,  -- seconds, for "Skip Intro"
  skip_intro_end int,    -- seconds
  skip_recap_start int,  -- seconds, for "Previously on..."
  skip_recap_end int,    -- seconds
  -- Metadata
  cast jsonb DEFAULT '[]',
  crew jsonb DEFAULT '[]',
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now(),
  UNIQUE (season_id, episode_number)
);

CREATE INDEX idx_episodes_season ON public.episodes(season_id);
CREATE INDEX idx_episodes_release ON public.episodes(release_date);

-- ============================================
-- WATCH HISTORY
-- ============================================
CREATE TABLE public.watch_history (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  episode_id uuid REFERENCES public.episodes(id) ON DELETE SET NULL,
  season_id uuid REFERENCES public.seasons(id) ON DELETE SET NULL,
  progress_seconds int NOT NULL DEFAULT 0,
  duration_seconds int NOT NULL,  -- cached for progress calc
  completed_at timestamptz,  -- set when progress >= 95%
  -- Device tracking for sync
  device_id uuid REFERENCES public.devices(id) ON DELETE SET NULL,
  platform text CHECK (platform IN ('ios','android','web','tv')),
  -- Metadata
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Critical indexes for Continue Watching queries
CREATE INDEX idx_watch_history_continue_watching 
  ON public.watch_history (profile_id, updated_at DESC) 
  WHERE progress_seconds > 0 AND progress_seconds < duration_seconds * 0.95;

CREATE INDEX idx_watch_history_profile_content 
  ON public.watch_history (profile_id, content_id, episode_id);

CREATE INDEX idx_watch_history_completed 
  ON public.watch_history (profile_id, completed_at DESC) 
  WHERE completed_at IS NOT NULL;

-- Partition by month for large-scale (optional, for >10M rows)
-- CREATE TABLE public.watch_history_2026_11 PARTITION OF public.watch_history
--   FOR VALUES FROM ('2026-11-01') TO ('2026-12-01');

-- ============================================
-- SUBSCRIPTIONS
-- ============================================
CREATE TABLE public.subscriptions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  stripe_subscription_id text UNIQUE,
  stripe_price_id text,  -- current price ID
  plan text NOT NULL CHECK (plan IN ('basic','standard','premium')),
  status text NOT NULL CHECK (status IN (
    'trialing','active','past_due','canceled','incomplete','incomplete_expired','unpaid','paused'
  )),
  current_period_start timestamptz NOT NULL,
  current_period_end timestamptz NOT NULL,
  cancel_at_period_end boolean DEFAULT false,
  canceled_at timestamptz,
  trial_start timestamptz,
  trial_end timestamptz,
  -- Plan features (denormalized for quick access)
  concurrent_streams int NOT NULL DEFAULT 1,
  max_quality text NOT NULL DEFAULT '720p' CHECK (max_quality IN ('720p','1080p','4k')),
  max_profiles int NOT NULL DEFAULT 1,
  supports_downloads boolean DEFAULT false,
  supports_hdr boolean DEFAULT false,
  -- Metadata
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

CREATE INDEX idx_subscriptions_user ON public.subscriptions(user_id);
CREATE INDEX idx_subscriptions_stripe ON public.subscriptions(stripe_subscription_id);
CREATE INDEX idx_subscriptions_status ON public.subscriptions(status) WHERE status IN ('active','trialing','past_due');

-- ============================================
-- DEVICES (for stream enforcement + push notifications)
-- ============================================
CREATE TABLE public.devices (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  user_id uuid NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  device_token text,  -- FCM/APNs token for push
  device_fingerprint text NOT NULL,  -- hash of user agent + IP + screen
  platform text NOT NULL CHECK (platform IN ('ios','android','web','tv')),
  app_version text,
  os_version text,
  browser_name text,
  browser_version text,
  screen_resolution text,
  timezone text,
  country_code text,  -- ISO 3166-1 alpha-2 (from IP)
  last_active_at timestamptz DEFAULT now(),
  last_ip inet,
  is_trusted boolean DEFAULT false,  -- for 2FA bypass
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_devices_profile ON public.devices(profile_id);
CREATE INDEX idx_devices_user ON public.devices(user_id);
CREATE INDEX idx_devices_active ON public.devices(last_active_at DESC) WHERE last_active_at > now() - interval '30 days';
CREATE UNIQUE INDEX idx_devices_fingerprint ON public.devices(user_id, device_fingerprint);

-- ============================================
-- RECOMMENDATIONS (materialized, refreshed nightly)
-- ============================================
CREATE TABLE public.recommendations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  score numeric(4,3) NOT NULL CHECK (score >= 0 AND score <= 1),  -- 0.000 to 1.000
  reason text NOT NULL CHECK (reason IN (
    'similar_genre','same_actor','same_director','trending','collaborative',
    'popular','continue_watching','my_list','new_release','award_winner'
  )),
  reason_detail jsonb,  -- {source_content_id, genre_ids, actor_ids}
  generated_at timestamptz DEFAULT now(),
  UNIQUE (profile_id, content_id)
);

CREATE INDEX idx_recommendations_profile_score 
  ON public.recommendations (profile_id, score DESC, generated_at DESC);

CREATE INDEX idx_recommendations_reason 
  ON public.recommendations (profile_id, reason, score DESC);

-- ============================================
-- MY LIST (saved content)
-- ============================================
CREATE TABLE public.my_list (
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  added_at timestamptz DEFAULT now(),
  -- Sorting: manual position (nulls last = bottom)
  position int,
  PRIMARY KEY (profile_id, content_id)
);

CREATE INDEX idx_my_list_profile ON public.my_list(profile_id, position NULLS LAST, added_at DESC);

-- ============================================
-- CONTENT AVAILABILITY (regional licensing)
-- ============================================
CREATE TABLE public.content_availability (
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  country_code text NOT NULL,  -- ISO 3166-1 alpha-2
  available_from timestamptz,
  available_until timestamptz,
  is_available boolean DEFAULT true,
  PRIMARY KEY (content_id, country_code)
);

CREATE INDEX idx_content_availability_country ON public.content_availability(country_code, is_available);

-- ============================================
-- VIEWING SESSIONS (for concurrent stream tracking)
-- ============================================
CREATE TABLE public.viewing_sessions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  device_id uuid NOT NULL REFERENCES public.devices(id) ON DELETE CASCADE,
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  episode_id uuid REFERENCES public.episodes(id) ON DELETE SET NULL,
  session_token text UNIQUE NOT NULL,  -- for manifest validation
  quality text NOT NULL CHECK (quality IN ('360p','480p','720p','1080p','4k')),
  started_at timestamptz DEFAULT now(),
  last_heartbeat timestamptz DEFAULT now(),
  ended_at timestamptz,
  -- Geo for licensing
  country_code text,
  ip_address inet
);

CREATE INDEX idx_viewing_sessions_profile ON public.viewing_sessions(profile_id, ended_at) WHERE ended_at IS NULL;
CREATE INDEX idx_viewing_sessions_device ON public.viewing_sessions(device_id, ended_at) WHERE ended_at IS NULL;
CREATE INDEX idx_viewing_sessions_token ON public.viewing_sessions(session_token);

-- ============================================
-- NOTIFICATIONS
-- ============================================
CREATE TABLE public.notifications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  type text NOT NULL CHECK (type IN (
    'new_episode','new_season','recommendation','watch_party_invite',
    'subscription_change','payment_failed','profile_lock','system'
  )),
  title text NOT NULL,
  body text,
  data jsonb DEFAULT '{}',  -- {content_id, episode_id, deep_link}
  is_read boolean DEFAULT false,
  read_at timestamptz,
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_notifications_profile_unread 
  ON public.notifications (profile_id, created_at DESC) WHERE is_read = false;

-- ============================================
-- ANALYTICS EVENTS (for PostHog/Segment sync)
-- ============================================
CREATE TABLE public.analytics_events (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid REFERENCES public.profiles(id) ON DELETE SET NULL,
  user_id uuid REFERENCES public.users(id) ON DELETE SET NULL,
  session_id uuid REFERENCES public.viewing_sessions(id) ON DELETE SET NULL,
  event_name text NOT NULL,  -- 'play', 'pause', 'complete', 'search', etc.
  properties jsonb DEFAULT '{}',
  device_info jsonb,  -- {platform, browser, os, screen}
  geo_info jsonb,  -- {country, region, city}
  created_at timestamptz DEFAULT now()
);

CREATE INDEX idx_analytics_profile_time ON public.analytics_events(profile_id, created_at DESC);
CREATE INDEX idx_analytics_event_name ON public.analytics_events(event_name, created_at DESC);
-- Partition by month recommended
```

### 1.2 Row Level Security (RLS) Policies

```sql
-- ============================================
-- ENABLE RLS ON ALL TABLES
-- ============================================
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.content ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.genres ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.content_genres ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.seasons ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.episodes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.watch_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.subscriptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.devices ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.recommendations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.my_list ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.content_availability ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.viewing_sessions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.analytics_events ENABLE ROW LEVEL SECURITY;

-- ============================================
-- HELPER FUNCTIONS
-- ============================================
CREATE OR REPLACE FUNCTION public.get_user_profiles()
RETURNS SETOF uuid LANGUAGE sql SECURITY DEFINER AS $$
  SELECT id FROM public.profiles WHERE user_id = auth.uid();
$$;

CREATE OR REPLACE FUNCTION public.get_user_id()
RETURNS uuid LANGUAGE sql SECURITY DEFINER AS $$
  SELECT auth.uid();
$$;

CREATE OR REPLACE FUNCTION public.is_profile_owner(profile_id uuid)
RETURNS boolean LANGUAGE sql SECURITY DEFINER AS $$
  SELECT EXISTS (
    SELECT 1 FROM public.profiles WHERE id = profile_id AND user_id = auth.uid()
  );
$$;

CREATE OR REPLACE FUNCTION public.get_active_subscription()
RETURNS public.subscriptions LANGUAGE sql SECURITY DEFINER AS $$
  SELECT * FROM public.subscriptions 
  WHERE user_id = auth.uid() 
  AND status IN ('active', 'trialing', 'past_due')
  ORDER BY current_period_end DESC
  LIMIT 1;
$$;

-- ============================================
-- USERS
-- ============================================
CREATE POLICY "Users view own record" ON public.users
  FOR SELECT USING (id = auth.uid());

CREATE POLICY "Users update own record" ON public.users
  FOR UPDATE USING (id = auth.uid()) WITH CHECK (id = auth.uid());

-- ============================================
-- PROFILES
-- ============================================
CREATE POLICY "Users view own profiles" ON public.profiles
  FOR SELECT USING (user_id = auth.uid());

CREATE POLICY "Users insert own profiles" ON public.profiles
  FOR INSERT WITH CHECK (user_id = auth.uid());

CREATE POLICY "Users update own profiles" ON public.profiles
  FOR UPDATE USING (user_id = auth.uid()) WITH CHECK (user_id = auth.uid());

CREATE POLICY "Users delete own profiles" ON public.profiles
  FOR DELETE USING (user_id = auth.uid());

-- ============================================
-- CONTENT (public read for published, admin write)
-- ============================================
CREATE POLICY "Public read published content" ON public.content
  FOR SELECT USING (is_published = true);

-- Admin policy would be added via service role

-- ============================================
-- GENRES (public read)
-- ============================================
CREATE POLICY "Public read genres" ON public.genres
  FOR SELECT USING (is_active = true);

-- ============================================
-- CONTENT-GENRES (public read via content)
-- ============================================
CREATE POLICY "Public read content-genres" ON public.content_genres
  FOR SELECT USING (
    EXISTS (SELECT 1 FROM public.content WHERE id = content_id AND is_published = true)
  );

-- ============================================
-- SEASONS (public read for published content)
-- ============================================
CREATE POLICY "Public read seasons" ON public.seasons
  FOR SELECT USING (
    EXISTS (SELECT 1 FROM public.content WHERE id = content_id AND is_published = true)
  );

-- ============================================
-- EPISODES (public read for published content)
-- ============================================
CREATE POLICY "Public read episodes" ON public.episodes
  FOR SELECT USING (
    EXISTS (
      SELECT 1 FROM public.seasons s
      JOIN public.content c ON c.id = s.content_id
      WHERE s.id = season_id AND c.is_published = true
    )
  );

-- ============================================
-- WATCH HISTORY (profile-scoped)
-- ============================================
CREATE POLICY "Profiles manage own watch history" ON public.watch_history
  FOR ALL USING (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  ) WITH CHECK (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  );

-- ============================================
-- SUBSCRIPTIONS (user-scoped)
-- ============================================
CREATE POLICY "Users view own subscription" ON public.subscriptions
  FOR SELECT USING (user_id = auth.uid());

-- ============================================
-- DEVICES (user-scoped)
-- ============================================
CREATE POLICY "Users manage own devices" ON public.devices
  FOR ALL USING (user_id = auth.uid()) WITH CHECK (user_id = auth.uid());

-- ============================================
-- RECOMMENDATIONS (profile-scoped)
-- ============================================
CREATE POLICY "Profiles view own recommendations" ON public.recommendations
  FOR SELECT USING (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  );

-- ============================================
-- MY LIST (profile-scoped)
-- ============================================
CREATE POLICY "Profiles manage own list" ON public.my_list
  FOR ALL USING (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  ) WITH CHECK (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  );

-- ============================================
-- CONTENT AVAILABILITY (public read)
-- ============================================
CREATE POLICY "Public read content availability" ON public.content_availability
  FOR SELECT USING (is_available = true);

-- ============================================
-- VIEWING SESSIONS (profile-scoped)
-- ============================================
CREATE POLICY "Profiles manage own viewing sessions" ON public.viewing_sessions
  FOR ALL USING (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  ) WITH CHECK (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  );

-- ============================================
-- NOTIFICATIONS (profile-scoped)
-- ============================================
CREATE POLICY "Profiles manage own notifications" ON public.notifications
  FOR ALL USING (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  ) WITH CHECK (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
  );

-- ============================================
-- ANALYTICS (profile-scoped, insert only for client)
-- ============================================
CREATE POLICY "Profiles insert own analytics" ON public.analytics_events
  FOR INSERT WITH CHECK (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
    OR user_id = auth.uid()
  );

CREATE POLICY "Profiles view own analytics" ON public.analytics_events
  FOR SELECT USING (
    profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid())
    OR user_id = auth.uid()
  );
```

### 1.3 Database Functions & Triggers

```sql
-- ============================================
-- UPDATED_AT TRIGGER
-- ============================================
CREATE OR REPLACE FUNCTION public.handle_updated_at()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$;

-- Apply to all tables with updated_at
CREATE TRIGGER users_updated_at BEFORE UPDATE ON public.users
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();
CREATE TRIGGER profiles_updated_at BEFORE UPDATE ON public.profiles
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();
CREATE TRIGGER content_updated_at BEFORE UPDATE ON public.content
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();
CREATE TRIGGER seasons_updated_at BEFORE UPDATE ON public.seasons
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();
CREATE TRIGGER episodes_updated_at BEFORE UPDATE ON public.episodes
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();
CREATE TRIGGER subscriptions_updated_at BEFORE UPDATE ON public.subscriptions
  FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();

-- ============================================
-- PROFILE LIMIT ENFORCEMENT
-- ============================================
CREATE OR REPLACE FUNCTION public.enforce_profile_limit()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
  profile_count int;
  max_profiles int;
BEGIN
  SELECT concurrent_streams INTO max_profiles  -- reuse field for profile limit
  FROM public.subscriptions 
  WHERE user_id = NEW.user_id AND status IN ('active', 'trialing')
  ORDER BY current_period_end DESC LIMIT 1;
  
  -- Default to 1 if no subscription
  max_profiles := COALESCE(max_profiles, 1);
  
  SELECT COUNT(*) INTO profile_count
  FROM public.profiles WHERE user_id = NEW.user_id;
  
  IF profile_count >= max_profiles THEN
    RAISE EXCEPTION 'Profile limit reached. Maximum % profiles allowed for your plan.', max_profiles;
  END IF;
  
  RETURN NEW;
END;
$$;

CREATE TRIGGER enforce_profile_limit
  BEFORE INSERT ON public.profiles
  FOR EACH ROW EXECUTE FUNCTION public.enforce_profile_limit();

-- ============================================
-- WATCH HISTORY PROGRESS COMPLETION
-- ============================================
CREATE OR REPLACE FUNCTION public.handle_watch_completion()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.progress_seconds >= NEW.duration_seconds * 0.95 
     AND OLD.progress_seconds < OLD.duration_seconds * 0.95 THEN
    NEW.completed_at := now();
  ELSIF NEW.progress_seconds < NEW.duration_seconds * 0.95 THEN
    NEW.completed_at := NULL;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER watch_history_completion
  BEFORE UPDATE ON public.watch_history
  FOR EACH ROW EXECUTE FUNCTION public.handle_watch_completion();

-- ============================================
-- SEASON EPISODE COUNT
-- ============================================
CREATE OR REPLACE FUNCTION public.update_season_episode_count()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    UPDATE public.seasons SET episode_count = episode_count + 1 WHERE id = NEW.season_id;
  ELSIF TG_OP = 'DELETE' THEN
    UPDATE public.seasons SET episode_count = episode_count - 1 WHERE id = OLD.season_id;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER season_episode_count
  AFTER INSERT OR DELETE ON public.episodes
  FOR EACH ROW EXECUTE FUNCTION public.update_season_episode_count();

-- ============================================
-- MY LIST POSITION MANAGEMENT
-- ============================================
CREATE OR REPLACE FUNCTION public.set_my_list_position()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.position IS NULL THEN
    -- Add to end
    SELECT COALESCE(MAX(position), 0) + 1 INTO NEW.position
    FROM public.my_list WHERE profile_id = NEW.profile_id;
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER my_list_position
  BEFORE INSERT ON public.my_list
  FOR EACH ROW EXECUTE FUNCTION public.set_my_list_position();

-- ============================================
-- VIEWING SESSION HEARTBEAT
-- ============================================
CREATE OR REPLACE FUNCTION public.update_session_heartbeat()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
  NEW.last_heartbeat := now();
  RETURN NEW;
END;
$$;

CREATE TRIGGER session_heartbeat
  BEFORE UPDATE ON public.viewing_sessions
  FOR EACH ROW EXECUTE FUNCTION public.update_session_heartbeat();
```

---

## 2. API Endpoints Catalog

### 2.1 Authentication

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| POST | `/auth/v1/signup` | None | `{email, password, metadata?}` | `{user, session}` | `400` invalid, `409` exists |
| POST | `/auth/v1/token?grant_type=password` | None | `{email, password}` | `{access_token, refresh_token, user}` | `400` invalid, `401` wrong |
| POST | `/auth/v1/token?grant_type=refresh_token` | Refresh | `{refresh_token}` | `{access_token, refresh_token}` | `401` expired/revoked |
| POST | `/auth/v1/logout` | Access | — | `{}` | `401` invalid |
| POST | `/auth/v1/recover` | None | `{email}` | `{}` | `400` invalid, `404` not found |
| POST | `/auth/v1/verify` | None | `{token, type: signup|recovery|email_change}` | `{user, session}` | `400` invalid/expired |
| POST | `/auth/v1/mfa/enroll` | Access | `{factor_type: totp}` | `{factor_id, qr_code, secret}` | `400` invalid, `409` enrolled |
| POST | `/auth/v1/mfa/challenge` | Access | `{factor_id}` | `{challenge_id}` | `400` invalid, `404` not found |
| POST | `/auth/v1/mfa/verify` | Access | `{challenge_id, code}` | `{access_token, refresh_token}` | `400` invalid, `401` wrong |
| POST | `/auth/v1/mfa/unenroll` | Access | `{factor_id}` | `{}` | `404` not found |
| GET | `/auth/v1/user` | Access | — | `{user}` | `401` invalid |
| PATCH | `/auth/v1/user` | Access | `{data, email, password, phone}` | `{user}` | `400` invalid, `409` conflict |

**OAuth Providers:** `google`, `apple` — initiated via `/auth/v1/authorize?provider={provider}`

### 2.2 Profiles

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/profiles` | Access | — | `{profiles: Profile[]}` | `401` |
| POST | `/profiles` | Access | `{name, avatar_url?, maturity_rating?, is_kids?, language?, kids_pin?}` | `{profile: Profile}` | `400` invalid, `409` limit/name |
| GET | `/profiles/{id}` | Access | — | `{profile: Profile}` | `401`, `403` not owner, `404` |
| PATCH | `/profiles/{id}` | Access | `{name?, avatar_url?, maturity_rating?, is_kids?, language?, autoplay_next?, skip_intro?, data_saver?, subtitle_appearance?, kids_pin?}` | `{profile: Profile}` | `400`, `403`, `404` |
| DELETE | `/profiles/{id}` | Access | — | `{}` | `403`, `404`, `409` last profile |
| POST | `/profiles/{id}/avatar` | Access | `multipart/form-data: file` | `{avatar_url: string}` | `400` invalid file, `413` too large |
| POST | `/profiles/{id}/switch` | Access | — | `{access_token, refresh_token, profile: Profile}` | `403`, `404` |
| POST | `/profiles/{id}/verify-pin` | Access | `{pin: string}` | `{valid: boolean}` | `400`, `403`, `404` |

### 2.3 Content

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/content` | Optional | `?page&limit&type&genre&year&maturity&sort&country` | `{data: Content[], pagination}` | `400` invalid params |
| GET | `/content/featured` | Optional | `?limit=10` | `{data: Content[]}` | — |
| GET | `/content/trending` | Optional | `?limit=20&country` | `{data: Content[]}` | — |
| GET | `/content/continue-watching` | Access | `?profile_id&limit=10` | `{data: ContinueWatchingItem[]}` | `401`, `403` |
| GET | `/content/my-list` | Access | `?profile_id&page&limit` | `{data: Content[], pagination}` | `401`, `403` |
| POST | `/content/my-list` | Access | `{profile_id, content_id}` | `{item: MyListItem}` | `400`, `403`, `404`, `409` |
| DELETE | `/content/my-list/{content_id}` | Access | `?profile_id` | `{}` | `403`, `404` |
| GET | `/content/{id}` | Optional | `?profile_id` (for progress) | `{content: ContentDetail}` | `404` |
| GET | `/content/{id}/seasons` | Optional | — | `{data: Season[]}` | `404` |
| GET | `/content/{id}/episodes` | Optional | `?season_id` | `{data: Episode[]}` | `404` |
| GET | `/genres` | Optional | — | `{data: Genre[]}` | — |
| GET | `/genres/{slug}/content` | Optional | `?page&limit` | `{data: Content[], pagination}` | `404` |

### 2.4 Search

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/search` | Optional | `?q&type&genre&year_from&year_to&maturity&language&page&limit` | `{data: Content[], pagination, suggestions}` | `400` q too short |
| GET | `/search/suggestions` | Optional | `?q&limit=10` | `{suggestions: string[]}` | `400` q too short |
| GET | `/search/popular` | Optional | `?limit=10` | `{queries: string[]}` | — |

### 2.5 Watch History

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/watch-history` | Access | `?profile_id&page&limit&status=in_progress|completed` | `{data: WatchHistoryItem[], pagination}` | `401`, `403` |
| POST | `/watch-history/progress` | Access | `{profile_id, content_id, episode_id?, season_id?, progress_seconds, duration_seconds, device_id?, platform?}` | `{item: WatchHistoryItem}` | `400`, `403`, `404` |
| DELETE | `/watch-history/{id}` | Access | `?profile_id` | `{}` | `403`, `404` |
| DELETE | `/watch-history` | Access | `?profile_id&content_id?&episode_id?` | `{deleted_count: int}` | `403` |

### 2.6 Recommendations

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/recommendations` | Access | `?profile_id&reason&limit=20` | `{data: RecommendationItem[]}` | `401`, `403` |
| POST | `/recommendations/refresh` | Access | `{profile_id?}` | `{status: 'queued'}` | `401`, `403` (admin only for others) |

### 2.7 Subscriptions

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/subscription` | Access | — | `{subscription: Subscription \| null}` | `401` |
| GET | `/subscription/plans` | None | — | `{plans: Plan[]}` | — |
| POST | `/subscription/checkout` | Access | `{plan: 'basic'|'standard'|'premium', success_url, cancel_url, promotion_code?}` | `{session_id, url}` | `400`, `401`, `402` no payment |
| POST | `/subscription/portal` | Access | `{return_url}` | `{url}` | `401`, `404` no subscription |
| POST | `/subscription/cancel` | Access | `{confirm: true, feedback?}` | `{subscription: Subscription}` | `401`, `404` |
| POST | `/subscription/change-plan` | Access | `{plan: 'basic'|'standard'|'premium', proration_behavior: 'create_prorations'|'none'}` | `{subscription: Subscription}` | `400`, `401`, `404` |

### 2.8 Video Playback

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/video/manifest/{content_id}` | Access | `?profile_id&episode_id?&quality=auto|360p|480p|720p|1080p|4k` | `{manifest_url, session_token, expires_at, max_quality}` | `401`, `403` no sub/concurrent, `404` |
| GET | `/video/manifest/{content_id}/episode/{episode_id}` | Access | `?profile_id&quality=auto|360p|480p|720p|1080p|4k` | `{manifest_url, session_token, expires_at, max_quality}` | `401`, `403`, `404` |
| POST | `/video/heartbeat` | Access | `{session_token, position_seconds, duration_seconds}` | `{active_sessions: int, max_sessions: int}` | `401`, `403` invalid/expired |
| POST | `/video/session/end` | Access | `{session_token}` | `{}` | `401`, `403` |

### 2.9 Devices

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/devices` | Access | — | `{data: Device[]}` | `401` |
| POST | `/devices` | Access | `{device_token?, platform, app_version?, os_version?, device_fingerprint}` | `{device: Device}` | `400`, `401` |
| PATCH | `/devices/{id}` | Access | `{device_token?, is_trusted?}` | `{device: Device}` | `401`, `403`, `404` |
| DELETE | `/devices/{id}` | Access | — | `{}` | `401`, `403`, `404` |
| DELETE | `/devices` | Access | `{confirm: true}` | `{revoked_count: int}` | `401` |

### 2.10 Notifications

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| GET | `/notifications` | Access | `?profile_id&unread_only&page&limit` | `{data: Notification[], pagination, unread_count}` | `401`, `403` |
| PATCH | `/notifications/{id}/read` | Access | — | `{notification: Notification}` | `401`, `403`, `404` |
| PATCH | `/notifications/read-all` | Access | `?profile_id` | `{updated_count: int}` | `401`, `403` |
| DELETE | `/notifications/{id}` | Access | — | `{}` | `401`, `403`, `404` |

### 2.11 Analytics (Client → Server)

| Method | Path | Auth | Request | Response | Errors |
|--------|------|------|---------|----------|--------|
| POST | `/analytics/events` | Access | `{events: AnalyticsEvent[]}` | `{accepted: int}` | `400`, `401` |

---

## 3. Request/Response Schemas (TypeScript)

```typescript
// ============================================
// CORE TYPES
// ============================================

interface Pagination {
  page: number;
  limit: number;
  total: number;
  totalPages: number;
  hasMore: boolean;
}

interface ApiError {
  code: string;
  message: string;
  details?: Record<string, unknown>;
  status: number;
}

interface ApiResponse<T> {
  data: T;
  error?: ApiError;
  meta?: {
    requestId: string;
    timestamp: string;
  };
}

// ============================================
// AUTH
// ============================================

interface AuthUser {
  id: string;
  email: string;
  emailVerified: boolean;
  phone?: string;
  createdAt: string;
  updatedAt: string;
  metadata: Record<string, unknown>;
  factors?: MfaFactor[];
}

interface MfaFactor {
  id: string;
  type: 'totp';
  status: 'verified' | 'unverified';
  createdAt: string;
}

interface Session {
  accessToken: string;
  refreshToken: string;
  expiresAt: number; // unix ms
  user: AuthUser;
}

// ============================================
// PROFILES
// ============================================

type MaturityRating = 'G' | 'PG' | 'PG-13' | 'R' | 'NC-17';
type Platform = 'ios' | 'android' | 'web' | 'tv';

interface Profile {
  id: string;
  userId: string;
  name: string;
  avatarUrl: string | null;
  maturityRating: MaturityRating;
  isKids: boolean;
  language: string;
  autoplayNext: boolean;
  skipIntro: boolean;
  dataSaver: boolean;
  subtitleAppearance: SubtitleAppearance;
  createdAt: string;
  updatedAt: string;
}

interface SubtitleAppearance {
  fontFamily?: 'default' | 'monospace' | 'serif' | 'sans-serif' | 'cursive';
  fontSize?: 'small' | 'medium' | 'large' | 'extra-large';
  fontColor?: string; // hex
  backgroundColor?: string; // hex
  backgroundOpacity?: 0 | 0.25 | 0.5 | 0.75 | 1;
  edgeStyle?: 'none' | 'raised' | 'depressed' | 'uniform' | 'drop-shadow';
  windowColor?: string;
  windowOpacity?: 0 | 0.25 | 0.5 | 0.75 | 1;
}

// ============================================
// CONTENT
// ============================================

type ContentType = 'movie' | 'series' | 'special';

interface ContentBase {
  id: string;
  title: string;
  originalTitle?: string;
  type: ContentType;
  description: string;
  shortDescription?: string;
  posterUrl: string;
  backdropUrl: string;
  logoUrl?: string;
  trailerUrl?: string;
  releaseYear: number;
  releaseDate?: string;
  durationSeconds?: number; // movies only
  maturityRating: MaturityRating;
  maturityDescriptors: string[];
  countryOfOrigin: string[];
  originalLanguage: string;
  tags: string[];
  cast: CastMember[];
  crew: CrewMember[];
  slug: string;
  isPublished: boolean;
  publishedAt?: string;
  viewCount: number;
  completionRate: number;
  trendingScore: number;
  createdAt: string;
  updatedAt: string;
}

interface ContentDetail extends ContentBase {
  seasons?: Season[];
  episodes?: Episode[];
  genres: Genre[];
  availability?: ContentAvailability;
  // User-specific (when profile_id provided)
  progress?: {
    progressSeconds: number;
    durationSeconds: number;
    completedAt?: string;
    episodeId?: string;
    seasonId?: string;
  };
  inMyList?: boolean;
  recommendationReason?: string;
}

interface ContinueWatchingItem {
  content: ContentBase;
  progressSeconds: number;
  durationSeconds: number;
  progressPercent: number;
  episodeId?: string;
  episodeTitle?: string;
  seasonNumber?: number;
  episodeNumber?: number;
  updatedAt: string;
  nextEpisode?: {
    id: string;
    title: string;
    episodeNumber: number;
    seasonNumber: number;
  };
}

interface CastMember {
  id: string;
  name: string;
  role: 'actor' | 'director' | 'writer' | 'producer';
  character?: string;
  profileUrl?: string;
  order: number;
}

interface CrewMember {
  id: string;
  name: string;
  role: string;
  department: string;
  order: number;
}

interface Season {
  id: string;
  contentId: string;
  seasonNumber: number;
  title?: string;
  overview?: string;
  posterUrl?: string;
  releaseDate?: string;
  episodeCount: number;
  createdAt: string;
  updatedAt: string;
}

interface Episode {
  id: string;
  seasonId: string;
  episodeNumber: number;
  title: string;
  overview?: string;
  durationSeconds: number;
  videoUrl: string; // signed manifest URL
  thumbnailUrl?: string;
  releaseDate?: string;
  skipIntroStart?: number;
  skipIntroEnd?: number;
  skipRecapStart?: number;
  skipRecapEnd?: number;
  cast: CastMember[];
  crew: CrewMember[];
  createdAt: string;
  updatedAt: string;
}

interface Genre {
  id: string;
  name: string;
  slug: string;
  description?: string;
  iconName?: string;
  displayOrder: number;
  isActive: boolean;
}

interface ContentAvailability {
  contentId: string;
  countryCode: string;
  availableFrom?: string;
  availableUntil?: string;
  isAvailable: boolean;
}

// ============================================
// WATCH HISTORY
// ============================================

interface WatchHistoryItem {
  id: string;
  profileId: string;
  contentId: string;
  episodeId?: string;
  seasonId?: string;
  progressSeconds: number;
  durationSeconds: number;
  completedAt?: string;
  deviceId?: string;
  platform?: Platform;
  createdAt: string;
  updatedAt: string;
  // Joined content data
  content: ContentBase;
  episode?: Episode;
  season?: Season;
}

// ============================================
// RECOMMENDATIONS
// ============================================

type RecommendationReason = 
  | 'similar_genre' | 'same_actor' | 'same_director' 
  | 'trending' | 'collaborative' | 'popular' 
  | 'continue_watching' | 'my_list' | 'new_release' | 'award_winner';

interface RecommendationItem {
  content: ContentBase;
  score: number; // 0.000 - 1.000
  reason: RecommendationReason;
  reasonDetail?: {
    sourceContentId?: string;
    genreIds?: string[];
    actorIds?: string[];
  };
  generatedAt: string;
}

// ============================================
// MY LIST
// ============================================

interface MyListItem {
  profileId: string;
  contentId: string;
  addedAt: string;
  position: number;
  content: ContentBase;
}

// ============================================
// SUBSCRIPTIONS
// ============================================

type PlanType = 'basic' | 'standard' | 'premium';
type SubscriptionStatus = 
  | 'trialing' | 'active' | 'past_due' 
  | 'canceled' | 'incomplete' | 'incomplete_expired' 
  | 'unpaid' | 'paused';

interface Plan {
  id: PlanType;
  name: string;
  monthlyPrice: number; // in cents
  yearlyPrice?: number; // in cents
  currency: string;
  features: {
    concurrentStreams: number;
    maxQuality: '720p' | '1080p' | '4k';
    maxProfiles: number;
    supportsDownloads: boolean;
    supportsHdr: boolean;
    supportedDevices: string[];
  };
  stripePriceIdMonthly: string;
  stripePriceIdYearly?: string;
}

interface Subscription {
  id: string;
  userId: string;
  stripeSubscriptionId?: string;
  stripePriceId?: string;
  plan: PlanType;
  status: SubscriptionStatus;
  currentPeriodStart: string;
  currentPeriodEnd: string;
  cancelAtPeriodEnd: boolean;
  canceledAt?: string;
  trialStart?: string;
  trialEnd?: string;
  concurrentStreams: number;
  maxQuality: '720p' | '1080p' | '4k';
  maxProfiles: number;
  supportsDownloads: boolean;
  supportsHdr: boolean;
  createdAt: string;
  updatedAt: string;
}

// ============================================
// VIDEO
// ============================================

interface VideoManifestResponse {
  manifestUrl: string; // signed HLS manifest
  sessionToken: string; // for heartbeat validation
  expiresAt: string; // ISO timestamp
  maxQuality: '720p' | '1080p' | '4k';
  availableQualities: ('360p' | '480p' | '720p' | '1080p' | '4k')[];
  drm?: {
    fairplay?: { certificateUrl: string; licenseUrl: string };
    widevine?: { licenseUrl: string };
    playready?: { licenseUrl: string };
  };
  subtitles: SubtitleTrack[];
  audioTracks: AudioTrack[];
}

interface SubtitleTrack {
  id: string;
  label: string;
  language: string; // ISO 639-1
  isDefault: boolean;
  isForcedNarrative: boolean; // SDH
  url: string; // WebVTT
}

interface AudioTrack {
  id: string;
  label: string;
  language: string; // ISO 639-1
  isDefault: boolean;
  isAudioDescription: boolean;
  url?: string; // separate audio manifest if needed
}

interface HeartbeatResponse {
  activeSessions: number;
  maxSessions: number;
  warningThreshold?: number; // e.g., 80% of max
}

// ============================================
// DEVICES
// ============================================

interface Device {
  id: string;
  profileId: string;
  userId: string;
  deviceToken?: string;
  deviceFingerprint: string;
  platform: Platform;
  appVersion?: string;
  osVersion?: string;
  browserName?: string;
  browserVersion?: string;
  screenResolution?: string;
  timezone?: string;
  countryCode?: string;
  lastActiveAt: string;
  lastIp?: string;
  isTrusted: boolean;
  createdAt: string;
}

// ============================================
// NOTIFICATIONS
// ============================================

type NotificationType = 
  | 'new_episode' | 'new_season' | 'recommendation' 
  | 'watch_party_invite' | 'subscription_change' 
  | 'payment_failed' | 'profile_lock' | 'system';

interface Notification {
  id: string;
  profileId: string;
  type: NotificationType;
  title: string;
  body?: string;
  data: Record<string, unknown>;
  isRead: boolean;
  readAt?: string;
  createdAt: string;
}

// ============================================
// ANALYTICS
// ============================================

interface AnalyticsEvent {
  eventName: string;
  properties: Record<string, unknown>;
  timestamp: string; // ISO
  profileId?: string;
  sessionId?: string;
  deviceInfo?: {
    platform: Platform;
    browser?: string;
    os?: string;
    screenResolution?: string;
  };
  geoInfo?: {
    countryCode?: string;
    region?: string;
    city?: string;
  };
}

// ============================================
// ERROR CODES
// ============================================

/*
Error Codes (returned in ApiError.code):
- AUTH_INVALID_CREDENTIALS: 401
- AUTH_SESSION_EXPIRED: 401
- AUTH_SESSION_REVOKED: 401
- AUTH_MFA_REQUIRED: 401
- AUTH_MFA_INVALID: 401
- AUTH_EMAIL_NOT_VERIFIED: 403
- PROFILE_LIMIT_REACHED: 409
- PROFILE_NAME_EXISTS: 409
- PROFILE_NOT_FOUND: 404
- PROFILE_FORBIDDEN: 403 (not owner)
- CONTENT_NOT_FOUND: 404
- CONTENT_NOT_PUBLISHED: 404
- CONTENT_NOT_AVAILABLE_IN_REGION: 403
- SUBSCRIPTION_REQUIRED: 402
- SUBSCRIPTION_INACTIVE: 402
- SUBSCRIPTION_PAST_DUE: 402
- CONCURRENT_STREAM_LIMIT: 403
- QUALITY_NOT_ALLOWED: 403
- INVALID_MANIFEST_TOKEN: 401
- MANIFEST_EXPIRED: 401
- DEVICE_LIMIT_REACHED: 409
- RATE_LIMIT_EXCEEDED: 429
- VALIDATION_ERROR: 400
- INTERNAL_ERROR: 500
*/
```

---

## 4. Supabase Configuration

### 4.1 Auth Settings

```yaml
# supabase/config.yml (Auth section)
auth:
  site_url: "https://app.netflix-clone.com"
  additional_redirect_urls:
    - "https://app.netflix-clone.com/auth/callback"
    - "netflixclone://auth/callback"
    - "http://localhost:3000/auth/callback"
  
  # Email/Password
  enable_signup: true
  enable_email_confirm: true
  enable_phone_confirm: false
  enable_phone_signup: false
  
  # Password Requirements
  password_min_length: 8
  password_require_uppercase: true
  password_require_lowercase: true
  password_require_numbers: true
  password_require_symbols: true
  
  # Session
  jwt_expiry: 900  # 15 minutes
  refresh_token_reuse_interval: 10  # seconds
  refresh_token_rotation_enabled: true
  
  # MFA
  mfa_enabled: true
  mfa_max_enrolled_factors: 5
  
  # OAuth Providers
  external:
    google:
      enabled: true
      client_id: "${GOOGLE_CLIENT_ID}"
      secret: "${GOOGLE_CLIENT_SECRET}"
      redirect_uri: "https://app.netflix-clone.com/auth/callback"
      scopes: ["openid", "email", "profile"]
    apple:
      enabled: true
      client_id: "${APPLE_CLIENT_ID}" # Services ID
      secret: "${APPLE_CLIENT_SECRET}" # Generated JWT
      redirect_uri: "https://app.netflix-clone.com/auth/callback"
      scopes: ["name", "email"]
  
  # Rate Limits
  rate_limits:
    signup: "10/hour/IP"
    login: "20/hour/IP"
    password_recovery: "5/hour/IP"
    mfa_enroll: "10/hour/user"
    mfa_verify: "20/hour/user"
  
  # Security
  security:
    enable_captcha_protection: true
    captcha_provider: "turnstile"
    captcha_secret: "${TURNSTILE_SECRET}"
    block_suspicious_ips: true
    lockout_duration: 900 # 15 minutes
    max_failed_attempts: 5
```

### 4.2 Storage Buckets

```yaml
# supabase/config.yml (Storage section)
storage:
  buckets:
    # Posters (2:3 aspect, WebP/AVIF, multiple sizes)
    content-posters:
      public: true
      file_size_limit: 10485760  # 10MB
      allowed_mime_types:
        - "image/webp"
        - "image/avif"
        - "image/jpeg"
        - "image/png"
      # CDN: Cloudflare Images or CloudFront
      # Transform: auto-format, quality=85, widths=[150,200,300,450,600]
    
    # Backdrops (16:9 aspect)
    content-backdrops:
      public: true
      file_size_limit: 20971520  # 20MB
      allowed_mime_types:
        - "image/webp"
        - "image/avif"
        - "image/jpeg"
      # Transform: widths=[320,640,1280,1920,3840]
    
    # Logos (transparent)
    content-logos:
      public: true
      file_size_limit: 5242880  # 5MB
      allowed_mime_types:
        - "image/webp"
        - "image/png"
        - "image/svg+xml"
    
    # Profile Avatars
    profile-avatars:
      public: false  # signed URLs only
      file_size_limit: 5242880  # 5MB
      allowed_mime_types:
        - "image/webp"
        - "image/jpeg"
        - "image/png"
      # Transform: square crop, widths=[64,128,256,512]
    
    # Video (HLS segments + manifests) - managed by Mux
    # NOT stored in Supabase Storage directly
    # content-videos bucket used only for uploads before Mux ingest
    
    # Subtitles (WebVTT)
    subtitles:
      public: false
      file_size_limit: 1048576  # 1MB
      allowed_mime_types:
        - "text/vtt"
        - "text/plain"

  # Signed URL expiry
  signed_url_expiry: 3600  # 1 hour
  
  # CDN Configuration
  cdn:
    provider: "cloudflare"
    domain: "cdn.netflix-clone.com"
    # Cache rules:
    # - posters/backdrops: 1 year (immutable with hash)
    # - avatars: 1 day (signed)
    # - subtitles: 1 hour (signed)
```

### 4.3 Realtime Configuration

```yaml
# supabase/config.yml (Realtime section)
realtime:
  enabled: true
  max_connections: 10000
  max_channels_per_connection: 20
  max_payload_size: 1048576  # 1MB
  
  # Channels
  channels:
    # Watch Party Sync
    - name: "watch-party:*"
      config:
        join_leave_events: true
        broadcast:
          ack: true
          self: false
        presence:
          enabled: true
          max_members: 50
    
    # Live Progress Sync (for continue watching across devices)
    - name: "progress:{profile_id}"
      config:
        broadcast:
          ack: false
          self: true
        presence:
          enabled: false
    
    # Notifications
    - name: "notifications:{profile_id}"
      config:
        broadcast:
          ack: true
          self: false
        presence:
          enabled: false
    
    # Concurrent Stream Monitoring
    - name: "streams:{user_id}"
      config:
        broadcast:
          ack: true
          self: false
        presence:
          enabled: true
          max_members: 10

  # Event Types (for client subscription)
  events:
    - "watch_party:state_change"    # {party_id, state, position, playing}
    - "watch_party:user_join"       # {party_id, user, profile}
    - "watch_party:user_leave"      # {party_id, user}
    - "progress:update"             # {profile_id, content_id, episode_id, position, duration}
    - "notification:new"            # {profile_id, notification}
    - "streams:limit_warning"       # {user_id, active, max, percentage}
    - "streams:limit_exceeded"      # {user_id, active, max, terminated_session}
```

### 4.4 Edge Functions

```yaml
# supabase/functions/
functions:
  # Stripe Webhooks
  stripe-webhook:
    path: "/stripe-webhook"
    method: POST
    secrets:
      - "STRIPE_WEBHOOK_SECRET"
    verify_jwt: false  # Stripe sends raw
    body: "raw"
    description: "Handle Stripe events: subscription changes, payments, invoices"
    events:
      - "customer.subscription.created"
      - "customer.subscription.updated"
      - "customer.subscription.deleted"
      - "invoice.payment_succeeded"
      - "invoice.payment_failed"
      - "invoice.upcoming"
      - "payment_method.attached"
      - "checkout.session.completed"
  
  # Recommendation Generation (Cron: daily 3AM UTC)
  generate-recommendations:
    path: "/generate-recommendations"
    method: POST
    secrets:
      - "SUPABASE_SERVICE_ROLE_KEY"
    verify_jwt: true
    cron: "0 3 * * *"
    description: "Generate nightly recommendations for all active profiles"
    # Algorithm: Content-based + Trending + Collaborative (Phase 2)
    # Output: upsert to public.recommendations
  
  # Video Processing Callback (from Mux)
  video-callback:
    path: "/video-callback"
    method: POST
    secrets:
      - "MUX_WEBHOOK_SECRET"
    verify_jwt: false
    body: "json"
    description: "Handle Mux webhooks: asset.ready, asset.error, live.stream_ended"
    events:
      - "video.asset.created"
      - "video.asset.ready"
      - "video.asset.errored"
      - "video.asset.deleted"
      - "video.master_access.ready"
  
  # Signed Manifest Generation
  signed-manifest:
    path: "/signed-manifest"
    method: GET
    verify_jwt: true
    description: "Generate signed HLS manifest URL with DRM tokens"
    params:
      - content_id (uuid, required)
      - episode_id (uuid, optional)
      - profile_id (uuid, required)
      - quality (enum, default: auto)
    logic:
      1. Validate profile ownership
      2. Check subscription status & tier
      3. Check concurrent stream limit (active sessions < max)
      4. Check content availability in user's country
      5. Create viewing_sessions record with session_token
      6. Generate signed Mux playback URL with token
      7. Return manifest_url, session_token, expires_at, max_quality
  
  # Heartbeat Handler
  video-heartbeat:
    path: "/video-heartbeat"
    method: POST
    verify_jwt: true
    description: "Update viewing session heartbeat, enforce concurrent limits"
    body:
      session_token: string
      position_seconds: number
      duration_seconds: number
    logic:
      1. Validate session_token exists and not ended
      2. Update last_heartbeat, position
      3. Check concurrent sessions for user
      4. If over limit: return warning, terminate oldest
      5. Return active_sessions, max_sessions, warning_threshold
  
  # Search Index Sync (on content changes)
  search-sync:
    path: "/search-sync"
    method: POST
    verify_jwt: true
    secrets:
      - "MEILISEARCH_API_KEY"
    description: "Sync content to MeiliSearch for instant search"
    triggers:
      - "content.insert"
      - "content.update"
      - "content.delete"
  
  # Analytics Ingestion
  analytics-ingest:
    path: "/analytics"
    method: POST
    verify_jwt: true
    description: "Batch ingest analytics events, forward to PostHog"
    body:
      events: AnalyticsEvent[]
    logic:
      1. Validate events schema
      2. Insert to public.analytics_events (batched)
      3. Forward to PostHog/Segment (async)
      4. Return accepted count
  
  # Profile Switch Token Exchange
  profile-switch:
    path: "/profile-switch"
    method: POST
    verify_jwt: true
    description: "Exchange access token for new token with profile claim"
    body:
      profile_id: uuid
    logic:
      1. Validate profile belongs to user
      2. Check profile limit for subscription
      3. Generate new JWT with profile_id claim
      4. Return new access_token, refresh_token, profile
```

---

## 5. Security & Rate Limiting

### 5.1 Rate Limits (API Gateway / Kong / Supabase)

```yaml
# Rate limiting tiers
rate_limits:
  # Anonymous
  anonymous:
    default: "100/minute/IP"
    auth: "20/minute/IP"
    search: "30/minute/IP"
    content_list: "60/minute/IP"
  
  # Authenticated (per user)
  authenticated:
    default: "300/minute/user"
    watch_history_progress: "120/minute/user"  # 2/sec for heartbeat
    video_manifest: "30/minute/user"
    video_heartbeat: "120/minute/user"
    profiles_crud: "30/minute/user"
    subscription_changes: "10/hour/user"
    device_management: "20/minute/user"
  
  # Admin (service role)
  admin:
    default: "1000/minute"
  
  # Strict limits
  strict:
    login: "5/minute/IP"
    signup: "3/hour/IP"
    password_reset: "3/hour/IP"
    mfa_verify: "10/minute/user"
    checkout: "10/hour/user"
```

### 5.2 Security Headers (via Edge Function / Middleware)

```typescript
// middleware.ts (Next.js) or Edge Function
const securityHeaders = {
  // Content Security Policy
  'Content-Security-Policy': [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.netflix-clone.com https://js.stripe.com https://cdn.mux.com",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
    "font-src 'self' data: https://fonts.gstatic.com",
    "img-src 'self' data: https: blob:",
    "media-src 'self' https://*.mux.com https://*.stream.mux.com blob:",
    "connect-src 'self' https://*.supabase.co https://api.stripe.com https://*.mux.com wss://*.supabase.co",
    "frame-src 'self' https://js.stripe.com https://hooks.stripe.com",
    "worker-src 'self' blob:",
    "manifest-src 'self'",
    "frame-ancestors 'none'",
    "base-uri 'self'",
    "form-action 'self' https://checkout.stripe.com"
  ].join('; '),
  
  // Other headers
  'X-Frame-Options': 'DENY',
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'Permissions-Policy': [
    'accelerometer=()',
    'camera=()',
    'geolocation=()',
    'gyroscope=()',
    'magnetometer=()',
    'microphone=()',
    'payment=(self "https://checkout.stripe.com")',
    'usb=()',
    'fullscreen=(self)',
    'picture-in-picture=(self)'
  ].join(', '),
  'Strict-Transport-Security': 'max-age=31536000; includeSubDomains; preload',
  'X-DNS-Prefetch-Control': 'on',
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Resource-Policy': 'same-origin',
  'Cross-Origin-Embedder-Policy': 'require-corp'
};

// CORS (for API routes)
const corsHeaders = {
  'Access-Control-Allow-Origin': 'https://app.netflix-clone.com',
  'Access-Control-Allow-Methods': 'GET, POST, PATCH, DELETE, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Client-Info, X-Request-ID',
  'Access-Control-Allow-Credentials': 'true',
  'Access-Control-Max-Age': '86400'
};
```

### 5.3 Input Validation (Zod Schemas)

```typescript
// validation/schemas.ts
import { z } from 'zod';

export const createProfileSchema = z.object({
  name: z.string().min(1).max(20).regex(/^[a-zA-Z0-9\s\-_']+$/),
  avatarUrl: z.string().url().optional().nullable(),
  maturityRating: z.enum(['G', 'PG', 'PG-13', 'R', 'NC-17']).default('PG-13'),
  isKids: z.boolean().default(false),
  language: z.string().length(2).default('en'),
  kidsPin: z.string().length(4).regex(/^\d{4}$/).optional().nullable(),
});

export const updateProgressSchema = z.object({
  profileId: z.string().uuid(),
  contentId: z.string().uuid(),
  episodeId: z.string().uuid().optional().nullable(),
  seasonId: z.string().uuid().optional().nullable(),
  progressSeconds: z.number().int().min(0),
  durationSeconds: z.number().int().min(1),
  deviceId: z.string().uuid().optional().nullable(),
  platform: z.enum(['ios', 'android', 'web', 'tv']).optional(),
});

export const searchSchema = z.object({
  q: z.string().min(2).max(100),
  type: z.enum(['movie', 'series', 'special']).optional(),
  genre: z.string().optional(),
  yearFrom: z.number().int().min(1900).max(2030).optional(),
  yearTo: z.number().int().min(1900).max(2030).optional(),
  maturity: z.enum(['G', 'PG', 'PG-13', 'R', 'NC-17']).optional(),
  language: z.string().length(2).optional(),
  page: z.number().int().min(1).default(1),
  limit: z.number().int().min(1).max(50).default(20),
});

export const checkoutSchema = z.object({
  plan: z.enum(['basic', 'standard', 'premium']),
  successUrl: z.string().url(),
  cancelUrl: z.string().url(),
  promotionCode: z.string().optional(),
});

export const manifestSchema = z.object({
  contentId: z.string().uuid(),
  episodeId: z.string().uuid().optional(),
  profileId: z.string().uuid(),
  quality: z.enum(['auto', '360p', '480p', '720p', '1080p', '4k']).default('auto'),
});

export const heartbeatSchema = z.object({
  sessionToken: z.string().min(1),
  positionSeconds: z.number().int().min(0),
  durationSeconds: z.number().int().min(1),
});
```

---

## 6. Acceptance Checks for Ange (QA)

### Schema Completeness
- [ ] All 15 core tables defined with columns, types, constraints
- [ ] All foreign keys and indexes specified
- [ ] RLS enabled on all tables with correct policies
- [ ] Helper functions for profile ownership, subscription access
- [ ] Triggers for updated_at, profile limits, watch completion, episode counts, list positions, session heartbeats
- [ ] Partitioning strategy documented for watch_history, analytics_events

### API Contract Completeness
- [ ] All 50+ endpoints documented with method, path, auth, request, response, errors
- [ ] Request/response TypeScript interfaces for all entities
- [ ] Error codes cataloged with HTTP status mapping
- [ ] Pagination, filtering, sorting patterns consistent

### Supabase Configuration
- [ ] Auth settings: providers, password policy, session, MFA, rate limits
- [ ] Storage buckets: 5 buckets with size limits, MIME types, CDN config
- [ ] Realtime: 4 channel configs with presence/broadcast settings
- [ ] Edge Functions: 8 functions with triggers, secrets, logic descriptions

### Security
- [ ] Rate limiting tiers defined (anonymous, authenticated, admin, strict)
- [ ] Security headers: CSP, HSTS, frame options, permissions policy
- [ ] CORS configuration for API routes
- [ ] Input validation schemas (Zod) for all mutations
- [ ] DRM integration points documented (FairPlay, Widevine, PlayReady)

### Integration with UI Specs
- [ ] API response shapes match UI component data requirements
- [ ] Query keys align with React Query patterns from UI specs
- [ ] Error codes map to UI error states
- [ ] WebSocket events match UI realtime needs (watch party, progress, notifications)

### Performance
- [ ] Critical indexes identified for Continue Watching, Search, Trending
- [ ] Materialized recommendations table with nightly refresh
- [ ] Signed URL expiry and CDN caching strategy
- [ ] Concurrent stream enforcement at manifest generation + heartbeat

---

## 7. Log Entry

- 2026-11-11 — Backend specs completed → wiki/backend-api.md