<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$key = env('GEMINI_API_KEY');
$response = \Illuminate\Support\Facades\Http::withoutVerifying()->post("https://generativelanguage.googleapis.com/v1beta/models/" . env('GEMINI_MODEL_IMAGE') . ":generateContent?key=$key", [
    "contents" => [[
        "parts" => [
            ["text" => "A high quality thumbnail of a cat."]
        ]
    ]]
]);
file_put_contents('test_image.json', $response->body());
