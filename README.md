# MBUS — Morong–SBMA Bus Reservation System

Public bus reservation system — PHP + Supabase PostgreSQL.

| Component | Host | Always online? |
|-----------|------|----------------|
| Database | [Supabase](https://supabase.com/dashboard/project/xeczvnheaixpfattbwsk) | Yes |
| Website | [Render](https://render.com) (recommended) | Yes (paid plan) |

**GitHub:** https://github.com/Cedric-23/MBUS

---

## Public production (real users, laptop off)

### Render (free — no credit card)

1. Sign up at https://render.com (GitHub login).
2. **New +** → **Blueprint** → repo **Cedric-23/MBUS**.
3. Set secrets when prompted:
   - `SUPABASE_DB_PASSWORD` — Supabase → Settings → Database
   - `SUPABASE_ANON_KEY` — Supabase → Settings → API
4. Click **Apply** (no payment needed on free plan).
5. Your public URL will look like:
   ```
   https://mbus.onrender.com/login.php
   ```

**Free plan note:** after ~15 minutes with no visitors, the site sleeps; the first visit may take ~30 seconds to wake up. For always-on, upgrade to **Starter** in Render later (requires card, ~$7/month).

### Environment variables (Render dashboard)

| Variable | Value |
|----------|--------|
| `MBUS_ENV` | `production` |
| `SUPABASE_DB_HOST` | `aws-1-ap-south-1.pooler.supabase.com` |
| `SUPABASE_DB_PORT` | `5432` |
| `SUPABASE_DB_NAME` | `postgres` |
| `SUPABASE_DB_USER` | `postgres.xeczvnheaixpfattbwsk` |
| `SUPABASE_DB_PASSWORD` | *(secret)* |
| `SUPABASE_URL` | `https://xeczvnheaixpfattbwsk.supabase.co` |
| `SUPABASE_ANON_KEY` | *(secret)* |

### After go-live checklist

- [ ] Test register, login, book seat, payment flow on the live URL
- [ ] Regenerate Supabase API keys if they were ever shared publicly
- [ ] Create admin/operator accounts via Supabase Table Editor or admin panel
- [ ] Optional: add custom domain in Render → Settings → Custom Domains

### Cheaper alternatives

- **Hostinger / Namecheap PHP hosting** (~₱150–300/mo) — upload files via FTP, add `.env` on server
- **Railway** — similar to Render, pay-as-you-go

---

## Local development (XAMPP)

1. Start **Apache** only (MySQL not needed).
2. Copy `.env.example` → `.env`, set `SUPABASE_DB_PASSWORD`.
3. Open `http://localhost/MBus/login.php`

---

## Database setup (one time)

Already applied if you ran setup before. To re-run:

```bash
php scripts/setup_supabase.php
```

Or run SQL files in Supabase SQL Editor: `00_reset.sql` → `01_schema.sql` → `02_data.sql`.

---

## Security (production)

- Test URLs (`dbtest.php`) are disabled when `MBUS_ENV=production`
- `/config`, `/scripts`, `/supabase` blocked via `.htaccess`
- HTTPS provided by Render automatically
- New commuters register at `/register.php` (Commuter role)
