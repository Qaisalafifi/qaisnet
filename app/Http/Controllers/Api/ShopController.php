<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\Package;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Shop::with('network', 'owner')->withCount('cards', 'sales');

        if ($user->isAdmin()) {
            // no restriction
        } elseif ($user->isNetworkOwner()) {
            $networkIds = Network::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('network_id', $networkIds);
        } else {
            $networkIds = $user->networks()->pluck('networks.id');
            $query->whereIn('network_id', $networkIds);
        }

        if ($request->filled('network_id')) {
            $networkId = (int) $request->network_id;
            if (!$user->isAdmin()) {
                if ($user->isNetworkOwner()) {
                    $ownsNetwork = Network::where('id', $networkId)
                        ->where('owner_id', $user->id)
                        ->exists();
                    if (!$ownsNetwork) abort(403, 'غير مصرح.');
                } else {
                    $linked = $user->networks()->where('network_id', $networkId)->exists();
                    if (!$linked) abort(403, 'غير مصرح.');
                }
            }
            $query->where('network_id', $networkId);
        }

        if ($user->isAdmin() && $request->filled('owner_id')) {
            $ownerId = (int) $request->owner_id;
            $query->where(function ($q) use ($ownerId) {
                $q->where('network_owner_id', $ownerId)
                  ->orWhereHas('network', function ($q2) use ($ownerId) {
                      $q2->where('owner_id', $ownerId);
                  });
            });
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('add_shop')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بإضافة بقالات.'], 403);
        }

        $request->validate([
            'name'       => 'required|string|max:255',
            'network_id' => 'required|exists:networks,id',
        ]);

        // Ensure network belongs to this owner
        $network = Network::findOrFail($request->network_id);

        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $shop = Shop::create([
            'name'             => $request->name,
            'network_id'       => $request->network_id,
            'network_owner_id' => $network->owner_id,
            'access_code'      => strtoupper(Str::random(8)),
            'link_code'        => strtoupper(Str::random(12)), // Generate link code for shop owner
        ]);

        return response()->json([
            'message' => 'تم إنشاء البقالة بنجاح',
            'shop' => $shop,
            'link_code' => $shop->link_code, // Return link code explicitly
        ], 201);
    }

    public function show(Request $request, Shop $shop)
    {
        $this->authorizeShop($request, $shop);

        return response()->json($shop->load('network', 'owner'));
    }

    public function update(Request $request, Shop $shop)
    {
        $this->authorizeShop($request, $shop);

        $request->validate([
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        $shop->update($request->only('name', 'is_active'));

        return response()->json($shop);
    }

    public function destroy(Request $request, Shop $shop)
    {
        $this->authorizeShop($request, $shop);

        $shop->delete();

        return response()->json(['message' => 'تم حذف البقالة بنجاح.']);
    }

    public function regenerateCode(Request $request, Shop $shop)
    {
        $this->authorizeShop($request, $shop);

        $shop->update(['access_code' => strtoupper(Str::random(8))]);

        return response()->json(['access_code' => $shop->access_code]);
    }

    // Stats for a shop
    public function stats(Request $request, Shop $shop)
    {
        $this->authorizeShop($request, $shop);

        $cards    = $shop->cards()->get();
        $sales    = $shop->sales()->with('card')->get();

        $byCategory = $cards->groupBy('category')->map(function ($group) use ($sales) {
            $cat      = $group->first()->category;
            $catSales = $sales->filter(fn($s) => $s->card->category == $cat);

            return [
                'category'       => $cat,
                'total_cards'    => $group->count(),
                'sold'           => $group->where('status', 'sold')->count(),
                'available'      => $group->where('status', 'available')->count(),
                'total_revenue'  => $catSales->sum('sold_price'),
            ];
        })->values();

        return response()->json([
            'shop'            => $shop->only('id', 'name'),
            'total_cards'     => $cards->count(),
            'total_sold'      => $cards->where('status', 'sold')->count(),
            'total_available' => $cards->where('status', 'available')->count(),
            'total_revenue'   => $sales->sum('sold_price'),
            'by_category'     => $byCategory,
        ]);
    }

    /**
     * Package-based cards summary for a shop (Owner/Admin)
     */
    public function packageStats(Request $request, Shop $shop)
    {
        $user = $request->user();
        if (!$user->isAdmin() && (!$user->isNetworkOwner() || $shop->network->owner_id !== $user->id)) {
            abort(403, 'غير مصرح.');
        }

        $cardBase = \App\Models\Card::query()
            ->where('cards.network_id', $shop->network_id)
            ->where(function ($q) use ($shop) {
                $q->where('assigned_shop_id', $shop->id)
                  ->orWhereHas('shopCards', function ($q2) use ($shop) {
                      $q2->where('shop_id', $shop->id);
                  });
            });

        $totals = (clone $cardBase)
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status='available' then 1 else 0 end) as available")
            ->selectRaw("sum(case when status='sold' then 1 else 0 end) as sold")
            ->selectRaw("sum(case when status='disabled' then 1 else 0 end) as disabled")
            ->selectRaw("sum(case when status='expired' then 1 else 0 end) as expired_status")
            ->first();

        $timeStats = (clone $cardBase)
            ->join('packages', 'cards.package_id', '=', 'packages.id')
            ->selectRaw("sum(case when cards.status='sold' and cards.sold_at is not null and DATE_ADD(cards.sold_at, INTERVAL packages.validity_days DAY) >= NOW() then 1 else 0 end) as in_use")
            ->selectRaw("sum(case when cards.status='sold' and cards.sold_at is not null and DATE_ADD(cards.sold_at, INTERVAL packages.validity_days DAY) < NOW() then 1 else 0 end) as sold_expired")
            ->first();

        $totalsPayload = [
            'total' => (int) ($totals->total ?? 0),
            'available' => (int) ($totals->available ?? 0),
            'sold' => (int) ($totals->sold ?? 0),
            'disabled' => (int) ($totals->disabled ?? 0),
            'expired_status' => (int) ($totals->expired_status ?? 0),
            'in_use' => (int) ($timeStats->in_use ?? 0),
            'sold_expired' => (int) ($timeStats->sold_expired ?? 0),
            'revenue' => 0,
            'profit' => 0,
            'sold_sales' => 0,
        ];

        $salesByPackage = \App\Models\Sale::where('shop_id', $shop->id)
            ->where('network_id', $shop->network_id)
            ->selectRaw('package_id, count(*) as sold_count, sum(price) as revenue')
            ->groupBy('package_id')
            ->get()
            ->keyBy('package_id');

        $assignedStats = (clone $cardBase)
            ->join('packages', 'cards.package_id', '=', 'packages.id')
            ->groupBy(
                'packages.id',
                'packages.name',
                'packages.data_limit',
                'packages.validity_days',
                'packages.price',
                'packages.wholesale_price',
                'packages.retail_price'
            )
            ->select(
                'packages.id',
                'packages.name',
                'packages.data_limit',
                'packages.validity_days'
            )
            ->selectRaw('COALESCE(packages.wholesale_price, packages.price) as wholesale_price')
            ->selectRaw('COALESCE(packages.retail_price, packages.price) as retail_price')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when cards.status='available' then 1 else 0 end) as available")
            ->selectRaw("sum(case when cards.status='sold' then 1 else 0 end) as sold")
            ->selectRaw("sum(case when cards.status='disabled' then 1 else 0 end) as disabled")
            ->selectRaw("sum(case when cards.status='expired' then 1 else 0 end) as expired_status")
            ->selectRaw("sum(case when cards.status='sold' and cards.sold_at is not null and DATE_ADD(cards.sold_at, INTERVAL packages.validity_days DAY) >= NOW() then 1 else 0 end) as in_use")
            ->selectRaw("sum(case when cards.status='sold' and cards.sold_at is not null and DATE_ADD(cards.sold_at, INTERVAL packages.validity_days DAY) < NOW() then 1 else 0 end) as sold_expired")
            ->orderBy('packages.name')
            ->get()
            ->keyBy('id');

        $packageIds = $assignedStats->keys()->merge($salesByPackage->keys())->unique()->filter();

        $packagesMeta = Package::whereIn('id', $packageIds)->get()->keyBy('id');

        $packages = $packageIds->map(function ($id) use ($assignedStats, $salesByPackage, $packagesMeta, &$totalsPayload) {
            $assigned = $assignedStats[$id] ?? null;
            $pkg = $packagesMeta[$id] ?? null;
            if (!$pkg) return null;

            $wholesale = (float) ($assigned->wholesale_price ?? $pkg->wholesale_price ?? $pkg->price ?? 0);
            $retail = (float) ($assigned->retail_price ?? $pkg->retail_price ?? $pkg->price ?? 0);

            $total = (int) ($assigned->total ?? 0);
            $available = (int) ($assigned->available ?? 0);
            $sold = (int) ($assigned->sold ?? 0);
            $disabled = (int) ($assigned->disabled ?? 0);
            $expiredStatus = (int) ($assigned->expired_status ?? 0);
            $inUse = (int) ($assigned->in_use ?? 0);
            $soldExpired = (int) ($assigned->sold_expired ?? 0);

            $sale = $salesByPackage[$id] ?? null;
            $soldSales = (int) ($sale->sold_count ?? 0);
            $revenue = (float) ($sale->revenue ?? 0);
            if ($revenue == 0.0 && $soldSales > 0) {
                $revenue = $retail * $soldSales;
            }
            $profit = $revenue - ($wholesale * $soldSales);

            $totalsPayload['revenue'] = ($totalsPayload['revenue'] ?? 0) + $revenue;
            $totalsPayload['profit'] = ($totalsPayload['profit'] ?? 0) + $profit;
            $totalsPayload['sold_sales'] = ($totalsPayload['sold_sales'] ?? 0) + $soldSales;

            return [
                'id' => $pkg->id,
                'name' => $pkg->name,
                'data_limit' => $pkg->data_limit,
                'validity_days' => $pkg->validity_days,
                'wholesale_price' => $wholesale,
                'retail_price' => $retail,
                'total' => $total,
                'available' => $available,
                'sold' => $sold,
                'disabled' => $disabled,
                'expired_status' => $expiredStatus,
                'in_use' => $inUse,
                'sold_expired' => $soldExpired,
                'not_sold' => max(0, $total - $sold),
                'sold_sales' => $soldSales,
                'revenue' => $revenue,
                'profit' => $profit,
            ];
        })->filter()->values();

        $totalsPayload['not_sold'] = max(0, $totalsPayload['total'] - $totalsPayload['sold']);

        return response()->json([
            'shop' => $shop->only(['id', 'name', 'network_id', 'link_code', 'is_active']),
            'totals' => $totalsPayload,
            'packages' => $packages,
        ]);
    }

    private function authorizeShop(Request $request, Shop $shop): void
    {
        $user = $request->user();
        if ($user->isAdmin()) return;
        if ($user->isNetworkOwner() && $shop->network->owner_id === $user->id) return;

        $networkIds = $user->networks()->pluck('id');
        if (! $networkIds->contains($shop->network_id)) {
            abort(403, 'غير مصرح.');
        }
    }
}
