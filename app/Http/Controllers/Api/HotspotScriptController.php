<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Services\MikroTikService;
use Illuminate\Http\Request;

class HotspotScriptController extends Controller
{
    protected $mikroTikService;

    public function __construct(MikroTikService $mikroTikService)
    {
        $this->mikroTikService = $mikroTikService;
    }

    public function install(Request $request, Network $network)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }
        if (! $user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        if (! $user->isAdmin() && ! $user->isTrial() && ! $network->isSubscriptionActive()) {
            return response()->json([
                'message' => 'انتهت صلاحية الاشتراك. يرجى التجديد أولاً'
            ], 422);
        }

        $request->validate([
            'profile' => 'required|string',
            'reset_only' => 'nullable|boolean',
        ]);

        $result = $this->mikroTikService->installHotspotLoginScript(
            $network,
            $request->profile,
            $request->boolean('reset_only', false),
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'فشل تثبيت السكربت',
            ], 422);
        }

        return response()->json([
            'message' => $request->boolean('reset_only', false)
                ? 'تمت إزالة سكربت الهوتسبوت من البروفايل'
                : 'تم تثبيت سكربت الهوتسبوت بنجاح',
        ]);
    }
}
