# Netflix Clone Research Summary

**Project:** Netflix Clone — Streaming Platform  
**Date:** 2026-11-11  
**Researcher:** Cedric  
**Source:** [[Netflix Clone Plan]] (wiki/netflix-clone-plan.md)  
**Output For:** [[Fuji]] (UI Specs) + [[Ash]] (Backend Specs)

---

## Executive Summary

Building a Netflix clone requires a modern full-stack architecture optimized for video delivery, real-time user interactions, and scalable content management. This research extracts detailed requirements, evaluates the tech stack, identifies constraints, and defines the handoff specifications for Fuji (frontend) and Ash (backend).

---

## 1. Tech Stack Analysis

### Frontend
| Technology | Version | Purpose | Rationale |
|------------|---------|---------|-----------|
| **Next.js** | 14+ (App Router) | React framework | Server components, streaming, edge runtime, image optimization |
| **React** | 18+ | UI library | Concurrent features, Suspense, transitions |
| **TypeScript** | 5+ | Type safety | End-to-end type safety with backend contracts |
| **Tailwind CSS** | 3.4+ | Styling | Utility-first, dark mode, responsive, Netflix-like theming |
| **TanStack Query** | 5+ | Server state | Caching, prefetching, optimistic updates, infinite scroll |
| **Zustand** | 4+ | Client state | Lightweight, player state, UI modals, sidebar |
| **Video.js / Mux Player** | Latest | Video playback | HLS/DASH, adaptive bitrate, DRM support, custom UI |
| **React Hook Form + Zod** | Latest | Forms | Validation, type-safe schemas |

### Backend (Supabase)
| Service | Purpose | Configuration |
|---------|---------|---------------|
| **PostgreSQL** | Primary database | Row Level Security, partitioning for watch_history |
| **Auth** | Authentication | Email/password, OAuth (Google, Apple), Magic Links, MFA |
| **Storage** | File storage | 4 buckets: posters, backdrops, videos, avatars + CDN |
| **Edge Functions** | Serverless compute | Stripe webhooks, recommendation cron, video callbacks |
| **Realtime** | WebSocket subscriptions | Watch party, live progress, notifications |
| **pgvector** | Vector embeddings | Content-based recommendations (future) |

### Infrastructure
| Component | Choice | Notes |
|-----------|--------|-------|
| **Video Hosting** | Mux / AWS MediaConvert | Transcoding, HLS/DASH, DRM (FairPlay/Widevine) |
| **CDN** | Cloudflare / CloudFront | Global delivery, signed URLs for protected content |
| **Payments** | Stripe | Subscriptions, billing portal, webhooks |
| **Search** | Meilisearch / Algolia | Instant search, typo tolerance, filters |
| **Analytics** | PostHog / Mixpanel | Event tracking, funnels, retention |
| **Monitoring** | Sentry + Supabase logs | Error tracking, performance |

---

## 2. Feature Requirements (Detailed)

### 2.1 Authentication & User Management
**Requirements:**
- Email/password with secure password requirements (min 8 chars, 1 uppercase, 1 number, 1 special)
- OAuth 2.0: Google, Apple (Sign in with Apple required for iOS)
- Magic links (passwordless) — 15-min expiry, single-use
- Multi-factor authentication (TOTP, SMS backup)
- Session management: JWT access tokens (15 min), refresh tokens (30 days), device tracking
- Account recovery: email verification, password reset flow
- **Profile switching** — seamless switch without re-auth, profile context in JWT claims

### 2.2 User Profiles
**Requirements:**
- Max 5 profiles per account (configurable per plan)
- Profile fields: name, avatar (upload/custom), maturity rating (G, PG, PG-13, R, NC-17), language, autoplay settings, kids mode toggle
- Kids mode: PIN-protected exit, content filtering by maturity, simplified UI
- Profile avatars: preset gallery + custom upload (Storage bucket: `profile-avatars`)
- **Profile context** — all queries scoped to `profile_id` via RLS

### 2.3 Content Catalog
**Content Types:**
- **Movie** — single video, ~90-180 min
- **Series** — seasons + episodes, episodic
- **Special** — stand-up, documentary, short-form

**Metadata per Content:**
| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | Yes | Primary key |
| `title` | text | Yes | Searchable, indexed |
| `type` | enum | Yes | 'movie' \| 'series' \| 'special' |
| `description` | text | Yes | Full synopsis |
| `short_description` | text | Yes | Card preview (max 200 chars) |
| `poster_url` | text | Yes | Vertical poster (2:3) |
| `backdrop_url` | text | Yes | Horizontal banner (16:9) |
| `release_year` | int | Yes | Filtering, sorting |
| `duration_seconds` | int | Movies only | For progress calculation |
| `maturity_rating` | enum | Yes | G, PG, PG-13, R, NC-17 |
| `trailer_url` | text | No | HLS manifest for preview |
| `tags` | text[] | No | "Oscar Winner", "Trending" |
| `cast` | jsonb | No | [{name, role, character}] |
| `crew` | jsonb | No | [{name, role}] |

**Genres & Categories:**
- Genres: Action, Comedy, Drama, Horror, Sci-Fi, Documentary, Animation, etc. (20+)
- Categories: "Trending Now", "New Releases", "Top 10 in [Country]", "Because You Watched", "Continue Watching", "My List"
- Collections: "Marvel Universe", "80s Classics", "Award Winners"

### 2.4 Video Playback
**Core Requirements:**
- **Adaptive Bitrate Streaming** — HLS (primary), DASH (fallback), multiple quality levels (1080p, 720p, 480p, 360p)
- **DRM** — FairPlay (Apple), Widevine (Android/Chrome), PlayReady (Edge) — via Mux/Cloudflare Stream
- **Resume Playback** — Save position every 10s, restore on reopen
- **Skip Intro** — ML-based detection or manual timestamps per episode
- **Next Episode Autoplay** — 15s countdown, dismissible, respects profile settings
- **Picture-in-Picture** — Native browser PiP API, custom overlay controls
- **Keyboard Shortcuts** — Space (play/pause), ←/→ (seek ±10s), ↑/↓ (volume), F (fullscreen), M (mute), N (next)
- **Subtitles/CC** — WebVTT, multiple languages, styling options (size, color, background)
- **Audio Tracks** — Multiple languages, audio descriptions
- **Quality Selector** — Auto (ABR) + manual override
- **Playback Speed** — 0.5x, 0.75x, 1x, 1.25x, 1.5x, 2x

**Player Events to Track:**
`play`, `pause`, `seek`, `quality_change`, `subtitle_change`, `fullscreen_change`, `error`, `complete`, `progress` (every 10s)

### 2.5 Recommendations
**Approaches (Phased):**
1. **Phase 1 (Launch):** Content-based + Popularity
   - "Because you watched X" → similar genres, actors, directors
   - "Trending Now" → view velocity (views/week) + completion rate
   - "Top Picks for You" → weighted by watch history genres
2. **Phase 2 (Post-Launch):** Collaborative Filtering
   - Matrix factorization (ALS) on watch_history
   - User-user similarity for "Profiles like you"
3. **Phase 3 (Advanced):** Hybrid + Contextual
   - Time-of-day, device, seasonal trends
   - pgvector embeddings for semantic similarity
   - A/B testing framework for algorithm variants

**Recommendation Data Model:**
```sql
recommendations (profile_id, content_id, score, reason, generated_at)
-- reason enum: 'similar_genre', 'same_actor', 'trending', 'collaborative', 'popular'
```

**Generation:** Nightly cron via Edge Function, stores top 50 per profile

### 2.6 Watch History & Continue Watching
**Requirements:**
- Track: `profile_id`, `content_id`, `episode_id` (nullable for movies), `progress_seconds`, `duration_seconds`, `completed_at`, `updated_at`
- **Continue Watching Row** — In-progress items (progress > 5% && < 95%), sorted by `updated_at` desc
- **Progress Bar** — Visual indicator on content cards
- **Remove from History** — Soft delete, hides from Continue Watching
- **Clear All History** — Profile-scoped, irreversible
- **Episode Tracking** — For series, track per-episode; auto-advance to next unwatched

### 2.7 Subscription & Billing
**Plans:**
| Plan | Price/Month | Streams | Quality | Profiles | Downloads |
|------|-------------|---------|---------|----------|-----------|
| Basic | $9.99 | 1 | 720p | 1 | ❌ |
| Standard | $15.99 | 2 | 1080p | 2 | ✅ |
| Premium | $22.99 | 4 | 4K+HDR | 4 | ✅ |

**Stripe Integration:**
- Products + Prices in Stripe Dashboard
- Subscription lifecycle: `trialing` → `active` → `past_due` → `canceled`
- Webhooks: `invoice.payment_succeeded`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`
- Billing Portal: Customer self-service (update payment, cancel, download invoices)
- **Concurrent Stream Enforcement** — Realtime presence tracking, hard limit per plan

### 2.8 Search & Discovery
**Requirements:**
- Instant search (debounced 300ms) — title, cast, genre, director
- Filters: Type (Movie/Series), Genre, Year Range, Maturity, Language
- Categories: Rows by genre, "Trending", "New This Week", "Expiring Soon"
- **Search Results Page** — Infinite scroll, server-side pagination
- **Empty State** — Suggestions, "No results for X, try Y"

---

## 3. Database Schema (Detailed for Ash)

### Core Tables

```sql
-- Users (Supabase Auth manages auth.users, this extends it)
CREATE TABLE public.users (
  id uuid PRIMARY KEY REFERENCES auth.users(id) ON DELETE CASCADE,
  email text UNIQUE NOT NULL,
  stripe_customer_id text UNIQUE,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Profiles (scoped to user)
CREATE TABLE public.profiles (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  name text NOT NULL,
  avatar_url text,
  maturity_rating text NOT NULL DEFAULT 'PG-13' CHECK (maturity_rating IN ('G','PG','PG-13','R','NC-17')),
  is_kids boolean DEFAULT false,
  language text DEFAULT 'en',
  autoplay_next boolean DEFAULT true,
  skip_intro boolean DEFAULT true,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now(),
  UNIQUE (user_id, name)
);

-- Content (movies, series, specials)
CREATE TABLE public.content (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  title text NOT NULL,
  type text NOT NULL CHECK (type IN ('movie','series','special')),
  description text NOT NULL,
  short_description text,
  poster_url text NOT NULL,
  backdrop_url text NOT NULL,
  release_year int NOT NULL,
  duration_seconds int, -- movies only
  maturity_rating text NOT NULL CHECK (maturity_rating IN ('G','PG','PG-13','R','NC-17')),
  trailer_url text,
  tags text[],
  cast jsonb,
  crew jsonb,
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Genres
CREATE TABLE public.genres (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text UNIQUE NOT NULL,
  slug text UNIQUE NOT NULL,
  description text
);

-- Content-Genres (many-to-many)
CREATE TABLE public.content_genres (
  content_id uuid REFERENCES public.content(id) ON DELETE CASCADE,
  genre_id uuid REFERENCES public.genres(id) ON DELETE CASCADE,
  PRIMARY KEY (content_id, genre_id)
);

-- Seasons (for series)
CREATE TABLE public.seasons (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  season_number int NOT NULL,
  title text,
  overview text,
  poster_url text,
  release_date date,
  created_at timestamptz DEFAULT now(),
  UNIQUE (content_id, season_number)
);

-- Episodes
CREATE TABLE public.episodes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  season_id uuid NOT NULL REFERENCES public.seasons(id) ON DELETE CASCADE,
  episode_number int NOT NULL,
  title text NOT NULL,
  overview text,
  duration_seconds int NOT NULL,
  video_url text NOT NULL, -- signed HLS manifest URL
  thumbnail_url text,
  release_date date,
  created_at timestamptz DEFAULT now(),
  UNIQUE (season_id, episode_number)
);

-- Watch History
CREATE TABLE public.watch_history (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  episode_id uuid REFERENCES public.episodes(id) ON DELETE SET NULL,
  progress_seconds int NOT NULL DEFAULT 0,
  duration_seconds int NOT NULL,
  completed_at timestamptz,
  updated_at timestamptz DEFAULT now(),
  created_at timestamptz DEFAULT now()
);

-- Index for Continue Watching query
CREATE INDEX idx_watch_history_continue_watching 
  ON public.watch_history (profile_id, updated_at DESC) 
  WHERE progress_seconds > 0 AND progress_seconds < duration_seconds * 0.95;

-- Subscriptions
CREATE TABLE public.subscriptions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL REFERENCES public.users(id) ON DELETE CASCADE,
  stripe_subscription_id text UNIQUE,
  plan text NOT NULL CHECK (plan IN ('basic','standard','premium')),
  status text NOT NULL CHECK (status IN ('trialing','active','past_due','canceled','incomplete')),
  current_period_end timestamptz NOT NULL,
  cancel_at_period_end boolean DEFAULT false,
  concurrent_streams int NOT NULL DEFAULT 1,
  max_quality text NOT NULL DEFAULT '720p' CHECK (max_quality IN ('720p','1080p','4k')),
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now()
);

-- Devices (for stream enforcement + notifications)
CREATE TABLE public.devices (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  device_token text NOT NULL, -- FCM/APNs token
  platform text NOT NULL CHECK (platform IN ('ios','android','web','tv')),
  user_agent text,
  last_active_at timestamptz DEFAULT now(),
  created_at timestamptz DEFAULT now()
);

-- Recommendations (materialized, refreshed nightly)
CREATE TABLE public.recommendations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  profile_id uuid NOT NULL REFERENCES public.profiles(id) ON DELETE CASCADE,
  content_id uuid NOT NULL REFERENCES public.content(id) ON DELETE CASCADE,
  score numeric(4,3) NOT NULL, -- 0.000 to 1.000
  reason text NOT NULL CHECK (reason IN ('similar_genre','same_actor','trending','collaborative','popular')),
  generated_at timestamptz DEFAULT now(),
  UNIQUE (profile_id, content_id)
);

-- My List (saved content)
CREATE TABLE public.my_list (
  profile_id uuid REFERENCES public.profiles(id) ON DELETE CASCADE,
  content_id uuid REFERENCES public.content(id) ON DELETE CASCADE,
  added_at timestamptz DEFAULT now(),
  PRIMARY KEY (profile_id, content_id)
);
```

### Row Level Security Policies

```sql
-- Enable RLS on all tables
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.content ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.watch_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.subscriptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.devices ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.recommendations ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.my_list ENABLE ROW LEVEL SECURITY;

-- Profiles: users see only their own profiles
CREATE POLICY "Users manage own profiles" ON public.profiles
  FOR ALL USING (user_id = auth.uid());

-- Content: public read (published content), admin write
CREATE POLICY "Public read published content" ON public.content
  FOR SELECT USING (true); -- Add published_at filter later

-- Watch History: profile-scoped
CREATE POLICY "Profiles manage own watch history" ON public.watch_history
  FOR ALL USING (profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid()));

-- Subscriptions: user-scoped
CREATE POLICY "Users view own subscription" ON public.subscriptions
  FOR SELECT USING (user_id = auth.uid());

-- Recommendations: profile-scoped
CREATE POLICY "Profiles view own recommendations" ON public.recommendations
  FOR SELECT USING (profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid()));

-- My List: profile-scoped
CREATE POLICY "Profiles manage own list" ON public.my_list
  FOR ALL USING (profile_id IN (SELECT id FROM public.profiles WHERE user_id = auth.uid()));
```

---

## 4. API Surface (for Ash)

### Authentication
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/signup` | None | Email/password registration |
| POST | `/auth/login` | None | Email/password login |
| POST | `/auth/oauth/{provider}` | None | OAuth initiation (Google, Apple) |
| POST | `/auth/magic-link` | None | Send magic link |
| POST | `/auth/verify` | None | Verify magic link/OAuth callback |
| POST | `/auth/refresh` | Refresh Token | Rotate access token |
| POST | `/auth/logout` | Access Token | Revoke session |
| POST | `/auth/mfa/enroll` | Access Token | Enroll TOTP |
| POST | `/auth/mfa/verify` | Access Token | Verify MFA challenge |

### Profiles
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/profiles` | Access Token | List profiles for user |
| POST | `/profiles` | Access Token | Create profile |
| GET | `/profiles/{id}` | Access Token | Get profile (scoped to user) |
| PATCH | `/profiles/{id}` | Access Token | Update profile |
| DELETE | `/profiles/{id}` | Access Token | Delete profile |
| POST | `/profiles/{id}/avatar` | Access Token | Upload avatar |
| POST | `/profiles/{id}/switch` | Access Token | Set active profile (returns new JWT with profile claim) |

### Content
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/content` | Optional | List content (paginated, filterable) |
| GET | `/content/{id}` | Optional | Get content details |
| GET | `/content/{id}/seasons` | Optional | Get seasons for series |
| GET | `/content/{id}/episodes` | Optional | Get episodes (optionally by season) |
| GET | `/content/featured` | Optional | Hero carousel content |
| GET | `/content/trending` | Optional | Trending now |
| GET | `/content/continue-watching` | Access Token | Continue watching row |
| GET | `/content/my-list` | Access Token | User's saved list |
| POST | `/content/my-list` | Access Token | Add to list |
| DELETE | `/content/my-list/{id}` | Access Token | Remove from list |

### Search
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/search` | Optional | Search content (q, type, genre, year, maturity) |
| GET | `/search/suggestions` | Optional | Autocomplete suggestions |

### Watch History
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/watch-history` | Access Token | List history (paginated) |
| POST | `/watch-history/progress` | Access Token | Update progress (batch: profile_id, content_id, episode_id?, progress, duration) |
| DELETE | `/watch-history/{id}` | Access Token | Remove single entry |
| DELETE | `/watch-history` | Access Token | Clear all history |

### Recommendations
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/recommendations` | Access Token | Get recommendations (by reason) |
| POST | `/recommendations/refresh` | Access Token | Trigger regeneration (admin/cron) |

### Subscriptions
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/subscription` | Access Token | Get current subscription |
| POST | `/subscription/checkout` | Access Token | Create Stripe Checkout Session |
| POST | `/subscription/portal` | Access Token | Create Billing Portal Session |
| GET | `/subscription/plans` | None | List available plans |

### Video Playback
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/video/manifest/{content_id}` | Access Token | Get signed HLS manifest (validates subscription tier + concurrent streams) |
| GET | `/video/manifest/{content_id}/episode/{episode_id}` | Access Token | Get signed HLS manifest for episode |
| POST | `/video/heartbeat` | Access Token | Heartbeat for concurrent stream tracking |

---

## 5. Constraints & Risks

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| **Video hosting costs** | High | High | Use Mux/Cloudflare Stream (per-minute pricing), implement signed URLs with short TTL, restrict 4K to Premium |
| **DRM complexity** | High | Medium | Start with clearkey (dev), integrate Mux DRM for production, use FairPlay/Widevine |
| **Supabase rate limits** | Medium | Medium | Implement client-side caching (TanStack Query), batch writes, use Edge Functions for heavy ops |
| **Concurrent stream enforcement** | High | Medium | Realtime presence + heartbeat, hard limit at manifest generation |
| **Content licensing/regional** | High | Low (MVP) | Geo-blocking via Cloudflare Workers, content availability flags |
| **Search performance** | Medium | Medium | Meilisearch/Algolia for instant search, PostgreSQL full-text for admin |
| **Recommendation quality** | Medium | High | Start simple (content-based), measure CTR, iterate |
| **Mobile/TV app parity** | Medium | High | Responsive web first, React Native/TV apps later |
| **Supabase Auth limits** | Low | Low | 50k MAU free tier, monitor usage |

---

## 6. Open Questions

1. **Video Source:** Will we use sample content (Big Buck Bunny, Sintel) or integrate with a content provider API (TMDB, JustWatch)?
2. **DRM Decision:** Start with clearkey for development, Mux DRM for production? Budget for DRM licenses?
3. **Recommendation ML:** Build in-house (pgvector + Edge Functions) or use managed service (AWS Personalize, Recombee)?
4. **Offline Downloads:** Phase 1 or Phase 2? Requires native apps or Service Worker + IndexedDB (web).
5. **Watch Party:** Real-time sync via Supabase Realtime — Phase 1 or later?
6. **Parental Controls:** PIN per profile, maturity lock — detailed UX needed from Fuji.
7. **Analytics Events:** Define event taxonomy for PostHog — separate task.
8. **Internationalization:** i18n strategy — next.js i18n routing, content translation workflow.

---

## 7. Handoff Specifications

### For Fuji (UI Specs → `wiki/ui-components.md`)
**Needed from this research:**
1. Complete component list with variants (11+ components defined in Section 2)
2. Design tokens: Netflix Red `#E50914`, Dark `#141414`, Gray gradients, typography scale
3. Responsive breakpoints: 320, 768, 1024, 1440, 1920px
4. Player keyboard shortcuts, accessibility requirements
5. State management architecture (React Query + Zustand)
6. Profile switching UX, kids mode UI
7. Search UX: instant results, filters, empty states

### For Ash (Backend Specs → `wiki/backend-api.md`)
**Needed from this research:**
1. Complete SQL schema with all tables, indexes, RLS policies (Section 3)
2. API endpoint catalog with auth requirements, request/response shapes (Section 4)
3. Supabase Auth configuration (providers, MFA, session settings)
4. Storage bucket configuration with signed URL policies
5. Edge Function specifications (Stripe webhooks, recommendation cron, video callbacks)
6. Realtime channel definitions (watch party, progress sync, notifications)
7. Rate limiting, security headers, CORS configuration

---

## 8. Log Entry

- 2026-11-11 — Research completed → raw_sources/netflix-research.md