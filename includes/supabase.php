<?php
/**
 * supabase.php — DEPRECATED
 * =========================
 * Supabase authentication has been removed from this project. The
 * whole login / signup / OTP verification / password reset pipeline
 * is now handled by pure PHP:
 *
 *   • includes/auth.php      — login(), logout(), requireLogin()
 *   • includes/functions.php — storeOtp(), verifyOtp(), sendOtpEmail()
 *   • otp_codes table         — stores 6-digit codes with expiry
 *
 * This file is kept ONLY as a backward-compatibility shim so that any
 * legacy references (e.g. third-party code) that still `require` it do
 * not produce a fatal error. The SupabaseAuth class below is preserved
 * as an empty stub — calls to its methods will return a helpful error
 * telling the caller to migrate to the new local-OTP helpers.
 *
 * DO NOT use this class in new code. Use the local-OTP helpers in
 * includes/functions.php instead.
 */

if (!defined('SUPABASE_URL')) define('SUPABASE_URL', '');
if (!defined('SUPABASE_KEY')) define('SUPABASE_KEY', '');

class SupabaseAuth {
    /** @var string */
    private $url;
    /** @var string */
    private $key;

    public function __construct($url = '', $key = '') {
        $this->url = $url;
        $this->key = $key;
        error_log('Deprecation warning: SupabaseAuth instantiated — Supabase auth has been removed. Migrate to includes/functions.php helpers (storeOtp, verifyOtp, sendOtpEmail).');
    }

    /**
     * @deprecated Use storeOtp() + sendOtpEmail() instead.
     */
    public function signUp($email, $password, $metadata) {
        return [
            'error' => 'Supabase auth has been removed. Migrate this call to storeOtp() + sendOtpEmail() in includes/functions.php.',
        ];
    }

    /**
     * @deprecated Use verifyOtp() instead.
     */
    public function verifyOtp($email, $otp) {
        return [
            'error' => 'Supabase auth has been removed. Migrate this call to verifyOtp() in includes/functions.php.',
        ];
    }

    /**
     * @deprecated Use storeOtp($email, 'password_reset') + sendOtpEmail() instead.
     */
    public function resendOtp($email) {
        return [
            'error' => 'Supabase auth has been removed. Migrate this call to storeOtp() in includes/functions.php.',
        ];
    }
}
