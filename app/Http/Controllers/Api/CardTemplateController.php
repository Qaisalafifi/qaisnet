<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardTemplate;
use App\Models\Network;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CardTemplateController extends Controller
{
    public function index(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        $templates = $network->cardTemplates()->latest()->get();

        $templates->each(function ($template) {
            if ($template->image_path) {
                $template->image_url = asset('storage-proxy/' . $template->image_path);
            }
        });

        return response()->json($templates);
    }

    public function store(Request $request, Network $network)
    {
        $this->authorizeNetwork($request, $network);

        $request->validate([
            'name' => 'required|string|max:255',
            'include_password' => 'required|boolean',
            'cards_per_page' => 'required|integer|min:1|max:100',
            'columns' => 'required|integer|min:1|max:10',
            'code_x_mm' => 'required|numeric|min:0|max:300',
            'code_y_mm' => 'required|numeric|min:0|max:300',
            'password_x_mm' => 'required_if:include_password,1|nullable|numeric|min:0|max:300',
            'password_y_mm' => 'required_if:include_password,1|nullable|numeric|min:0|max:300',
            'card_width_mm' => 'sometimes|numeric|min:1|max:300',
            'card_height_mm' => 'required_with:card_width_mm|numeric|min:1|max:300',
            'code_font_size' => 'sometimes|numeric|min:1|max:200',
            'password_font_size' => 'sometimes|numeric|min:1|max:200',
            'image' => 'required|image|max:5120',
        ]);

        $cardsPerPage = (int) $request->cards_per_page;
        $columns = (int) $request->columns;
        if ($columns > $cardsPerPage || $cardsPerPage % $columns !== 0) {
            return response()->json([
                'message' => 'عدد الكروت لكل صفحة يجب أن يكون قابلاً للقسمة على عدد الأعمدة.',
                'errors' => [
                    'cards_per_page' => ['تحقق من عدد الكروت والأعمدة.'],
                ],
            ], 422);
        }

        if ($request->filled('card_width_mm') && $request->filled('card_height_mm')) {
            $contentWidthMm = 210 - (10 * 2);
            $contentHeightMm = 297 - (10 * 2);
            if ($request->card_width_mm > $contentWidthMm || $request->card_height_mm > $contentHeightMm) {
                return response()->json([
                    'message' => 'حجم الكرت أكبر من مساحة الصفحة.',
                ], 422);
            }
        }

        $imagePath = $request->file('image')->store('card_templates', 'public');

        $template = CardTemplate::create([
            'network_id' => $network->id,
            'name' => $request->name,
            'image_path' => $imagePath,
            'include_password' => $request->include_password,
            'cards_per_page' => $cardsPerPage,
            'columns' => $columns,
            'code_x_mm' => $request->code_x_mm,
            'code_y_mm' => $request->code_y_mm,
            'password_x_mm' => $request->include_password ? $request->password_x_mm : null,
            'password_y_mm' => $request->include_password ? $request->password_y_mm : null,
            'card_width_mm' => $request->input('card_width_mm'),
            'card_height_mm' => $request->input('card_height_mm'),
            'code_font_size' => $request->input('code_font_size', 12),
            'password_font_size' => $request->input('password_font_size', 11),
            'created_by' => $request->user()->id,
        ]);

        $template->image_url = asset('storage-proxy/' . $template->image_path);

        return response()->json($template, 201);
    }

    public function update(Request $request, CardTemplate $template)
    {
        $this->authorizeTemplate($request, $template);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'include_password' => 'sometimes|boolean',
            'cards_per_page' => 'sometimes|integer|min:1|max:100',
            'columns' => 'sometimes|integer|min:1|max:10',
            'code_x_mm' => 'sometimes|numeric|min:0|max:300',
            'code_y_mm' => 'sometimes|numeric|min:0|max:300',
            'password_x_mm' => 'required_if:include_password,1|nullable|numeric|min:0|max:300',
            'password_y_mm' => 'required_if:include_password,1|nullable|numeric|min:0|max:300',
            'card_width_mm' => 'sometimes|numeric|min:1|max:300',
            'card_height_mm' => 'required_with:card_width_mm|numeric|min:1|max:300',
            'code_font_size' => 'sometimes|numeric|min:1|max:200',
            'password_font_size' => 'sometimes|numeric|min:1|max:200',
            'image' => 'sometimes|image|max:5120',
        ]);

        $cardsPerPage = (int) ($request->cards_per_page ?? $template->cards_per_page);
        $columns = (int) ($request->columns ?? $template->columns);
        if ($columns > $cardsPerPage || $cardsPerPage % $columns !== 0) {
            return response()->json([
                'message' => 'عدد الكروت لكل صفحة يجب أن يكون قابلاً للقسمة على عدد الأعمدة.',
                'errors' => [
                    'cards_per_page' => ['تحقق من عدد الكروت والأعمدة.'],
                ],
            ], 422);
        }

        if ($request->filled('card_width_mm') && $request->filled('card_height_mm')) {
            $contentWidthMm = 210 - (10 * 2);
            $contentHeightMm = 297 - (10 * 2);
            if ($request->card_width_mm > $contentWidthMm || $request->card_height_mm > $contentHeightMm) {
                return response()->json([
                    'message' => 'حجم الكرت أكبر من مساحة الصفحة.',
                ], 422);
            }
        }

        $data = $request->only([
            'name',
            'include_password',
            'cards_per_page',
            'columns',
            'code_x_mm',
            'code_y_mm',
            'password_x_mm',
            'password_y_mm',
            'card_width_mm',
            'card_height_mm',
            'code_font_size',
            'password_font_size',
        ]);

        if ($request->hasFile('image')) {
            if ($template->image_path) {
                Storage::disk('public')->delete($template->image_path);
            }
            $data['image_path'] = $request->file('image')->store('card_templates', 'public');
        }

        if (array_key_exists('include_password', $data) && $data['include_password'] == false) {
            $data['password_x_mm'] = null;
            $data['password_y_mm'] = null;
        }

        $template->update($data);

        if ($template->image_path) {
            $template->image_url = asset('storage-proxy/' . $template->image_path);
        }

        return response()->json($template);
    }

    public function destroy(Request $request, CardTemplate $template)
    {
        $this->authorizeTemplate($request, $template);

        $template->delete();

        return response()->json(['message' => 'تم حذف القالب بنجاح.']);
    }

    private function authorizeNetwork(Request $request, Network $network): void
    {
        $user = $request->user();
        if ($user->isAdmin()) return;
        if ($network->owner_id !== $user->id) {
            abort(403, 'غير مصرح.');
        }
    }

    private function authorizeTemplate(Request $request, CardTemplate $template): void
    {
        $user = $request->user();
        if ($user->isAdmin()) return;
        if ($template->network->owner_id !== $user->id) {
            abort(403, 'غير مصرح.');
        }
    }
}
