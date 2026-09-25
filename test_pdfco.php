// Test if PDF.co can actually fetch your file
$testUrl = 'https://oludevops.infinityfreeapp.com/uniportal/assets/uploads/evidence/EVID_6a040c74da990.pdf';

$ch = curl_init($testUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_NOBODY         => true, // HEAD only
    // Simulate PDF.co's request — no browser user agent
    CURLOPT_USERAGENT      => 'PDFco/1.0',
]);
curl_exec($ch);
$code        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "HTTP: $code<br>";
echo "Content-Type: $contentType<br>";