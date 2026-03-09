<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$key = env('GEMINI_API_KEY');
$response = \Illuminate\Support\Facades\Http::withoutVerifying()->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent?key=$key", [
    "contents" => [[
        "parts" => [
            ["text" => "Produce a high quality thumbnail of a cat."]
        ]
    ]]
]);
file_put_contents('test_image2.json', $response->body());






