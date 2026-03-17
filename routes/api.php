<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardGenerationController;
use App\Http\Controllers\Api\CardTemplateController;
use App\Http\Controllers\Api\NetworkController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ShopNetworkController;
use Illuminate\Support\Facades\Route;

// ── Auth ───────────────────────────────────────────────────────────────────
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// ── Protected routes ───────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ── Admin only ─────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard',       [AdminController::class, 'dashboard']);
        Route::get('/users',           [AdminController::class, 'users']);
        Route::post('/users',          [AdminController::class, 'storeUser']);
        Route::put('/users/{user}',    [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'deleteUser']);
        
        // Admin network management
        Route::get('/networks', [NetworkController::class, 'index']);
        Route::get('/networks/{network}', [NetworkController::class, 'show']);
        Route::put('/networks/{network}', [NetworkController::class, 'update']);
        Route::delete('/networks/{network}', [NetworkController::class, 'destroy']);
        
        // Admin subscriptions
        Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
        Route::post('/networks/{network}/renew', [AdminController::class, 'renewSubscription']);
    });

    // ── Network Owner Routes ──────────────────────────────────────────────
    Route::prefix('owner')->group(function () {
        Route::get('/dashboard', [NetworkController::class, 'dashboard']);
        
        // Networks
        Route::get('/networks', [NetworkController::class, 'index']);
        Route::post('/networks', [NetworkController::class, 'store']);
        Route::get('/networks/{network}', [NetworkController::class, 'show']);
        Route::put('/networks/{network}', [NetworkController::class, 'update']);
        Route::delete('/networks/{network}', [NetworkController::class, 'destroy']);
        Route::post('/networks/{network}/test-connection', [NetworkController::class, 'testConnection']);
        
        // Packages
        Route::get('/networks/{network}/packages', [PackageController::class, 'index']);
        Route::post('/networks/{network}/packages', [PackageController::class, 'store']);
        Route::put('/packages/{package}', [PackageController::class, 'update']);
        Route::delete('/packages/{package}', [PackageController::class, 'destroy']);
        Route::post('/networks/{network}/packages/sync-from-mikrotik', [PackageController::class, 'syncFromMikroTik']);
        
        // Card Generation
        Route::post('/networks/{network}/cards/generate', [CardGenerationController::class, 'generate']);
        Route::get('/card-batches/{batch}/download', [CardGenerationController::class, 'downloadCards']);
        Route::get('/card-batches/{batch}/print', [CardGenerationController::class, 'printCards']);

        // Card Templates
        Route::get('/networks/{network}/templates', [CardTemplateController::class, 'index']);
        Route::post('/networks/{network}/templates', [CardTemplateController::class, 'store']);
        Route::put('/templates/{template}', [CardTemplateController::class, 'update']);
        Route::delete('/templates/{template}', [CardTemplateController::class, 'destroy']);
        
        // Shops
        Route::get('/shops', [ShopController::class, 'index']);
        Route::post('/shops', [ShopController::class, 'store']);
        Route::get('/shops/{shop}', [ShopController::class, 'show']);
        Route::put('/shops/{shop}', [ShopController::class, 'update']);
        Route::delete('/shops/{shop}', [ShopController::class, 'destroy']);
        Route::get('/shops/{shop}/cards', [ShopController::class, 'cards']);
        Route::get('/shops/{shop}/package-stats', [ShopController::class, 'packageStats']);
        
        // Reports
        Route::get('/networks/{network}/report', [SaleController::class, 'networkReport']);
        Route::get('/networks/{network}/shops/{user}/report', [SaleController::class, 'shopReport']);
    });

    // ── Shop Owner Routes ──────────────────────────────────────────────────
    Route::prefix('shop')->group(function () {
        Route::get('/dashboard', [ShopNetworkController::class, 'getLinkedNetworks']);
        
        // Link network
        Route::post('/link-network', [ShopNetworkController::class, 'linkNetwork']);
        Route::get('/networks', [ShopNetworkController::class, 'getLinkedNetworks']);
        
        // Get available cards
        Route::get('/networks/{network}/packages/{package}/available-cards', [ShopNetworkController::class, 'getAvailableCards']);
        Route::get('/networks/{network}/packages', [ShopNetworkController::class, 'getPackages']);
        Route::get('/networks/{network}/package-report', [ShopNetworkController::class, 'packageReport']);
        
        // Sell card
        Route::post('/sell-card', [SaleController::class, 'sell']);
        
        // Reports
        Route::get('/sales', [SaleController::class, 'index']);
        Route::get('/networks/{network}/report', [SaleController::class, 'networkReport']);
    });

    // ── Common Routes ──────────────────────────────────────────────────────
    Route::get('/networks', [NetworkController::class, 'index']);
    Route::get('/networks/{network}', [NetworkController::class, 'show']);
    Route::get('/cards', [CardController::class, 'index']);
    Route::delete('/cards/{card}', [CardController::class, 'destroy']);
    Route::get('/sales', [SaleController::class, 'index']);

});
