# LASU Result Complaint Portal — Updated Build

## What changed in this update

### 🔴 Critical bug fixes
1. **`audit_log` column name** — `logAudit()` in `includes/functions.php` was inserting into `note` but the schema column is `notes`. Audit entries were silently failing. Now fixed.
2. **`course_assignments` foreign key** — the FK pointed at `courses_old` (a renamed table), so every "Assign Lecturer" action in `hod/course_assignments.php` was throwing a hard FK error. Migration `001` drops the broken FK and re-creates it pointing at `courses`.
3. **`getPendingHODCount()` SQL** — was filtering by `co.department` (column doesn't exist on `courses` after the schema normalization). Reworked to filter by `users.department`.
4. **`getPendingLecturerCount()` status** — was filtering by `status='pending_verification'` (doesn't exist in the enum). Corrected to `'assigned_to_lecturer'`.

### 🚫 Supabase removal — pure PHP auth pipeline
Supabase Auth has been completely removed and replaced with a self-contained PHP auth pipeline:

- **`includes/auth.php`** — `login()` no longer has a Supabase fallback. Pure PHP credential check against `users.password_hash`. Surfaces friendly errors via `$_SESSION['login_error']` for pre-registered / unverified accounts.
- **`includes/supabase.php`** — kept as a deprecation stub. The `SupabaseAuth` class still exists but every method returns a "Supabase auth has been removed" error pointing at the new helpers. Existing references won't fatal — they'll just no-op.
- **`config/config.php`** — removed `SUPABASE_URL` and `SUPABASE_KEY` constants. Added `OTP_EXPIRY_MINUTES` (15), `OTP_LENGTH` (6), `OTP_MAX_ATTEMPTS` (5).
- **`register_student.php`** — rewritten. Validates input → checks for pre-existing user → stashes user data as JSON in the `otp_codes` table → sends a 6-digit OTP via `mail()` → redirects to `verify_otp.php`. If `mail()` is unavailable (InfinityFree free tier), the OTP is passed via `?code=` so testing still works.
- **`verify_otp.php`** — rewritten to verify against the local `otp_codes` table. Handles both `'registration'` and `'password_reset'` purposes. Tracks failed attempts (lockout after 5). Pre-fills the 6 input boxes when in dev mode.
- **`forgot_password.php`** — NEW page. Enter email / matric / PF_NO → looks up the user → generates a `password_reset` OTP → redirects to `verify_otp.php?purpose=password_reset`.
- **`reset_password.php`** — NEW page. Reached from `verify_otp.php` after the OTP check passes. Re-verifies the OTP before letting the user set a new password (defensive).
- **`login.php`** — surfaces the new `$_GET['success']` flags (`registered`, `verified`, `password_reset`) and the specific error message from `login()`. The "Forgot Password?" link now points at the new `forgot_password.php`.
- **`register_staff.php`** — was already direct PHP; just fixed the `note` → `notes` audit-log fallback.

### 🔔 Notification best practices
Before: `notifications.php` ran `UPDATE notifications SET is_read=1` for every notification on every page load — making the unread badge meaningless.

After:
- **No auto-mark-as-read** on page load — unread notifications stay unread until the user explicitly acts.
- **Per-row "Mark as read" button** — green ✓ icon; calls the new `mark_notification_read.php` AJAX endpoint, which flips `is_read = 1` only for that one row and updates the topbar badge in-place.
- **"Mark all as read" button** at the top of the page — explicit action via POST; redirects with a `?marked=N` flash message.
- **`addNotification()` now takes a `type` parameter** — defaults to `'complaint'` for backward compat, but new code can pass `'system'`, `'deadline'`, etc. The migration adds the `type` column.
- The `notifications` table also has a new `type` column (added by the migration script).

### 🆕 New admin page — `admin/manage_student.php`
- Mirrors `manage_staff.php` styling exactly (same Tailwind card / table / toggles).
- Search by name / email / matric number.
- Filter by department AND level.
- Toggle `is_active` (deactivate/reactivate students).
- Emergency password reset (admin sets a temporary password; student must change on next login).
- Read-only "VERIFIED" / "PENDING" badge (students verify via OTP).
- Linked from the admin sidebar in `includes/layout.php` — label "Manage Students", icon `school`.

### 📄 Print feature polish — server-side PDF via PDF.co
- **`download_letter.php`** — NEW endpoint. Generates the official LASU letter HTML, sends it to the PDF.co `/v1/pdf/convert/from/html` API, downloads the rendered PDF from PDF.co's CDN, and streams it to the browser as a downloadable attachment. Authorisation: HOD of the complaint's department, or admin.
- **`hod/generate_letter.php`** — added a "Download PDF" button next to the existing "Print / Save PDF" button. Logo fallback: if `/assets/img/lasu_logo.jpg` is missing, uses the public LASU logo URL instead. Watermark URL also uses the fallback.
- **`hod/review_complaint.php`** — when the complaint status is `endorsed`, `verified`, `approved`, or `rejected`, two new buttons appear in the page header: "Download PDF" (direct download via PDF.co) and "View Official Letter" (opens `generate_letter.php` in a new tab).

### 🗃️ Database changes
See **`database/migrations/001_fix_schema.sql`** — idempotent migration script you should run against your existing DB. It:
1. Renames `audit_log.note` → `audit_log.notes` (if needed).
2. Adds `users.must_change_password` (used by the admin password-reset flow + `requireLogin()`).
3. Creates the new `otp_codes` table (replaces Supabase Auth).
4. Drops the broken `course_assignments_ibfk_1` FK and re-creates it pointing at `courses`.
5. Adds `notifications.type` column (best-practice for filtering).

The canonical schema is in **`database/schema.sql`** — apply this only if you're starting fresh.

## File inventory

### New files (added in this update)
- `forgot_password.php`
- `reset_password.php`
- `mark_notification_read.php`
- `download_letter.php`
- `admin/manage_student.php`
- `database/migrations/001_fix_schema.sql`

### Modified files
- `config/config.php` — removed Supabase constants, added OTP settings
- `includes/auth.php` — pure PHP `login()`, removed Supabase fallback
- `includes/functions.php` — fixed `logAudit`, `getPendingHODCount`, `getPendingLecturerCount`; added `generateAuditHash`, `generateOtpCode`, `storeOtp`, `verifyOtp`, `sendOtpEmail`, `markNotificationRead`, `markAllNotificationsRead`; `addNotification` now accepts `type` parameter
- `includes/supabase.php` — deprecation stub (kept for backward compat)
- `includes/layout.php` — added admin sidebar link to `manage_student.php`
- `login.php` — surfaces success messages + session-based login errors
- `register_student.php` — pure PHP + local OTP, no Supabase
- `register_staff.php` — fixed `note` → `notes` in audit-log fallback
- `verify_otp.php` — rewritten to use local `otp_codes` table
- `notifications.php` — best-practices overhaul (no auto-mark-as-read)
- `hod/generate_letter.php` — added Download PDF button + logo fallback
- `hod/review_complaint.php` — added Download PDF + View Letter buttons in header
- `database/schema.sql` — canonical schema with all fixes applied

### Unchanged files (verified intact)
- All other PHP files (`admin/dashboard.php`, `admin/import_*.php`, `admin/manage_staff.php`, `hod/batch_process.php`, `hod/course_assignments.php`, `hod/dashboard.php`, `lecturer/*`, `level_adviser/*`, `student/*`, `index.php`, `logout.php`, `delete_notification.php`, `maintenance.php`, `profile.php`, `includes/header.php`, `includes/footer.php`, `includes/complaint_rules.php`, `includes/groq_scan.php`, `includes/qr_verifier.php`).

## How to deploy

1. **Backup your current DB** (export from phpMyAdmin).
2. **Run the migration**: in phpMyAdmin, open the SQL tab and paste the contents of `database/migrations/001_fix_schema.sql`. Run it. It's idempotent — safe to run twice.
3. **Upload the updated PHP files** to InfinityFree, replacing the old ones. Your UI is untouched — only backend logic, the auth pages (`login.php`, `register_*.php`, `verify_otp.php`, `forgot_password.php`, `reset_password.php`), `notifications.php`, the new `admin/manage_student.php`, and the print endpoints (`download_letter.php`, `generate_letter.php`) have changed.
4. **Test the new auth flow**:
   - As a student: register → enter details → on submit you'll be redirected to `verify_otp.php`. If `mail()` is disabled on your host, the OTP will be displayed on screen in a yellow "Dev mode" banner. Enter it to complete registration → redirected to login.
   - As staff: `register_staff.php` works as before (no OTP needed — direct DB insert).
   - Forgot password: click the new "Forgot Password?" link on the login page → enter your identifier → OTP flow → reset password.
5. **Test the new admin page**: log in as admin → sidebar → "Manage Students".
6. **Test the new PDF download**: as HOD, approve a complaint → on the review page, click "Download PDF" in the header. The PDF should download as `LASU_Letter_<ticket>.pdf`.

## Notes
- The OTP "dev mode" banner only appears when `mail()` returns false (typical on InfinityFree free tier). Once you wire up real SMTP (or move to a host with mail() enabled), the banner disappears automatically and codes are delivered by email.
- All existing UI is untouched. No CSS classes, layout, or color palettes have been modified.
- The `supabase_uid` column on `users` is preserved on the schema for backward reference, but the auth pipeline no longer uses it.
