<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$key = env('GEMINI_API_KEY');
$response = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://generativelanguage.googleapis.com/v1beta/models?key=$key");
file_put_contents('models.json', $response->body());
