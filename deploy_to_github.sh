#!/usr/bin/env bash
# =====================================================================
# Deploy script: commits the portable LASU Result Complaint Portal
# build and pushes it to GitHub.
#
# USAGE:
#   1. Clone the repo (if you haven't already):
#        git clone https://github.com/Daewis/csc_complaint_system.git
#        cd csc_complaint_system
#
#   2. Extract the contents of the portable zip on top of the clone,
#      overwriting all files (this preserves the .git folder).
#
#   3. Run this script:
#        bash deploy_to_github.sh
#
#   4. When prompted, enter your GitHub username and Personal Access
#      Token (PAT). Create a PAT at:
#        https://github.com/settings/tokens/new?scopes=repo
#
# The first run will prompt for credentials. After that, GitHub will
# cache them via the credential helper.
# =====================================================================

set -e

echo "============================================================"
echo "  LASU Result Complaint Portal — GitHub Deploy Script"
echo "============================================================"
echo ""
echo "This script will:"
echo "  1. Stage all changes (new, modified, and deleted files)"
echo "  2. Commit them with a descriptive message"
echo "  3. Push to origin/main on GitHub"
echo ""
echo "Make sure you're in the project root (the folder containing"
echo "this script and index.php)."
echo ""

# Safety check — make sure we're in a git repo
if [[ ! -d .git ]]; then
  echo "ERROR: Not a git repository. Run this from the project root."
  echo "  (The project root is the folder containing index.php + .git)"
  exit 1
fi

# Make sure origin is set
if ! git remote get-url origin &>/dev/null; then
  echo "Adding remote origin..."
  git remote add origin https://github.com/Daewis/csc_complaint_system.git
fi

# Stage all changes including deletions (the previous commit had
# vendor/ committed which we now want to remove via .gitignore)
echo "→ Staging changes..."
git add -A

# Show what's about to be committed
echo ""
echo "→ Changes to be committed:"
git status --short | head -30
echo ""
TOTAL=$(git status --short | wc -l)
echo "  ($TOTAL file(s) affected)"
echo ""

# Commit with a descriptive message
echo "→ Committing..."
git commit -m "Make project portable off InfinityFree

- Replace hardcoded InfinityFree DB credentials + base URL with .env-driven config
- Dynamic BASE_URL detection (works on localhost, subfolder, custom domain)
- Add .env.example, .gitignore, .htaccess (generic Apache rules)
- Add comprehensive README.md with setup, requirements, deployment guides
- Fix hardcoded /assets/, /hod/, /admin/ paths → BASE_URL-prefixed
- Fix audit_log.notes column name (was 'note', inserts silently failed)
- Fix course_assignments FK (was pointing at renamed courses_old)
- Fix getPendingHODCount + getPendingLecturerCount SQL (status enum + normalized schema)
- Remove Supabase auth — pure PHP auth pipeline + local otp_codes table
- New: forgot_password.php, reset_password.php, mark_notification_read.php
- New: admin/manage_student.php page (mirrors manage_staff.php)
- New: download_letter.php — server-side PDF via PDF.co API
- Notifications: no longer auto-mark-as-read on page load; per-row + mark-all buttons
- Logo fallback to public LASU URL when local asset is missing
- All file paths now __DIR__-based — no absolute paths anywhere" --allow-empty

echo ""
echo "→ Pushing to GitHub..."
echo "(If this is the first push, you'll be prompted for your GitHub"
echo " username and Personal Access Token. Create a PAT at:"
echo " https://github.com/settings/tokens/new?scopes=repo)"
echo ""

git push -u origin main

echo ""
echo "============================================================"
echo "  ✓ Done! Pushed to https://github.com/Daewis/csc_complaint_system"
echo "============================================================"
