<?php

namespace App\Http\Controllers\PurchasingLite;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Services\PurchasingLite\PurchasingLiteEmailService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PurchaseRequestOwnerController extends Controller
{
    public function show(PurchaseRequest $purchaseRequest): View
    {
        $purchaseRequest->loadMissing(['items']);

        $selectedVendorItems = $this->getSelectedVendorItems($purchaseRequest);
        $selectedGrandTotal = collect($selectedVendorItems)->sum(function ($item) {
            return (float) ($item['total_price'] ?? 0);
        });

        return view('purchasing-lite.purchase-requests.owner-review', [
            'purchaseRequest' => $purchaseRequest,
            'selectedVendorItems' => $selectedVendorItems,
            'selectedGrandTotal' => $selectedGrandTotal,
        ]);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $remarks = trim((string) $request->input('remarks'));

        DB::transaction(function () use ($purchaseRequest, $remarks) {
            $oldStatus = (string) ($purchaseRequest->status ?? '');

            $this->sendToFinancialController($purchaseRequest, $remarks);

            $this->createLog($purchaseRequest, [
                'action' => 'owner_approved_to_financial_controller',
                'from_status' => $oldStatus,
                'to_status' => 'submitted_to_financial_controller',
                'remarks' => $remarks,
            ]);
        });

        $this->sendOwnerApprovedEmails($purchaseRequest, $remarks);

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been approved and sent to Financial Controller.');
    }

    public function splitApprove(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate([
            'approved_item_ids' => ['required', 'array', 'min:1'],
            'approved_item_ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $remarks = trim((string) ($validated['remarks'] ?? ''));

        $approvedItemIds = collect($validated['approved_item_ids'])
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $result = DB::transaction(function () use ($purchaseRequest, $approvedItemIds, $remarks) {
            return $this->processOwnerSelectedItems($purchaseRequest, $approvedItemIds, $remarks);
        });

        $this->sendOwnerSelectionEmails($result, $remarks);

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'Selected item(s) have been approved and sent to Financial Controller.');
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'approved_item_ids' => ['required', 'array', 'min:1'],
            'approved_item_ids.*' => ['array'],
            'approved_item_ids.*.*' => ['integer'],
            'owner_quantities' => ['nullable', 'array'],
            'owner_quantities.*' => ['array'],
            'owner_quantities.*.*' => ['nullable', 'numeric', 'min:0.01'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $remarks = trim((string) ($validated['remarks'] ?? ''));
        $ownerQuantities = $this->normalizeOwnerQuantities($validated['owner_quantities'] ?? []);

        $approvedGroups = collect($validated['approved_item_ids'])
            ->mapWithKeys(function ($itemIds, $purchaseRequestId) {
                return [
                    (int) $purchaseRequestId => collect($itemIds)
                        ->map(fn($id) => (int) $id)
                        ->unique()
                        ->values(),
                ];
            })
            ->filter(fn($itemIds) => $itemIds->count() > 0);

        if ($approvedGroups->count() < 1) {
            return back()->with('error', 'Please select at least one item to approve.');
        }

        $results = collect();

        DB::transaction(function () use ($approvedGroups, $ownerQuantities, $remarks, &$results) {
            foreach ($approvedGroups as $purchaseRequestId => $approvedItemIds) {
                $purchaseRequest = PurchaseRequest::query()
                    ->with('items')
                    ->where('id', $purchaseRequestId)
                    ->where('current_step', 'owner')
                    ->first();

                if (! $purchaseRequest) {
                    continue;
                }

                $this->applyOwnerQuantityAdjustments(
                    $purchaseRequest,
                    $approvedItemIds,
                    $ownerQuantities[$purchaseRequestId] ?? []
                );

                $purchaseRequest->load('items');

                $result = $this->processOwnerSelectedItems($purchaseRequest, $approvedItemIds, $remarks);

                if (! empty($result)) {
                    $results->push($result);
                }
            }
        });

        foreach ($results as $result) {
            $this->sendOwnerSelectionEmails($result, $remarks);
        }

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'Selected item(s) have been approved and sent to Financial Controller.');
    }

    public function saveQuantities(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'owner_quantities' => ['required', 'array', 'min:1'],
            'owner_quantities.*' => ['array'],
            'owner_quantities.*.*' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $ownerQuantities = $this->normalizeOwnerQuantities($validated['owner_quantities'] ?? []);

        if (empty($ownerQuantities)) {
            return back()->with('error', 'Please enter at least one valid quantity to save.');
        }

        DB::transaction(function () use ($ownerQuantities) {
            foreach ($ownerQuantities as $purchaseRequestId => $itemQuantities) {
                $purchaseRequest = PurchaseRequest::query()
                    ->with('items')
                    ->where('id', $purchaseRequestId)
                    ->where('current_step', 'owner')
                    ->first();

                if (! $purchaseRequest) {
                    continue;
                }

                $this->applyOwnerQuantityAdjustments(
                    $purchaseRequest,
                    array_keys($itemQuantities),
                    $itemQuantities
                );
            }
        });

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'OR quantity changes have been saved.');
    }

    public function saveRemarks(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'owner_requester_remarks' => ['required', 'array', 'min:1'],
            'owner_requester_remarks.*' => ['nullable', 'string', 'max:5000'],
        ]);

        $remarksByPurchaseRequest = collect($validated['owner_requester_remarks'])
            ->mapWithKeys(fn($remarks, $purchaseRequestId) => [(int) $purchaseRequestId => trim((string) $remarks)]);

        DB::transaction(function () use ($remarksByPurchaseRequest) {
            foreach ($remarksByPurchaseRequest as $purchaseRequestId => $remarks) {
                $purchaseRequest = PurchaseRequest::query()
                    ->where('id', $purchaseRequestId)
                    ->where('current_step', 'owner')
                    ->first();

                if (! $purchaseRequest) {
                    continue;
                }

                $oldRemarks = (string) ($purchaseRequest->requester_remarks ?? '');

                if ($oldRemarks === $remarks) {
                    continue;
                }

                $this->safeFill($purchaseRequest, [
                    'requester_remarks' => $remarks,
                ]);

                $purchaseRequest->save();

                $this->createLog($purchaseRequest, [
                    'action' => 'owner_updated_requester_remarks',
                    'from_status' => $purchaseRequest->status,
                    'to_status' => $purchaseRequest->status,
                    'remarks' => $remarks,
                ]);
            }
        });

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'OR remarks changes have been saved.');
    }

    public function returnToGm(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:5000'],
        ]);

        $remarks = trim((string) $validated['remarks']);

        DB::transaction(function () use ($purchaseRequest, $remarks) {
            $oldStatus = (string) ($purchaseRequest->status ?? '');

            $this->safeFill($purchaseRequest, [
                'status' => 'revision_to_gm_from_owner',
                'current_step' => 'gm',
                'owner_remarks' => $remarks,
                'owner_return_remarks' => $remarks,
                'current_status_at' => now(),
            ]);

            $purchaseRequest->save();

            $this->createLog($purchaseRequest, [
                'action' => 'returned_to_gm_from_owner',
                'from_status' => $oldStatus,
                'to_status' => 'revision_to_gm_from_owner',
                'remarks' => $remarks,
            ]);
        });

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been returned to GM.');
    }

    public function rejectToRequester(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:5000'],
        ]);

        $remarks = trim((string) $validated['remarks']);

        DB::transaction(function () use ($purchaseRequest, $remarks) {
            $oldStatus = (string) ($purchaseRequest->status ?? '');

            $this->safeFill($purchaseRequest, [
                'status' => 'rejected',
                'current_step' => 'requester',
                'owner_remarks' => $remarks,
                'rejected_remarks' => $remarks,
                'reject_reason' => $remarks,
                'rejection_reason' => $remarks,
                'rejected_at' => now(),
                'current_status_at' => now(),
            ]);

            $purchaseRequest->save();

            $this->createLog($purchaseRequest, [
                'action' => 'rejected_to_requester_from_owner',
                'from_status' => $oldStatus,
                'to_status' => 'rejected',
                'remarks' => $remarks,
            ]);
        });

        app(PurchasingLiteEmailService::class)->sendToRequester(
            purchaseRequest: $purchaseRequest,
            subject: 'PR Rejected by OR - ' . $this->getPurchaseRequestNumber($purchaseRequest),
            title: 'PR Rejected by OR',
            messageText: 'OR has rejected this purchase request.',
            buttonLabel: 'Open PR',
            buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
            remarks: $remarks
        );

        app(PurchasingLiteEmailService::class)->sendToRoles(
            purchaseRequest: $purchaseRequest,
            roles: ['cost_control', 'purchasing'],
            subject: 'PR Rejected by OR - ' . $this->getPurchaseRequestNumber($purchaseRequest),
            title: 'PR Rejected by OR',
            messageText: 'OR has rejected this purchase request.',
            buttonLabel: 'Open PR List',
            buttonUrl: route('purchasing-lite.purchase-requests.meeting-list'),
            remarks: $remarks
        );

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been rejected.');
    }

    private function processOwnerSelectedItems(PurchaseRequest $purchaseRequest, $approvedItemIds, string $remarks): ?array
    {
        $purchaseRequest->loadMissing('items');

        $items = $purchaseRequest->items;

        if ($items->count() < 1) {
            return null;
        }

        $validItemIds = $items->pluck('id')->map(fn($id) => (int) $id);

        $approvedItemIds = collect($approvedItemIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $validItemIds->contains($id))
            ->unique()
            ->values();

        if ($approvedItemIds->count() < 1) {
            return null;
        }

        $approvedItems = $items->filter(function ($item) use ($approvedItemIds) {
            return $approvedItemIds->contains((int) $item->id);
        })->values();

        $unapprovedItems = $items->filter(function ($item) use ($approvedItemIds) {
            return ! $approvedItemIds->contains((int) $item->id);
        })->values();

        $oldStatus = (string) ($purchaseRequest->status ?? '');

        if ($unapprovedItems->count() < 1) {
            $this->sendToFinancialController($purchaseRequest, $remarks);

            $this->createLog($purchaseRequest, [
                'action' => 'owner_approved_to_financial_controller',
                'from_status' => $oldStatus,
                'to_status' => 'submitted_to_financial_controller',
                'remarks' => $remarks,
            ]);

            return [
                'type' => 'full',
                'financial_purchase_request' => $purchaseRequest,
                'original_purchase_request' => null,
            ];
        }

        $financePurchaseRequest = $this->createFinanceSplitPurchaseRequest($purchaseRequest, $remarks);

        $approvedItemIdsArray = $approvedItems
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $this->moveItemsToPurchaseRequest($approvedItems, $financePurchaseRequest);
        $this->moveRelatedVendorRowsToPurchaseRequest($purchaseRequest, $financePurchaseRequest, $approvedItemIdsArray);

        $this->safeFill($purchaseRequest, [
            'status' => 'submitted_to_owner',
            'current_step' => 'owner',
            'owner_remarks' => $remarks,
            'owner_split_remarks' => $remarks,
            'split_remarks' => $remarks,
            'current_status_at' => now(),
        ]);

        $purchaseRequest->save();

        $this->createLog($purchaseRequest, [
            'action' => 'owner_partial_approved_original_stays_owner',
            'from_status' => $oldStatus,
            'to_status' => 'submitted_to_owner',
            'remarks' => $remarks,
        ]);

        $this->createLog($financePurchaseRequest, [
            'action' => 'owner_partial_approved_to_financial_controller',
            'from_status' => $oldStatus,
            'to_status' => 'submitted_to_financial_controller',
            'remarks' => $remarks,
        ]);

        return [
            'type' => 'split',
            'financial_purchase_request' => $financePurchaseRequest,
            'original_purchase_request' => $purchaseRequest,
        ];
    }

    private function sendToFinancialController(PurchaseRequest $purchaseRequest, ?string $remarks = null): void
    {
        $this->safeFill($purchaseRequest, [
            'status' => 'submitted_to_financial_controller',
            'current_step' => 'financial_controller',
            'owner_remarks' => $remarks,
            'owner_approved_at' => now(),
            'current_status_at' => now(),
        ]);

        $purchaseRequest->save();
    }

    private function createFinanceSplitPurchaseRequest(PurchaseRequest $purchaseRequest, string $remarks): PurchaseRequest
    {
        $financePurchaseRequest = $purchaseRequest->replicate();

        $newPrNumber = $this->generateSplitPrNumber($purchaseRequest);

        $this->safeFill($financePurchaseRequest, [
            'pr_number' => $newPrNumber,
            'request_number' => $newPrNumber,
            'status' => 'submitted_to_financial_controller',
            'current_step' => 'financial_controller',
            'requester_remarks' => $this->appendSplitNote(
                (string) ($purchaseRequest->requester_remarks ?? ''),
                $purchaseRequest->pr_number ?? $purchaseRequest->request_number ?? '-'
            ),
            'owner_remarks' => $remarks,
            'owner_split_remarks' => $remarks,
            'split_remarks' => $remarks,
            'owner_approved_at' => now(),
            'current_status_at' => now(),
            'approved_at' => null,
            'rejected_at' => null,
        ]);

        $financePurchaseRequest->save();

        return $financePurchaseRequest;
    }

    private function generateSplitPrNumber(PurchaseRequest $purchaseRequest): string
    {
        $table = $purchaseRequest->getTable();
        $numberColumn = Schema::hasColumn($table, 'pr_number') ? 'pr_number' : 'request_number';

        $currentNumber = (string) (
            $purchaseRequest->{$numberColumn}
            ?? $purchaseRequest->pr_number
            ?? $purchaseRequest->request_number
            ?? 'PR'
        );

        $baseNumber = preg_replace('/-S\d+$/i', '', $currentNumber);

        $existingNumbers = PurchaseRequest::query()
            ->where($numberColumn, 'like', $baseNumber . '-S%')
            ->pluck($numberColumn)
            ->filter()
            ->values();

        $highest = 0;

        foreach ($existingNumbers as $existingNumber) {
            if (preg_match('/-S(\d+)$/i', (string) $existingNumber, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $baseNumber . '-S' . ($highest + 1);
    }

    private function appendSplitNote(string $currentRemarks, string $sourcePrNumber): string
    {
        $currentRemarks = trim($currentRemarks);
        $splitNote = 'Splited from ' . $sourcePrNumber;

        if ($currentRemarks === '') {
            return $splitNote;
        }

        if (str_contains($currentRemarks, $splitNote)) {
            return $currentRemarks;
        }

        return $currentRemarks . "\n\n" . $splitNote;
    }

    private function moveItemsToPurchaseRequest($items, PurchaseRequest $targetPurchaseRequest): void
    {
        foreach ($items as $item) {
            $itemTable = method_exists($item, 'getTable') ? $item->getTable() : null;

            if (! $itemTable || ! Schema::hasColumn($itemTable, 'purchase_request_id')) {
                continue;
            }

            $item->purchase_request_id = $targetPurchaseRequest->id;
            $item->save();
        }
    }

    private function moveRelatedVendorRowsToPurchaseRequest(
        PurchaseRequest $oldPurchaseRequest,
        PurchaseRequest $targetPurchaseRequest,
        array $itemIds
    ): void {
        if (empty($itemIds)) {
            return;
        }

        $candidateTables = [
            'purchase_request_offer_items',
            'purchase_request_vendor_offer_items',
            'purchase_request_vendor_items',
            'purchase_request_vendor_bids',
            'purchase_request_bids',
            'vendor_bids',
        ];

        foreach ($candidateTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'purchase_request_id')) {
                continue;
            }

            $query = DB::table($table)
                ->where('purchase_request_id', $oldPurchaseRequest->id);

            if (Schema::hasColumn($table, 'purchase_request_item_id')) {
                $query->whereIn('purchase_request_item_id', $itemIds);
            } elseif (Schema::hasColumn($table, 'item_id')) {
                $query->whereIn('item_id', $itemIds);
            } else {
                continue;
            }

            $updates = [
                'purchase_request_id' => $targetPurchaseRequest->id,
            ];

            if (Schema::hasColumn($table, 'updated_at')) {
                $updates['updated_at'] = now();
            }

            $query->update($updates);
        }
    }

    private function normalizeOwnerQuantities(array $ownerQuantities): array
    {
        $normalized = [];

        foreach ($ownerQuantities as $purchaseRequestId => $itemQuantities) {
            if (! is_array($itemQuantities)) {
                continue;
            }

            foreach ($itemQuantities as $itemId => $quantity) {
                $quantity = (float) $quantity;

                if ($quantity <= 0) {
                    continue;
                }

                $normalized[(int) $purchaseRequestId][(int) $itemId] = $quantity;
            }
        }

        return $normalized;
    }

    private function applyOwnerQuantityAdjustments(PurchaseRequest $purchaseRequest, $approvedItemIds, array $itemQuantities): void
    {
        if (empty($itemQuantities)) {
            return;
        }

        $approvedItemIds = collect($approvedItemIds)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($purchaseRequest->items as $item) {
            $itemId = (int) $item->id;

            if (! $approvedItemIds->contains($itemId) || ! isset($itemQuantities[$itemId])) {
                continue;
            }

            $quantity = (float) $itemQuantities[$itemId];

            if ($quantity <= 0) {
                continue;
            }

            if (Schema::hasColumn($item->getTable(), 'quantity')) {
                $item->quantity = $quantity;
                $item->save();
            }

            $this->updateSelectedVendorItemQuantity($purchaseRequest, $itemId, $quantity);
        }
    }

    private function updateSelectedVendorItemQuantity(PurchaseRequest $purchaseRequest, int $itemId, float $quantity): void
    {
        $candidateTables = [
            'purchase_request_offer_items',
            'purchase_request_vendor_offer_items',
            'purchase_request_vendor_items',
            'purchase_request_vendor_bids',
            'purchase_request_bids',
            'vendor_bids',
        ];

        foreach ($candidateTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);

            if (Schema::hasColumn($table, 'purchase_request_id')) {
                $query->where('purchase_request_id', $purchaseRequest->id);
            }

            if (Schema::hasColumn($table, 'purchase_request_item_id')) {
                $query->where('purchase_request_item_id', $itemId);
            } elseif (Schema::hasColumn($table, 'item_id')) {
                $query->where('item_id', $itemId);
            } else {
                continue;
            }

            if (Schema::hasColumn($table, 'is_selected')) {
                $query->where('is_selected', 1);
            } elseif (Schema::hasColumn($table, 'is_selected_by_cost_control')) {
                $query->where('is_selected_by_cost_control', 1);
            } elseif (Schema::hasColumn($table, 'selected_by_cost_control')) {
                $query->where('selected_by_cost_control', 1);
            } elseif (Schema::hasColumn($table, 'selected_offer_item_id')) {
                $query->whereNotNull('selected_offer_item_id');
            }

            $row = $query->latest('id')->first();

            if (! $row) {
                continue;
            }

            $unitPrice =
                $row->unit_price
                ?? $row->price
                ?? $row->offer_price
                ?? $row->vendor_price
                ?? 0;

            $updates = [];

            if (Schema::hasColumn($table, 'quantity')) {
                $updates['quantity'] = $quantity;
            } elseif (Schema::hasColumn($table, 'qty')) {
                $updates['qty'] = $quantity;
            }

            if (Schema::hasColumn($table, 'total_price')) {
                $updates['total_price'] = $quantity * (float) $unitPrice;
            } elseif (Schema::hasColumn($table, 'total')) {
                $updates['total'] = $quantity * (float) $unitPrice;
            }

            if (Schema::hasColumn($table, 'updated_at')) {
                $updates['updated_at'] = now();
            }

            if (! empty($updates)) {
                DB::table($table)->where('id', $row->id)->update($updates);
            }

            $this->refreshVendorOfferTotal($table, $row);

            return;
        }
    }

    private function refreshVendorOfferTotal(string $itemTable, $itemRow): void
    {
        if (
            $itemTable !== 'purchase_request_vendor_offer_items'
            || ! isset($itemRow->purchase_request_vendor_offer_id)
            || ! Schema::hasTable('purchase_request_vendor_offers')
            || ! Schema::hasColumn('purchase_request_vendor_offers', 'offer_total')
        ) {
            return;
        }

        $offerTotal = (float) DB::table('purchase_request_vendor_offer_items')
            ->where('purchase_request_vendor_offer_id', $itemRow->purchase_request_vendor_offer_id)
            ->sum('total_price');

        $updates = ['offer_total' => $offerTotal];

        if (Schema::hasColumn('purchase_request_vendor_offers', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        DB::table('purchase_request_vendor_offers')
            ->where('id', $itemRow->purchase_request_vendor_offer_id)
            ->update($updates);
    }

    private function sendOwnerSelectionEmails(?array $result, string $remarks): void
    {
        if (empty($result)) {
            return;
        }

        $type = (string) ($result['type'] ?? '');
        $financialPurchaseRequest = $result['financial_purchase_request'] ?? null;
        $originalPurchaseRequest = $result['original_purchase_request'] ?? null;

        if ($financialPurchaseRequest instanceof PurchaseRequest) {
            $this->sendOwnerApprovedEmails($financialPurchaseRequest, $remarks);
        }

        if ($type !== 'split' || ! $originalPurchaseRequest instanceof PurchaseRequest) {
            return;
        }

        $originalPrNumber = $this->getPurchaseRequestNumber($originalPurchaseRequest);
        $financePrNumber = $financialPurchaseRequest instanceof PurchaseRequest
            ? $this->getPurchaseRequestNumber($financialPurchaseRequest)
            : '-';

        app(PurchasingLiteEmailService::class)->sendToRequester(
            purchaseRequest: $originalPurchaseRequest,
            subject: 'PR Split by OR - ' . $originalPrNumber,
            title: 'PR Split by OR',
            messageText: 'OR approved selected item(s). The remaining item(s) will stay with OR for later approval.',
            buttonLabel: 'Open PR',
            buttonUrl: route('purchasing-lite.purchase-requests.show', $originalPurchaseRequest),
            remarks: $remarks !== ''
                ? $remarks
                : 'Selected item(s) were approved and sent as ' . $financePrNumber . '. Remaining item(s) stay with OR.'
        );

        app(PurchasingLiteEmailService::class)->sendToRoles(
            purchaseRequest: $originalPurchaseRequest,
            roles: ['cost_control', 'purchasing'],
            subject: 'PR Split by OR - ' . $originalPrNumber,
            title: 'PR Split by OR',
            messageText: 'OR approved selected item(s). The remaining item(s) will stay with OR for later approval.',
            buttonLabel: 'Open PR List',
            buttonUrl: route('purchasing-lite.purchase-requests.meeting-list'),
            remarks: $remarks !== ''
                ? $remarks
                : 'Selected item(s) were approved and sent as ' . $financePrNumber . '. Remaining item(s) stay with OR.'
        );
    }

    private function sendOwnerApprovedEmails(PurchaseRequest $purchaseRequest, string $remarks): void
    {
        $prNumber = $this->getPurchaseRequestNumber($purchaseRequest);

        app(PurchasingLiteEmailService::class)->sendToRoles(
            purchaseRequest: $purchaseRequest,
            roles: ['financial_controller'],
            subject: 'PR Approved by OR - ' . $prNumber,
            title: 'PR Approved by OR',
            messageText: 'OR has approved this purchase request. Please continue the payment follow up.',
            buttonLabel: 'Open PR',
            buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
            remarks: $remarks !== '' ? $remarks : 'Approved by OR and sent to Financial Controller.'
        );

    }

    private function getPurchaseRequestNumber(PurchaseRequest $purchaseRequest): string
    {
        return (string) (
            $purchaseRequest->pr_number
            ?? $purchaseRequest->request_number
            ?? ('PR-' . $purchaseRequest->id)
        );
    }

    private function getSelectedVendorItems(PurchaseRequest $purchaseRequest): array
    {
        $items = $purchaseRequest->items ?? collect();
        $selectedVendorItems = [];

        foreach ($items as $item) {
            $selectedVendorItems[$item->id] = $this->getSelectedVendorItemForItem($purchaseRequest, $item);
        }

        return array_filter($selectedVendorItems);
    }

    private function getSelectedVendorItemForItem(PurchaseRequest $purchaseRequest, $item): ?array
    {
        $candidateTables = [
            'purchase_request_offer_items',
            'purchase_request_vendor_offer_items',
            'purchase_request_vendor_items',
            'purchase_request_vendor_bids',
            'purchase_request_bids',
            'vendor_bids',
        ];

        foreach ($candidateTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);

            if (Schema::hasColumn($table, 'purchase_request_id')) {
                $query->where('purchase_request_id', $purchaseRequest->id);
            }

            if (Schema::hasColumn($table, 'purchase_request_item_id')) {
                $query->where('purchase_request_item_id', $item->id);
            } elseif (Schema::hasColumn($table, 'item_id')) {
                $query->where('item_id', $item->id);
            }

            if (Schema::hasColumn($table, 'is_selected')) {
                $query->where('is_selected', 1);
            } elseif (Schema::hasColumn($table, 'is_selected_by_cost_control')) {
                $query->where('is_selected_by_cost_control', 1);
            } elseif (Schema::hasColumn($table, 'selected_by_cost_control')) {
                $query->where('selected_by_cost_control', 1);
            } elseif (Schema::hasColumn($table, 'selected_offer_item_id')) {
                $query->whereNotNull('selected_offer_item_id');
            }

            $row = $query->latest('id')->first();

            if (! $row) {
                continue;
            }

            $vendorName = $row->vendor_name ?? null;

            if (! $vendorName && isset($row->vendor_id) && Schema::hasTable('vendors')) {
                $vendor = DB::table('vendors')->where('id', $row->vendor_id)->first();
                $vendorName = $vendor->name ?? null;
            }

            $unitPrice =
                $row->unit_price
                ?? $row->price
                ?? $row->offer_price
                ?? $row->vendor_price
                ?? 0;

            $quantity =
                $row->quantity
                ?? $row->qty
                ?? $item->quantity
                ?? 0;

            $totalPrice =
                $row->total_price
                ?? $row->total
                ?? ((float) $unitPrice * (float) $quantity);

            return [
                'vendor_name' => $vendorName ?: '-',
                'unit_price' => (float) $unitPrice,
                'quantity' => (float) $quantity,
                'total_price' => (float) $totalPrice,
            ];
        }

        return null;
    }

    private function safeFill(PurchaseRequest $purchaseRequest, array $values): void
    {
        $table = $purchaseRequest->getTable();

        foreach ($values as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $purchaseRequest->{$column} = $value;
            }
        }
    }

    private function createLog(PurchaseRequest $purchaseRequest, array $data): void
    {
        if (! class_exists(\App\Models\PurchaseRequestLog::class)) {
            return;
        }

        $log = new \App\Models\PurchaseRequestLog();

        $table = $log->getTable();

        $values = [
            'purchase_request_id' => $purchaseRequest->id,
            'user_id' => Auth::id(),
            'user_name' => Auth::user()->name ?? null,
            'role' => Auth::user()->role ?? Auth::user()->role_name ?? 'owner',
            'action' => $data['action'] ?? null,
            'from_status' => $data['from_status'] ?? null,
            'to_status' => $data['to_status'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'remark' => $data['remarks'] ?? null,
            'notes' => $data['remarks'] ?? null,
            'acted_at' => Carbon::now(),
        ];

        foreach ($values as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $log->{$column} = $value;
            }
        }

        $log->save();
    }
}
