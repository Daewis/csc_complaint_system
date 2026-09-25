# LASU Result Complaint Portal

A PHP/MySQL web portal for managing academic result complaints at Lagos State University. Students file complaints about result discrepancies; Level Advisers endorse; Heads of Department assign to Lecturers; Lecturers verify; HODs issue the final ruling. Each step generates an auditable, tamper-evident record and a printable official letter (with PDF.co-powered server-side PDF generation).

**Stack:** PHP 7.4+ (works on 8.x), MySQL 5.7+ / MariaDB 10.3+, Tailwind CSS (CDN), Material Symbols, PHP `mail()` for OTP delivery, optional Groq for AI document scanning, optional PDF.co for PDF generation.

**No Supabase.** Authentication is pure PHP — local bcrypt passwords + an `otp_codes` table for email verification.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Requirements](#requirements)
- [Setup — Local Development](#setup--local-development)
- [Setup — Production Deployment](#setup--production-deployment)
- [Configuration Reference](#configuration-reference)
- [Database](#database)
- [PHP Extensions Required](#php-extensions-required)
- [Project Structure](#project-structure)
- [The Complaint Workflow](#the-complaint-workflow)
- [Role-Based Access](#role-based-access)
- [API Integrations](#api-integrations)
- [Maintenance Mode](#maintenance-mode)
- [Troubleshooting](#troubleshooting)

---

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/Daewis/csc_complaint_system.git csc_complaint_system
cd csc_complaint_system

# 2. Copy the env template and edit it with your DB credentials
cp .env.example .env
# Edit .env — at minimum set DB_NAME, DB_USER, DB_PASS

# 3. Import the database schema into MySQL
mysql -u root -p < database/schema.sql
# Or import via phpMyAdmin / Adminer / any GUI tool

# 4. Start PHP's built-in server (for local dev)
php -S localhost:8000

# 5. Open in your browser
# http://localhost:8000/login.php
```

If your PHP build has PDO MySQL, cURL, and GD enabled (most do), you're up and running.

---

## Requirements

| Requirement | Version | Notes |
|---|---|---|
| **PHP** | 7.4+ (8.x recommended) | Tested on 7.4 and 8.2 |
| **MySQL / MariaDB** | 5.7+ / 10.3+ | Uses utf8mb4 charset throughout |
| **PDO MySQL** extension | — | Required. Pure-PDO throughout, no `mysqli`. |
| **cURL** extension | — | Required for Groq + PDF.co API calls |
| **GD** extension | — | Required for signature/evidence image handling |
| **mbstring** extension | — | Required for proper UTF-8 string handling |
| **fileinfo** extension | — | Required for `mime_content_type()` on uploads |
| **PHP `mail()`** | — | Optional but recommended. If disabled (e.g. on shared hosts), OTPs are surfaced on-screen in "dev mode" so testing still works. |
| **Composer** | 2.x | Only needed if you want PhpSpreadsheet (for XLSX course imports). The CSV importer works without it. |

PHP version note: the code uses `??`, `[]` short arrays, `str_contains()` (PHP 8+), and `match` (PHP 8+) in a couple of places. If you're on PHP 7.4, replace `str_contains()` calls with `strpos() !== false` and `match` blocks with `switch`/`if-else`.

---

## Setup — Local Development

### Option A: XAMPP / MAMP / Laragon (recommended for beginners)

1. Install [XAMPP](https://www.apachefriends.org/), [MAMP](https://www.mamp.info/), or [Laragon](https://laragon.org/).
2. Clone or copy the project into your web server's document root:
   - XAMPP: `C:\xampp\htdocs\csc_complaint_system`
   - MAMP: `/Applications/MAMP/htdocs/csc_complaint_system`
   - Laragon: `C:\laragon\www\csc_complaint_system`
3. Start Apache + MySQL from the control panel.
4. Open phpMyAdmin and import `database/schema.sql` into a new database (e.g. `lasu_uniportal`).
5. Copy `.env.example` to `.env` and set your DB credentials.
6. Visit `http://localhost/csc_complaint_system/login.php` (or `http://localhost:8888/csc_complaint_system/login.php` for MAMP).

### Option B: PHP's built-in server (fastest, no Apache needed)

```bash
cd csc_complaint_system
cp .env.example .env
# Edit .env with your DB credentials
mysql -u root -p < database/schema.sql
php -S localhost:8000
# Visit http://localhost:8000/login.php
```

### Option C: Docker (advanced — not bundled)

A minimal `docker-compose.yml` would be:

```yaml
version: '3.8'
services:
  web:
    image: php:8.2-apache
    volumes:
      - ./:/var/www/html/
    ports:
      - "8000:80"
    depends_on:
      - db
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: lasu_uniportal
    volumes:
      - ./database/schema.sql:/docker-entrypoint-initdb.d/schema.sql
    ports:
      - "3306:3306"
```

Then `docker-compose up -d` and visit `http://localhost:8000/login.php`. Set `DB_HOST=db` and `DB_PASS=root` in `.env`.

---

## Setup — Production Deployment

### Generic Apache / Nginx

1. **Upload the project files** to your web server. The web root should point at the project folder (the folder containing `index.php`).

   For Apache, the project ships with a generic `.htaccess` that:
   - protects `.env`, `.git`, `vendor/`, `config/config.php` from direct access
   - denies access to the `assets/uploads/` directory (user-uploaded files)
   - serves `maintenance.php` when `MAINTENANCE_MODE=true`

2. **Import the schema**: upload `database/schema.sql` and run it against your MySQL server, OR run the idempotent migration `database/migrations/001_fix_schema.sql` if you have an existing DB.

3. **Copy `.env.example` to `.env`** and set:
   ```
   APP_ENV=production
   DB_HOST=your-mysql-host
   DB_NAME=your-db-name
   DB_USER=your-db-user
   DB_PASS=your-db-password
   BASE_URL=https://your-domain.com/
   ```

4. **Set directory permissions** so PHP can write to `assets/uploads/`:
   ```bash
   chmod -R 755 assets/uploads
   chown -R www-data:www-data assets/uploads   # on Debian/Ubuntu
   ```

5. **Create your first admin** — connect to MySQL and run:
   ```sql
   INSERT INTO users (full_name, email, password_hash, role, is_active, is_verified)
   VALUES (
     'System Admin',
     'admin@lasu.edu.ng',
     '$2y$10$PLACE_BCRYPT_HASH_HERE',
     'admin',
     1,
     1
   );
   ```
   To generate the bcrypt hash, run `php -r "echo password_hash('your-password', PASSWORD_BCRYPT);"`.

### InfinityFree / 000webhost / other free hosts

The project is now fully portable — InfinityFree-specific hardcoding has been removed. To deploy on a free host:

1. Upload all files via the host's file manager or FTP.
2. Use phpMyAdmin to import `database/schema.sql`.
3. Edit `.env` with the DB credentials the host gives you (their DB host will be something like `sqlXXX.epizy.com`).
4. Set `BASE_URL` to your free subdomain.
5. Set `APP_ENV=production` so errors are hidden.

The project will work the same as it does on localhost.

---

## Configuration Reference

All settings are loaded from environment variables — either real env vars (for Docker / production server config) or the `.env` file at the project root (for local dev). See `.env.example` for the full list.

### Critical settings

| Variable | Default | Purpose |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL hostname |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `lasu_uniportal` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | (empty) | Database password |
| `APP_ENV` | `local` | `local` shows errors; `production` hides them |
| `BASE_URL` | (auto-detected) | Force a specific URL only if you need to (e.g. for CLI scripts) |

### Optional settings

| Variable | Default | Purpose |
|---|---|---|
| `GROQ_API_KEY` | (empty) | Required only if you use AI document scanning for course-removal appeals |
| `PDFCO_API_KEY` | (empty) | Required only if you want server-side PDF generation (browser's "Save as PDF" works without it) |
| `OTP_EXPIRY_MINUTES` | `15` | OTP code lifetime |
| `OTP_LENGTH` | `6` | Number of digits in the OTP |
| `OTP_MAX_ATTEMPTS` | `5` | Failed verification attempts before lockout |
| `MAIL_FROM` | `no-reply@lasu.edu.ng` | From: address for OTP emails |
| `MIN_UNITS` | `18` | Minimum units a student must keep after a course removal appeal |
| `SLA_HOURS` | `72` | Hours the lecturer has to verify before the ticket is flagged overdue |
| `MAINTENANCE_MODE` | `false` | Toggle the maintenance splash page |

---

## Database

The canonical schema is in `database/schema.sql` — apply this if you're starting fresh.

If you have an existing database (e.g. you're upgrading from the old Supabase-based build), run `database/migrations/001_fix_schema.sql` instead. It's idempotent — safe to run twice. It:

1. Renames `audit_log.note` → `audit_log.notes` (was a bug — inserts were silently failing).
2. Adds `users.must_change_password` column.
3. Creates the `otp_codes` table (replaces Supabase Auth for OTP).
4. Fixes the `course_assignments` FK to point at `courses` (was pointing at the renamed `courses_old`).
5. Adds `notifications.type` column.

### Tables overview

| Table | Purpose |
|---|---|
| `users` | All accounts (students + staff + admin). Role flags: `is_lecturer`, `is_level_adviser`, `is_hod`. |
| `faculties` / `departments` | Normalized organizational hierarchy. |
| `courses` | Course codes + titles (normalized — no dept/level here). |
| `course_offerings` | Per-department, per-level, per-semester course offering. |
| `course_assignments` | Maps a lecturer to a course offering for a session/semester. |
| `complaints` | The main complaint record — covers the entire lifecycle. |
| `audit_log` | Tamper-evident audit trail of every action. |
| `notifications` | Per-user in-app notifications. |
| `otp_codes` | 6-digit OTP codes for email verification + password reset. |

---

## PHP Extensions Required

The project uses these extensions. Make sure they're enabled in `php.ini` (most local dev environments have them all on by default):

| Extension | Why | Used by |
|---|---|---|
| `pdo_mysql` | Database access | Everything |
| `curl` | HTTP API calls | `includes/groq_scan.php`, `download_letter.php` |
| `gd` | Image manipulation (signatures, profile pictures, evidence previews) | `includes/functions.php` |
| `mbstring` | UTF-8 string handling | `includes/functions.php` (sanitize) |
| `fileinfo` | MIME type detection on uploads | `includes/functions.php` (uploadEvidence) |
| `openssl` | HTTPS cURL requests | Implicit |
| `session` | User sessions | `includes/auth.php` |
| `mail` (PHP built-in) | OTP delivery | `includes/functions.php` (sendOtpEmail) — optional, has dev-mode fallback |

To verify your local install has everything:

```bash
php -m | grep -iE "pdo_mysql|curl|gd|mbstring|fileinfo|openssl|session"
```

### Optional (only if you want the XLSX importer for staff)

The admin "Import Staff" page supports `.xlsx` via PhpSpreadsheet. If you want this:

```bash
composer require phpoffice/phpspreadsheet
```

Without Composer, the page still works with CSV uploads / pasted CSV data.

---

## Project Structure

```
csc_complaint_system/
├── .env.example              ← Template — copy to .env and edit
├── .env                      ← Your real config (gitignored — never commit)
├── .gitignore
├── .htaccess                 ← Generic Apache rules (no InfinityFree quirks)
├── README.md                 ← You are here
├── UPDATE_NOTES.md           ← Changelog for the latest refactor
├── index.php                 ← Routes logged-in users to their dashboard
├── login.php                 ← Login form (student / staff tabs)
├── logout.php                ← Destroys session
├── register_student.php      ← Student self-registration (local OTP)
├── register_staff.php        ← Staff self-registration (PF_NO lookup + set password)
├── verify_otp.php            ← 6-digit OTP entry (handles registration + password reset)
├── forgot_password.php       ← Request a password reset
├── reset_password.php        ← Set a new password after OTP verification
├── profile.php               ← User profile + signature upload + change password
├── notifications.php         ← User notifications (with per-row mark-as-read)
├── delete_notification.php   ← AJAX endpoint to delete a notification
├── mark_notification_read.php← AJAX endpoint to mark a notification as read
├── maintenance.php           ← Maintenance splash page
├── download_letter.php       ← Server-side PDF generation (PDF.co API)
│
├── admin/
│   ├── dashboard.php         ← System overview stats
│   ├── import_staff.php      ← Bulk staff whitelist via CSV/XLSX
│   ├── import_courses.php    ← Bulk course catalog via CSV
│   ├── manage_staff.php      ← Staff management (toggle role flags, reset pw)
│   └── manage_student.php    ← Student management (toggle active, reset pw)
│
├── hod/
│   ├── dashboard.php         ← HOD departmental overview
│   ├── review_complaint.php  ← Per-complaint HOD review + actions
│   ├── batch_process.php    ← Batch approve/reject verified complaints
│   ├── course_assignments.php← Assign lecturers to courses
│   └── generate_letter.php   ← Standalone printable A4 letter page
│
├── lecturer/
│   ├── dashboard.php         ← Lecturer's verification queue
│   └── verify_complaint.php  ← Score verification + upgrade recommendations
│
├── level_adviser/
│   ├── dashboard.php         ← LA dashboard (filtered by level + department)
│   ├── review_complaint.php  ← Endorse / return complaints
│   └── batch_process.php    ← Batch endorse multiple complaints
│
├── student/
│   ├── dashboard.php         ← Student's complaints list with status pills
│   ├── new_complaint.php     ← The complaint submission form (with live letter preview)
│   ├── view_complaint.php    ← Read-only complaint detail + resubmit when returned
│   └── history.php           ← Full history with filters + search
│
├── config/
│   ├── config.php            ← Loaded by every page — all settings + BASE_URL detection
│   ├── config.example.php    ← Documented reference (copy of the template)
│   └── database.php          ← PDO connection factory
│
├── includes/
│   ├── auth.php              ← Session + login + role-based access control
│   ├── functions.php         ← Sanitize, statusBadge, stats, uploads, OTP helpers
│   ├── layout.php            ← Sidebar + topbar Tailwind shell
│   ├── layout_end.php        ← Closes the layout (sidebar toggle script)
│   ├── header.php            ← Legacy Bootstrap header (kept for compat)
│   ├── footer.php            ← Legacy Bootstrap footer (kept for compat)
│   ├── complaint_rules.php   ← Course-removal eligibility rules (MIN_UNITS check)
│   ├── groq_scan.php         ← AI document scan (PDF.co for PDF→PNG, Groq for OCR)
│   ├── qr_verifier.php       ← LASU QR code decode + URL verification
│   └── supabase.php          ← DEPRECATED stub (kept for backward compat)
│
├── database/
│   ├── schema.sql            ← Canonical full schema (apply on fresh DB)
│   └── migrations/
│       └── 001_fix_schema.sql ← Idempotent fix-up for existing DBs
│
└── assets/
    ├── img/                  ← LASU logo, etc.
    ├── css/                  ← (Legacy Bootstrap overrides if any)
    ├── js/                   ← app.js (sidebar toggle, etc.)
    └── uploads/              ← User uploads (gitignored contents)
        ├── evidence/         ← Complaint evidence files
        ├── signatures/       ← Student + staff signature images
        ├── profiles/         ← Profile pictures
        └── temp/             ← AI scan temp images
```

---

## The Complaint Workflow

```
Student submits complaint (status: pending)
   │
   ├── (optional) AI scans evidence — Groq reads course form
   │
   ▼
Level Adviser reviews (status: pending → endorsed | returned_to_student)
   │
   ├── If returned: student edits & resubmits (back to pending)
   │
   ▼
HOD assigns to course lecturer (status: endorsed → assigned_to_lecturer)
   │  ── SLA clock starts (72 hours)
   │
   ▼
Lecturer verifies scores OR recommends on upgrades (status: assigned_to_lecturer → verified)
   │
   ▼
HOD issues final ruling (status: verified → approved | rejected)
   │
   ▼
Official letter generated — `generate_letter.php` (print) or `download_letter.php` (server-side PDF)
```

Each status transition:
- Inserts a row into `audit_log` with the actor, timestamp, and IP address
- Generates a SHA-256 audit hash that's printed on the official letter (tamper-evidence)
- Pushes a notification to the relevant next-actor (LA → HOD, HOD → Lecturer, Lecturer → HOD, HOD → Student)

---

## Role-Based Access

The sidebar (`includes/layout.php`) is role-aware and shows different sections based on the logged-in user's role and flags:

- **`student`** — sees student dashboard, new complaint, history
- **`staff`** with `is_lecturer=1` — sees lecturer verification queue
- **`staff`** with `is_level_adviser=1` — sees LA dashboard + batch process
- **`staff`** with `is_hod=1` — sees HOD dashboard, batch process, course assignments
- **`admin`** — sees everything the staff roles see, plus all admin pages

A single staff account can hold multiple flags simultaneously (e.g. someone who's both HOD and a Level Adviser for their department).

---

## API Integrations

### Groq (AI document scanning)

Used by `student/new_complaint.php` when a student files a **Course Removal** appeal. The student uploads their course registration form; Groq's Llama-4-Scout vision model reads it, extracts the course codes and total units, and the system checks whether removing the course would drop the student below the `MIN_UNITS` threshold.

- **Get a key:** https://console.groq.com (free tier — generous limits)
- **Set in `.env`:** `GROQ_API_KEY=your_key_here`
- **Without a key:** the page still works, but the AI scan is skipped and the LA manually verifies the document.

### PDF.co (HTML → PDF conversion)

Used by `download_letter.php` to render the official LASU letter as a downloadable PDF file. The same letter is also printable via the browser's native "Print → Save as PDF" dialog on `generate_letter.php`, so PDF.co is purely optional.

- **Get a key:** https://app.pdf.co (free tier — 100 credits/month)
- **Set in `.env`:** `PDFCO_API_KEY=your_key_here`
- **Without a key:** clicking "Download PDF" will return an HTTP 500 with a message. The "Print / Save PDF" button on `generate_letter.php` still works.

### PHP `mail()` (OTP delivery)

The registration and password-reset flows generate 6-digit OTPs and try to email them via PHP's built-in `mail()`. If `mail()` is disabled (common on free hosts), the OTP is surfaced on-screen in a yellow "Dev mode" banner so you can still complete the flow during testing.

To deliver real emails in production, use a host with `mail()` enabled, or wire up an SMTP library (e.g. PHPMailer) and replace the `sendOtpEmail()` body.

---

## Maintenance Mode

Toggle by setting `MAINTENANCE_MODE=true` in `.env`. All visitors (except those with a bypass cookie) will be redirected to `maintenance.php`.

To bypass: visit any URL with `?bypass=<secret>` (default secret: `lasu` — override via `MAINTENANCE_SECRET` in `.env`). This sets a cookie valid for 1 hour so you can browse the site while it's in maintenance for everyone else.

---

## Troubleshooting

### "DB Error: SQLSTATE[HY000] [2002] No connection could be made"
- Check `DB_HOST` and `DB_PORT` in `.env`
- On Docker, use `DB_HOST=db` (the service name)
- On Linux/Mac localhost, use `127.0.0.1` (not `localhost` — `localhost` forces a socket connection)

### OTP emails never arrive
- Set `APP_ENV=local` in `.env` — the verify-otp page will show the code in a yellow "Dev mode" banner
- For real delivery: ensure your host has `mail()` enabled, or replace `sendOtpEmail()` in `includes/functions.php` with a PHPMailer/SMTP call

### BASE_URL is wrong / assets don't load
- The project auto-detects BASE_URL from the request — should just work
- If you're behind a reverse proxy or using CLI, set `BASE_URL` explicitly in `.env`
- Check the sidebar links — they should all start with your real URL

### "Download PDF" returns an error
- Set `PDFCO_API_KEY` in `.env` (free tier available at https://app.pdf.co)
- Without a key, use the "Print / Save PDF" button on `generate_letter.php` instead (uses the browser's native PDF)

### AI document scan fails
- Set `GROQ_API_KEY` in `.env`
- Without a key, the system skips the AI check and the Level Adviser verifies the document manually

### Course removal is always rejected as "below MIN_UNITS"
- Adjust `MIN_UNITS` in `.env` (default: 18) to match your faculty's policy

### "404 Not Found" on sub-pages
- Make sure your web server's document root points at the project folder (the one containing `index.php`)
- On Apache, ensure `.htaccess` is allowed (`AllowOverride All` in the vhost config)
- On Nginx, you don't need `.htaccess` — the project doesn't use Apache-only routing rules

### Old staff users can't log in after the Supabase removal
- The `login()` function now rejects unverified accounts. If you have legacy users with `is_verified=0`, run:
  ```sql
  UPDATE users SET is_verified = 1, password_hash = '<bcrypt-hash>' WHERE email = 'legacy.user@lasu.edu.ng';
  ```
  Generate the bcrypt hash with: `php -r "echo password_hash('their-password', PASSWORD_BCRYPT);"`

---

## License

This project is for internal use by Lagos State University. See the project maintainers for licensing questions.

---

## Changelog

See `UPDATE_NOTES.md` for the latest changes (Supabase removal, portability fixes, notification best-practices, new admin pages, PDF.co integration).
