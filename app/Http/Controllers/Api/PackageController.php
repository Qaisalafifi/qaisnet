<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\Package;
use App\Services\MikroTikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PackageController extends Controller
{
    protected $mikroTikService;

    public function __construct(MikroTikService $mikroTikService)
    {
        $this->mikroTikService = $mikroTikService;
    }

    /**
     * Get packages for a network
     */
    public function index(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        $packages = Package::where('network_id', $network->id)
            ->withCount(['cards as available_cards' => function($q) {
                $q->where('status', 'available');
            }])
            ->latest()
            ->get();

        return response()->json($packages);
    }

    /**
     * Create a new package
     */
    public function store(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        if (!$request->filled('retail_price') && $request->filled('price')) {
            $request->merge(['retail_price' => $request->price]);
        }
        if (!$request->filled('wholesale_price') && $request->filled('price')) {
            $request->merge(['wholesale_price' => $request->price]);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'retail_price' => 'required|numeric|min:0',
            'wholesale_price' => 'required|numeric|min:0',
            'data_limit' => 'required|string',
            'validity_days' => 'required|integer|min:1',
            'mikrotik_profile_name' => 'required|string',
        ]);

        $package = Package::create([
            'network_id' => $network->id,
            'name' => $request->name,
            'price' => $request->retail_price,
            'retail_price' => $request->retail_price,
            'wholesale_price' => $request->wholesale_price,
            'data_limit' => $request->data_limit,
            'validity_days' => $request->validity_days,
            'mikrotik_profile_name' => $request->mikrotik_profile_name,
            'status' => 'active',
        ]);

        return response()->json($package, 201);
    }

    /**
     * Update package
     */
    public function update(Request $request, Package $package)
    {
        $this->authorizePackage($request, $package);

        if ($request->filled('price') && !$request->filled('retail_price')) {
            $request->merge(['retail_price' => $request->price]);
        }
        if ($request->filled('price') && !$request->filled('wholesale_price')) {
            $request->merge(['wholesale_price' => $request->price]);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'retail_price' => 'sometimes|numeric|min:0',
            'wholesale_price' => 'sometimes|numeric|min:0',
            'data_limit' => 'sometimes|string',
            'validity_days' => 'sometimes|integer|min:1',
            'mikrotik_profile_name' => 'sometimes|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $data = $request->only([
            'name',
            'retail_price',
            'wholesale_price',
            'data_limit',
            'validity_days',
            'mikrotik_profile_name',
            'status',
        ]);
        if ($request->filled('retail_price')) {
            $data['price'] = $request->retail_price;
        }

        $package->update($data);

        return response()->json($package);
    }

    /**
     * Delete package
     */
    public function destroy(Request $request, Package $package)
    {
        $this->authorizePackage($request, $package);

        // Check if package has cards
        if ($package->cards()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الباقة لأنها تحتوي على كروت'
            ], 422);
        }

        $package->delete();

        return response()->json(['message' => 'تم حذف الباقة بنجاح']);
    }

    /**
     * Sync packages from MikroTik
     */
    public function syncFromMikroTik(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        try {
            $profiles = $this->mikroTikService->getProfiles($network);

            if (empty($profiles)) {
                return response()->json([
                    'message' => 'لم يتم العثور على بروفايلات في MikroTik',
                    'profiles' => []
                ]);
            }

            $synced = [];
            foreach ($profiles as $profile) {
                if (isset($profile['name'])) {
                    $package = Package::firstOrCreate(
                        [
                            'network_id' => $network->id,
                            'mikrotik_profile_name' => $profile['name'],
                        ],
                        [
                            'name' => $profile['name'],
                            'price' => 0,
                            'wholesale_price' => 0,
                            'retail_price' => 0,
                            'data_limit' => 'unlimited',
                            'validity_days' => 30,
                            'status' => 'active',
                        ]
                    );
                    $synced[] = $package;
                }
            }

            return response()->json([
                'message' => 'تم مزامنة الباقات بنجاح',
                'packages' => $synced,
                'profiles' => $profiles
            ]);
        } catch (\Exception $e) {
            Log::error('Sync Packages Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء المزامنة: ' . $e->getMessage()
            ], 500);
        }
    }

    private function authorizeNetwork(Request $request, Network $network): void
    {
        $user = $request->user();
        if ($user->isAdmin()) return;
        if ($user->isNetworkOwner() && $network->owner_id === $user->id) return;
        if ($user->isShop() && $user->networks()->where('network_id', $network->id)->exists()) return;
        abort(403, 'غير مصرح');
    }

    private function authorizePackage(Request $request, Package $package): void
    {
        $this->authorizeNetwork($request, $package->network);
    }
}
