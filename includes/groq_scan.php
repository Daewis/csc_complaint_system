<?php

  require_once __DIR__ . '/../config/config.php';



/*
|--------------------------------------------------------------------------
| Convert PDF first page to PNG via PDF.co
|--------------------------------------------------------------------------
*/

function convertPdfToImageViaPdfCo(string $pdfPath): ?string
{
    if (!file_exists($pdfPath)) {
        file_put_contents(__DIR__ . '/pdfco_failure.txt', 'PDF file does not exist: ' . $pdfPath);
        return null;
    }

    // ── STEP 1: Upload PDF directly to PDF.co CDN ─────────────────────────
    // InfinityFree public URLs return HTML to non-browser agents (422 error).
    // Uploading directly bypasses this — PDF.co downloads from its own CDN.
    $uploadCh = curl_init('https://api.pdf.co/v1/file/upload');
    curl_setopt_array($uploadCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['x-api-key: ' . PDFCO_API_KEY],
        CURLOPT_POSTFIELDS     => [
            'file' => new CURLFile($pdfPath, 'application/pdf', basename($pdfPath))
        ],
    ]);
    $uploadResponse = curl_exec($uploadCh);
    $uploadErr      = curl_error($uploadCh);
    curl_close($uploadCh);

    if ($uploadErr) {
        file_put_contents(__DIR__ . '/pdfco_failure.txt', 'Upload cURL error: ' . $uploadErr);
        return null;
    }

    $uploadData = json_decode($uploadResponse, true);
    file_put_contents(__DIR__ . '/pdfco_upload_debug.txt', print_r($uploadData, true));

    if (empty($uploadData['url'])) {
        file_put_contents(__DIR__ . '/pdfco_failure.txt', 'Upload failed: ' . $uploadResponse);
        return null;
    }

    $uploadedUrl = $uploadData['url']; // PDF.co CDN URL — always accessible to PDF.co

    // ── STEP 2: Convert using the CDN URL ─────────────────────────────────
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.pdf.co/v1/pdf/convert/to/png',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . PDFCO_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'pages'   => '0',           // first page only
            'name'    => 'converted.png',
            'async'   => false,
            'url'     => $uploadedUrl,
        ]),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    file_put_contents(__DIR__ . '/pdfco_raw_response.txt', $response);

    if ($curlErr) {
        file_put_contents(__DIR__ . '/pdfco_failure.txt', 'Convert cURL error: ' . $curlErr);
        return null;
    }
    if ($httpCode >= 400) {
        file_put_contents(__DIR__ . '/pdfco_failure.txt', 'HTTP Error: ' . $httpCode);
        return null;
    }

    $data = json_decode($response, true);
    file_put_contents(__DIR__ . '/pdfco_debug.txt', print_r($data, true));

    if (!$data || !empty($data['error']) || empty($data['urls'][0])) {
        file_put_contents(__DIR__ . '/pdfco_failure.txt', print_r($data, true));
        return null;
    }

    // ── STEP 3: Download converted PNG ────────────────────────────────────
    $tempDir = __DIR__ . '/../assets/uploads/temp/';
    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
    $tempImage = $tempDir . uniqid('pdf_', true) . '.png';

    $imgCh = curl_init();
    curl_setopt_array($imgCh, [
        CURLOPT_URL            => $data['urls'][0],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $imageContent = curl_exec($imgCh);
    $imgErr       = curl_error($imgCh);
    curl_close($imgCh);

    if ($imgErr || !$imageContent) {
        file_put_contents(__DIR__ . '/pdfco_image_error.txt', $imgErr ?: 'Empty image content');
        return null;
    }

    file_put_contents($tempImage, $imageContent);
    return file_exists($tempImage) ? $tempImage : null;
}
/*
|--------------------------------------------------------------------------
| Main AI document scan
|--------------------------------------------------------------------------
*/
function scanDocumentWithGroq(string $filePath): array
{
    /*
    |--------------------------------------------------------------------------
    | Validate file
    |--------------------------------------------------------------------------
    */
    if (!file_exists($filePath)) {
        return [
            'success' => false,
            'message' => 'Evidence file not found'
        ];
    }

    $mime = mime_content_type($filePath);
    $tempConvertedImage = null;

    /*
    |--------------------------------------------------------------------------
    | Handle PDF uploads
    |--------------------------------------------------------------------------
    */
    if ($mime === 'application/pdf') {

        $tempConvertedImage = convertPdfToImageViaPdfCo($filePath);

        if (!$tempConvertedImage) {

            return [
                'success' => false,
                'message' => 'PDF conversion failed'
            ];
        }

        $filePath = $tempConvertedImage;
        $mime     = 'image/png';
    }

    /*
    |--------------------------------------------------------------------------
    | Auto cleanup temp image
    |--------------------------------------------------------------------------
    */
    register_shutdown_function(function () use ($tempConvertedImage) {

        if (
            $tempConvertedImage &&
            file_exists($tempConvertedImage)
        ) {
            unlink($tempConvertedImage);
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Allowed image types
    |--------------------------------------------------------------------------
    */
    $allowed = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!in_array($mime, $allowed)) {

        return [
            'success' => false,
            'message' => 'Unsupported file type'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Read image
    |--------------------------------------------------------------------------
    */
    $imageData = file_get_contents($filePath);

    if (!$imageData) {

        return [
            'success' => false,
            'message' => 'Failed to read image'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Encode image
    |--------------------------------------------------------------------------
    */
    $base64 = base64_encode($imageData);

    /*
    |--------------------------------------------------------------------------
    | AI prompt
    |--------------------------------------------------------------------------
    */
    $prompt = '
You are an academic course registration parser.

Read the uploaded LASU course registration form carefully.

Extract:
- total registered units
- all course codes
- each course unit

Rules:
- Return ONLY valid JSON
- No markdown
- No explanation
- No comments
- No extra text

JSON format:

{
  "total_units": 21,
  "courses": [
    {
      "code": "CSC 201",
      "units": 3
    }
  ],
  "confidence": 95
}

If unreadable return:

{
  "error": "Cannot read document",
  "confidence": 0
}
';

    /*
    |--------------------------------------------------------------------------
    | Groq payload
    |--------------------------------------------------------------------------
    */
    $payload = [
        'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',

        'messages' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $prompt
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:$mime;base64,$base64"
                    ]
                ]
            ]
        ]],

        'temperature' => 0,
        'max_tokens'  => 700
    ];

    /*
    |--------------------------------------------------------------------------
    | Initialize Groq request
    |--------------------------------------------------------------------------
    */
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.groq.com/openai/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 120,

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Content-Type: application/json'
        ],

        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    /*
    |--------------------------------------------------------------------------
    | Execute request
    |--------------------------------------------------------------------------
    */
    $response = curl_exec($ch);

    /*
    |--------------------------------------------------------------------------
    | Save raw Groq response
    |--------------------------------------------------------------------------
    */
    file_put_contents(
        __DIR__ . '/groq_raw_response.txt',
        $response
    );

    /*
    |--------------------------------------------------------------------------
    | cURL error
    |--------------------------------------------------------------------------
    */
    if (curl_errno($ch)) {

        $err = curl_error($ch);

        curl_close($ch);

        return [
            'success' => false,
            'message' => 'Curl error: ' . $err
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP status
    |--------------------------------------------------------------------------
    */
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    /*
    |--------------------------------------------------------------------------
    | HTTP error
    |--------------------------------------------------------------------------
    */
    if ($httpCode >= 400) {

        return [
            'success' => false,
            'message' => "Groq API returned HTTP $httpCode",
            'raw' => $response
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Decode response
    |--------------------------------------------------------------------------
    */
    $data = json_decode($response, true);

    if (!$data) {

        return [
            'success' => false,
            'message' => 'Invalid API response',
            'raw' => $response
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | API error
    |--------------------------------------------------------------------------
    */
    if (isset($data['error'])) {

        return [
            'success' => false,
            'message' => $data['error']['message'] ?? 'Unknown API error'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Extract AI content
    |--------------------------------------------------------------------------
    */
    $content = $data['choices'][0]['message']['content'] ?? '';

    if (!$content) {

        return [
            'success' => false,
            'message' => 'Empty AI response'
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Remove markdown fences
    |--------------------------------------------------------------------------
    */
    $content = preg_replace('/```json|```/', '', $content);

    /*
    |--------------------------------------------------------------------------
    | Clean whitespace
    |--------------------------------------------------------------------------
    */
    $content = trim($content);

    /*
    |--------------------------------------------------------------------------
    | Parse JSON
    |--------------------------------------------------------------------------
    */
    $parsed = json_decode($content, true);

    if (!$parsed) {

        return [
            'success' => false,
            'message' => 'AI response parsing failed',
            'raw' => $content
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */
    return [
        'success' => true,
        'data'    => $parsed
    ];
}
