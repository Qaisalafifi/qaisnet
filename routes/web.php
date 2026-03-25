<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage-proxy/{path}', function ($path) {
    if (!Storage::disk('public')->exists($path)) {
        abort(404);
    }
    $bytes = Storage::disk('public')->get($path);
    $mime = Storage::disk('public')->mimeType($path) ?? 'application/octet-stream';
    return response($bytes, 200)
        ->header('Content-Type', $mime)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', '*');
})->where('path', '.*');

use Illuminate\Support\Facades\Artisan;

Route::get('/run-migrate', function () {
    Artisan::call('migrate:fresh --force');
    return "تم رفع الجداول بنجاح: " . Artisan::output();
});
