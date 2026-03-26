<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Network;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    public function dashboard()
    {
        return response()->json([
            'users'              => User::count(),
            'network_owners'     => User::where('role', 'network_owner')->count(),
            'shops'              => User::where('role', 'shop')->count(),
            'networks'           => Network::count(),
            'total_cards'        => Card::count(),
            'available_cards'    => Card::where('status', 'available')->count(),
            'total_revenue'      => Schema::hasColumn('sales', 'price')
                ? Sale::sum('price')
                : Sale::sum('sold_price'),
            'active_subs'        => User::where('subscription_status', 'active')->count(),
            'expired_subs'       => User::where('subscription_status', 'expired')->count(),
        ]);
    }

    public function users(Request $request)
    {
        $query = User::query();
        if ($request->filled('role')) $query->where('role', $request->role);
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('username', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        return response()->json($query->withCount('ownedNetworks')->latest()->paginate(20));
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'username' => 'required|string|unique:users,username',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:admin,network_owner,shop',
            'phone'    => 'nullable|string',
            'email'    => 'nullable|email|unique:users,email',
        ]);

        $email = $request->email;
        if (empty($email)) {
            $email = $request->username . '@qaisnet.local';
        }

        $user = User::create([
            'name'                => $request->name,
            'username'            => $request->username,
            'email'               => $email,
            'password'            => Hash::make($request->password),
            'role'                => $request->role,
            'phone'               => $request->phone,
            'subscription_status' => 'active',
            'subscription_ends_at'=> now()->addMonth(),
        ]);

        return response()->json(['message' => 'تم إنشاء المستخدم بنجاح', 'user' => $user], 201);
    }

    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name'                => 'sometimes|string|max:255',
            'password'            => 'nullable|string|min:6',
            'subscription_status' => 'sometimes|in:active,inactive,expired,trial',
            'subscription_type'   => 'nullable|string',
            'subscription_ends_at'=> 'nullable|date',
        ]);

        $data = $request->only('name', 'subscription_status', 'subscription_type', 'subscription_ends_at');
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);
        $this->syncNetworkSubscriptions($user, $request);

        return response()->json(['message' => 'تم تحديث البيانات بنجاح', 'user' => $user]);
    }

    private function syncNetworkSubscriptions(User $user, Request $request): void
    {
        if ($user->role !== 'network_owner') {
            return;
        }

        $updates = [];

        if ($request->filled('subscription_type')) {
            $updates['subscription_type'] = $request->subscription_type;
        }

        if ($request->filled('subscription_ends_at')) {
            $updates['subscription_end_at'] = $request->subscription_ends_at;
        }

        if ($request->filled('subscription_status')) {
            $updates['status'] = match ($request->subscription_status) {
                'active' => 'active',
                'expired' => 'expired',
                'trial' => 'active',
                default => 'suspended',
            };
        }

        if (!empty($updates)) {
            Network::where('owner_id', $user->id)->update($updates);
        }
    }

    public function deleteUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'لا يمكنك حذف حسابك الحالي'], 403);
        }
        $user->delete();
        return response()->json(['message' => 'تم حذف المستخدم بنجاح']);
    }
}
