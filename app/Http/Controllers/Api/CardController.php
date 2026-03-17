<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Network;
use App\Models\Shop;
use App\Services\MikroTikService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CardController extends Controller
{
    private MikroTikService $mikroTikService;

    public function __construct(MikroTikService $mikroTikService)
    {
        $this->mikroTikService = $mikroTikService;
    }

    public function index(Request $request)
    {
        $user  = $request->user();
        $query = Card::with('network', 'assignedShop', 'package');

        if ($user->isShop()) {
            // Shop: see all available cards from their linked networks
            $networkIds = $user->networks()->pluck('networks.id');
            if ($networkIds->isEmpty()) return response()->json(['data' => [], 'total' => 0]);

            $shopIds = $user->shops()->pluck('id');
            if ($shopIds->isEmpty()) return response()->json(['data' => [], 'total' => 0]);

            $query->whereIn('network_id', $networkIds)
                  ->where('status', 'available')
                  ->where(function ($q) use ($shopIds) {
                      $q->whereIn('assigned_shop_id', $shopIds)
                        ->orWhereHas('shopCards', function ($q2) use ($shopIds) {
                            $q2->whereIn('shop_id', $shopIds);
                        });
                  });
        } elseif ($user->isNetworkOwner()) {
            $networkIds = $user->ownedNetworks()->pluck('id');
            $query->whereIn('network_id', $networkIds);
        }
        // admin sees all

        // Filters
        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('shop_id'))     $query->where('assigned_shop_id', $request->shop_id);
        if ($request->filled('network_id'))  $query->where('network_id', $request->network_id);
        if ($request->filled('package_id'))  $query->where('package_id', $request->package_id);
        if ($request->filled('category'))    $query->where('category', $request->category);
        if ($request->filled('assignment')) {
            $assignment = $request->assignment;
            if ($assignment === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('assigned_shop_id')
                      ->whereDoesntHave('shopCards');
                });
            } elseif ($assignment === 'assigned') {
                $query->where(function ($q) {
                    $q->whereNotNull('assigned_shop_id')
                      ->orWhereHas('shopCards');
                });
            }
        }

        return response()->json($query->latest()->paginate(50));
    }

    // Batch create cards
    public function store(Request $request)
    {
        $request->validate([
            'network_id'       => 'required|exists:networks,id',
            'assigned_shop_id' => 'nullable|exists:shops,id',
            'category'         => 'required|numeric',
            'data_amount'      => 'required|string',
            'duration'         => 'required|string',
            'price'            => 'required|numeric|min:0',
            'quantity'         => 'required|integer|min:1|max:500',
        ]);

        $user    = $request->user();
        $network = Network::findOrFail($request->network_id);

        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $cards = [];
        for ($i = 0; $i < $request->quantity; $i++) {
            $cards[] = Card::create([
                'network_id'       => $request->network_id,
                'assigned_shop_id' => $request->assigned_shop_id,
                'serial_number'    => strtoupper(Str::random(4) . '-' . Str::random(4) . '-' . Str::random(4)),
                'category'         => $request->category,
                'data_amount'      => $request->data_amount,
                'duration'         => $request->duration,
                'price'            => $request->price,
                'status'           => 'available',
            ]);
        }

        return response()->json([
            'message' => "تم إنشاء {$request->quantity} كرت بنجاح.",
            'cards'   => $cards,
        ], 201);
    }

    public function show(Request $request, Card $card)
    {
        $this->authorizeCard($request, $card);

        return response()->json($card->load('network', 'assignedShop', 'sale'));
    }

    // Assign card(s) to shop
    public function assignToShop(Request $request)
    {
        $request->validate([
            'card_ids' => 'required|array',
            'card_ids.*' => 'exists:cards,id',
            'shop_id'  => 'required|exists:shops,id',
        ]);

        $user = $request->user();
        $shop = Shop::findOrFail($request->shop_id);

        if (! $user->isAdmin()) {
            $networkIds = $user->isNetworkOwner()
                ? $user->ownedNetworks()->pluck('id')
                : $user->networks()->pluck('id');
            if (! $networkIds->contains($shop->network_id)) {
                return response()->json(['message' => 'غير مصرح.'], 403);
            }
        }

        Card::whereIn('id', $request->card_ids)
            ->where('status', 'available')
            ->update(['assigned_shop_id' => $request->shop_id]);

        return response()->json(['message' => 'تم تخصيص الكروت للبقالة بنجاح.']);
    }

    public function destroy(Request $request, Card $card)
    {
        $this->authorizeCard($request, $card);

        if ($request->user()->isShop()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        if ($card->status !== 'available') {
            return response()->json(['message' => 'لا يمكن حذف كرت غير متاح.'], 422);
        }

        $action = $request->input('mikrotik_action', 'remove'); // remove | disable
        if (!in_array($action, ['remove', 'disable'], true)) {
            return response()->json(['message' => 'قيمة mikrotik_action غير صحيحة.'], 422);
        }
        if ($card->code && $card->network) {
            $ok = $action === 'disable'
                ? $this->mikroTikService->disableUser($card->network, $card->code)
                : $this->mikroTikService->removeUser($card->network, $card->code);

            if (!$ok) {
                return response()->json(['message' => 'تعذر إزالة المستخدم من MikroTik.'], 422);
            }
        }

        $card->delete();

        return response()->json(['message' => 'تم حذف الكرت بنجاح.']);
    }

    private function authorizeCard(Request $request, Card $card): void
    {
        $user = $request->user();
        if ($user->isAdmin()) return;

        if ($user->isNetworkOwner()) {
            $networkIds = $user->ownedNetworks()->pluck('id');
            if (! $networkIds->contains($card->network_id)) abort(403);
            return;
        }

        if ($user->isShop()) {
            $shop = $user->shops()->first();
            if (! $shop || $card->assigned_shop_id !== $shop->id) abort(403);
        }
    }
}
