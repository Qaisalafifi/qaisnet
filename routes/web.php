<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/owner-dashboard', function () {
    return view('owner-dashboard');
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



Route::get('/run-migrate', function (Request $request) {
    $token = $request->query('token');
    $serverToken = env('MAINTENANCE_TOKEN');
    if (!$serverToken || !$token || !hash_equals($serverToken, $token)) {
        abort(403, 'غير مصرح');
    }

    try {
        Artisan::call('migrate --force');
        return "تم رفع الجداول بنجاح: <br><pre>" . Artisan::output() . "</pre>";
    } catch (\Exception $e) {
        return "فشل رفع الجداول: " . $e->getMessage();
    }
});

Route::get('/run-storage-link', function (Request $request) {
    $token = $request->query('token');
    $serverToken = env('MAINTENANCE_TOKEN');
    if (!$serverToken || !$token || !hash_equals($serverToken, $token)) {
        abort(403, 'غير مصرح');
    }

    try {
        Artisan::call('storage:link');
        return "تم تفعيل storage:link بنجاح: <br><pre>" . Artisan::output() . "</pre>";
    } catch (\Exception $e) {
        return "فشل تفعيل storage:link: " . $e->getMessage();
    }
});
