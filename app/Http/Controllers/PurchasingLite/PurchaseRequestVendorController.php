<?php

namespace App\Http\Controllers\PurchasingLite;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLog;
use App\Models\PurchaseRequestVendorOffer;
use App\Models\PurchaseRequestVendorOfferItem;
use App\Models\Vendor;
use App\Services\PurchasingLite\PurchasingLiteEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseRequestVendorController extends Controller
{
    public function index(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();
        $role = $this->normalizedUserRole($user);

        if (! in_array($role, ['purchasing', 'admin'], true)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only Purchasing can manage vendor offers.');
        }

        if ($this->normalizedStep($purchaseRequest->current_step) !== 'purchasing') {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'This purchase request is not with Purchasing.');
        }

        $purchaseRequest->load([
            'items',
            'logs.user',
            'vendorOffers.vendor',
            'vendorOffers.offerItems.purchaseRequestItem',
        ]);

        $savedBids = [];

        foreach ($purchaseRequest->vendorOffers as $vendorOffer) {
            foreach ($vendorOffer->offerItems as $offerItem) {
                $bidNumber = 1;

                if (preg_match('/Bid\s+([1-3])/i', (string) $offerItem->notes, $matches)) {
                    $bidNumber = (int) $matches[1];
                }

                $savedBids[$offerItem->purchase_request_item_id][$bidNumber] = [
                    'vendor_name' => $vendorOffer->vendor_name_snapshot ?: optional($vendorOffer->vendor)->name,
                    'unit_price' => $offerItem->unit_price,
                ];
            }
        }

        return view('purchasing-lite.purchase-requests.vendors', [
            'user' => $user,
            'purchaseRequest' => $purchaseRequest,
            'savedBids' => $savedBids,
        ]);
    }

    public function store(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();
        $role = $this->normalizedUserRole($user);

        if (! in_array($role, ['purchasing', 'admin'], true)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only Purchasing can save vendor bids.');
        }

        if ($this->normalizedStep($purchaseRequest->current_step) !== 'purchasing') {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'This purchase request is not with Purchasing.');
        }

        $purchaseRequest->load('items');

        if ($purchaseRequest->items->isEmpty()) {
            return back()
                ->withErrors([
                    'items' => 'This PR does not have any item.',
                ])
                ->withInput();
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.quantity' => ['nullable', 'string', 'max:100'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.last_purchase_date' => ['nullable', 'date'],

            'bids' => ['nullable', 'array'],
            'bids.*' => ['nullable', 'array'],
            'bids.*.*.vendor_name' => ['nullable', 'string', 'max:191'],
            'bids.*.*.unit_price' => ['nullable', 'string', 'max:100'],
        ]);

        $itemUpdates = $validated['items'] ?? [];
        $bids = $validated['bids'] ?? [];

        DB::beginTransaction();

        try {
            foreach ($purchaseRequest->items as $item) {
                $itemUpdate = $itemUpdates[$item->id] ?? [];

                $quantityRaw = trim((string) ($itemUpdate['quantity'] ?? $item->quantity));
                $unitRaw = trim((string) ($itemUpdate['unit'] ?? $item->unit));
                $lastPurchaseDate = $itemUpdate['last_purchase_date'] ?? null;

                $quantity = $this->parseMoney($quantityRaw);

                $item->update([
                    'quantity' => $quantity > 0 ? $quantity : 1,
                    'unit' => $unitRaw !== '' ? $unitRaw : null,
                    'last_purchase_date' => filled($lastPurchaseDate) ? $lastPurchaseDate : null,
                ]);
            }

            $purchaseRequest->load('items');

            $existingOffers = $purchaseRequest->vendorOffers()
                ->with('offerItems')
                ->get();

            foreach ($existingOffers as $existingOffer) {
                $existingOffer->offerItems()->delete();
                $existingOffer->delete();
            }

            $groupedBids = [];

            foreach ($purchaseRequest->items as $item) {
                for ($bidNumber = 1; $bidNumber <= 3; $bidNumber++) {
                    $vendorName = trim((string) ($bids[$item->id][$bidNumber]['vendor_name'] ?? ''));
                    $unitPriceRaw = trim((string) ($bids[$item->id][$bidNumber]['unit_price'] ?? ''));

                    if ($vendorName === '' || $unitPriceRaw === '') {
                        continue;
                    }

                    $unitPrice = $this->parseMoney($unitPriceRaw);

                    if ($unitPrice <= 0) {
                        continue;
                    }

                    $normalizedName = Vendor::normalizeName($vendorName);

                    if ($normalizedName === '') {
                        continue;
                    }

                    if (! isset($groupedBids[$normalizedName])) {
                        $groupedBids[$normalizedName] = [
                            'vendor_name' => $vendorName,
                            'items' => [],
                            'offer_total' => 0,
                        ];
                    }

                    $quantity = (float) $item->quantity;
                    $totalPrice = $quantity * $unitPrice;

                    $groupedBids[$normalizedName]['items'][] = [
                        'purchase_request_item_id' => $item->id,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'bid_number' => $bidNumber,
                    ];

                    $groupedBids[$normalizedName]['offer_total'] += $totalPrice;
                }
            }

            foreach ($groupedBids as $normalizedName => $bidGroup) {
                $vendor = Vendor::firstOrCreate(
                    [
                        'normalized_name' => $normalizedName,
                    ],
                    [
                        'name' => $bidGroup['vendor_name'],
                        'is_active' => true,
                    ]
                );

                if (empty($vendor->name) && ! empty($bidGroup['vendor_name'])) {
                    $vendor->update([
                        'name' => $bidGroup['vendor_name'],
                    ]);
                }

                $vendorOffer = PurchaseRequestVendorOffer::create([
                    'purchase_request_id' => $purchaseRequest->id,
                    'vendor_id' => $vendor->id,

                    'vendor_name_snapshot' => $vendor->name,
                    'vendor_phone_snapshot' => $vendor->phone ?? null,
                    'vendor_email_snapshot' => $vendor->email ?? null,
                    'vendor_address_snapshot' => $vendor->address ?? null,

                    'quotation_number' => null,
                    'quotation_file' => null,

                    'currency' => 'IDR',
                    'offer_total' => $bidGroup['offer_total'],
                    'lead_time_days' => null,
                    'notes' => 'Saved from simple bid table.',

                    'is_selected_by_cost_control' => false,
                    'created_by' => $user->id,
                ]);

                foreach ($bidGroup['items'] as $bidItem) {
                    PurchaseRequestVendorOfferItem::create([
                        'purchase_request_vendor_offer_id' => $vendorOffer->id,
                        'purchase_request_item_id' => $bidItem['purchase_request_item_id'],
                        'unit_price' => $bidItem['unit_price'],
                        'quantity' => $bidItem['quantity'],
                        'brand' => null,
                        'notes' => 'Bid ' . $bidItem['bid_number'],
                    ]);
                }
            }

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'vendor_bids_saved',
                'from_status' => $purchaseRequest->status,
                'to_status' => $purchaseRequest->status,
                'from_step' => $purchaseRequest->current_step,
                'to_step' => $purchaseRequest->current_step,
                'remarks' => empty($groupedBids)
                    ? 'Vendor bids, item quantity, and item unit saved by Purchasing. No vendor bid was entered.'
                    : 'Vendor bids, item quantity, and item unit saved by Purchasing.',
                'acted_at' => now(),
            ]);

            DB::commit();

            return redirect()
                ->route('purchasing-lite.purchase-requests.vendors', $purchaseRequest)
                ->with('success', 'Vendor bids have been saved.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to save vendor bids. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    public function sendToCostControl(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();
        $role = $this->normalizedUserRole($user);

        if (! in_array($role, ['purchasing', 'admin'], true)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only Purchasing can send this PR to Cost Control.');
        }

        if ($this->normalizedStep($purchaseRequest->current_step) !== 'purchasing') {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'This purchase request is not with Purchasing.');
        }

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'status' => 'submitted_to_cost_control',
                'current_step' => 'cost_control',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'sent_to_cost_control',
                'from_status' => $fromStatus,
                'to_status' => 'submitted_to_cost_control',
                'from_step' => $fromStep,
                'to_step' => 'cost_control',
                'remarks' => 'PR sent to Cost Control. Vendor comparison will be validated by Cost Control.',
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRoles(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control'],
                subject: 'PR Sent to Cost Control - ' . $purchaseRequest->pr_number,
                title: 'PR Needs Cost Control Review',
                messageText: 'Purchasing has submitted vendor bids. Please review and select the vendor.',
                buttonLabel: 'Select Vendor',
                buttonUrl: route('purchasing-lite.purchase-requests.cost-control.show', $purchaseRequest),
                remarks: 'PR sent to Cost Control. Vendor comparison will be validated by Cost Control.'
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been sent to Cost Control.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.vendors', $purchaseRequest)
                ->with('error', 'Failed to send PR to Cost Control. ' . $e->getMessage());
        }
    }

    public function sendBackToRequester(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();
        $role = $this->normalizedUserRole($user);

        if (! in_array($role, ['purchasing', 'admin'], true)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only Purchasing can send this PR back to Requester.');
        }

        if ($this->normalizedStep($purchaseRequest->current_step) !== 'purchasing') {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'This purchase request is not with Purchasing.');
        }

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'status' => 'revision_to_requester_from_purchasing',
                'current_step' => 'requester',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'sent_back_to_requester',
                'from_status' => $fromStatus,
                'to_status' => 'revision_to_requester_from_purchasing',
                'from_step' => $fromStep,
                'to_step' => 'requester',
                'remarks' => $validated['remarks'],
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRequester(
                purchaseRequest: $purchaseRequest,
                subject: 'PR Sent Back for Revision - ' . $purchaseRequest->pr_number,
                title: 'PR Sent Back by Purchasing',
                messageText: 'Purchasing has sent your purchase request back for revision.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $validated['remarks']
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been sent back to Requester.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.vendors', $purchaseRequest)
                ->with('error', 'Failed to send PR back to Requester. ' . $e->getMessage());
        }
    }

    public function rejectToRequester(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();
        $role = $this->normalizedUserRole($user);

        if (! in_array($role, ['purchasing', 'admin'], true)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only Purchasing can reject this PR.');
        }

        if ($this->normalizedStep($purchaseRequest->current_step) !== 'purchasing') {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'This purchase request is not with Purchasing.');
        }

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'status' => 'rejected',
                'current_step' => 'requester',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'rejected_to_requester',
                'from_status' => $fromStatus,
                'to_status' => 'rejected',
                'from_step' => $fromStep,
                'to_step' => 'requester',
                'remarks' => $validated['remarks'],
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRequester(
                purchaseRequest: $purchaseRequest,
                subject: 'PR Rejected - ' . $purchaseRequest->pr_number,
                title: 'PR Rejected by Purchasing',
                messageText: 'Purchasing has rejected this purchase request.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $validated['remarks']
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been rejected and returned to Requester.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.vendors', $purchaseRequest)
                ->with('error', 'Failed to reject PR. ' . $e->getMessage());
        }
    }

    public function searchVendors(Request $request)
    {
        $query = trim((string) $request->get('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $lowerQuery = mb_strtolower($query);
        $normalizedQuery = mb_strtolower(Vendor::normalizeName($query));
        $compactQuery = preg_replace('/[^a-z0-9]/', '', $normalizedQuery);

        $vendors = DB::table('vendors')
            ->select([
                'id',
                'name',
                'normalized_name',
                'contact_person',
                'phone',
                'email',
                'address',
                'is_active',
            ])
            ->where(function ($vendorQuery) use ($lowerQuery, $normalizedQuery, $compactQuery) {
                $vendorQuery
                    ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", ['%' . $lowerQuery . '%'])
                    ->orWhereRaw("LOWER(COALESCE(normalized_name, '')) LIKE ?", ['%' . $normalizedQuery . '%'])
                    ->orWhereRaw("LOWER(COALESCE(contact_person, '')) LIKE ?", ['%' . $lowerQuery . '%'])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", ['%' . $lowerQuery . '%'])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", ['%' . $lowerQuery . '%'])
                    ->orWhereRaw("
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(LOWER(COALESCE(name, '')), ' ', ''),
                                '.', ''),
                            ',', ''),
                        '-', '') LIKE ?
                    ", ['%' . $compactQuery . '%'])
                    ->orWhereRaw("
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(LOWER(COALESCE(normalized_name, '')), ' ', ''),
                                '.', ''),
                            ',', ''),
                        '-', '') LIKE ?
                    ", ['%' . $compactQuery . '%']);
            })
            ->orderByRaw("
                CASE
                    WHEN LOWER(COALESCE(normalized_name, '')) = ? THEN 1
                    WHEN LOWER(COALESCE(normalized_name, '')) LIKE ? THEN 2
                    WHEN LOWER(COALESCE(name, '')) LIKE ? THEN 3
                    WHEN LOWER(COALESCE(name, '')) LIKE ? THEN 4
                    ELSE 5
                END
            ", [
                $normalizedQuery,
                $normalizedQuery . '%',
                $lowerQuery . '%',
                '%' . $lowerQuery . '%',
            ])
            ->orderBy('name')
            ->limit(10)
            ->get();

        if ($request->boolean('debug')) {
            $database = DB::selectOne('SELECT DATABASE() AS database_name');

            return response()->json([
                'database' => $database->database_name ?? null,
                'query' => $query,
                'lower_query' => $lowerQuery,
                'normalized_query' => $normalizedQuery,
                'compact_query' => $compactQuery,
                'vendor_count' => DB::table('vendors')->count(),
                'matched_count' => $vendors->count(),
                'vendors' => $vendors,
            ]);
        }

        return response()->json(
            $vendors->map(function ($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'normalized_name' => $vendor->normalized_name,
                    'contact_person' => $vendor->contact_person,
                    'phone' => $vendor->phone,
                    'email' => $vendor->email,
                    'address' => $vendor->address,
                    'is_active' => $vendor->is_active,
                ];
            })->values()
        );
    }

    private function parseMoney(string $value): float
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $value = preg_replace('/[^0-9.,]/', '', $value);

        if ($value === '') {
            return 0;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            $lastComma = strrpos($value, ',');
            $lastDot = strrpos($value, '.');

            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }

            return (float) $value;
        }

        if ($hasComma) {
            $parts = explode(',', $value);
            $lastPart = end($parts);

            if (strlen((string) $lastPart) === 3) {
                $value = str_replace(',', '', $value);
            } else {
                $value = str_replace(',', '.', $value);
            }

            return (float) $value;
        }

        if ($hasDot) {
            $parts = explode('.', $value);
            $lastPart = end($parts);

            if (strlen((string) $lastPart) === 3) {
                $value = str_replace('.', '', $value);
            }

            return (float) $value;
        }

        return (float) $value;
    }

    private function normalizedUserRole($user): string
    {
        $role = strtolower(trim((string) ($user->role ?? $user->role_name ?? '')));

        return str_replace(['-', ' '], '_', $role);
    }

    private function normalizedStep(?string $step): string
    {
        $step = strtolower(trim((string) $step));

        return str_replace(['-', ' '], '_', $step);
    }
}
