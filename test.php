<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = new App\Services\ThumbnailService();
$prompt = "A high quality image of a cat";
$res = $service->generateFinalImage($prompt);
dd($res);
