<?php
/**
 * LASU Result Complaint Portal — Example Configuration
 * ====================================================================
 *
 * This file documents every configuration knob the project exposes.
 * The actual config your app loads is `config.php` (next to this file).
 * Most settings can be overridden via environment variables or the
 * `.env` file at the project root — you usually don't need to edit
 * this file at all.
 *
 * SETUP CHECKLIST:
 *   1. Copy this file to `config.php` (it already exists in the repo).
 *   2. Copy `.env.example` to `.env` at the project root.
 *   3. Edit `.env` with your environment-specific values.
 *   4. Import `database/schema.sql` into MySQL.
 *   5. Start PHP's built-in server (for local dev):
 *        php -S localhost:8000 -t /path/to/uniportal
 *      Or put the project in your web server's document root.
 *
 * ENVIRONMENT VARIABLES (set in .env, docker-compose, or server env):
 *   ── Database ────────────────────────────────────────────
 *   DB_HOST            MySQL host (default: 127.0.0.1)
 *   DB_PORT            MySQL port (default: 3306)
 *   DB_NAME            Database name (default: lasu_uniportal)
 *   DB_USER            Database user (default: root)
 *   DB_PASS            Database password (default: empty)
 *
 *   ── Application ────────────────────────────────────────
 *   APP_NAME           Application display name
 *   APP_VERSION        Version string
 *   APP_ENV            "local" or "production" (controls error display)
 *   APP_TIMEZONE       PHP timezone (default: Africa/Lagos)
 *   BASE_URL           Optional forced BASE_URL (auto-detected if not set)
 *
 *   ── API Keys ────────────────────────────────────────────
 *   GROQ_API_KEY       Groq API key (for AI document scanning)
 *   PDFCO_API_KEY     PDF.co API key (for HTML→PDF conversion)
 *
 *   ── OTP / Email ────────────────────────────────────────
 *   OTP_EXPIRY_MINUTES  Code lifetime (default: 15)
 *   OTP_LENGTH           Digits in the code (default: 6)
 *   OTP_MAX_ATTEMPTS     Lockout threshold (default: 5)
 *   MAIL_FROM            From: address for OTP emails
 *   MAIL_FROM_NAME       From: display name
 *
 *   ── Business Rules ─────────────────────────────────────
 *   MIN_UNITS           Min units after course removal (default: 18)
 *   MAX_UNITS           Max units per semester (default: 26)
 *   SLA_HOURS           Lecturer verification SLA in hours (default: 72)
 *   SESSION_TIMEOUT     Session timeout in seconds (default: 3600)
 *
 *   ── Maintenance ───────────────────────────────────────
 *   MAINTENANCE_MODE   "true" or "false" (default: false)
 *   MAINTENANCE_SECRET Bypass secret (default: lasu)
 *
 * @package LASU Result Complaint Portal
 */
echo "This is the example config file. Copy it to config.php (it should already exist) and configure via .env instead.";
