<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Sale;
use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    /**
     * Sell a card for a specific network
     */
    public function sell(Request $request)
    {
        $request->validate([
            'network_id' => 'required|exists:networks,id',
            'package_id' => 'required|exists:packages,id',
        ]);

        $user = $request->user();

        // Check if user is shop owner
        if (!$user->isShop()) {
            return response()->json(['message' => 'غير مصرح. يجب أن تكون صاحب بقالة'], 403);
        }

        // Check if user is linked to this network
        if (!$user->networks()->where('network_id', $request->network_id)->exists()) {
            return response()->json(['message' => 'هذه الشبكة غير مرتبطة بحسابك.'], 403);
        }

        $shop = \App\Models\Shop::where('owner_id', $user->id)
            ->where('network_id', $request->network_id)
            ->first();

        if (!$shop) {
            return response()->json([
                'message' => 'لا توجد بقالة مرتبطة لهذا الحساب.'
            ], 422);
        }

        // Find available card for this shop (assigned only)
        $card = Card::where('network_id', $request->network_id)
                    ->where('package_id', $request->package_id)
                    ->where('status', 'available')
                    ->where(function($q) use ($shop) {
                        $q->where('assigned_shop_id', $shop->id)
                          ->orWhereHas('shopCards', function($q2) use ($shop) {
                              $q2->where('shop_id', $shop->id);
                          });
                    })
                    ->first();

        if (!$card) {
            return response()->json([
                'message' => 'لا توجد كروت متاحة من هذه الباقة.'
            ], 404);
        }

        $package = \App\Models\Package::findOrFail($request->package_id);
        $sellPrice = $package->retail_price ?? $package->price ?? 0;

        DB::transaction(function () use ($card, $user, $request, $package, $shop, $sellPrice) {
            $card->update([
                'status'  => 'sold',
                'sold_at' => now(),
            ]);

            Sale::create([
                'card_id'        => $card->id,
                'shop_id'        => $shop->id,
                'network_id'     => $request->network_id,
                'package_id'     => $request->package_id,
                'price'          => $sellPrice,
                'sold_by_user_id' => $user->id,
                'sold_at'        => now(),
            ]);

            // Log audit
            \App\Models\AuditLog::create([
                'user_id' => $user->id,
                'action' => 'card_sold',
                'description' => "تم بيع كرت {$card->code}",
                'ip' => $request->ip(),
                'metadata' => [
                    'card_id' => $card->id,
                    'network_id' => $request->network_id,
                    'package_id' => $request->package_id,
                ],
            ]);
        });

        return response()->json([
            'message' => 'تم بيع الكرت بنجاح.',
            'card'    => [
                'id' => $card->id,
                'code' => $card->code,
                'package' => [
                    'name' => $package->name,
                    'data_limit' => $package->data_limit,
                    'validity_days' => $package->validity_days,
                ],
            ],
        ]);
    }

    /**
     * Detailed Report per Network
     */
    public function networkReport(Request $request, Network $network)
    {
        $user = $request->user();

        // Check access
        if ($user->isShop() && !$user->networks()->where('network_id', $network->id)->exists()) {
            abort(403, 'غير مصرح.');
        }

        $query = Sale::where('network_id', $network->id);
        if ($user->isShop()) {
            $query->where('user_id', $user->id);
        }

        $totalSales   = (clone $query)->count();
        $totalRevenue = (clone $query)->sum('sold_price');
        $totalProfit  = $totalRevenue; // Simplification, can be adjusted

        // Category breakdown
        $categoryReport = Card::where('network_id', $network->id)
            ->where('status', 'sold')
            ->select('category', DB::raw('count(*) as count'), DB::raw('sum(price) as revenue'))
            ->when($user->isShop(), fn($q) => $q->whereIn('id', (clone $query)->pluck('card_id')))
            ->groupBy('category')
            ->get();

        $availableCards = Card::where('network_id', $network->id)
            ->where('status', 'available')
            ->count();

        return response()->json([
            'network_name'    => $network->name,
            'total_sales'     => $totalSales,
            'total_revenue'   => $totalRevenue,
            'available_cards' => $availableCards,
            'categories'      => $categoryReport,
        ]);
    }

    /**
     * Detailed report for a specific shop in a network
     */
    public function shopReport(Request $request, Network $network, \App\Models\User $user)
    {
        // Check if owner owns network OR user is admin
        if ($network->owner_id !== $request->user()->id && !$request->user()->isAdmin()) {
            abort(403);
        }

        $query = Sale::where('network_id', $network->id)->where('user_id', $user->id);

        $totalSales   = (clone $query)->count();
        $totalRevenue = (clone $query)->sum('sold_price');

        // Detailed category breakdown for this shop
        $categoryBreakdown = Card::where('network_id', $network->id)
            ->where('status', 'sold')
            ->whereIn('id', (clone $query)->pluck('card_id'))
            ->select('category', DB::raw('count(*) as count'), DB::raw('sum(price) as revenue'))
            ->groupBy('category')
            ->get();

        // Recent sales for this shop
        $recentSales = (clone $query)->with('card')->latest()->limit(20)->get();

        return response()->json([
            'shop_name'          => $user->shop_name ?? $user->name,
            'total_sales'        => $totalSales,
            'total_revenue'      => $totalRevenue,
            'category_breakdown' => $categoryBreakdown,
            'recent_sales'       => $recentSales,
        ]);
    }

    /**
     * Recent sales list
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Sale::with('card', 'network')->latest('sold_at');

        if ($user->isShop()) {
            $query->where('user_id', $user->id);
        }

        if ($request->has('network_id')) {
            $query->where('network_id', $request->network_id);
        }

        return response()->json($query->paginate(20));
    }
}
