<?php
use Illuminate\Support\Facades\Artisan;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\CardGenerationController;
use App\Http\Controllers\Api\CardTemplateController;
use App\Http\Controllers\Api\HotspotUserController;
use App\Http\Controllers\Api\HotspotScriptController;
use App\Http\Controllers\Api\NetworkController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ShopNetworkController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\SubscriptionRequestController;
use App\Http\Controllers\Api\UserManagerUserController;
use Illuminate\Support\Facades\Route;

Route::get('/refresh', function() {
    Artisan::call('route:clear');
    return "Routes Cleared";
});

// ── Auth ───────────────────────────────────────────────────────────────────
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/register-owner', [AuthController::class, 'registerOwner']);

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
        
        // Subscription requests
        Route::get('/subscription-requests', [SubscriptionRequestController::class, 'index']);
        Route::put('/subscription-requests/{subscriptionRequest}/approve', [SubscriptionRequestController::class, 'approve']);
        Route::put('/subscription-requests/{subscriptionRequest}/reject', [SubscriptionRequestController::class, 'reject']);
        
        // Admin network management
        Route::get('/networks', [NetworkController::class, 'index']);
        Route::get('/networks/{network}', [NetworkController::class, 'show']);
        Route::put('/networks/{network}', [NetworkController::class, 'update']);
        Route::delete('/networks/{network}', [NetworkController::class, 'destroy']);
        
        // Admin subscriptions
        Route::get('/subscriptions', [AdminController::class, 'subscriptions']);
        Route::post('/networks/{network}/renew', [AdminController::class, 'renewSubscription']);

        // Payment methods
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
        Route::put('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update']);
        Route::delete('/payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);

        // Subscription plans
        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
        Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
        Route::put('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'destroy']);

        // Admin shops management
        Route::get('/shops', [ShopController::class, 'index']);
        Route::put('/shops/{shop}', [ShopController::class, 'update']);
        Route::delete('/shops/{shop}', [ShopController::class, 'destroy']);
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
        Route::get('/networks/{network}/active-sessions', [NetworkController::class, 'activeSessions']);
        Route::delete('/networks/{network}/active-sessions', [NetworkController::class, 'clearActiveSessions']);
        Route::get('/networks/{network}/connected-devices', [NetworkController::class, 'connectedDevices']);
        Route::get('/networks/{network}/hotspot-hosts', [NetworkController::class, 'hotspotHosts']);
        Route::get('/networks/{network}/neighbors', [NetworkController::class, 'neighbors']);
        Route::delete('/networks/{network}/hotspot-hosts', [NetworkController::class, 'clearHotspotHosts']);
        Route::get('/networks/{network}/port-stats', [NetworkController::class, 'portStats']);
        Route::post('/networks/{network}/hotspot-scripts', [HotspotScriptController::class, 'install']);

        // Hotspot users
        Route::get('/networks/{network}/hotspot-users', [HotspotUserController::class, 'index']);
        Route::post('/networks/{network}/hotspot-users', [HotspotUserController::class, 'store']);
        Route::put('/networks/{network}/hotspot-users/{userId}', [HotspotUserController::class, 'update']);
        Route::delete('/networks/{network}/hotspot-users/{userId}', [HotspotUserController::class, 'destroy']);
        Route::post('/networks/{network}/hotspot-users/{userId}/reset-counters', [HotspotUserController::class, 'resetCounters']);
        Route::post('/networks/{network}/hotspot-users/{userId}/disable', [HotspotUserController::class, 'disable']);
        Route::post('/networks/{network}/hotspot-users/{userId}/enable', [HotspotUserController::class, 'enable']);
        Route::post('/networks/{network}/hotspot-users/{userId}/shared-users', [HotspotUserController::class, 'setSharedUsers']);

        // User Manager users
        Route::get('/networks/{network}/user-manager-users', [UserManagerUserController::class, 'index']);

        // Subscription requests (owner)
        Route::get('/subscription-requests', [SubscriptionRequestController::class, 'myRequests']);
        Route::post('/subscription-requests', [SubscriptionRequestController::class, 'store']);

        // Billing (owner)
        Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
        
        // Packages
        Route::get('/networks/{network}/packages', [PackageController::class, 'index']);
        Route::post('/networks/{network}/packages', [PackageController::class, 'store']);
        Route::put('/packages/{package}', [PackageController::class, 'update']);
        Route::delete('/packages/{package}', [PackageController::class, 'destroy']);
        Route::post('/networks/{network}/packages/sync-from-mikrotik', [PackageController::class, 'syncFromMikroTik']);
        
        // Card Generation
        Route::post('/networks/{network}/cards/generate', [CardGenerationController::class, 'generate']);
        Route::post('/networks/{network}/cards/import', [CardGenerationController::class, 'importFromClient']);
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
Route::get('/run-seed', function () {
    try {
        Artisan::call('db:seed', ['--force' => true]);
        return "تمت إضافة البيانات بنجاح في قاعدة البيانات!";
    } catch (\Exception $e) {
        return "حدث خطأ: " . $e->getMessage();
    }
});

Route::get('/fix-auth', function () {
    try {
        // 1. مسح المستخدمين القدامى للتأكد من نظافة الجدول
        User::truncate(); 

        // 2. إضافة مستخدم جديد بكلمة سر مشفرة برمجياً
        User::create([
            'name' => 'Admin Qais',
            'email' => 'admin@qaisnet.com',
            'password' => Hash::make('admin123'), // هنا السر!
            'role' => 'admin',
        ]);

        return "تم تحديث البيانات وتشفير كلمة السر بنجاح!";
    } catch (\Exception $e) {
        return "خطأ: " . $e->getMessage();
    }
});
