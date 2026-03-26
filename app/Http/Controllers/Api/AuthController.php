<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Professional Login for Shop Owners & Admins
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // Support login by username or email
        $user = User::where('username', $request->username)
                    ->orWhere('email', $request->username)
                    ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['بيانات الاعتماد غير صحيحة.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user'  => $this->withPlan($user),
            'token' => $token,
        ]);
    }

    /**
     * Professional Registration for Shop Owners
     */
    public function register(Request $request)
    {
        $request->validate([
            'shop_name' => 'required|string|max:255',
            'phone'     => 'required|string|max:20',
            'username'  => 'required|string|max:255|unique:users',
            'password'  => 'required|string|min:6',
        ]);

        $user = User::create([
            'name'      => $request->shop_name, // Full name used as shop name
            'shop_name' => $request->shop_name,
            'phone'     => $request->phone,
            'username'  => $request->username,
            'email'     => $request->username . '@qaisnet.local', // Dummy email if not provided
            'password'  => Hash::make($request->password),
            'role'      => 'shop',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'تم إنشاء الحساب بنجاح',
            'user'    => $this->withPlan($user),
            'token'   => $token,
        ], 201);
    }

    /**
     * Registration for Network Owners (Trial)
     */
    public function registerOwner(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users,username',
            'password' => 'required|string|min:6',
            'phone'    => 'nullable|string|max:20',
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
            'role'                => 'network_owner',
            'phone'               => $request->phone,
            'subscription_status' => 'trial',
            'subscription_ends_at'=> null,
            'subscription_type'   => 'trial',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'تم إنشاء حساب صاحب الشبكة (تجريبي) بنجاح',
            'user'    => $this->withPlan($user),
            'token'   => $token,
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('networks');
        return response()->json($this->withPlan($user));
    }

    private function withPlan(User $user): User
    {
        $plan = $user->planConfig();

        $user->setAttribute('plan', $user->planKey());
        $user->setAttribute('features', $plan['features'] ?? []);
        $user->setAttribute('limits', $plan['limits'] ?? []);

        return $user;
    }
}
