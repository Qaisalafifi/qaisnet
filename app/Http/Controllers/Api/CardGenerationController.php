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

        // Check subscription (trial is always allowed)
        if (!$user->isAdmin()) {
            if (! $user->isTrial() && ! $network->isSubscriptionActive()) {
                return response()->json([
                    'message' => 'انتهت صلاحية الاشتراك. يرجى التجديد أولاً'
                ], 422);
            }
        }

        $maxCount = 1000;
        if ($user->isNetworkOwner()) {
            $limit = $user->planLimit('card_generation_max', 1000);
            if (is_numeric($limit)) {
                $maxCount = (int) $limit;
            }
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'card_length' => 'required|integer|min:6|max:20',
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^\d*$/'],
            'suffix' => ['nullable', 'string', 'max:10', 'regex:/^\d*$/'],
            'count' => 'required|integer|min:1|max:' . $maxCount,
            'assign_to_shop_id' => 'nullable|exists:shops,id',
            'include_password' => 'sometimes|boolean',
            'password_mode' => 'sometimes|string|in:same,random',
            'password_length' => 'required_if:password_mode,random|integer|min:4|max:20',
            'mikrotik_mode' => 'nullable|in:hotspot,user_manager,auto',
        ]);

        if ($request->filled('assign_to_shop_id') && $user->isNetworkOwner() && ! $user->hasFeature('assign_cards')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بتخصيص الكروت للبقالة.'], 403);
        }

        $package = Package::findOrFail($request->package_id);
        $mikrotikMode = $request->input('mikrotik_mode');
        $modeExplicit = $request->filled('mikrotik_mode');

        // Verify package belongs to network
        if ($package->network_id !== $network->id) {
            return response()->json(['message' => 'الباقة لا تنتمي لهذه الشبكة'], 422);
        }

        // Sync packages from MikroTik to ensure profiles are up to date
        $syncMode = $mikrotikMode ?: ($package->mikrotik_mode ?: null);
        $profiles = $this->mikroTikService->getProfiles($network, $syncMode);
        if (empty($profiles) && !$modeExplicit) {
            $syncMode = 'auto';
            $profiles = $this->mikroTikService->getProfiles($network, $syncMode);
        }

        if (empty($profiles)) {
            $error = $this->mikroTikService->getLastError();
            $message = $error ? ('تعذر جلب البروفايلات من الميكروتك: ' . $error) : 'لم يتم العثور على بروفايلات في MikroTik';
            return response()->json(['message' => $message], 422);
        }

        $this->syncPackagesFromProfiles($network, $profiles, $syncMode);
        $package->refresh();

        if (!$modeExplicit && !empty($package->mikrotik_mode)) {
            $mikrotikMode = $package->mikrotik_mode;
        }

        if ($modeExplicit && !empty($package->mikrotik_mode) && $package->mikrotik_mode !== $mikrotikMode) {
            return response()->json([
                'message' => 'الباقة لا تطابق نظام الكروت المختار.'
            ], 422);
        }

        $profileNames = array_filter(array_map(function ($profile) {
            return $profile['name'] ?? null;
        }, $profiles));
        if (!in_array($package->mikrotik_profile_name, $profileNames, true)) {
            return response()->json([
                'message' => 'بروفايل الباقة غير موجود في الميكروتك. تم تحديث الباقات، يرجى اختيار باقة صحيحة.'
            ], 422);
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
                    $package->validity_days,
                    $mikrotikMode
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
     * Import cards generated on client (direct MikroTik) and store in DB
     */
    public function importFromClient(Request $request, Network $network)
    {
        $user = $request->user();

        if (!$user->isAdmin() && $network->owner_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if (!$user->isAdmin()) {
            if (! $user->isTrial() && ! $network->isSubscriptionActive()) {
                return response()->json([
                    'message' => 'انتهت صلاحية الاشتراك. يرجى التجديد أولاً'
                ], 422);
            }
        }

        $maxCount = 1000;
        if ($user->isNetworkOwner()) {
            $limit = $user->planLimit('card_generation_max', 1000);
            if (is_numeric($limit)) {
                $maxCount = (int) $limit;
            }
        }

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'cards' => 'required|array|min:1|max:' . $maxCount,
            'cards.*.code' => 'required|string|max:64',
            'cards.*.password' => 'nullable|string|max:64',
            'assign_to_shop_id' => 'nullable|exists:shops,id',
            'card_length' => 'nullable|integer|min:4|max:20',
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^\d*$/'],
            'suffix' => ['nullable', 'string', 'max:10', 'regex:/^\d*$/'],
        ]);

        if ($request->filled('assign_to_shop_id') && $user->isNetworkOwner() && ! $user->hasFeature('assign_cards')) {
            return response()->json(['message' => 'خطة التجربة لا تسمح بتخصيص الكروت للبقالة.'], 403);
        }

        $package = Package::findOrFail($request->package_id);
        if ($package->network_id !== $network->id) {
            return response()->json(['message' => 'الباقة لا تنتمي لهذه الشبكة'], 422);
        }

        $assignShopId = $request->input('assign_to_shop_id');
        if ($assignShopId) {
            $shop = Shop::findOrFail($assignShopId);
            if (!$user->isAdmin() && $shop->network_id !== $network->id) {
                return response()->json(['message' => 'غير مصرح'], 403);
            }
        }

        $cardsInput = $request->input('cards', []);
        $codes = array_values(array_unique(array_map(function ($item) {
            return trim((string) ($item['code'] ?? ''));
        }, $cardsInput)));

        $existing = Card::where('network_id', $network->id)
            ->whereIn('code', $codes)
            ->pluck('code')
            ->all();
        $existingSet = array_flip($existing);

        $firstCode = null;
        $lastCode = null;
        $stored = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            $batch = CardBatch::create([
                'network_id' => $network->id,
                'package_id' => $package->id,
                'count' => count($cardsInput),
                'card_length' => $request->input('card_length') ?: (isset($cardsInput[0]['code']) ? strlen((string) $cardsInput[0]['code']) : null),
                'prefix' => $request->input('prefix'),
                'suffix' => $request->input('suffix'),
                'created_by' => $user->id,
            ]);

            $createdCards = [];
            foreach ($cardsInput as $item) {
                $code = trim((string) ($item['code'] ?? ''));
                if ($code === '') {
                    $skipped++;
                    continue;
                }
                if (isset($existingSet[$code])) {
                    $skipped++;
                    continue;
                }

                $password = isset($item['password']) ? (string) $item['password'] : '';

                $card = Card::create([
                    'network_id' => $network->id,
                    'package_id' => $package->id,
                    'code' => $code,
                    'password' => $password,
                    'status' => 'available',
                    'generated_batch_id' => $batch->id,
                    'assigned_shop_id' => $assignShopId,
                ]);

                if ($assignShopId) {
                    \App\Models\ShopCard::create([
                        'shop_id' => $assignShopId,
                        'card_id' => $card->id,
                        'assigned_at' => now(),
                    ]);
                }

                $createdCards[] = $card;
                $stored++;
                if ($firstCode === null) {
                    $firstCode = $code;
                }
                $lastCode = $code;
            }

            $batch->update([
                'first_code' => $firstCode,
                'last_code' => $lastCode,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'تم حفظ الكروت بنجاح',
                'batch' => $batch->fresh(),
                'summary' => [
                    'total_requested' => count($cardsInput),
                    'successful' => $stored,
                    'skipped' => $skipped,
                    'first_code' => $firstCode,
                    'last_code' => $lastCode,
                ],
                'cards' => collect($createdCards)->map(function ($card) {
                    return [
                        'id' => $card->id,
                        'code' => $card->code,
                        'password' => $card->password,
                    ];
                }),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import Cards Error', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'حدث خطأ أثناء حفظ الكروت: ' . $e->getMessage()
            ], 500);
        }
    }

    private function syncPackagesFromProfiles(Network $network, array $profiles, ?string $mode): void
    {
        foreach ($profiles as $profile) {
            $profileName = $profile['name'] ?? null;
            if (!is_string($profileName) || trim($profileName) === '') {
                continue;
            }

            $resolvedMode = $this->resolveProfileMode($mode, $profile['source'] ?? null);

            $package = Package::where('network_id', $network->id)
                ->where('mikrotik_profile_name', $profileName)
                ->first();

            if ($package) {
                if ($package->mikrotik_mode !== $resolvedMode) {
                    $package->update(['mikrotik_mode' => $resolvedMode]);
                }
                continue;
            }

            Package::create([
                'network_id' => $network->id,
                'name' => $profileName,
                'price' => 0,
                'wholesale_price' => 0,
                'retail_price' => 0,
                'data_limit' => 'unlimited',
                'validity_days' => 30,
                'mikrotik_profile_name' => $profileName,
                'mikrotik_mode' => $resolvedMode,
                'status' => 'active',
            ]);
        }
    }

    private function resolveProfileMode(?string $requestedMode, ?string $source): string
    {
        $requestedMode = strtolower(trim((string) $requestedMode));
        if (in_array($requestedMode, ['hotspot', 'user_manager'], true)) {
            return $requestedMode;
        }

        $source = strtolower(trim((string) $source));
        if ($source === 'user-manager' || $source === 'user_manager') {
            return 'user_manager';
        }
        if ($source === 'hotspot') {
            return 'hotspot';
        }

        return 'hotspot';
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
