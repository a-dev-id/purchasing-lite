<?php

namespace App\Http\Controllers\PurchasingLite;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLog;
use App\Services\PurchasingLite\PurchasingLiteEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseRequestGmController extends Controller
{
    public function show(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can review this purchase request.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
        }

        $purchaseRequest->load([
            'items',
            'vendorOffers.vendor',
            'vendorOffers.offerItems.purchaseRequestItem',
            'logs.user',
        ]);

        $selectedVendorItems = $this->getSelectedVendorItems($purchaseRequest);
        $selectedGrandTotal = collect($selectedVendorItems)->sum('total_price');

        return view('purchasing-lite.purchase-requests.gm-review', [
            'user' => $user,
            'purchaseRequest' => $purchaseRequest,
            'selectedVendorItems' => $selectedVendorItems,
            'selectedGrandTotal' => $selectedGrandTotal,
        ]);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can approve this PR.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $purchaseRequest->load([
            'items',
            'vendorOffers.offerItems',
        ]);

        if (! $this->allItemsHaveCostControlSelection($purchaseRequest)) {
            return back()
                ->withErrors([
                    'selected_vendor' => 'Cost Control selected vendor data is incomplete. Please return this PR to Cost Control.',
                ])
                ->withInput();
        }

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;
            $remarks = $validated['remarks'] ?: 'Approved by General Manager and sent to OR.';

            $purchaseRequest->update([
                'status' => 'submitted_to_owner',
                'current_step' => 'owner',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'approved_by_gm',
                'from_status' => $fromStatus,
                'to_status' => 'submitted_to_owner',
                'from_step' => $fromStep,
                'to_step' => 'owner',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRoles(
                purchaseRequest: $purchaseRequest,
                roles: ['owner'],
                subject: 'PR Waiting for OR Approval',
                title: 'PR Approved by GM',
                messageText: 'General Manager has approved this purchase request. Please review it from your OR dashboard.',
                buttonLabel: 'Open OR Dashboard',
                buttonUrl: route('purchasing-lite.dashboard'),
                remarks: $remarks,
                threadKey: 'purchasing-lite-or-approval'
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been approved and sent to OR.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to approve PR. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    public function splitApprove(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can split and approve this PR.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
        }

        $validated = $request->validate([
            'approved_item_ids' => ['required', 'array', 'min:1'],
            'approved_item_ids.*' => ['required', 'integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $purchaseRequest->load([
            'items',
            'vendorOffers.offerItems',
        ]);

        if (! $this->allItemsHaveCostControlSelection($purchaseRequest)) {
            return back()
                ->withErrors([
                    'selected_vendor' => 'Cost Control selected vendor data is incomplete. Please return this PR to Cost Control.',
                ])
                ->withInput();
        }

        $allItemIds = $purchaseRequest->items
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values();

        $approvedItemIds = collect($validated['approved_item_ids'])
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $invalidItemIds = $approvedItemIds
            ->filter(fn($id) => ! $allItemIds->contains($id))
            ->values();

        if ($invalidItemIds->isNotEmpty()) {
            return back()
                ->withErrors([
                    'approved_item_ids' => 'Invalid selected item. Please refresh the page and try again.',
                ])
                ->withInput();
        }

        if ($allItemIds->count() <= 1) {
            return back()
                ->withErrors([
                    'approved_item_ids' => 'This PR only has one item. Please use the normal Approve button.',
                ])
                ->withInput();
        }

        if ($approvedItemIds->count() === $allItemIds->count()) {
            return $this->approve($request, $purchaseRequest);
        }

        $remainingItemIds = $allItemIds
            ->reject(fn($id) => $approvedItemIds->contains($id))
            ->values();

        DB::beginTransaction();

        try {
            $oldPrNumber = $this->getPurchaseRequestNumber($purchaseRequest);

            $newPurchaseRequest = $purchaseRequest->replicate([
                'id',
                'created_at',
                'updated_at',
            ]);

            $newPurchaseRequest->status = 'submitted_to_gm';
            $newPurchaseRequest->current_step = 'gm';
            $newPurchaseRequest->current_status_at = now();

            if ($this->modelHasColumn($newPurchaseRequest, 'pr_number')) {
                $newPurchaseRequest->pr_number = $this->generateSplitPurchaseRequestNumber($purchaseRequest, 'pr_number');
            }

            if ($this->modelHasColumn($newPurchaseRequest, 'request_number')) {
                $newPurchaseRequest->request_number = $this->generateSplitPurchaseRequestNumber($purchaseRequest, 'request_number');
            }

            if ($this->modelHasColumn($newPurchaseRequest, 'parent_purchase_request_id')) {
                $newPurchaseRequest->parent_purchase_request_id = $purchaseRequest->id;
            }

            if ($this->modelHasColumn($newPurchaseRequest, 'split_from_id')) {
                $newPurchaseRequest->split_from_id = $purchaseRequest->id;
            }

            if ($this->modelHasColumn($newPurchaseRequest, 'split_from_pr_number')) {
                $newPurchaseRequest->split_from_pr_number = $oldPrNumber;
            }

            if ($this->modelHasColumn($newPurchaseRequest, 'requester_remarks')) {
                $existingRemarks = trim((string) ($newPurchaseRequest->requester_remarks ?? ''));

                $newPurchaseRequest->requester_remarks = trim(
                    $existingRemarks . "\n\nSplited from " . $oldPrNumber
                );
            }

            $newPurchaseRequest->save();

            $newPrNumber = $this->getPurchaseRequestNumber($newPurchaseRequest);

            foreach ($purchaseRequest->items as $item) {
                if (! $remainingItemIds->contains((int) $item->id)) {
                    continue;
                }

                if (! $this->modelHasColumn($item, 'purchase_request_id')) {
                    throw new \Exception('purchase_request_id column was not found on PR item table.');
                }

                $item->purchase_request_id = $newPurchaseRequest->id;
                $item->save();
            }

            $newVendorOfferMap = [];

            foreach ($purchaseRequest->vendorOffers as $vendorOffer) {
                foreach ($vendorOffer->offerItems as $offerItem) {
                    $itemId = (int) $offerItem->purchase_request_item_id;

                    if (! $remainingItemIds->contains($itemId)) {
                        continue;
                    }

                    if (! isset($newVendorOfferMap[$vendorOffer->id])) {
                        $newVendorOffer = $vendorOffer->replicate([
                            'id',
                            'created_at',
                            'updated_at',
                        ]);

                        if (! $this->modelHasColumn($newVendorOffer, 'purchase_request_id')) {
                            throw new \Exception('purchase_request_id column was not found on vendor offer table.');
                        }

                        $newVendorOffer->purchase_request_id = $newPurchaseRequest->id;
                        $newVendorOffer->save();

                        $newVendorOfferMap[$vendorOffer->id] = $newVendorOffer;
                    }

                    $newVendorOffer = $newVendorOfferMap[$vendorOffer->id];
                    $offerItemVendorOfferForeignKey = $this->getOfferItemVendorOfferForeignKey($offerItem);

                    if (! $offerItemVendorOfferForeignKey) {
                        throw new \Exception('Vendor offer foreign key column was not found on offer item table.');
                    }

                    $offerItem->{$offerItemVendorOfferForeignKey} = $newVendorOffer->id;
                    $offerItem->save();
                }
            }

            foreach ($purchaseRequest->vendorOffers as $vendorOffer) {
                if (! $this->modelHasColumn($vendorOffer, 'is_selected_by_cost_control')) {
                    continue;
                }

                $hasSelectedItem = $vendorOffer->offerItems()
                    ->where('notes', 'like', '%SELECTED_BY_COST_CONTROL%')
                    ->exists();

                $vendorOffer->update([
                    'is_selected_by_cost_control' => $hasSelectedItem,
                ]);
            }

            foreach ($newVendorOfferMap as $newVendorOffer) {
                if (! $this->modelHasColumn($newVendorOffer, 'is_selected_by_cost_control')) {
                    continue;
                }

                $hasSelectedItem = $newVendorOffer->offerItems()
                    ->where('notes', 'like', '%SELECTED_BY_COST_CONTROL%')
                    ->exists();

                $newVendorOffer->update([
                    'is_selected_by_cost_control' => $hasSelectedItem,
                ]);
            }

            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;
            $remarks = $validated['remarks'] ?: 'Approved selected items by General Manager. Remaining items split to ' . $newPrNumber . '.';

            $purchaseRequest->update([
                'status' => 'submitted_to_owner',
                'current_step' => 'owner',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'split_approved_by_gm',
                'from_status' => $fromStatus,
                'to_status' => 'submitted_to_owner',
                'from_step' => $fromStep,
                'to_step' => 'owner',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $newPurchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'split_from_pr_by_gm',
                'from_status' => $fromStatus,
                'to_status' => 'submitted_to_gm',
                'from_step' => $fromStep,
                'to_step' => 'gm',
                'remarks' => 'Splited from ' . $oldPrNumber . ($validated['remarks'] ? "\n\nRemarks: " . $validated['remarks'] : ''),
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRoles(
                purchaseRequest: $purchaseRequest,
                roles: ['owner'],
                subject: 'PR Waiting for OR Approval',
                title: 'PR Partially Approved by GM',
                messageText: 'General Manager has approved selected items from this purchase request. Please review it from your OR dashboard.',
                buttonLabel: 'Open OR Dashboard',
                buttonUrl: route('purchasing-lite.dashboard'),
                remarks: $remarks,
                threadKey: 'purchasing-lite-or-approval'
            );

            app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
                purchaseRequest: $newPurchaseRequest,
                roles: ['cost_control', 'purchasing'],
                subject: 'PR Split by GM - ' . $newPrNumber,
                title: 'PR Split by General Manager',
                messageText: 'General Manager approved selected items and split the remaining items into a separate PR.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $newPurchaseRequest),
                remarks: $validated['remarks'] ?: 'Remaining items were split from ' . $oldPrNumber . '.'
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'Selected items have been approved and sent to OR. Remaining items were split to ' . $newPrNumber . '.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to split and approve PR. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    public function saveQuantities(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can save PR quantities.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
        }

        $validated = $request->validate([
            'gm_quantities' => ['required', 'array', 'min:1'],
            'gm_quantities.*' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $purchaseRequest->load([
            'items',
            'vendorOffers.offerItems',
        ]);

        $gmQuantities = collect($validated['gm_quantities'])
            ->mapWithKeys(fn($quantity, $itemId) => [(int) $itemId => (float) $quantity])
            ->filter(fn($quantity) => $quantity > 0);

        if ($gmQuantities->isEmpty()) {
            return back()
                ->withErrors([
                    'gm_quantities' => 'Please enter at least one valid quantity to save.',
                ])
                ->withInput();
        }

        DB::beginTransaction();

        try {
            $savedCount = 0;

            foreach ($purchaseRequest->items as $item) {
                $itemId = (int) $item->id;

                if (! $gmQuantities->has($itemId)) {
                    continue;
                }

                $quantity = (float) $gmQuantities->get($itemId);

                if ((float) $item->quantity !== $quantity && $this->modelHasColumn($item, 'quantity')) {
                    $item->quantity = $quantity;
                    $item->save();
                }

                if ($this->updateSelectedVendorItemQuantity($purchaseRequest, $itemId, $quantity)) {
                    $savedCount++;
                }
            }

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'gm_updated_quantities',
                'from_status' => $purchaseRequest->status,
                'to_status' => $purchaseRequest->status,
                'from_step' => $purchaseRequest->current_step,
                'to_step' => $purchaseRequest->current_step,
                'remarks' => 'GM saved PR quantity changes before approval.',
                'acted_at' => now(),
            ]);

            DB::commit();

            return redirect()
                ->route('purchasing-lite.purchase-requests.gm.show', $purchaseRequest)
                ->with('success', $savedCount > 0 ? 'PR quantity changes have been saved.' : 'PR quantities have been saved.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to save PR quantities. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    public function returnToCostControl(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can return this PR to Cost Control.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
        }

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'status' => 'revision_to_cost_control_from_gm',
                'current_step' => 'cost_control',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'returned_to_cost_control_from_gm',
                'from_status' => $fromStatus,
                'to_status' => 'revision_to_cost_control_from_gm',
                'from_step' => $fromStep,
                'to_step' => 'cost_control',
                'remarks' => $validated['remarks'],
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRoles(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control'],
                subject: 'PR Returned to Cost Control by GM - ' . $purchaseRequest->pr_number,
                title: 'PR Returned by GM',
                messageText: 'General Manager has returned this purchase request to Cost Control for revision.',
                buttonLabel: 'Open Cost Control Review',
                buttonUrl: route('purchasing-lite.purchase-requests.cost-control.show', $purchaseRequest),
                remarks: $validated['remarks']
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been returned to Cost Control.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to return PR to Cost Control. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    public function sendBackToRequester(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can return this PR to Requester.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
        }

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'status' => 'revision_to_requester_from_gm',
                'current_step' => 'requester',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'returned_to_requester_from_gm',
                'from_status' => $fromStatus,
                'to_status' => 'revision_to_requester_from_gm',
                'from_step' => $fromStep,
                'to_step' => 'requester',
                'remarks' => $validated['remarks'],
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control', 'purchasing'],
                subject: 'PR Returned to Requester by GM - ' . $purchaseRequest->pr_number,
                title: 'PR Returned by GM',
                messageText: 'General Manager has returned this purchase request to the requester for revision.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $validated['remarks']
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been returned to Requester.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to return PR to Requester. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    public function rejectToRequester(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userHasGmAccess($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only General Manager can reject this PR.');
        }

        if (! $this->purchaseRequestIsAtGmStep($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'This PR is no longer waiting for General Manager review.');
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
                'role_name' => $user->role ?? $user->role_name ?? null,
                'action' => 'rejected_by_gm',
                'from_status' => $fromStatus,
                'to_status' => 'rejected',
                'from_step' => $fromStep,
                'to_step' => 'requester',
                'remarks' => $validated['remarks'],
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control', 'purchasing'],
                subject: 'PR Rejected by GM - ' . $purchaseRequest->pr_number,
                title: 'PR Rejected by General Manager',
                messageText: 'General Manager has rejected this purchase request.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $validated['remarks']
            );

            return redirect()
                ->route('purchasing-lite.dashboard')
                ->with('success', 'PR has been rejected.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors([
                    'error' => 'Failed to reject PR. ' . $e->getMessage(),
                ])
                ->withInput();
        }
    }

    private function userHasGmAccess($user): bool
    {
        $role = $this->normalizeStep($user->role ?? $user->role_name ?? '');

        if (in_array($role, [
            'general_manager',
            'generalmanager',
        ], true)) {
            $role = 'gm';
        }

        return in_array($role, [
            'admin',
            'gm',
        ], true);
    }

    private function purchaseRequestIsAtGmStep(PurchaseRequest $purchaseRequest): bool
    {
        return $this->normalizeStep($purchaseRequest->current_step) === 'gm';
    }

    private function normalizeStep(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return str_replace(['-', ' '], '_', $value);
    }

    private function getSelectedVendorItems(PurchaseRequest $purchaseRequest): array
    {
        $selectedVendorItems = [];

        $validItemIds = $purchaseRequest->items
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values();

        foreach ($purchaseRequest->items as $item) {
            $selectedVendorItems[$item->id] = null;
        }

        foreach ($purchaseRequest->vendorOffers as $vendorOffer) {
            foreach ($vendorOffer->offerItems as $offerItem) {
                $itemId = (int) $offerItem->purchase_request_item_id;

                if (! $validItemIds->contains($itemId)) {
                    continue;
                }

                $isSelected = str_contains((string) $offerItem->notes, 'SELECTED_BY_COST_CONTROL');

                if (! $isSelected) {
                    continue;
                }

                $quantity = (float) ($offerItem->quantity ?: 0);
                $unitPrice = (float) ($offerItem->unit_price ?: 0);

                $selectedVendorItems[$itemId] = [
                    'item_id' => $itemId,
                    'offer_item_id' => $offerItem->id,
                    'vendor_offer_id' => $vendorOffer->id,
                    'vendor_id' => $vendorOffer->vendor_id,
                    'vendor_name' => $vendorOffer->vendor_name_snapshot ?: optional($vendorOffer->vendor)->name ?: '-',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $quantity * $unitPrice,
                    'notes' => $this->cleanSelectionNotes($offerItem->notes),
                ];
            }
        }

        return $selectedVendorItems;
    }

    private function allItemsHaveCostControlSelection(PurchaseRequest $purchaseRequest): bool
    {
        $requiredItemIds = $purchaseRequest->items
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values();

        if ($requiredItemIds->isEmpty()) {
            return false;
        }

        $selectedItemIds = $purchaseRequest->vendorOffers
            ->flatMap(function ($vendorOffer) {
                return $vendorOffer->offerItems;
            })
            ->filter(function ($offerItem) use ($requiredItemIds) {
                return $requiredItemIds->contains((int) $offerItem->purchase_request_item_id)
                    && str_contains((string) $offerItem->notes, 'SELECTED_BY_COST_CONTROL');
            })
            ->pluck('purchase_request_item_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($requiredItemIds as $itemId) {
            if (! $selectedItemIds->contains((int) $itemId)) {
                return false;
            }
        }

        return true;
    }

    private function cleanSelectionNotes(?string $notes): string
    {
        $notes = (string) $notes;

        $notes = str_replace('| SELECTED_BY_COST_CONTROL', '', $notes);
        $notes = str_replace('SELECTED_BY_COST_CONTROL |', '', $notes);
        $notes = str_replace('SELECTED_BY_COST_CONTROL', '', $notes);

        return trim($notes);
    }

    private function updateSelectedVendorItemQuantity(PurchaseRequest $purchaseRequest, int $itemId, float $quantity): bool
    {
        foreach ($purchaseRequest->vendorOffers as $vendorOffer) {
            foreach ($vendorOffer->offerItems as $offerItem) {
                if ((int) $offerItem->purchase_request_item_id !== $itemId) {
                    continue;
                }

                if (! str_contains((string) $offerItem->notes, 'SELECTED_BY_COST_CONTROL')) {
                    continue;
                }

                $offerItem->quantity = $quantity;
                $offerItem->save();

                if ($this->modelHasColumn($vendorOffer, 'offer_total')) {
                    $vendorOffer->offer_total = $vendorOffer->offerItems()
                        ->sum('total_price');
                    $vendorOffer->save();
                }

                return true;
            }
        }

        return false;
    }

    private function getPurchaseRequestNumber(PurchaseRequest $purchaseRequest): string
    {
        return (string) (
            $purchaseRequest->pr_number
            ?? $purchaseRequest->request_number
            ?? ('PR-' . $purchaseRequest->id)
        );
    }

    private function generateSplitPurchaseRequestNumber(PurchaseRequest $purchaseRequest, string $column): string
    {
        $baseNumber = (string) ($purchaseRequest->{$column} ?? $this->getPurchaseRequestNumber($purchaseRequest));

        $nextNumber = PurchaseRequest::query()
            ->where($column, 'like', $baseNumber . '-S%')
            ->count() + 1;

        do {
            $newNumber = $baseNumber . '-S' . $nextNumber;
            $exists = PurchaseRequest::query()
                ->where($column, $newNumber)
                ->exists();

            $nextNumber++;
        } while ($exists);

        return $newNumber;
    }

    private function modelHasColumn($model, string $column): bool
    {
        return Schema::connection($model->getConnectionName())
            ->hasColumn($model->getTable(), $column);
    }

    private function getOfferItemVendorOfferForeignKey($offerItem): ?string
    {
        $candidates = [
            'purchase_request_vendor_offer_id',
            'purchase_request_vendor_id',
            'vendor_offer_id',
            'offer_id',
        ];

        foreach ($candidates as $candidate) {
            if ($this->modelHasColumn($offerItem, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
