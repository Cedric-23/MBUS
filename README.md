# MBUS — Morong–SBMA Bus Reservation System

PHP bus reservation app backed by **Supabase (PostgreSQL)**.

## Requirements

- PHP 8.2+ with `pdo_pgsql` and `pgsql` enabled
- Apache (XAMPP) or similar web server
- Supabase project: [xeczvnheaixpfattbwsk](https://supabase.com/dashboard/project/xeczvnheaixpfattbwsk)

## Setup

1. Copy environment file:
   ```bash
   copy .env.example .env
   ```
2. Set `SUPABASE_DB_PASSWORD` in `.env` (Supabase Dashboard → **Project Settings** → **Database**).
3. Create tables and seed data:
   ```bash
   php scripts/setup_supabase.php
   ```
4. Point your web server at this folder (e.g. `http://localhost/MBus/`).
5. Enable `extension=pdo_pgsql` and `extension=pgsql` in `php.ini`, then restart Apache.

## Deploy

Host on any PHP-capable platform (shared hosting, VPS, Railway, etc.). Set the same environment variables on the server and run `setup_supabase.php` once if the database is empty.

## Repository

https://github.com/Cedric-23/MBUS
