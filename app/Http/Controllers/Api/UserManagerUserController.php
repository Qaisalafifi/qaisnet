<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\MikroTikService;
use Illuminate\Http\Request;

class UserManagerUserController extends Controller
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

        $users = $this->mikroTikService->getUserManagerUsers($network);
        if (empty($users)) {
            $error = $this->mikroTikService->getLastError();
            if ($error) {
                return response()->json([
                    'message' => $error,
                ], 422);
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = strtolower($search);
            $users = array_values(array_filter($users, function ($user) use ($needle) {
                foreach (['username', 'name', 'comment', 'customer'] as $key) {
                    $value = $user[$key] ?? null;
                    if ($value !== null && stripos((string) $value, $needle) !== false) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return response()->json([
            'count' => count($users),
            'users' => $users,
        ]);
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
}
