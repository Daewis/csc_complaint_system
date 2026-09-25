<?php
/**
 * qr_verifier.php
 * Decode + verify LASU QR codes from uploaded evidence.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Zxing\QrReader;

/**
 * Decode QR code from image
 */
function decodeQrFromImage(string $imagePath): ?string
{
    if (!file_exists($imagePath)) {
        return null;
    }

    $mime = mime_content_type($imagePath);

    $allowed = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    if (!in_array($mime, $allowed)) {
        return null;
    }

    try {
        $qrcode = new QrReader($imagePath);
        $text   = $qrcode->text();

        if (!$text || trim($text) === '') {
            return null;
        }

        return trim($text);

    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Verify LASU QR URL
 */
function verifyLasuQRCode(string $qrText): array
{
    $qrText = trim($qrText);

    // Must be valid URL
    if (!filter_var($qrText, FILTER_VALIDATE_URL)) {
        return [
            'valid'  => false,
            'url'    => null,
            'reason' => 'QR content is not a valid URL.'
        ];
    }

    $host = strtolower(parse_url($qrText, PHP_URL_HOST) ?? '');

    // Strict LASU whitelist
    $allowedHosts = [
        'studentservices.lasu.edu.ng',
        'lasu.edu.ng',
        'www.lasu.edu.ng'
    ];

    $validHost = false;

    foreach ($allowedHosts as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            $validHost = true;
            break;
        }
    }

    if (!$validHost) {
        return [
            'valid'  => false,
            'url'    => $qrText,
            'reason' => "QR URL is not from an approved LASU domain."
        ];
    }

    // Verify URL exists
    $ch = curl_init($qrText);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'LASU Complaint Portal QR Verifier'
    ]);

    curl_exec($ch);

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);

    curl_close($ch);

    if ($curlErr) {
        return [
            'valid'  => false,
            'url'    => $qrText,
            'reason' => "Could not verify QR URL."
        ];
    }

    if ($httpCode < 200 || $httpCode >= 400) {
        return [
            'valid'  => false,
            'url'    => $qrText,
            'reason' => "QR URL returned HTTP {$httpCode}."
        ];
    }

    return [
        'valid'  => true,
        'url'    => $qrText,
        'reason' => 'LASU QR successfully verified.'
    ];
}

/**
 * Full QR verification pipeline
 */
function scanAndVerifyQR(string $imagePath): array
{
    $qrText = decodeQrFromImage($imagePath);

    if (!$qrText) {
        return [
            'success'  => false,
            'qr_text'  => null,
            'verified' => false,
            'url'      => null,
            'reason'   => 'No QR code detected in uploaded image.'
        ];
    }

    $verification = verifyLasuQRCode($qrText);

    return [
        'success'  => true,
        'qr_text'  => $qrText,
        'verified' => $verification['valid'],
        'url'      => $verification['url'],
        'reason'   => $verification['reason']
    ];
}

/**
 * Convert first page of PDF to image
 * NOTE:
 * Imagick is usually unavailable on InfinityFree.
 * This will work locally or on VPS/cPanel hosting.
 */
function convertPdfFirstPageToImage(string $pdfPath, string $outputPath): bool
{
    if (!extension_loaded('imagick')) {
        return false;
    }

    try {
        $imagick = new Imagick();

        $imagick->setResolution(200, 200);

        $imagick->readImage($pdfPath . '[0]');

        $imagick->setImageFormat('png');

        $imagick->writeImage($outputPath);

        $imagick->clear();
        $imagick->destroy();

        return file_exists($outputPath);

    } catch (Throwable $e) {
        return false;
    }
}
