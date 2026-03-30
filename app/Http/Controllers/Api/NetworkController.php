<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\MikroTikService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

class NetworkController extends Controller
{
    protected $mikroTikService;

    public function __construct(MikroTikService $mikroTikService)
    {
        $this->mikroTikService = $mikroTikService;
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $networkIds = Network::where('owner_id', $user->id)->pluck('id');

        return response()->json([
            'networks_count' => $networkIds->count(),
            'shops_count'    => \DB::table('network_shop')->whereIn('network_id', $networkIds)->distinct('user_id')->count(),
            'available_cards'=> \App\Models\Card::whereIn('network_id', $networkIds)->where('status', 'available')->count(),
            'total_sales'    => \App\Models\Sale::whereIn('network_id', $networkIds)->count(),
            'total_revenue'  => Schema::hasColumn('sales', 'price')
                ? \App\Models\Sale::whereIn('network_id', $networkIds)->sum('price')
                : \App\Models\Sale::whereIn('network_id', $networkIds)->sum('sold_price'),
        ]);
    }

    /**
     * List networks for current user
     * If shop: list linked networks
     * If owner: list owned networks
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $networks = Network::with('owner')->withCount('cards')->get();
        } elseif ($user->isShop()) {
            $networks = $user->networks()->with('owner')->withCount('cards')->get();
        } else {
            $networks = Network::where('owner_id', $user->id)
                               ->withCount('cards')
                               ->get();
        }

        return response()->json($networks);
    }

    /**
     * Link a network to a shop via code
     */
    public function link(Request $request)
    {
        $request->validate([
            'linking_code' => 'required|string',
        ]);

        $network = Network::where('linking_code', $request->linking_code)->first();

        if (!$network) {
            return response()->json(['message' => 'رمز الربط غير صحيح.'], 404);
        }

        $user = $request->user();

        // Check if already linked
        if ($user->networks()->where('network_id', $network->id)->exists()) {
            return response()->json(['message' => 'هذه الشبكة مرتبطة بالفعل بحسابك.'], 422);
        }

        $user->networks()->attach($network->id);

        return response()->json([
            'message' => 'تم ربط الشبكة بنجاح.',
            'network' => $network
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        if ($user->isNetworkOwner()) {
            if (! $user->hasFeature('add_network')) {
                return response()->json(['message' => 'خطة التجربة لا تسمح بإضافة شبكة.'], 403);
            }

            $maxNetworks = $user->planLimit('networks_max', null);
            if (is_numeric($maxNetworks)) {
                $count = Network::where('owner_id', $user->id)->count();
                if ($count >= (int) $maxNetworks) {
                    return response()->json(['message' => 'تم الوصول للحد الأقصى من الشبكات في خطتك.'], 422);
                }
            }
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'ip_address' => ['required', 'string', 'max:255', $this->ipOrHostRule()],
            'api_port' => 'nullable|integer|min:1|max:65535',
            'mikrotik_user' => 'required|string',
            'mikrotik_password' => 'required|string',
            'mikrotik_mode' => 'nullable|in:hotspot,user_manager,auto',
            'user_manager_customer' => 'nullable|string|max:255',
            'subscription_type' => 'nullable|in:monthly,yearly',
            'subscription_start_at' => 'nullable|date',
            'subscription_end_at' => 'nullable|date|after:subscription_start_at',
        ]);

        $network = Network::create([
            'name'                 => $request->name,
            'owner_id'             => $user->id,
            'linking_code'         => Str::upper(Str::random(8)),
            'ip_address'           => $request->ip_address,
            'api_port'             => $request->api_port ?? 8728,
            'mikrotik_user'        => $request->mikrotik_user,
            'mikrotik_password'    => encrypt($request->mikrotik_password), // Encrypt password
            'mikrotik_mode'        => $request->input('mikrotik_mode', 'hotspot'),
            'user_manager_customer' => $request->input('user_manager_customer'),
            'subscription_type'    => $request->subscription_type,
            'subscription_start_at' => $request->subscription_start_at,
            'subscription_end_at'   => $request->subscription_end_at,
            'status'               => 'active',
        ]);

        return response()->json($network, 201);
    }

    /**
     * Update network
     */
    public function update(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'ip_address' => ['sometimes', 'string', 'max:255', $this->ipOrHostRule()],
            'api_port' => 'sometimes|integer|min:1|max:65535',
            'mikrotik_user' => 'sometimes|string',
            'mikrotik_password' => 'sometimes|string',
            'mikrotik_mode' => 'sometimes|in:hotspot,user_manager,auto',
            'user_manager_customer' => 'sometimes|nullable|string|max:255',
            'subscription_type' => 'sometimes|in:monthly,yearly',
            'subscription_start_at' => 'sometimes|date',
            'subscription_end_at' => 'sometimes|date|after:subscription_start_at',
            'status' => 'sometimes|in:active,expired,suspended',
        ]);

        $data = $request->only([
            'name', 'ip_address', 'api_port', 'mikrotik_user',
            'mikrotik_mode', 'user_manager_customer',
            'subscription_type', 'subscription_start_at', 'subscription_end_at', 'status'
        ]);

        if ($request->filled('mikrotik_password')) {
            $data['mikrotik_password'] = encrypt($request->mikrotik_password);
        }

        $network->update($data);

        return response()->json($network);
    }

    /**
     * Delete network
     */
    public function destroy(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        $network->delete();

        return response()->json(['message' => 'تم حذف الشبكة بنجاح']);
    }

    /**
     * Test MikroTik connection
     */
    public function testConnection(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        $result = $this->mikroTikService->testConnection($network);

        return response()->json($result);
    }

    /**
     * Active sessions on hotspot (owner only)
     */
    public function activeSessions(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('active_connections')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بعرض المتصلين حالياً.'], 403);
        }

        $sessions = $this->mikroTikService->getActiveHotspotSessions($network);

        return response()->json([
            'count' => count($sessions),
            'sessions' => $sessions,
        ]);
    }

    /**
     * Connected devices (ARP table) (owner only)
     */
    public function connectedDevices(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('connected_devices')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بعرض الأجهزة المتصلة.'], 403);
        }

        $devices = $this->mikroTikService->getConnectedDevices($network);

        return response()->json([
            'count' => count($devices),
            'devices' => $devices,
        ]);
    }

    /**
     * Hotspot hosts (connected) (owner only)
     */
    public function hotspotHosts(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('connected_devices')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بعرض المتصلين حالياً.'], 403);
        }

        $hosts = $this->mikroTikService->getHotspotHosts($network);

        return response()->json([
            'count' => count($hosts),
            'hosts' => $hosts,
        ]);
    }

    /**
     * Neighbor devices (owner only)
     */
    public function neighbors(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('connected_devices')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بعرض الأجهزة المتصلة.'], 403);
        }

        $neighbors = $this->mikroTikService->getNeighbors($network);

        return response()->json([
            'count' => count($neighbors),
            'devices' => $neighbors,
        ]);
    }

    /**
     * Clear active sessions (owner only)
     */
    public function clearActiveSessions(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('active_connections')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بحذف النشطين.'], 403);
        }

        $result = $this->mikroTikService->clearActiveSessions($network);
        if (!($result['success'] ?? false)) {
            return response()->json(['message' => $result['message'] ?? 'فشل حذف النشطين'], 422);
        }

        return response()->json([
            'success' => true,
            'removed' => $result['count'] ?? 0,
        ]);
    }

    /**
     * Clear hotspot hosts (owner only)
     */
    public function clearHotspotHosts(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('connected_devices')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بحذف المتصلين.'], 403);
        }

        $result = $this->mikroTikService->clearHotspotHosts($network);
        if (!($result['success'] ?? false)) {
            return response()->json(['message' => $result['message'] ?? 'فشل حذف المتصلين'], 422);
        }

        return response()->json([
            'success' => true,
            'removed' => $result['count'] ?? 0,
        ]);
    }

    /**
     * Port/interface stats (paid only)
     */
    public function portStats(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if ($user->isNetworkOwner() && ! $user->hasFeature('port_stats')) {
            return response()->json(['message' => 'هذه الميزة متاحة فقط للمشترك.'], 403);
        }

        $ports = $this->mikroTikService->getPortStats($network);

        return response()->json([
            'count' => count($ports),
            'ports' => $ports,
        ]);
    }

    public function show(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);
        
        // If owner or admin, also show linking code and stats
        if ($request->user()->isAdmin() || $network->owner_id === $request->user()->id) {
            $network->loadCount(['cards as available_cards' => function($q) {
                $q->where('status', 'available');
            }, 'cards as sold_cards' => function($q) {
                $q->where('status', 'sold');
            }]);
        }

        return response()->json($network->load('owner'));
    }

    /**
     * List shops linked to this network
     */
    public function linkedShops(Request $request, Network $network)
    {
        if ($network->owner_id !== $request->user()->id && !$request->user()->isAdmin()) {
            abort(403);
        }

        $shops = $network->users()
            ->where('role', 'shop')
            ->get()
            ->map(function($shop) use ($network) {
                // Simplified stats for the list
                $shop->total_sales = \App\Models\Sale::where('network_id', $network->id)
                    ->where('user_id', $shop->id)
                    ->count();
                return $shop;
            });

        return response()->json($shops);
    }

    /**
     * Add cards to a network (Batch upload)
     */
    public function addCards(Request $request, Network $network)
    {
        if ($network->owner_id !== $request->user()->id && !$request->user()->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'cards' => 'required|array|min:1',
            'cards.*.serial_number' => 'required|string|distinct',
            'cards.*.category' => 'required|numeric',
            'cards.*.data_amount' => 'required|string',
            'cards.*.duration' => 'required|string',
            'cards.*.price' => 'required|numeric',
        ]);

        $added = 0;
        foreach ($request->cards as $cardData) {
            \App\Models\Card::create(array_merge($cardData, [
                'network_id' => $network->id,
                'status' => 'available'
            ]));
            $added++;
        }

        return response()->json(['message' => "تم إضافة $added كرت بنجاح."]);
    }

    private function authorizeNetwork(Request $request, Network $network): void
    {
        $user = $request->user();
        if ($user->isShop()) {
            if (!$user->networks()->where('network_id', $network->id)->exists()) {
                abort(403, 'غير مصرح.');
            }
        } elseif (!$user->isAdmin() && $network->owner_id !== $user->id) {
            abort(403, 'غير مصرح.');
        }
    }

    private function ipOrHostRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return;
            }

            $pattern = '/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)'
                . '(?:\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/';

            if (!is_string($value) || !preg_match($pattern, $value)) {
                $fail('الرجاء إدخال IP صحيح أو DNS صالح.');
            }
        };
    }
}
