# How to Run MBUS System

This guide will help you set up and run the MBUS (Morong–SBMA Bus Reservation System) locally on your Windows machine.

---

## Prerequisites

### Required Software:
1. **PHP** (version 8.0 or higher)
   - Download from: https://www.php.net/downloads.php
   - Or use XAMPP which includes PHP + Apache

2. **Database**: Supabase PostgreSQL (cloud-hosted)
   - Account at: https://supabase.com
   - Free tier available

3. **Web Server**: Apache (included in XAMPP) or PHP built-in server

---

## Option 1: Using XAMPP (Recommended for Windows)

### Step 1: Install XAMPP
1. Download XAMPP from: https://www.apachefriends.org/download.html
2. Run the installer and complete the installation
3. Start XAMPP Control Panel

### Step 2: Place the MBUS Files
1. Navigate to: `C:\xampp\htdocs\`
2. Create a folder named `MBUS` (or copy the existing MBUS folder there)
3. Copy all MBUS files to: `C:\xampp\htdocs\MBUS\`

### Step 3: Configure Environment
1. Go to: `C:\xampp\htdocs\MBUS\`
2. Copy `.env.example` to `.env`
3. Edit `.env` with your Supabase credentials:

```bash
# Production vs Local
MBUS_ENV=local

# Supabase Database (get from Supabase Dashboard)
SUPABASE_DB_HOST=aws-1-ap-south-1.pooler.supabase.com
SUPABASE_DB_PORT=5432
SUPABASE_DB_NAME=postgres
SUPABASE_DB_USER=postgres.xeczvnheaixpfattbwsk
SUPABASE_DB_PASSWORD=your_actual_password_here

# Supabase API (get from Supabase Dashboard → Settings → API)
SUPABASE_URL=https://xeczvnheaixpfattbwsk.supabase.co
SUPABASE_ANON_KEY=your_actual_anon_key_here
SUPABASE_SERVICE_ROLE_KEY=your_actual_service_role_key_here

# SMTP Configuration (for password reset emails)
SMTP_HOST=smtp.gmail.com
SMTP_USERNAME=your_email@gmail.com
SMTP_PASSWORD=your_app_password
SMTP_SECURE=ssl
SMTP_PORT=465

# Google Maps API Key (optional - for route visualization)
GOOGLE_MAPS_API_KEY=your_google_maps_api_key
```

### Step 4: Start Apache
1. Open XAMPP Control Panel
2. Click "Start" next to Apache
3. MySQL is NOT needed (we use Supabase)

### Step 5: Setup Database
If this is a fresh installation, run the database setup:

**Option A: Using PHP script**
1. Open browser and go to: `http://localhost/MBUS/scripts/setup_supabase.php`
2. Wait for setup to complete

**Option B: Manual setup in Supabase**
1. Go to Supabase Dashboard → SQL Editor
2. Run these SQL files in order:
   - `supabase/00_reset.sql`
   - `supabase/01_schema.sql`
   - `supabase/02_data.sql`

### Step 6: Access the Application
Open your browser and navigate to:
- **Login page**: `http://localhost/MBUS/login.php`
- **Register page**: `http://localhost/MBUS/register.php`

---

## Option 2: Using PHP Built-in Server

### Step 1: Install PHP
1. Download PHP from: https://windows.php.net/download/
2. Extract to: `C:\php`
3. Add `C:\php` to your Windows PATH environment variable

### Step 2: Place the MBUS Files
1. Navigate to: `C:\Users\Admin\Documents\MBUS\MBUS\`
2. This is where your files already are

### Step 3: Configure Environment
1. Edit `.env` file with your Supabase credentials (same as above)
2. Make sure `.env` file exists in the MBUS folder

### Step 4: Start PHP Server
Open Command Prompt or PowerShell and run:

```bash
cd C:\Users\Admin\Documents\MBUS\MBUS
php -S localhost:8000
```

### Step 5: Access the Application
Open your browser and navigate to:
- **Login page**: `http://localhost:8000/login.php`
- **Register page**: `http://localhost:8000/register.php`

---

## Getting Supabase Credentials

### 1. Create Supabase Project
1. Go to: https://supabase.com
2. Sign up/login
3. Click "New Project"
4. Name your project (e.g., "MBUS")
5. Set database password (save this!)
6. Wait for project to be created (2-3 minutes)

### 2. Get Database Credentials
1. Go to: Project Settings → Database
2. Copy the following:
   - **Host**: Look for "Connection string" → "Session" → Host
   - **Port**: 5432 (or 6543 for session pooler)
   - **Database Name**: postgres
   - **User**: postgres.xxxxxxxxxxxx (your project ID)
   - **Password**: The password you set during project creation

### 3. Get API Keys
1. Go to: Project Settings → API
2. Copy:
   - **Project URL**: https://xxxxxxxxxxxxx.supabase.co
   - **anon public**: Your anon key
   - **service_role**: Your service role key (keep secret!)

---

## Getting SMTP Credentials (Optional but Recommended)

For password reset emails to work:

### 1. Using Gmail
1. Enable 2-Factor Authentication on your Google Account
2. Go to: https://myaccount.google.com/apppasswords
3. Create an "App Password" for "Mail"
4. Use the generated 16-character password as `SMTP_PASSWORD`
5. Your email is `SMTP_USERNAME`

### 2. Without SMTP
The system will still work without SMTP, but password reset emails won't be sent. Users will see an error message.

---

## Getting Google Maps API Key (Optional)

For route visualization:

1. Go to: https://console.cloud.google.com/
2. Create a new project
3. Enable "Maps JavaScript API"
4. Create an API key
5. Add the key to your `.env` file

**Without the key**: The maps won't load, but the system will still work.

---

## Default Accounts

After database setup, you can use these default accounts (if created in `02_data.sql`):

### Admin Account:
- Email: admin@mbus.com
- Password: admin123

### Test Commuter:
- Email: commuter@test.com
- Password: commuter123

### Test Operator:
- Email: operator@test.com
- Password: operator123

**Or register a new account at `/register.php`**

---

## Troubleshooting

### Issue: "Connection failed"
**Solution:**
- Check your `.env` file has correct Supabase credentials
- Verify Supabase project is active (not paused)
- Check if password is correct

### Issue: "Table not found"
**Solution:**
- Run the database setup script: `scripts/setup_supabase.php`
- Or manually run SQL files in Supabase SQL Editor

### Issue: "CSRF token validation failed"
**Solution:**
- Clear your browser cookies
- Try refreshing the page
- Ensure PHP sessions are working (check `session_save_path()`)

### Issue: "Security validation failed"
**Solution:**
- This is expected if you're testing forms without the CSRF token
- Make sure you're using the updated forms with `<?php csrf_field(); ?>`

### Issue: Images not loading
**Solution:**
- Check that `Assets/` folder exists in the correct location
- Verify file permissions

### Issue: "Hardcoded credentials" errors
**Solution:**
- Make sure you've created a `.env` file
- Copy from `.env.example` and fill in your actual credentials

---

## Testing the System

### 1. Register a New Account
1. Go to: `http://localhost/MBUS/register.php`
2. Fill in the form
3. Submit and verify account creation

### 2. Login
1. Go to: `http://localhost/MBUS/login.php`
2. Enter your credentials
3. You should be redirected to the commuter dashboard

### 3. View Schedules
1. From dashboard, click "My Reservations" or navigate to schedule
2. View available bus schedules
3. Check seat availability

### 4. Make a Reservation
1. Select a schedule with available seats
2. Choose your seat(s)
3. Select pickup and destination
4. Reserve for 10 minutes

### 5. Complete Payment
1. Enter payment details (test mode - any reference works)
2. Confirm payment
3. View your ticket

### 6. Test Admin Panel
1. Login as admin
2. Go to: `http://localhost/MBUS/Admin/admin_dashboard.php`
3. Manage buses, operators, routes, schedules

### 7. Test Operator Panel
1. Login as operator
2. Go to: `http://localhost/MBUS/Operator/operator_dashboard.php`
3. Manage trips and passengers

---

## Security Notes

### After Local Testing:
1. **Never commit `.env` file** to version control
2. **Regenerate API keys** if they were ever exposed
3. **Use strong passwords** for all accounts
4. **Enable HTTPS** in production (Render provides this automatically)

### For Production Deployment:
1. Set `MBUS_ENV=production` in environment
2. Use strong, unique passwords
3. Enable rate limiting (pending implementation)
4. Regular security audits
5. Keep dependencies updated

---

## Quick Start Commands

### With XAMPP:
```bash
# 1. Start XAMPP Control Panel
# 2. Start Apache
# 3. Open browser
# 4. Go to: http://localhost/MBUS/login.php
```

### With PHP Built-in Server:
```bash
cd C:\Users\Admin\Documents\MBUS\MBUS
php -S localhost:8000
# Then open: http://localhost:8000/login.php
```

### Check PHP Version:
```bash
php -v
```

### Check Database Connection:
```bash
# Run the test script
php test_connection.php
```

---

## File Structure

```
MBUS/
├── Admin/              # Admin panel files
├── Assets/             # CSS, JS, images
├── Commuter/           # Commuter panel files
├── Operator/           # Operator panel files
├── Includes/           # Helper functions (csrf.php, email_helper.php, etc.)
├── api/                # API endpoints
├── config/             # Configuration files
├── scripts/            # Setup scripts
├── supabase/           # SQL files for database setup
├── .env                # Environment variables (CREATE THIS)
├── .env.example        # Environment variables template
├── login.php           # Login page
├── register.php        # Registration page
└── ...                 # Other files
```

---

## Support

If you encounter issues:
1. Check the BUG_REPORT.md for known issues
2. Check FIXES_SUMMARY.md for applied security fixes
3. Review error logs (if available)
4. Verify all environment variables are set correctly

---

**Last Updated:** 2026-10-23
**System:** MBUS (Morong–SBMA Bus Reservation System)
