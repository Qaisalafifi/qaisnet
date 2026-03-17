<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardBatch;
use App\Models\CardTemplate;
use App\Models\Network;
use App\Models\Package;
use App\Models\Shop;
use App\Services\MikroTikService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CardGenerationController extends Controller
{
    protected $mikroTikService;

    public function __construct(MikroTikService $mikroTikService)
    {
        $this->mikroTikService = $mikroTikService;
    }

    /**
     * Generate cards for a network
     */
    public function generate(Request $request, Network $network)
    {
        // Generate cards can be long-running (MikroTik + DB)
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $user = $request->user();
        
        // Authorization
        if (!$user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        // Check subscription
        if (!$network->isSubscriptionActive()) {
            return response()->json([
                'message' => 'انتهت صلاحية الاشتراك. يرجى التجديد أولاً'
            ], 422);
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'card_length' => 'required|integer|min:6|max:20',
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^\d*$/'],
            'suffix' => ['nullable', 'string', 'max:10', 'regex:/^\d*$/'],
            'count' => 'required|integer|min:1|max:1000',
            'assign_to_shop_id' => 'nullable|exists:shops,id',
            'include_password' => 'sometimes|boolean',
            'password_mode' => 'sometimes|string|in:same,random',
            'password_length' => 'required_if:password_mode,random|integer|min:4|max:20',
        ]);

        $package = Package::findOrFail($request->package_id);

        // Verify package belongs to network
        if ($package->network_id !== $network->id) {
            return response()->json(['message' => 'الباقة لا تنتمي لهذه الشبكة'], 422);
        }

        // Fail fast if MikroTik is not reachable to avoid long timeouts
        if (!$this->mikroTikService->connect($network)) {
            $msg = $this->mikroTikService->getLastError() ?? 'تعذر الاتصال بالميكروتك';
            return response()->json([
                'message' => 'تعذر الاتصال بالميكروتك: ' . $msg,
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create batch record
            $batch = CardBatch::create([
                'network_id' => $network->id,
                'package_id' => $package->id,
                'count' => $request->count,
                'card_length' => $request->card_length,
                'prefix' => $request->prefix,
                'suffix' => $request->suffix,
                'created_by' => $user->id,
            ]);

            $generatedCards = [];
            $firstCode = null;
            $lastCode = null;
            $failedCount = 0;

            $includePassword = $request->boolean('include_password', false);
            $passwordMode = $includePassword ? ($request->input('password_mode') ?? 'same') : 'none';
            $passwordLength = (int) ($request->input('password_length') ?? $request->card_length);

            for ($i = 0; $i < $request->count; $i++) {
                $attempts = 0;
                $maxAttempts = 50;
                $code = null;
                $password = null;

                // Generate unique code
                while ($attempts < $maxAttempts) {
                    $code = $this->generateCode(
                        $request->card_length,
                        $request->prefix,
                        $request->suffix
                    );
                    if ($includePassword) {
                        if ($passwordMode === 'random') {
                            $password = $this->generateNumericString($passwordLength);
                            $attempt = 0;
                            while ($password === $code && $attempt < 3) {
                                $password = $this->generateNumericString($passwordLength);
                                $attempt++;
                            }
                        } else {
                            $password = $code;
                        }
                    } else {
                        $password = '';
                    }

                    // Check if code already exists
                    if (!Card::where('network_id', $network->id)
                        ->where('code', $code)
                        ->exists()) {
                        break;
                    }
                    $attempts++;
                }

                if ($attempts >= $maxAttempts) {
                    $failedCount++;
                    continue;
                }

                // Create user in MikroTik
                $mikroTikSuccess = $this->mikroTikService->createUserOnSession(
                    $network,
                    $code,
                    $password,
                    $package->mikrotik_profile_name,
                    $package->validity_days
                );

                if (!$mikroTikSuccess) {
                    $failedCount++;
                    Log::warning('Failed to create MikroTik user', [
                        'network_id' => $network->id,
                        'code' => $code
                    ]);
                    continue;
                }

                // Create card in database
                $card = Card::create([
                    'network_id' => $network->id,
                    'package_id' => $package->id,
                    'code' => $code,
                    'password' => $password,
                    'status' => 'available',
                    'generated_batch_id' => $batch->id,
                    'assigned_shop_id' => $request->assign_to_shop_id,
                ]);

                // Assign to shop if specified
                if ($request->assign_to_shop_id) {
                    \App\Models\ShopCard::create([
                        'shop_id' => $request->assign_to_shop_id,
                        'card_id' => $card->id,
                    ]);
                }

                $generatedCards[] = $card;

                if ($firstCode === null) {
                    $firstCode = $code;
                }
                $lastCode = $code;
            }

            // Update batch with first and last codes
            $batch->update([
                'first_code' => $firstCode,
                'last_code' => $lastCode,
            ]);

            // Log audit
            \App\Models\AuditLog::create([
                'user_id' => $user->id,
                'action' => 'cards_generated',
                'description' => "تم إنشاء {$batch->count} كرت للشبكة {$network->name}",
                'ip' => $request->ip(),
                'metadata' => [
                    'network_id' => $network->id,
                    'package_id' => $package->id,
                    'batch_id' => $batch->id,
                    'success_count' => count($generatedCards),
                    'failed_count' => $failedCount,
                ],
            ]);

            DB::commit();

            return response()->json([
                'message' => "تم إنشاء " . count($generatedCards) . " كرت بنجاح",
                'batch' => $batch->fresh(),
                'summary' => [
                    'total_requested' => $request->count,
                    'successful' => count($generatedCards),
                    'failed' => $failedCount,
                    'first_code' => $firstCode,
                    'last_code' => $lastCode,
                ],
                'cards' => collect($generatedCards)->map(function ($card) use ($includePassword) {
                    $item = [
                        'id' => $card->id,
                        'code' => $card->code,
                    ];
                    if ($includePassword) {
                        $item['password'] = $card->password;
                    }
                    return $item;
                }),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Card Generation Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء إنشاء الكروت: ' . $e->getMessage()
            ], 500);
        } finally {
            $this->mikroTikService->disconnect();
        }
    }

    /**
     * Generate card code
     */
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

    /**
     * Download cards as CSV
     */
    public function downloadCards(Request $request, CardBatch $batch)
    {
        $user = $request->user();
        
        if (!$user->isAdmin() && $batch->created_by !== $user->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $cards = $batch->cards()->get(['code', 'status']);

        $csv = "Code,Status\n";
        foreach ($cards as $card) {
            $csv .= "{$card->code},{$card->status}\n";
        }

        return response($csv)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="cards_' . $batch->id . '.csv"');
    }

    /**
     * Download cards as PDF using a template
     */
    public function printCards(Request $request, CardBatch $batch)
    {
        $user = $request->user();

        if (!$user->isAdmin() && $batch->network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'template_id' => 'required|exists:card_templates,id',
        ]);

        $template = CardTemplate::findOrFail($request->template_id);
        if ($template->network_id !== $batch->network_id) {
            return response()->json(['message' => 'القالب لا ينتمي لهذه الشبكة'], 422);
        }

        $batch->loadMissing(['network', 'package']);

        $cards = $batch->cards()
            ->select(['code', 'password'])
            ->orderBy('id')
            ->get();

        $marginMm = 10;
        $pageWidthMm = 210;
        $pageHeightMm = 297;
        $contentWidthMm = $pageWidthMm - ($marginMm * 2);
        $contentHeightMm = $pageHeightMm - ($marginMm * 2);
        if ($template->card_width_mm && $template->card_height_mm) {
            $cellWidthMm = (float) $template->card_width_mm;
            $cellHeightMm = (float) $template->card_height_mm;
            $columns = max(1, (int) floor($contentWidthMm / $cellWidthMm));
            $rows = max(1, (int) floor($contentHeightMm / $cellHeightMm));
            $cardsPerPage = max(1, $columns * $rows);
        } else {
            $cardsPerPage = max(1, (int) $template->cards_per_page);
            $columns = max(1, (int) $template->columns);
            $rows = (int) ceil($cardsPerPage / $columns);
            $cellWidthMm = $columns > 0 ? ($contentWidthMm / $columns) : 50;
            $cellHeightMm = $rows > 0 ? ($contentHeightMm / $rows) : 20;
        }

        $imageData = null;
        if ($template->image_path && Storage::disk('public')->exists($template->image_path)) {
            $bytes = Storage::disk('public')->get($template->image_path);
            $mime = Storage::disk('public')->mimeType($template->image_path) ?? 'image/png';
            $imageData = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        $pdf = Pdf::loadView('cards.print', [
            'template' => $template,
            'network' => $batch->network,
            'package' => $batch->package,
            'cards' => $cards,
            'cardsPerPage' => $cardsPerPage,
            'columns' => $columns,
            'rows' => $rows,
            'cellWidthMm' => $cellWidthMm,
            'cellHeightMm' => $cellHeightMm,
            'imageData' => $imageData,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('cards_batch_' . $batch->id . '.pdf');
    }
}
