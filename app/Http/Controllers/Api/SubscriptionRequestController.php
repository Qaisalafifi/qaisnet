<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\SubscriptionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SubscriptionRequestController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        if ($user->subscription_status === 'active') {
            return response()->json(['message' => 'حسابك نشط بالفعل.'], 422);
        }

        $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        $existing = SubscriptionRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'لديك طلب اشتراك قيد المراجعة.',
                'data' => $existing,
            ], 409);
        }

        $subscriptionRequest = SubscriptionRequest::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'requested_plan' => 'paid',
            'message' => $request->message,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب الاشتراك بنجاح',
            'data' => $subscriptionRequest,
        ], 201);
    }

    public function myRequests(Request $request)
    {
        $user = $request->user();
        if (! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $requests = SubscriptionRequest::where('user_id', $user->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $query = SubscriptionRequest::with('user')->latest();
        if ($status) {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function approve(Request $request, SubscriptionRequest $subscriptionRequest)
    {
        if ($subscriptionRequest->status !== 'pending') {
            return response()->json(['message' => 'تمت معالجة الطلب مسبقاً.'], 422);
        }

        $request->validate([
            'admin_note' => 'nullable|string|max:1000',
            'subscription_ends_at' => 'nullable|date',
        ]);

        $admin = $request->user();
        $user = $subscriptionRequest->user;

        $endAt = $request->filled('subscription_ends_at')
            ? Carbon::parse($request->subscription_ends_at)
            : now()->addDays(30);

        $user->update([
            'subscription_status' => 'active',
            'subscription_type' => 'paid',
            'subscription_ends_at' => $endAt,
        ]);

        Network::where('owner_id', $user->id)->update([
            'status' => 'active',
            'subscription_end_at' => $endAt,
        ]);

        $subscriptionRequest->update([
            'status' => 'approved',
            'admin_note' => $request->admin_note,
            'handled_by' => $admin->id,
            'handled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الطلب',
            'data' => $subscriptionRequest->fresh(),
        ]);
    }

    public function reject(Request $request, SubscriptionRequest $subscriptionRequest)
    {
        if ($subscriptionRequest->status !== 'pending') {
            return response()->json(['message' => 'تمت معالجة الطلب مسبقاً.'], 422);
        }

        $request->validate([
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $admin = $request->user();
        $subscriptionRequest->update([
            'status' => 'rejected',
            'admin_note' => $request->admin_note,
            'handled_by' => $admin->id,
            'handled_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض الطلب',
            'data' => $subscriptionRequest->fresh(),
        ]);
    }
}
