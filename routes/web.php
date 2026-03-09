<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ThumbnailController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
// Route::post('/generate-thumbnail', [ThumbnailController::class, 'generate'])->name('thumbnail.create');
| contains the "web" middleware group. Now create something great!
|
*/

// keep a dedicated url for the form as well as the home page so the user can
// navigate to either root or /thumbnail/create without confusion
Route::get('/', [ThumbnailController::class, 'index'])->name('home');
// explicit GET route for the form; same view as home so users can navigate either way
Route::get('/thumbnail/create', [ThumbnailController::class, 'index'])->name('thumbnail.form');

// ensure a direct browser visit to /generate-thumbnail doesn’t throw a method error
Route::get('/generate-thumbnail', function () {
    return redirect()->route('thumbnail.form');
});

Route::post('/generate-thumbnail', [ThumbnailController::class, 'generate'])
    ->middleware('throttle:5,1')
    ->name('thumbnail.create');

