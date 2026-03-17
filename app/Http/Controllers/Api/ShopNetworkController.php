<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Network;
use App\Models\Package;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopNetworkController extends Controller
{
    /**
     * Link network to shop using link code
     */
    public function linkNetwork(Request $request)
    {
        $request->validate([
            'link_code' => 'required|string',
        ]);

        $user = $request->user();

        if (!$user->isShop()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $shop = Shop::where('link_code', $request->link_code)->first();

        if (!$shop) {
            return response()->json(['message' => 'رمز الربط غير صحيح'], 404);
        }

        if ($shop->owner_id && $shop->owner_id !== $user->id) {
            return response()->json(['message' => 'هذا الرمز مستخدم بالفعل'], 422);
        }

        // Check if already linked
        if ($user->networks()->where('network_id', $shop->network_id)->exists()) {
            if ($shop->owner_id !== $user->id) {
                $shop->owner_id = $user->id;
                $shop->save();
            }
            return response()->json([
                'message' => 'هذه الشبكة مرتبطة بالفعل بحسابك',
                'network' => $shop->network,
            ]);
        }

        // Link network to shop owner
        $user->networks()->attach($shop->network_id);
        if ($shop->owner_id !== $user->id) {
            $shop->owner_id = $user->id;
            $shop->save();
        }

        return response()->json([
            'message' => 'تم ربط الشبكة بنجاح',
            'network' => $shop->network,
        ]);
    }

    /**
     * Get linked networks for shop owner
     */
    public function getLinkedNetworks(Request $request)
    {
        $user = $request->user();

        if (!$user->isShop()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $shopIds = $user->shops()->pluck('id');

        $networks = $user->networks()
            ->with(['packages' => function($q) {
                $q->where('status', 'active');
            }])
            ->withCount(['cards as available_cards' => function($q) use ($shopIds) {
                $q->where('status', 'available')
                  ->where(function ($q2) use ($shopIds) {
                      $q2->whereIn('assigned_shop_id', $shopIds)
                         ->orWhereHas('shopCards', function ($q3) use ($shopIds) {
                             $q3->whereIn('shop_id', $shopIds);
                         });
                  });
            }])
            ->withCount(['cards as cards_count' => function($q) use ($shopIds) {
                $q->where('status', 'available')
                  ->where(function ($q2) use ($shopIds) {
                      $q2->whereIn('assigned_shop_id', $shopIds)
                         ->orWhereHas('shopCards', function ($q3) use ($shopIds) {
                             $q3->whereIn('shop_id', $shopIds);
                         });
                  });
            }])
            ->get();

        return response()->json($networks);
    }

    /**
     * Get packages for a network with available card counts for this shop
     */
    public function getPackages(Request $request, Network $network)
    {
        $user = $request->user();

        if (!$user->isShop()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if (!$user->networks()->where('network_id', $network->id)->exists()) {
            return response()->json(['message' => 'هذه الشبكة غير مرتبطة بحسابك'], 403);
        }

        $shopIds = $user->shops()
            ->where('network_id', $network->id)
            ->pluck('id');

        if ($shopIds->isEmpty()) {
            return response()->json([]);
        }

        $packages = Package::where('network_id', $network->id)
            ->where('status', 'active')
            ->withCount(['cards as available_cards' => function ($q) use ($shopIds) {
                $q->where('status', 'available')
                  ->where(function ($q2) use ($shopIds) {
                      $q2->whereIn('assigned_shop_id', $shopIds)
                         ->orWhereHas('shopCards', function ($q3) use ($shopIds) {
                             $q3->whereIn('shop_id', $shopIds);
                         });
                  });
            }])
            ->having('available_cards', '>', 0)
            ->get();

        return response()->json($packages);
    }

    /**
     * Get available cards for a package in a network
     */
    public function getAvailableCards(Request $request, Network $network)
    {
        $user = $request->user();

        if (!$user->isShop()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        // Check if network is linked
        if (!$user->networks()->where('network_id', $network->id)->exists()) {
            return response()->json(['message' => 'هذه الشبكة غير مرتبطة بحسابك'], 403);
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
        ]);

        $package = \App\Models\Package::findOrFail($request->package_id);
        $shopIds = $user->shops()
            ->where('network_id', $network->id)
            ->pluck('id');

        $availableCount = \App\Models\Card::where('network_id', $network->id)
            ->where('package_id', $package->id)
            ->where('status', 'available')
            ->where(function($q) use ($shopIds) {
                $q->whereIn('assigned_shop_id', $shopIds)
                  ->orWhereHas('shopCards', function($q2) use ($shopIds) {
                      $q2->whereIn('shop_id', $shopIds);
                  });
            })
            ->count();

        return response()->json([
            'package' => $package,
            'available_count' => $availableCount,
        ]);
    }

    /**
     * Shop report per network (sales, profit, remaining by package)
     */
    public function packageReport(Request $request, Network $network)
    {
        $user = $request->user();

        if (!$user->isShop()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if (!$user->networks()->where('network_id', $network->id)->exists()) {
            return response()->json(['message' => 'هذه الشبكة غير مرتبطة بحسابك'], 403);
        }

        $shopIds = $user->shops()
            ->where('network_id', $network->id)
            ->pluck('id');

        if ($shopIds->isEmpty()) {
            return response()->json([
                'network' => $network->only(['id', 'name']),
                'totals' => [
                    'sold_count' => 0,
                    'revenue' => 0,
                    'profit' => 0,
                    'remaining_count' => 0,
                ],
                'packages' => [],
            ]);
        }

        $assignedCards = Card::where('network_id', $network->id)
            ->where(function ($q) use ($shopIds) {
                $q->whereIn('assigned_shop_id', $shopIds)
                  ->orWhereHas('shopCards', function ($q2) use ($shopIds) {
                      $q2->whereIn('shop_id', $shopIds);
                  });
            });

        $remainingByPackage = (clone $assignedCards)
            ->where('status', 'available')
            ->select('package_id', DB::raw('count(*) as remaining'))
            ->groupBy('package_id')
            ->get()
            ->keyBy('package_id');

        $salesByPackage = \App\Models\Sale::where('network_id', $network->id)
            ->whereIn('shop_id', $shopIds)
            ->select('package_id', DB::raw('count(*) as sold_count'), DB::raw('sum(price) as revenue'))
            ->groupBy('package_id')
            ->get()
            ->keyBy('package_id');

        $packageIds = $remainingByPackage->keys()->merge($salesByPackage->keys())->unique()->filter();
        if ($packageIds->isEmpty()) {
            return response()->json([
                'network' => $network->only(['id', 'name']),
                'totals' => [
                    'sold_count' => 0,
                    'revenue' => 0,
                    'profit' => 0,
                    'remaining_count' => 0,
                ],
                'packages' => [],
            ]);
        }

        $packages = Package::whereIn('id', $packageIds)->get();

        $totals = [
            'sold_count' => 0,
            'revenue' => 0,
            'profit' => 0,
            'remaining_count' => 0,
        ];

        $packagePayload = $packages->map(function ($package) use ($remainingByPackage, $salesByPackage, &$totals) {
            $remaining = (int) ($remainingByPackage[$package->id]->remaining ?? 0);
            $soldCount = (int) ($salesByPackage[$package->id]->sold_count ?? 0);
            $revenue = (float) ($salesByPackage[$package->id]->revenue ?? 0);
            $wholesale = (float) ($package->wholesale_price ?? $package->price ?? 0);
            $retail = (float) ($package->retail_price ?? $package->price ?? 0);
            if ($revenue == 0.0 && $soldCount > 0) {
                $revenue = $retail * $soldCount;
            }
            $profit = $revenue - ($wholesale * $soldCount);

            $totals['sold_count'] += $soldCount;
            $totals['revenue'] += $revenue;
            $totals['profit'] += $profit;
            $totals['remaining_count'] += $remaining;

            return [
                'id' => $package->id,
                'name' => $package->name,
                'data_limit' => $package->data_limit,
                'validity_days' => $package->validity_days,
                'wholesale_price' => $wholesale,
                'retail_price' => $retail,
                'sold_count' => $soldCount,
                'revenue' => $revenue,
                'profit' => $profit,
                'remaining_count' => $remaining,
            ];
        })->values();

        return response()->json([
            'network' => $network->only(['id', 'name']),
            'totals' => $totals,
            'packages' => $packagePayload,
        ]);
    }
}
