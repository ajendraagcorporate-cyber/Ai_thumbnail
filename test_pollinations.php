<?php
$prompt = urlencode("A high quality YouTube thumbnail of a cat");
$url = "https://image.pollinations.ai/prompt/{$prompt}?width=1280&height=720&nologo=true";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
// bypass ssl for local testing
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode == 200) {
    echo "Success: " . strlen($response) . " bytes\n";
    file_put_contents('pollinations_test.jpg', $response);
} else {
    echo "Error: HTTP $httpcode\n";
}
