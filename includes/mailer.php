<?php
/**
 * includes/mailer.php — PHPMailer factory + helper
 * ====================================================================
 *
 * Sends email via PHPMailer using the SMTP server configured in .env.
 * Default provider is Brevo (300 emails/day free) but the same code
 * works with Gmail, Mailtrap, SendGrid, Mailgun, Amazon SES, or any
 * standard SMTP server — just change the SMTP_* env vars.
 *
 * DRIVER SWITCH:
 *   • MAIL_DRIVER=smtp → PHPMailer with SMTP (default, works everywhere)
 *   • MAIL_DRIVER=mail → PHP's built-in mail() function
 *   • MAIL_DRIVER=log  → writes the email body to error_log (testing only)
 *
 * USAGE:
 *   require_once __DIR__ . '/includes/mailer.php';
 *
 *   $result = sendMail([
 *       'to'      => 'student@example.com',
 *       'to_name' => 'Olawale Adeyemi',
 *       'subject' => 'Your Verification Code',
 *       'body'    => 'Your code is 123456. It expires in 15 minutes.',
 *       'alt'     => 'Your code is 123456. It expires in 15 minutes.', // plain text
 *   ]);
 *
 *   // Returns: ['sent' => bool, 'error' => string|null, 'driver' => string]
 *
 * DEPENDENCIES:
 *   • PHPMailer 6.x — install via `composer require phpmailer/phpmailer`
 *   • With MAIL_DRIVER=smtp, a missing PHPMailer or empty SMTP credentials
 *     return an error (no silent fallback to mail()).
 *
 * @package CSC Result Complaint Portal
 * @since   1.0.0
 */

require_once __DIR__ . '/../config/config.php';

// Load the Composer autoloader (if installed). We check for the PHPMailer
// class itself later, so this works no matter where this file is included
// from (top level or inside a function).
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

/**
 * sendMail — central email dispatcher.
 *
 * Picks the configured driver (smtp / mail / log) and routes the
 * email through it. Returns an array with 'sent' (bool), 'error'
 * (string|null), and 'driver' (which driver was actually used).
 *
 * @param array $args [
 *   'to'      => string  recipient email (required)
 *   'to_name' => string  recipient display name (optional)
 *   'subject' => string  email subject (required)
 *   'body'    => string  HTML body (required)
 *   'alt'     => string  plain-text alt body (optional, derived from HTML if absent)
 *   'reply_to'=> string  reply-to address (optional, defaults to MAIL_REPLY_TO)
 * ]
 * @return array{sent: bool, error: ?string, driver: string}
 */
function sendMail(array $args): array {
    $to       = trim($args['to']      ?? '');
    $toName   = trim($args['to_name'] ?? '');
    $subject  = trim($args['subject'] ?? '');
    $body     = $args['body']         ?? '';
    $altBody  = $args['alt']          ?? '';
    $replyTo  = $args['reply_to']     ?? MAIL_REPLY_TO;

    if (!$to || !$subject || !$body) {
        return ['sent' => false, 'error' => 'Missing required email parameter (to/subject/body).', 'driver' => 'none'];
    }

    $driver = strtolower(MAIL_DRIVER);

    // ── Driver: log (testing — never actually sends) ──────────────────
    if ($driver === 'log') {
        $log = sprintf(
            "[%s] [LOG DRIVER] To: %s <%s> | Subject: %s\nBody: %s\n",
            date('Y-m-d H:i:s'),
            $toName,
            $to,
            $subject,
            strip_tags($body)
        );
        error_log($log);
        return ['sent' => true, 'error' => null, 'driver' => 'log'];
    }

    // ── Driver: smtp (PHPMailer) ──────────────────────────────────────
    if ($driver === 'smtp') {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log('sendMail: PHPMailer not installed.');
            return ['sent' => false, 'error' => 'PHPMailer not installed.', 'driver' => 'smtp'];
        }

        if (!SMTP_USERNAME || !SMTP_PASSWORD) {
            error_log('sendMail: SMTP_USERNAME/SMTP_PASSWORD are empty.');
            return ['sent' => false, 'error' => 'SMTP credentials are empty.', 'driver' => 'smtp'];
        }

        return _sendViaPhpMailer($to, $toName, $subject, $body, $altBody, $replyTo);
    }

    // ── Driver: mail (PHP built-in mail()) ────────────────────────────
    if ($driver === 'mail') {
        return _sendViaMailFunction($to, $toName, $subject, $body, $altBody, $replyTo);
    }

    // Unknown driver
    return ['sent' => false, 'error' => "Unknown MAIL_DRIVER: '{$driver}'. Use 'smtp', 'mail', or 'log'.", 'driver' => 'none'];
}


/**
 * _sendViaPhpMailer — uses PHPMailer 6.x with the configured SMTP server.
 *
 * @internal — call sendMail() instead; this is the implementation detail.
 */
function _sendViaPhpMailer(string $to, string $toName, string $subject, string $body, string $altBody, string $replyTo): array {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true); // true = throw exceptions on error

        // ── Server settings ──────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = 'base64';

        // Encryption: 'tls' for port 587 (STARTTLS), 'ssl' for port 465 (implicit SSL)
        if (SMTP_ENCRYPTION === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (SMTP_ENCRYPTION === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        // Timeout — keep low so a misconfigured SMTP server doesn't hang the request
        $mail->Timeout = 30;

        // ── Recipients ────────────────────────────────────────────────
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        if ($replyTo && $replyTo !== MAIL_FROM) {
            $mail->addReplyTo($replyTo, MAIL_FROM_NAME);
        }
        $mail->addAddress($to, $toName);

        // ── Content ────────────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($body);

        $mail->send();
        return ['sent' => true, 'error' => null, 'driver' => 'smtp'];
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $err = 'PHPMailer error: ' . $e->getMessage();
        error_log('sendMail (smtp) failed: ' . $err);
        return ['sent' => false, 'error' => $err, 'driver' => 'smtp'];
    } catch (Throwable $e) {
        $err = 'Unexpected error: ' . $e->getMessage();
        error_log('sendMail (smtp) failed: ' . $err);
        return ['sent' => false, 'error' => $err, 'driver' => 'smtp'];
    }
}

/**
 * _sendViaMailFunction — uses PHP's built-in mail(). Only used when
 * MAIL_DRIVER=mail. Note: on XAMPP/local machines mail() often reports
 * success without actually delivering anything.
 *
 * @internal
 */
function _sendViaMailFunction(string $to, string $toName, string $subject, string $body, string $altBody, string $replyTo): array {
    $headers = [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    if (!$sent) {
        error_log("sendMail (mail driver) failed for {$to}");
        return ['sent' => false, 'error' => 'mail() returned false (host may have it disabled).', 'driver' => 'mail'];
    }
    return ['sent' => true, 'error' => null, 'driver' => 'mail'];
}

/**
 * buildOtpEmailBody — returns a styled HTML email body for a 6-digit OTP.
 *
 * Used by sendOtpEmail() in functions.php. Renders the same look on every
 * email client (Gmail, Outlook, Apple Mail, etc.) thanks to inline CSS.
 */
function buildOtpEmailBody(string $code, string $purpose): string {
    $appName = defined('APP_NAME') ? APP_NAME : 'CSC Result Complaint Portal';
    $expiryMinutes = defined('OTP_EXPIRY_MINUTES') ? OTP_EXPIRY_MINUTES : 15;
    $title = $purpose === 'password_reset' ? 'Password Reset Code' : 'Email Verification Code';
    $intro = $purpose === 'password_reset'
        ? 'You requested a password reset for your ' . htmlspecialchars($appName) . ' account. Use the code below to set a new password.'
        : 'Welcome to ' . htmlspecialchars($appName) . '. To complete your registration, please verify your email address with the code below.';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#f7f9ff;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f7f9ff;">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table role="presentation" width="520" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:24px;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,0.05);">
          <!-- Top colour bar -->
          <tr><td style="height:5px;background:#c0392b;"></td></tr>
          <tr><td style="height:6px;background:#001e40;"></td></tr>
          <tr><td style="height:5px;background:#fecb00;"></td></tr>
          <!-- Header -->
          <tr><td style="padding:32px 40px 16px;">
            <h1 style="margin:0 0 4px;font-size:22px;font-weight:900;color:#001e40;text-align:center;">{$title}</h1>
            <p style="margin:0;font-size:11px;color:#43474f;text-transform:uppercase;letter-spacing:0.2em;text-align:center;font-weight:700;">{$appName}</p>
          </td></tr>
          <!-- Body -->
          <tr><td style="padding:8px 40px 8px;font-size:14px;color:#0b1d2c;line-height:1.7;">
            <p style="margin:0 0 16px;">{$intro}</p>
          </td></tr>
          <!-- OTP code box -->
          <tr><td align="center" style="padding:16px 40px 8px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
              <tr><td style="background-color:#edf4ff;border:1px dashed #d2e4f9;border-radius:16px;padding:20px 32px;">
                <p style="margin:0 0 4px;font-size:10px;color:#43474f;text-transform:uppercase;letter-spacing:0.2em;text-align:center;font-weight:700;">Your Code</p>
                <p style="margin:0;font-size:38px;font-weight:900;letter-spacing:8px;color:#001e40;text-align:center;font-family:'Courier New',monospace;">{$code}</p>
              </td></tr>
            </table>
          </td></tr>
          <!-- Expiry notice -->
          <tr><td style="padding:16px 40px 24px;font-size:13px;color:#43474f;line-height:1.6;text-align:center;">
            <p style="margin:0 0 8px;">This code expires in <strong>{$expiryMinutes} minutes</strong>.</p>
            <p style="margin:0;font-size:12px;color:#745b00;background-color:#ffe08b40;padding:8px 12px;border-radius:8px;display:inline-block;">If you did not request this, you can safely ignore this email.</p>
          </td></tr>
          <!-- Footer -->
          <tr><td style="padding:24px 40px;border-top:1px solid #d2e4f9;">
            <p style="margin:0;font-size:10px;color:#43474f;text-align:center;text-transform:uppercase;letter-spacing:0.2em;font-weight:700;">Lagos State University &bull; Academic Redress System</p>
          </td></tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
}