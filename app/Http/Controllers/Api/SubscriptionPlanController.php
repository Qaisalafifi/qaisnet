<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{
    public function index(Request $request)
    {
        $query = SubscriptionPlan::query();
        if (!$request->user()->isAdmin()) {
            $query->where('is_active', true);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $request->validate([
            'code' => 'required|string|max:50|unique:subscription_plans,code',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'duration_days' => 'required|integer|min:1',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $plan = SubscriptionPlan::create($request->only([
            'code', 'name', 'price', 'currency', 'duration_days', 'is_active', 'sort_order',
        ]));

        return response()->json(['success' => true, 'data' => $plan], 201);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $request->validate([
            'code' => 'sometimes|string|max:50|unique:subscription_plans,code,' . $subscriptionPlan->id,
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'duration_days' => 'sometimes|integer|min:1',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $subscriptionPlan->update($request->only([
            'code', 'name', 'price', 'currency', 'duration_days', 'is_active', 'sort_order',
        ]));

        return response()->json(['success' => true, 'data' => $subscriptionPlan]);
    }

    public function destroy(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $subscriptionPlan->delete();
        return response()->json(['success' => true, 'message' => 'تم حذف الخطة']);
    }
}
