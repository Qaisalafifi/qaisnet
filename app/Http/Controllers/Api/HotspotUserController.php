<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\Package;
use App\Services\MikroTikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HotspotUserController extends Controller
{
    protected $mikroTikService;

    public function __construct(MikroTikService $mikroTikService)
    {
        $this->mikroTikService = $mikroTikService;
    }

    public function index(Request $request, Network $network)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $users = $this->mikroTikService->getHotspotUsers($network);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = strtolower($search);
            $users = array_values(array_filter($users, function ($user) use ($needle) {
                foreach (['name', 'comment', 'profile', 'server'] as $key) {
                    $value = $user[$key] ?? null;
                    if ($value !== null && strtolower((string) $value) !== '' &&
                        stripos((string) $value, $needle) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }

        $packages = Package::where('network_id', $network->id)->get();
        $packageMap = [];
        foreach ($packages as $package) {
            $packageMap[$package->mikrotik_profile_name] = $package;
        }

        foreach ($users as &$user) {
            $profile = $user['profile'] ?? $user['server_profile'] ?? null;
            if ($profile && isset($packageMap[$profile])) {
                $pkg = $packageMap[$profile];
                $user['package'] = [
                    'id' => $pkg->id,
                    'name' => $pkg->name,
                    'price' => $pkg->retail_price ?? $pkg->price,
                    'retail_price' => $pkg->retail_price,
                    'wholesale_price' => $pkg->wholesale_price,
                    'data_limit' => $pkg->data_limit,
                    'validity_days' => $pkg->validity_days,
                ];
            }

            $disabled = $this->isTruthy($user['disabled'] ?? null);
            $user['status'] = $disabled ? 'disabled' : 'active';
        }

        return response()->json([
            'count' => count($users),
            'users' => $users,
        ]);
    }

    public function store(Request $request, Network $network)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        // Allow long running operations for batch creation
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $user = $request->user();
        $maxCount = 1000;
        if ($user->isNetworkOwner()) {
            $limit = $user->planLimit('card_generation_max', 1000);
            if (is_numeric($limit)) {
                $maxCount = (int) $limit;
            }
        }

        $count = (int) $request->input('count', 0);
        $isBatch = $count > 1;

        if ($isBatch) {
            $request->validate([
                'count' => 'required|integer|min:2|max:' . $maxCount,
                'card_length' => 'required|integer|min:4|max:20',
                'prefix' => ['nullable', 'string', 'max:10', 'regex:/^\\d*$/'],
                'suffix' => ['nullable', 'string', 'max:10', 'regex:/^\\d*$/'],
                'password_mode' => 'nullable|string|in:none,same,random',
                'password_length' => 'required_if:password_mode,random|integer|min:4|max:20',
                'profile' => 'required|string',
                'server' => 'nullable|string',
                'limit_uptime' => 'nullable|string',
                'limit_bytes_total' => 'nullable|integer|min:0',
                'shared_users' => 'nullable|integer|min:0',
                'comment' => 'nullable|string|max:255',
            ]);

            $existingNames = [];
            foreach ($this->mikroTikService->getHotspotUsers($network) as $row) {
                $name = $row['name'] ?? null;
                if ($name) {
                    $existingNames[$name] = true;
                }
            }

            if (!$this->mikroTikService->connect($network)) {
                $msg = $this->mikroTikService->getLastError() ?? 'تعذر الاتصال بالميكروتك';
                return response()->json(['message' => 'تعذر الاتصال بالميكروتك: ' . $msg], 422);
            }

            $generatedUsers = [];
            $firstCode = null;
            $lastCode = null;
            $failedCount = 0;

            $passwordMode = $request->input('password_mode', 'none');
            $passwordLength = (int) ($request->input('password_length') ?? $request->card_length);

            try {
                for ($i = 0; $i < $request->count; $i++) {
                    $attempts = 0;
                    $maxAttempts = 50;
                    $username = null;
                    $password = '';

                    while ($attempts < $maxAttempts) {
                        $username = $this->generateCode(
                            $request->card_length,
                            $request->prefix,
                            $request->suffix
                        );

                        if ($username && isset($existingNames[$username])) {
                            $attempts++;
                            continue;
                        }

                        if ($passwordMode === 'random') {
                            $password = $this->generateNumericString($passwordLength);
                            $attempt = 0;
                            while ($password === $username && $attempt < 3) {
                                $password = $this->generateNumericString($passwordLength);
                                $attempt++;
                            }
                        } elseif ($passwordMode === 'same') {
                            $password = $username;
                        } else {
                            $password = '';
                        }
                        break;
                    }

                    if (!$username || $attempts >= $maxAttempts) {
                        $failedCount++;
                        continue;
                    }

                    try {
                        $payload = [
                            'name' => $username,
                            'password' => $password,
                            'profile' => $request->profile,
                            'server' => $request->input('server'),
                            'limit_uptime' => $request->input('limit_uptime'),
                            'limit_bytes_total' => $request->input('limit_bytes_total'),
                            'shared_users' => $request->input('shared_users'),
                            'comment' => $request->input('comment'),
                        ];

                        $this->mikroTikService->createHotspotUserOnSession($payload);

                        $existingNames[$username] = true;
                        $generatedUsers[] = [
                            'username' => $username,
                            'password' => $password,
                        ];

                        if ($firstCode === null) {
                            $firstCode = $username;
                        }
                        $lastCode = $username;
                    } catch (\Exception $e) {
                        $failedCount++;
                        Log::warning('Failed to create hotspot user', [
                            'network_id' => $network->id,
                            'username' => $username,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } finally {
                $this->mikroTikService->disconnect();
            }

            return response()->json([
                'message' => 'تم إنشاء ' . count($generatedUsers) . ' مستخدم بنجاح',
                'summary' => [
                    'total_requested' => $request->count,
                    'successful' => count($generatedUsers),
                    'failed' => $failedCount,
                    'first_code' => $firstCode,
                    'last_code' => $lastCode,
                ],
                'users' => $generatedUsers,
            ], 201);
        }

        $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'nullable|string|max:64',
            'profile' => 'required|string',
            'server' => 'nullable|string',
            'limit_uptime' => 'nullable|string',
            'limit_bytes_total' => 'nullable|integer|min:0',
            'shared_users' => 'nullable|integer|min:0',
            'comment' => 'nullable|string|max:255',
            'lock_to_one_device' => 'nullable|boolean',
        ]);

        $sharedUsers = $request->input('shared_users');
        if ($request->boolean('lock_to_one_device')) {
            $sharedUsers = 1;
        }

        $data = [
            'name' => $request->username,
            'password' => $request->input('password', ''),
            'profile' => $request->profile,
            'server' => $request->input('server'),
            'limit_uptime' => $request->input('limit_uptime'),
            'limit_bytes_total' => $request->input('limit_bytes_total'),
            'shared_users' => $sharedUsers,
            'comment' => $request->input('comment'),
        ];

        $success = $this->mikroTikService->createHotspotUserWithParams($network, $data);

        if (!$success) {
            $error = $this->mikroTikService->getLastError();
            return response()->json([
                'message' => $error ? ('فشل إضافة المستخدم: ' . $error) : 'فشل إضافة المستخدم',
            ], 422);
        }

        return response()->json([
            'message' => 'تم إضافة المستخدم بنجاح',
        ], 201);
    }

    public function update(Request $request, Network $network, string $userId)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $request->validate([
            'name' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:64',
            'profile' => 'nullable|string',
            'server' => 'nullable|string',
            'limit_uptime' => 'nullable|string',
            'limit_bytes_total' => 'nullable|integer|min:0',
            'shared_users' => 'nullable|integer|min:0',
            'comment' => 'nullable|string|max:255',
            'disabled' => 'nullable|boolean',
        ]);

        $payload = $request->only([
            'name',
            'password',
            'profile',
            'server',
            'limit_uptime',
            'limit_bytes_total',
            'shared_users',
            'comment',
            'disabled',
        ]);

        $success = $this->mikroTikService->updateHotspotUser($network, $userId, $payload);

        if (!$success) {
            return response()->json(['message' => 'فشل تحديث المستخدم'], 422);
        }

        return response()->json(['message' => 'تم تحديث المستخدم بنجاح']);
    }

    public function destroy(Request $request, Network $network, string $userId)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $success = $this->mikroTikService->removeHotspotUserById($network, $userId);
        if (!$success) {
            return response()->json(['message' => 'فشل حذف المستخدم'], 422);
        }

        return response()->json(['message' => 'تم حذف المستخدم']);
    }

    public function resetCounters(Request $request, Network $network, string $userId)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $success = $this->mikroTikService->resetHotspotUserCounters($network, $userId);
        if (!$success) {
            return response()->json(['message' => 'فشل تصفير العدادات'], 422);
        }

        return response()->json(['message' => 'تم تصفير العدادات']);
    }

    public function disable(Request $request, Network $network, string $userId)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $success = $this->mikroTikService->disableHotspotUserById($network, $userId);
        if (!$success) {
            return response()->json(['message' => 'فشل تعطيل المستخدم'], 422);
        }

        return response()->json(['message' => 'تم تعطيل المستخدم']);
    }

    public function enable(Request $request, Network $network, string $userId)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $success = $this->mikroTikService->enableHotspotUserById($network, $userId);
        if (!$success) {
            return response()->json(['message' => 'فشل تفعيل المستخدم'], 422);
        }

        return response()->json(['message' => 'تم تفعيل المستخدم']);
    }

    public function setSharedUsers(Request $request, Network $network, string $userId)
    {
        $this->authorizeOwner($request, $network);
        if ($response = $this->ensureSubscription($request, $network)) {
            return $response;
        }

        $request->validate([
            'shared_users' => 'required|integer|min:0',
        ]);

        $payload = [
            'shared_users' => $request->shared_users,
        ];
        if ((int) $request->shared_users === 0) {
            $payload['mac_address'] = '';
        }

        $success = $this->mikroTikService->updateHotspotUser($network, $userId, $payload);

        if (!$success) {
            return response()->json(['message' => 'فشل تحديث القفل'], 422);
        }

        return response()->json(['message' => 'تم تحديث القفل']);
    }

    private function authorizeOwner(Request $request, Network $network): void
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            abort(403, 'غير مصرح.');
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            abort(403, 'غير مصرح.');
        }
    }

    private function ensureSubscription(Request $request, Network $network)
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return null;
        }
        if (!$user->isTrial() && !$network->isSubscriptionActive()) {
            return response()->json([
                'message' => 'انتهت صلاحية الاشتراك. يرجى التجديد أولاً'
            ], 422);
        }
        return null;
    }

    private function isTruthy($value): bool
    {
        if ($value === null) return false;
        if (is_bool($value)) return $value;
        $raw = strtolower((string) $value);
        return in_array($raw, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function generateCode(int $length, ?string $prefix = null, ?string $suffix = null): string
    {
        $prefix = $prefix ?? '';
        $suffix = $suffix ?? '';
        $middleLength = $length - strlen($prefix) - strlen($suffix);

        if ($middleLength < 1) {
            $middleLength = 1;
        }

        $middle = $this->generateNumericString($middleLength);
        return $prefix . $middle . $suffix;
    }

    private function generateNumericString(int $length): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }
        return $digits;
    }
}
