<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Network;
use App\Models\PaymentMethod;
use App\Models\SubscriptionPlan;
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
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'receipt' => 'required|file|mimes:jpg,jpeg,png,pdf|max:8192',
        ]);

        $plan = SubscriptionPlan::where('id', $request->plan_id)->where('is_active', true)->first();
        if (!$plan) {
            return response()->json(['message' => 'خطة الاشتراك غير متاحة حالياً.'], 422);
        }

        $paymentMethod = PaymentMethod::where('id', $request->payment_method_id)
            ->where('is_active', true)
            ->first();
        if (!$paymentMethod) {
            return response()->json(['message' => 'طريقة الدفع غير متاحة حالياً.'], 422);
        }

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

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
            'requested_plan' => $plan->code,
            'plan_id' => $plan->id,
            'payment_method_id' => $paymentMethod->id,
            'receipt_path' => $receiptPath,
            'message' => $request->message,
        ]);

        $subscriptionRequest->load(['paymentMethod', 'plan']);
        $payload = $subscriptionRequest->toArray();
        $payload['receipt_url'] = $this->buildReceiptUrl($request, $receiptPath);

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال طلب الاشتراك بنجاح',
            'data' => $payload,
        ], 201);
    }

    public function myRequests(Request $request)
    {
        $user = $request->user();
        if (! $user->isNetworkOwner()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $requests = SubscriptionRequest::where('user_id', $user->id)
            ->with(['paymentMethod', 'plan'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests->map(function ($req) use ($request) {
                $data = $req->toArray();
                $data['receipt_url'] = $this->buildReceiptUrl($request, $req->receipt_path);
                return $data;
            }),
        ]);
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $query = SubscriptionRequest::with(['user', 'paymentMethod', 'plan'])->latest();
        if ($status) {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(function ($req) use ($request) {
                $data = $req->toArray();
                $data['receipt_url'] = $this->buildReceiptUrl($request, $req->receipt_path);
                return $data;
            }),
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
            'plan_id' => 'nullable|exists:subscription_plans,id',
        ]);

        $admin = $request->user();
        $user = $subscriptionRequest->user;

        $plan = null;
        if ($request->filled('plan_id')) {
            $plan = SubscriptionPlan::find($request->plan_id);
        } elseif ($subscriptionRequest->plan_id) {
            $plan = $subscriptionRequest->plan;
        }

        if ($request->filled('subscription_ends_at')) {
            $endAt = Carbon::parse($request->subscription_ends_at);
        } elseif ($plan) {
            $endAt = now()->addDays((int) ($plan->duration_days ?? 30));
        } else {
            $endAt = now()->addDays(30);
        }

        $user->update([
            'subscription_status' => 'active',
            'subscription_type' => $plan?->code ?? 'paid',
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
            'plan_id' => $plan?->id ?? $subscriptionRequest->plan_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت الموافقة على الطلب',
            'data' => $subscriptionRequest->fresh()->load(['paymentMethod', 'plan']),
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

    private function buildReceiptUrl(Request $request, ?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        $normalized = ltrim($path, '/');
        if (strpos($normalized, 'storage/') !== 0) {
            $normalized = 'storage/' . $normalized;
        }

        return $base . '/' . $normalized;
    }
}
