<?php
$url = 'https://github.com/google/fonts/raw/main/ofl/notosansdevanagari/NotoSansDevanagari-Bold.ttf';
$fontDir = __DIR__ . '/public/fonts';
if (!is_dir($fontDir)) {
    mkdir($fontDir, 0777, true);
}
$fontPath = $fontDir . '/NotoSansDevanagari-Bold.ttf';
$context = stream_context_create([
    'http' => [
        'header' => 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    ]
]);
file_put_contents($fontPath, file_get_contents($url, false, $context));
echo "Downloaded font size: " . filesize($fontPath) . " bytes\n";
