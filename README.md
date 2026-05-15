# MBUS — Morong–SBMA Bus Reservation System

PHP bus reservation app backed by **Supabase (PostgreSQL)**.

- **GitHub:** https://github.com/Cedric-23/MBUS
- **Database:** Supabase (always online in the cloud)

## Local development (XAMPP)

1. Start **Apache** in XAMPP (MySQL is not required).
2. Copy `.env.example` to `.env` and set `SUPABASE_DB_PASSWORD`.
3. Open `http://localhost/MBus/login.php`

## Deploy online (works when your laptop is off)

Use **[Render](https://render.com)** (free tier) to host the PHP app. Your database stays on Supabase.

### Step 1 — Push code (already on GitHub)

Repo: https://github.com/Cedric-23/MBUS

### Step 2 — Create Render account

1. Go to https://render.com and sign up (GitHub login is easiest).
2. Connect your GitHub account.

### Step 3 — New Web Service

1. Click **New +** → **Blueprint** (or **Web Service**).
2. Connect repository **Cedric-23/MBUS**.
3. If using Blueprint, Render reads `render.yaml` automatically.
4. If manual: set **Runtime** to **Docker**, **Region** → Singapore (near Supabase).

### Step 4 — Environment variables

In Render → your service → **Environment**, add:

| Key | Value |
|-----|--------|
| `SUPABASE_DB_HOST` | `aws-1-ap-south-1.pooler.supabase.com` |
| `SUPABASE_DB_PORT` | `5432` |
| `SUPABASE_DB_NAME` | `postgres` |
| `SUPABASE_DB_USER` | `postgres.xeczvnheaixpfattbwsk` |
| `SUPABASE_DB_PASSWORD` | *(your Supabase database password)* |
| `SUPABASE_URL` | `https://xeczvnheaixpfattbwsk.supabase.co` |
| `SUPABASE_ANON_KEY` | *(from Supabase → Settings → API)* |

Do **not** commit passwords to GitHub.

### Step 5 — Deploy

Click **Deploy**. When finished, Render gives you a URL like:

`https://mbus-xxxx.onrender.com`

Open:

- `https://your-app.onrender.com/login.php`

### Notes

- **Free tier:** the site may sleep after ~15 minutes of no traffic; the first visit can take ~30 seconds to wake up.
- For **always-fast** hosting, use a paid plan or shared PHP hosting (Hostinger, etc.).
- Database setup (`scripts/setup_supabase.php`) only needs to run **once** — already done on Supabase.

## Repository

https://github.com/Cedric-23/MBUS
