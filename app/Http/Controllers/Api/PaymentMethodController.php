<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index(Request $request)
    {
        $query = PaymentMethod::query();
        if (!$request->user()->isAdmin()) {
            $query->where('is_active', true);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
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
            'type' => 'required|in:bank,wallet',
            'name' => 'required|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $method = PaymentMethod::create($request->only([
            'type', 'name', 'account_name', 'account_number', 'notes', 'is_active', 'sort_order',
        ]));

        return response()->json(['success' => true, 'data' => $method], 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $request->validate([
            'type' => 'sometimes|in:bank,wallet',
            'name' => 'sometimes|string|max:255',
            'account_name' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $paymentMethod->update($request->only([
            'type', 'name', 'account_name', 'account_number', 'notes', 'is_active', 'sort_order',
        ]));

        return response()->json(['success' => true, 'data' => $paymentMethod]);
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'غير مصرح.'], 403);
        }

        $paymentMethod->delete();
        return response()->json(['success' => true, 'message' => 'تم حذف وسيلة الدفع']);
    }
}
