<?php

namespace App\Http\Controllers\PurchasingLite;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseRequestLog;
use App\Services\PurchasingLite\PurchasingLiteEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseRequestController extends Controller
{
    public function create()
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanCreatePr($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to create a purchase request.');
        }

        return view('purchasing-lite.purchase-requests.create', [
            'user' => $user,
        ]);
    }

    public function store(Request $request)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanCreatePr($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to create a purchase request.');
        }

        $validated = $this->validatePrRequest($request);
        $items = $this->filledItems($validated);

        if ($items->isEmpty()) {
            return back()
                ->withErrors(['items' => 'Please add at least one item.'])
                ->withInput();
        }

        DB::beginTransaction();

        try {
            $purchaseRequest = PurchaseRequest::create([
                'pr_number' => $this->generatePrNumber(),
                'title' => $validated['title'],
                'requested_by' => $user->id,
                'requester_name' => $validated['requester_name'],
                'department_name' => $user->department_name ?? 'Unknown',
                'date_needed' => $validated['date_needed'] ?? null,
                'priority' => $validated['priority'] ?? 'regular',
                'status' => 'draft',
                'current_step' => 'requester',
                'requester_remarks' => $validated['requester_remarks'] ?? null,
                'current_status_at' => now(),
            ]);

            $this->saveItems($purchaseRequest, $request, $items, $validated);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'created_draft',
                'from_status' => null,
                'to_status' => 'draft',
                'from_step' => null,
                'to_step' => 'requester',
                'remarks' => 'Draft PR created.',
                'acted_at' => now(),
            ]);

            DB::commit();

            return redirect()
                ->route('purchasing-lite.purchase-requests.edit', $purchaseRequest)
                ->with('success', 'Draft PR has been saved. You can edit it before submitting.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors(['error' => 'Failed to save draft. ' . $e->getMessage()])
                ->withInput();
        }
    }

    public function edit(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditDraft($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to edit this purchase request.');
        }

        if (! $this->purchaseRequestIsEditableByRequester($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only draft or returned purchase requests can be edited.');
        }

        $purchaseRequest->load('items');

        return view('purchasing-lite.purchase-requests.edit', [
            'user' => $user,
            'purchaseRequest' => $purchaseRequest,
        ]);
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditDraft($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to update this purchase request.');
        }

        if (! $this->purchaseRequestIsEditableByRequester($purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'Only draft or returned purchase requests can be updated.');
        }

        $validated = $this->validatePrRequest($request);
        $items = $this->filledItems($validated);

        if ($items->isEmpty()) {
            return back()
                ->withErrors(['items' => 'Please add at least one item.'])
                ->withInput();
        }

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'title' => $validated['title'],
                'requester_name' => $validated['requester_name'],
                'date_needed' => $validated['date_needed'] ?? null,
                'priority' => $validated['priority'] ?? 'regular',
                'requester_remarks' => $validated['requester_remarks'] ?? null,
                'current_status_at' => now(),
            ]);

            $existingItems = $purchaseRequest->items()
                ->get()
                ->keyBy('id');

            $purchaseRequest->items()->delete();

            $this->saveItems($purchaseRequest, $request, $items, $validated, $existingItems);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => $fromStatus === 'draft' ? 'updated_draft' : 'updated_returned_pr',
                'from_status' => $fromStatus,
                'to_status' => $fromStatus,
                'from_step' => $fromStep,
                'to_step' => $fromStep,
                'remarks' => $fromStatus === 'draft'
                    ? 'Draft PR updated.'
                    : 'Returned PR updated by requester.',
                'acted_at' => now(),
            ]);

            DB::commit();

            return redirect()
                ->route('purchasing-lite.purchase-requests.edit', $purchaseRequest)
                ->with('success', 'Purchase request has been updated.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()
                ->withErrors(['error' => 'Failed to update purchase request. ' . $e->getMessage()])
                ->withInput();
        }
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanViewPr($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to view this purchase request.');
        }

        $purchaseRequest->load([
            'items',
            'logs.user',
            'vendorOffers.vendor',
            'vendorOffers.offerItems',
        ]);

        return view('purchasing-lite.purchase-requests.show', [
            'user' => $user,
            'purchaseRequest' => $purchaseRequest,
            'vendorBidOptions' => $this->getVendorBidOptionsForSummary($purchaseRequest),
        ]);
    }

    public function submit(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditDraft($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to submit this purchase request.');
        }

        if (! $this->purchaseRequestIsEditableByRequester($purchaseRequest)) {
            return redirect()
                ->route('purchasing-lite.purchase-requests.edit', $purchaseRequest)
                ->with('error', 'Only draft or returned purchase requests can be submitted.');
        }

        if ($purchaseRequest->items()->count() < 1) {
            return redirect()
                ->route('purchasing-lite.purchase-requests.edit', $purchaseRequest)
                ->with('error', 'Please add at least one item before submitting.');
        }

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $purchaseRequest->update([
                'status' => 'submitted_to_purchasing',
                'current_step' => 'purchasing',
                'submitted_at' => $purchaseRequest->submitted_at ?? now(),
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => $fromStatus === 'draft' ? 'submitted_to_purchasing' : 'resubmitted_to_purchasing',
                'from_status' => $fromStatus,
                'to_status' => 'submitted_to_purchasing',
                'from_step' => $fromStep,
                'to_step' => 'purchasing',
                'remarks' => $fromStatus === 'draft'
                    ? 'PR submitted to Purchasing.'
                    : 'Returned PR revised and resubmitted to Purchasing.',
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRoles(
                purchaseRequest: $purchaseRequest,
                roles: ['purchasing'],
                subject: 'New PR Submitted - ' . $purchaseRequest->pr_number,
                title: 'New PR Submitted to Purchasing',
                messageText: 'A purchase request has been submitted and needs vendor bids from Purchasing.',
                buttonLabel: 'Add Vendor Bids',
                buttonUrl: route('purchasing-lite.purchase-requests.vendors', $purchaseRequest),
                remarks: $purchaseRequest->requester_remarks
            );

            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'Purchase request has been submitted to Purchasing.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.edit', $purchaseRequest)
                ->with('error', 'Failed to submit purchase request. ' . $e->getMessage());
        }
    }

    public function destroy(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditDraft($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to delete this purchase request.');
        }

        if ((string) $purchaseRequest->status !== 'draft') {
            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Only draft purchase requests can be deleted.');
        }

        DB::beginTransaction();

        try {
            $purchaseRequest->delete();

            DB::commit();

            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'Draft purchase request has been deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Failed to delete purchase request. ' . $e->getMessage());
        }
    }

    public function markOnShipping(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanPurchasingFollowUp($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to update this purchase request.');
        }

        if ((string) $purchaseRequest->status !== 'paid_to_vendor') {
            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Only Paid to Vendor PR can be marked as On Shipping.');
        }

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;
            $remarks = 'PR marked as On Shipping by Purchasing.';

            $this->updatePurchaseRequestSafely($purchaseRequest, [
                'status' => 'on_shipping',
                'current_step' => 'purchasing',
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'marked_on_shipping',
                'from_status' => $fromStatus,
                'to_status' => 'on_shipping',
                'from_step' => $fromStep,
                'to_step' => 'purchasing',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control', 'purchasing'],
                subject: 'PR On Shipping - ' . $purchaseRequest->pr_number,
                title: 'PR Marked as On Shipping',
                messageText: 'Purchasing has marked this purchase request as On Shipping.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $remarks
            );

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('success', 'PR has been marked as On Shipping.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Failed to update PR. ' . $e->getMessage());
        }
    }

    public function generalPaymentSummary(Request $request)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanViewGeneralPaymentSummary($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to view the payment summary.');
        }

        $selectedPurchaseRequestIds = $this->selectedGeneralPaymentSummaryPurchaseRequestIds($request);
        $purchaseRequests = $this->generalPaymentSummaryPurchaseRequests($selectedPurchaseRequestIds)->get();
        $vendorBidOptions = [];

        foreach ($purchaseRequests as $purchaseRequest) {
            $vendorBidOptions[$purchaseRequest->id] = $this->getVendorBidOptionsForSummary($purchaseRequest);
        }

        return view('purchasing-lite.purchase-requests.payment-summary', [
            'user' => $user,
            'purchaseRequests' => $purchaseRequests,
            'vendorBidOptions' => $vendorBidOptions,
            'canEditPaymentSummary' => $this->userCanEditGeneralPaymentSummary($user),
            'selectedPurchaseRequestIds' => $selectedPurchaseRequestIds,
        ]);
    }

    public function saveGeneralPaymentSummary(Request $request)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditGeneralPaymentSummary($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to update this payment summary.');
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.selected_offer_item_id' => ['nullable', 'integer'],
            'items.*.unit_price' => ['nullable', 'string', 'max:100'],
            'items.*.payment_method' => ['nullable', 'string', 'in:cash,credit,transfer'],
            'items.*.payment_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $itemUpdates = $validated['items'] ?? [];
        $selectedPurchaseRequestIds = $this->selectedGeneralPaymentSummaryPurchaseRequestIds($request);
        $purchaseRequests = $this->generalPaymentSummaryPurchaseRequests($selectedPurchaseRequestIds)->get();

        DB::beginTransaction();

        try {
            foreach ($purchaseRequests as $purchaseRequest) {
                $prHadUpdates = false;

                foreach ($purchaseRequest->items as $item) {
                    if (! array_key_exists($item->id, $itemUpdates)) {
                        continue;
                    }

                    $update = $itemUpdates[$item->id] ?? [];
                    $selectedOfferItemId = (int) ($update['selected_offer_item_id'] ?? 0);

                    if ($selectedOfferItemId > 0) {
                        $selectedOfferItem = DB::table('purchase_request_vendor_offer_items as offer_items')
                            ->join('purchase_request_vendor_offers as offers', 'offers.id', '=', 'offer_items.purchase_request_vendor_offer_id')
                            ->where('offers.purchase_request_id', $purchaseRequest->id)
                            ->where('offer_items.purchase_request_item_id', $item->id)
                            ->where('offer_items.id', $selectedOfferItemId)
                            ->select([
                                'offer_items.id',
                                'offer_items.quantity',
                            ])
                            ->first();

                        if (! $selectedOfferItem) {
                            throw new \RuntimeException('Invalid vendor selection for item ' . $item->item_name . '.');
                        }

                        $unitPrice = $this->parseMoney((string) ($update['unit_price'] ?? ''));
                        $quantity = (float) ($selectedOfferItem->quantity ?? $item->quantity ?? 1);

                        $this->savePurchasingSummaryVendorSelection(
                            $purchaseRequest,
                            $item,
                            $selectedOfferItemId,
                            max(0, $unitPrice),
                            $quantity
                        );
                    }

                    $item->update([
                            'purchasing_payment_method' => $update['payment_method'] ?? null,
                            'purchasing_payment_note' => trim((string) ($update['payment_note'] ?? '')) ?: null,
                    ]);

                    $prHadUpdates = true;
                }

                if ($prHadUpdates) {
                    PurchaseRequestLog::create([
                        'purchase_request_id' => $purchaseRequest->id,
                        'user_id' => $user->id,
                        'role_name' => $user->role ?? null,
                        'action' => 'purchasing_saved_general_payment_summary',
                        'from_status' => $purchaseRequest->status,
                        'to_status' => $purchaseRequest->status,
                        'from_step' => $purchaseRequest->current_step,
                        'to_step' => $purchaseRequest->current_step,
                        'remarks' => 'Purchasing saved this PR from the general payment summary.',
                        'acted_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return redirect()
                ->route('purchasing-lite.purchasing.payment-summary', $this->generalPaymentSummaryRouteParams($selectedPurchaseRequestIds))
                ->with('success', 'General payment summary has been saved.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchasing.payment-summary', $this->generalPaymentSummaryRouteParams($selectedPurchaseRequestIds))
                ->with('error', 'Failed to save payment summary. ' . $e->getMessage());
        }
    }

    public function downloadGeneralPaymentSummaryPdf(Request $request)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanViewGeneralPaymentSummary($user)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to download this payment summary.');
        }

        $selectedPurchaseRequestIds = $this->selectedGeneralPaymentSummaryPurchaseRequestIds($request);
        $purchaseRequests = $this->generalPaymentSummaryPurchaseRequests($selectedPurchaseRequestIds)->get();
        $rows = [];
        $grandTotal = 0;
        $rowNumber = 1;

        foreach ($purchaseRequests as $purchaseRequest) {
            foreach ($purchaseRequest->items as $item) {
                $selectedVendorItem = $this->getSelectedVendorItemForSummary($purchaseRequest, $item);
                $totalPrice = (float) ($selectedVendorItem['total_price'] ?? 0);
                $grandTotal += $totalPrice;

                $rows[] = [
                    'no' => (string) $rowNumber,
                    'pr' => (string) ($purchaseRequest->pr_number ?? $purchaseRequest->id),
                    'item' => (string) $item->item_name,
                    'qty' => $this->formatQtyForSummary($item->quantity),
                    'vendor' => (string) ($selectedVendorItem['vendor_name'] ?? '-'),
                    'price' => $selectedVendorItem ? $this->formatRupiahForSummary($selectedVendorItem['unit_price']) : '-',
                    'total' => $selectedVendorItem ? $this->formatRupiahForSummary($totalPrice) : '-',
                    'total_value' => $totalPrice,
                    'payment' => $this->formatPaymentMethodForSummary($item->purchasing_payment_method ?? null),
                    'note' => (string) ($item->purchasing_payment_note ?: '-'),
                ];

                $rowNumber++;
            }
        }

        $pdf = $this->makeGeneralPaymentSummaryPdf($rows, $grandTotal);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="general-payment-summary.pdf"',
        ]);
    }

    public function savePaymentSummary(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditPurchasingPaymentSummary($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to update this payment summary.');
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.selected_offer_item_id' => ['nullable', 'integer'],
            'items.*.unit_price' => ['nullable', 'string', 'max:100'],
            'items.*.payment_method' => ['nullable', 'string', 'in:cash,credit,transfer'],
            'items.*.payment_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $itemUpdates = $validated['items'] ?? [];

        DB::beginTransaction();

        try {
            $purchaseRequest->load('items');

            foreach ($purchaseRequest->items as $item) {
                $update = $itemUpdates[$item->id] ?? [];
                $selectedOfferItemId = (int) ($update['selected_offer_item_id'] ?? 0);

                if ($selectedOfferItemId > 0) {
                    $selectedOfferItem = DB::table('purchase_request_vendor_offer_items as offer_items')
                        ->join('purchase_request_vendor_offers as offers', 'offers.id', '=', 'offer_items.purchase_request_vendor_offer_id')
                        ->where('offers.purchase_request_id', $purchaseRequest->id)
                        ->where('offer_items.purchase_request_item_id', $item->id)
                        ->where('offer_items.id', $selectedOfferItemId)
                        ->select([
                            'offer_items.id',
                            'offer_items.quantity',
                            'offer_items.notes',
                        ])
                        ->first();

                    if (! $selectedOfferItem) {
                        throw new \RuntimeException('Invalid vendor selection for item ' . $item->item_name . '.');
                    }

                    $unitPrice = $this->parseMoney((string) ($update['unit_price'] ?? ''));

                    if ($unitPrice < 0) {
                        $unitPrice = 0;
                    }

                    $quantity = (float) ($selectedOfferItem->quantity ?? $item->quantity ?? 1);

                    $this->savePurchasingSummaryVendorSelection(
                        $purchaseRequest,
                        $item,
                        $selectedOfferItemId,
                        $unitPrice,
                        $quantity
                    );
                }

                $item->update([
                    'purchasing_payment_method' => $update['payment_method'] ?? null,
                    'purchasing_payment_note' => trim((string) ($update['payment_note'] ?? '')) ?: null,
                ]);
            }

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'purchasing_saved_payment_summary',
                'from_status' => $purchaseRequest->status,
                'to_status' => $purchaseRequest->status,
                'from_step' => $purchaseRequest->current_step,
                'to_step' => $purchaseRequest->current_step,
                'remarks' => 'Purchasing saved vendor, price, payment method, and note summary.',
                'acted_at' => now(),
            ]);

            DB::commit();

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('success', 'Payment summary has been saved.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Failed to save payment summary. ' . $e->getMessage());
        }
    }

    public function downloadPaymentSummaryPdf(PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanEditPurchasingPaymentSummary($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to download this payment summary.');
        }

        $purchaseRequest->load('items');

        $rows = [];
        $grandTotal = 0;

        foreach ($purchaseRequest->items as $index => $item) {
            $selectedVendorItem = $this->getSelectedVendorItemForSummary($purchaseRequest, $item);
            $totalPrice = (float) ($selectedVendorItem['total_price'] ?? 0);
            $grandTotal += $totalPrice;

            $rows[] = [
                'no' => (string) ($index + 1),
                'item' => (string) $item->item_name,
                'qty' => $this->formatQtyForSummary($item->quantity),
                'unit' => (string) ($item->unit ?: '-'),
                'vendor' => (string) ($selectedVendorItem['vendor_name'] ?? '-'),
                'price' => $selectedVendorItem ? $this->formatRupiahForSummary($selectedVendorItem['unit_price']) : '-',
                'total' => $selectedVendorItem ? $this->formatRupiahForSummary($totalPrice) : '-',
                'payment' => $this->formatPaymentMethodForSummary($item->purchasing_payment_method ?? null),
                'note' => (string) ($item->purchasing_payment_note ?: '-'),
            ];
        }

        $pdf = $this->makePaymentSummaryPdf($purchaseRequest, $rows, $grandTotal);
        $fileName = 'payment-summary-' . Str::slug((string) ($purchaseRequest->pr_number ?? $purchaseRequest->id)) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public function markReceived(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanPurchasingFollowUp($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to update this purchase request.');
        }

        if (! in_array((string) $purchaseRequest->status, ['on_shipping', 'on_delivery'], true)) {
            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Only On Shipping PR can be marked as Received.');
        }

        $validated = $request->validate([
            'received_date' => ['required', 'date'],
            'received_remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $receivedDate = \Carbon\Carbon::parse($validated['received_date'])->startOfDay();
        $receivedRemarks = trim((string) ($validated['received_remarks'] ?? ''));

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $remarks = trim(
                'Received date: ' . $receivedDate->format('d M Y') .
                    ($receivedRemarks !== '' ? "\nRemarks: " . $receivedRemarks : '')
            );

            $this->updatePurchaseRequestSafely($purchaseRequest, [
                'status' => 'received',
                'current_step' => 'purchasing',
                'received_date' => $receivedDate->toDateString(),
                'received_at' => $receivedDate,
                'received_remarks' => $receivedRemarks,
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'marked_received',
                'from_status' => $fromStatus,
                'to_status' => 'received',
                'from_step' => $fromStep,
                'to_step' => 'purchasing',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control', 'purchasing'],
                subject: 'PR Received - ' . $purchaseRequest->pr_number,
                title: 'PR Item Received by Purchasing',
                messageText: 'Purchasing has marked this purchase request as Received.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $remarks
            );

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('success', 'PR has been marked as Received.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Failed to update PR. ' . $e->getMessage());
        }
    }

    public function markHandedOverToRequester(Request $request, PurchaseRequest $purchaseRequest)
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userCanPurchasingFollowUp($user, $purchaseRequest)) {
            return redirect('/purchasing-lite/dashboard')
                ->with('error', 'You are not allowed to update this purchase request.');
        }

        if ((string) $purchaseRequest->status !== 'received') {
            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Only Received PR can be handed over to requester.');
        }

        $validated = $request->validate([
            'handover_date' => ['required', 'date'],
            'handover_remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $handoverDate = \Carbon\Carbon::parse($validated['handover_date'])->startOfDay();
        $handoverRemarks = trim((string) ($validated['handover_remarks'] ?? ''));

        DB::beginTransaction();

        try {
            $fromStatus = $purchaseRequest->status;
            $fromStep = $purchaseRequest->current_step;

            $remarks = trim(
                'Hand over date: ' . $handoverDate->format('d M Y') .
                    ($handoverRemarks !== '' ? "\nRemarks: " . $handoverRemarks : '')
            );

            $this->updatePurchaseRequestSafely($purchaseRequest, [
                'status' => 'handed_over_to_requester',
                'current_step' => 'completed',
                'handover_date' => $handoverDate->toDateString(),
                'handed_over_at' => $handoverDate,
                'handover_remarks' => $handoverRemarks,
                'completed_at' => now(),
                'current_status_at' => now(),
            ]);

            PurchaseRequestLog::create([
                'purchase_request_id' => $purchaseRequest->id,
                'user_id' => $user->id,
                'role_name' => $user->role ?? null,
                'action' => 'handed_over_to_requester',
                'from_status' => $fromStatus,
                'to_status' => 'handed_over_to_requester',
                'from_step' => $fromStep,
                'to_step' => 'completed',
                'remarks' => $remarks,
                'acted_at' => now(),
            ]);

            DB::commit();

            app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
                purchaseRequest: $purchaseRequest,
                roles: ['cost_control', 'purchasing'],
                subject: 'PR Handed Over to Requester - ' . $purchaseRequest->pr_number,
                title: 'PR Handed Over to Requester',
                messageText: 'Purchasing has handed over this purchase request item to the requester. This PR is now completed.',
                buttonLabel: 'Open PR',
                buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
                remarks: $remarks
            );

            return redirect('/purchasing-lite/dashboard')
                ->with('success', 'PR has been handed over to requester and completed.');
        } catch (\Throwable $e) {
            DB::rollBack();

            return redirect()
                ->route('purchasing-lite.purchase-requests.show', $purchaseRequest)
                ->with('error', 'Failed to update PR. ' . $e->getMessage());
        }
    }

    public function searchItems(Request $request)
    {
        if (! Auth::check()) {
            return response()->json([]);
        }

        $query = trim((string) $request->get('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $items = Item::query()
            ->where('is_active', true)
            ->where('name', 'like', '%' . $query . '%')
            ->orderBy('name')
            ->limit(10)
            ->get([
                'id',
                'name',
                'default_specification',
                'default_unit',
                'image',
                'last_price',
                'currency',
            ]);

        return response()->json(
            $items->map(function (Item $item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'specification' => $item->default_specification,
                    'unit' => $item->default_unit,
                    'image' => $item->image,
                    'image_url' => $this->filePathIsImage($item->image) ? asset('storage/' . ltrim($item->image, '/')) : null,
                    'last_price' => $item->last_price,
                    'currency' => $item->currency,
                ];
            })
        );
    }

    private function validatePrRequest(Request $request): array
    {
        return $request->validate([
            'requester_name' => ['required', 'string', 'max:191'],
            'date_needed' => ['nullable', 'date'],
            'priority' => ['required', 'string', 'in:regular,important,urgent'],
            'title' => ['required', 'string', 'max:191'],
            'requester_remarks' => ['nullable', 'string'],

            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.item_name' => ['nullable', 'string', 'max:191'],
            'items.*.specification' => ['nullable', 'string'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.stock' => ['nullable', 'numeric', 'min:0'],

            'items.*.item_photos' => ['nullable', 'array'],
            'items.*.item_photos.*' => [
                'nullable',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    if ($this->uploadedFileIsImage($value) && $value->getSize() > 2 * 1024 * 1024) {
                        $fail('Each image must be 2 MB or smaller.');
                    }
                },
            ],

            'items.*.remove_photos' => ['nullable', 'array'],
            'items.*.remove_photos.*' => ['nullable', 'string'],
        ], [
            'items.*.item_photos.*.file' => 'Each upload must be a valid file.',
            'items.*.item_photos.*.max' => 'Each file must be 10 MB or smaller.',
        ]);
    }

    private function filledItems(array $validated)
    {
        return collect($validated['items'] ?? [])
            ->filter(function ($item) {
                return trim((string) ($item['item_name'] ?? '')) !== '';
            });
    }

    private function saveItems(
        PurchaseRequest $purchaseRequest,
        Request $request,
        $items,
        array $validated,
        $existingItems = null
    ): void {
        $sortOrder = 1;

        foreach ($items as $itemIndex => $item) {
            $itemName = trim((string) ($item['item_name'] ?? ''));
            $specification = trim((string) ($item['specification'] ?? ''));
            $unit = trim((string) ($item['unit'] ?? ''));
            $stock = isset($item['stock']) && $item['stock'] !== '' ? (float) $item['stock'] : null;
            $quantity = (float) ($item['quantity'] ?? 0);

            $itemPhotoPaths = [];
            $existingItemId = (int) ($item['id'] ?? 0);

            if ($existingItems && $existingItemId > 0 && $existingItems->has($existingItemId)) {
                $existingItem = $existingItems->get($existingItemId);

                if (is_array($existingItem->item_photos) && count($existingItem->item_photos)) {
                    $itemPhotoPaths = $existingItem->item_photos;
                } elseif (! empty($existingItem->item_photo)) {
                    $itemPhotoPaths = [$existingItem->item_photo];
                }
            }

            $itemPhotoPaths = $this->normalizePhotoPaths($itemPhotoPaths);
            $removePhotoPaths = $this->normalizePhotoPaths($item['remove_photos'] ?? []);

            if (! empty($removePhotoPaths)) {
                $itemPhotoPaths = array_values(array_filter($itemPhotoPaths, function ($photoPath) use ($removePhotoPaths) {
                    return ! in_array($photoPath, $removePhotoPaths, true);
                }));
            }

            if ($request->hasFile("items.$itemIndex.item_photos")) {
                foreach ($request->file("items.$itemIndex.item_photos") as $photo) {
                    if ($photo) {
                        $itemPhotoPaths[] = $this->storeItemAttachment($photo);
                    }
                }
            }

            $itemPhotoPaths = $this->normalizePhotoPaths($itemPhotoPaths);
            $itemPhotoPath = $itemPhotoPaths[0] ?? null;

            $masterItem = Item::whereRaw('LOWER(TRIM(name)) = ?', [
                strtolower($itemName),
            ])->first();

            if (! $masterItem) {
                Item::create([
                    'name' => $itemName,
                    'default_specification' => $specification ?: null,
                    'default_unit' => $unit ?: null,
                    'image' => $itemPhotoPath,
                    'currency' => 'IDR',
                    'is_active' => true,
                ]);
            } else {
                $updates = [];

                if (empty($masterItem->default_specification) && $specification !== '') {
                    $updates['default_specification'] = $specification;
                }

                if (empty($masterItem->default_unit) && $unit !== '') {
                    $updates['default_unit'] = $unit;
                }

                if (empty($masterItem->image) && $itemPhotoPath) {
                    $updates['image'] = $itemPhotoPath;
                }

                if (! empty($updates)) {
                    $masterItem->update($updates);
                }
            }

            PurchaseRequestItem::create([
                'purchase_request_id' => $purchaseRequest->id,
                'sort_order' => $sortOrder,
                'item_name' => $itemName,
                'specification' => $specification ?: null,
                'quantity' => $quantity > 0 ? $quantity : 1,
                'unit' => $unit ?: null,
                'stock' => $stock,
                'item_photo' => $itemPhotoPath,
                'item_photos' => $itemPhotoPaths,
                'needed_date' => $validated['date_needed'] ?? null,
                'estimated_unit_price' => null,
                'estimated_total_price' => null,
                'requester_remarks' => null,
                'gm_status' => 'pending',
            ]);

            $sortOrder++;
        }
    }

    private function normalizePhotoPaths($photoPaths): array
    {
        if (! is_array($photoPaths)) {
            $photoPaths = [$photoPaths];
        }

        return array_values(array_filter(array_unique(array_map(function ($photoPath) {
            return ltrim(trim((string) $photoPath), '/');
        }, $photoPaths))));
    }

    private function storeItemAttachment($file): string
    {
        if ($this->uploadedFileIsImage($file)) {
            return $file->store('purchase-request-items', 'public');
        }

        return $file->storeAs(
            'purchase-request-items/files/' . Str::uuid()->toString(),
            $this->cleanOriginalFileName($file->getClientOriginalName()),
            'public'
        );
    }

    private function uploadedFileIsImage($file): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/');
    }

    private function cleanOriginalFileName(?string $fileName): string
    {
        $fileName = trim((string) $fileName);
        $fileName = str_replace(['/', '\\'], '-', $fileName);
        $fileName = preg_replace('/[\x00-\x1F\x7F]+/', '', $fileName) ?: 'file';

        return substr($fileName, 0, 180);
    }

    private function filePathIsImage(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'bmp',
            'svg',
        ], true);
    }

    private function requesterEditableStatuses(): array
    {
        return [
            'draft',
            'revision_to_requester_from_purchasing',
            'revision_from_purchasing',
        ];
    }

    private function purchaseRequestIsEditableByRequester(PurchaseRequest $purchaseRequest): bool
    {
        return in_array((string) $purchaseRequest->status, $this->requesterEditableStatuses(), true)
            && (string) $purchaseRequest->current_step === 'requester';
    }

    private function userCanCreatePr($user): bool
    {
        $role = $this->normalizedUserRole($user);

        return $role === 'admin'
            || $role === 'requester'
            || str_contains($role, 'requester')
            || in_array($role, [
                'it',
                'housekeeping',
                'housekeeping & garden',
                'sales',
                'sales & marketing',
                'spa',
                'essence spa',
            ], true);
    }

    private function userCanEditDraft($user, PurchaseRequest $purchaseRequest): bool
    {
        $role = $this->normalizedUserRole($user);

        if ($role === 'admin') {
            return true;
        }

        if (
            $role === 'requester'
            || str_contains($role, 'requester')
            || in_array($role, [
                'it',
                'housekeeping',
                'housekeeping & garden',
                'sales',
                'sales & marketing',
                'spa',
                'essence spa',
            ], true)
        ) {
            return (int) $purchaseRequest->requested_by === (int) $user->id;
        }

        return false;
    }

    private function userCanPurchasingFollowUp($user, PurchaseRequest $purchaseRequest): bool
    {
        $role = $this->normalizedUserRole($user);

        if ($role === 'admin') {
            return true;
        }

        return $role === 'purchasing'
            && (string) $purchaseRequest->current_step === 'purchasing'
            && in_array((string) $purchaseRequest->status, [
                'paid_to_vendor',
                'on_shipping',
                'on_delivery',
                'received',
            ], true);
    }

    private function userCanEditPurchasingPaymentSummary($user, PurchaseRequest $purchaseRequest): bool
    {
        $role = $this->normalizedUserRole($user);

        if ($role === 'admin') {
            return true;
        }

        return $role === 'purchasing'
            && (string) $purchaseRequest->status === 'on_progress';
    }

    private function userCanViewGeneralPaymentSummary($user): bool
    {
        $role = $this->normalizedUserRole($user);

        return in_array($role, [
            'admin',
            'purchasing',
            'financial controller',
            'financialcontroller',
            'fc',
            'accounting',
        ], true);
    }

    private function userCanEditGeneralPaymentSummary($user): bool
    {
        $role = $this->normalizedUserRole($user);

        return in_array($role, ['admin', 'purchasing'], true);
    }

    private function generalPaymentSummaryPurchaseRequests(array $selectedPurchaseRequestIds = [])
    {
        $query = PurchaseRequest::query()
            ->with([
                'items' => function ($query) {
                    $query->orderBy('sort_order')->orderBy('id');
                },
                'vendorOffers.vendor',
                'vendorOffers.offerItems',
            ])
            ->where('status', 'on_progress')
            ->latest('id');

        if (! empty($selectedPurchaseRequestIds)) {
            $query->whereIn('id', $selectedPurchaseRequestIds);
        }

        return $query;
    }

    private function selectedGeneralPaymentSummaryPurchaseRequestIds(Request $request): array
    {
        $selectedIds = $request->input('purchase_request_ids', []);

        if (! is_array($selectedIds)) {
            $selectedIds = [$selectedIds];
        }

        return collect($selectedIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function generalPaymentSummaryRouteParams(array $selectedPurchaseRequestIds): array
    {
        return empty($selectedPurchaseRequestIds)
            ? []
            : ['purchase_request_ids' => $selectedPurchaseRequestIds];
    }

    private function userCanViewPr($user, PurchaseRequest $purchaseRequest): bool
    {
        $role = $this->normalizedUserRole($user);

        $roleStep = str_replace(' ', '_', $role);

        $currentStep = strtolower(trim((string) $purchaseRequest->current_step));
        $currentStep = str_replace(['-', ' '], '_', $currentStep);

        if ($role === 'admin') {
            return true;
        }

        if (in_array($role, [
            'financial controller',
            'financialcontroller',
            'fc',
        ], true)) {
            return true;
        }

        if ($role === 'purchasing' && (string) $purchaseRequest->status === 'on_progress') {
            return true;
        }

        if (
            $role === 'requester'
            || str_contains($role, 'requester')
            || in_array($role, [
                'it',
                'housekeeping',
                'housekeeping & garden',
                'sales',
                'sales & marketing',
                'spa',
                'essence spa',
            ], true)
        ) {
            return (int) $purchaseRequest->requested_by === (int) $user->id;
        }

        return $currentStep === $roleStep;
    }

    private function updatePurchaseRequestSafely(PurchaseRequest $purchaseRequest, array $values): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($purchaseRequest->getTable());

        foreach ($values as $column => $value) {
            if (in_array($column, $columns, true)) {
                $purchaseRequest->{$column} = $value;
            }
        }

        $purchaseRequest->save();
    }

    private function normalizedUserRole($user): string
    {
        $role = strtolower((string) ($user->role ?? $user->role_name ?? ''));

        return str_replace(['-', '_'], ' ', trim($role));
    }

    private function getVendorBidOptionsForSummary(PurchaseRequest $purchaseRequest): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('purchase_request_vendor_offer_items')) {
            return [];
        }

        $rows = DB::table('purchase_request_vendor_offer_items as offer_items')
            ->join('purchase_request_vendor_offers as offers', 'offers.id', '=', 'offer_items.purchase_request_vendor_offer_id')
            ->leftJoin('vendors', 'vendors.id', '=', 'offers.vendor_id')
            ->where('offers.purchase_request_id', $purchaseRequest->id)
            ->select([
                'offer_items.id as offer_item_id',
                'offer_items.purchase_request_item_id',
                'offer_items.unit_price',
                'offer_items.quantity',
                'offer_items.total_price',
                'offer_items.notes',
                'offers.id as vendor_offer_id',
                'offers.vendor_name_snapshot',
                'offers.is_selected_by_cost_control',
                'vendors.name as vendor_name',
            ])
            ->orderBy('offer_items.purchase_request_item_id')
            ->orderBy('offer_items.id')
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $itemId = (int) $row->purchase_request_item_id;
            $unitPrice = (float) ($row->unit_price ?? 0);
            $quantity = (float) ($row->quantity ?? 0);
            $totalPrice = $row->total_price !== null
                ? (float) $row->total_price
                : $unitPrice * $quantity;

            $bidNumber = null;

            if (preg_match('/Bid\s+([1-3])/i', (string) $row->notes, $matches)) {
                $bidNumber = (int) $matches[1];
            }

            $options[$itemId][] = [
                'offer_item_id' => (int) $row->offer_item_id,
                'vendor_offer_id' => (int) $row->vendor_offer_id,
                'vendor_name' => $row->vendor_name_snapshot ?: ($row->vendor_name ?? '-'),
                'bid_number' => $bidNumber,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'total_price' => $totalPrice,
                'is_selected' => str_contains((string) $row->notes, 'SELECTED_BY_COST_CONTROL'),
                'offer_is_selected' => (bool) $row->is_selected_by_cost_control,
            ];
        }

        foreach ($options as $itemId => $itemOptions) {
            $fallbackBidNumber = 1;

            foreach ($itemOptions as $index => $itemOption) {
                if (empty($itemOptions[$index]['bid_number'])) {
                    $itemOptions[$index]['bid_number'] = $fallbackBidNumber;
                }

                $fallbackBidNumber++;
            }

            $hasSelectedOption = collect($itemOptions)->contains(fn($option) => (bool) $option['is_selected']);

            if (! $hasSelectedOption) {
                for ($index = count($itemOptions) - 1; $index >= 0; $index--) {
                    if (! empty($itemOptions[$index]['offer_is_selected'])) {
                        $itemOptions[$index]['is_selected'] = true;
                        break;
                    }
                }
            }

            usort($itemOptions, function ($a, $b) {
                return (($a['bid_number'] ?? 99) <=> ($b['bid_number'] ?? 99))
                    ?: ((float) $a['unit_price'] <=> (float) $b['unit_price']);
            });

            $options[$itemId] = $itemOptions;
        }

        return $options;
    }

    private function savePurchasingSummaryVendorSelection(
        PurchaseRequest $purchaseRequest,
        PurchaseRequestItem $item,
        int $selectedOfferItemId,
        float $unitPrice,
        float $quantity
    ): void {
        $offerItems = DB::table('purchase_request_vendor_offer_items as offer_items')
            ->join('purchase_request_vendor_offers as offers', 'offers.id', '=', 'offer_items.purchase_request_vendor_offer_id')
            ->where('offers.purchase_request_id', $purchaseRequest->id)
            ->where('offer_items.purchase_request_item_id', $item->id)
            ->select([
                'offer_items.id',
                'offer_items.notes',
            ])
            ->get();

        foreach ($offerItems as $offerItem) {
            $notes = $this->removeSelectedVendorMarker($offerItem->notes);

            if ((int) $offerItem->id === $selectedOfferItemId) {
                $notes = trim($notes);
                $notes = $notes === ''
                    ? 'SELECTED_BY_COST_CONTROL'
                    : $notes . ' | SELECTED_BY_COST_CONTROL';
            }

            DB::table('purchase_request_vendor_offer_items')
                ->where('id', $offerItem->id)
                ->update([
                    'notes' => $notes,
                    'updated_at' => now(),
                ]);
        }

        DB::table('purchase_request_vendor_offer_items')
            ->where('id', $selectedOfferItemId)
            ->update([
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'total_price' => $unitPrice * $quantity,
                'updated_at' => now(),
            ]);

        $vendorOfferIds = DB::table('purchase_request_vendor_offers')
            ->where('purchase_request_id', $purchaseRequest->id)
            ->pluck('id');

        foreach ($vendorOfferIds as $vendorOfferId) {
            $offerTotal = (float) DB::table('purchase_request_vendor_offer_items')
                ->where('purchase_request_vendor_offer_id', $vendorOfferId)
                ->sum('total_price');

            $hasSelectedItem = DB::table('purchase_request_vendor_offer_items')
                ->where('purchase_request_vendor_offer_id', $vendorOfferId)
                ->where('notes', 'like', '%SELECTED_BY_COST_CONTROL%')
                ->exists();

            DB::table('purchase_request_vendor_offers')
                ->where('id', $vendorOfferId)
                ->update([
                    'offer_total' => $offerTotal,
                    'is_selected_by_cost_control' => $hasSelectedItem,
                    'updated_at' => now(),
                ]);
        }
    }

    private function removeSelectedVendorMarker(?string $notes): string
    {
        $notes = (string) $notes;
        $notes = str_replace('| SELECTED_BY_COST_CONTROL', '', $notes);
        $notes = str_replace('SELECTED_BY_COST_CONTROL |', '', $notes);
        $notes = str_replace('SELECTED_BY_COST_CONTROL', '', $notes);

        return trim($notes);
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

    private function getSelectedVendorItemForSummary(PurchaseRequest $purchaseRequest, PurchaseRequestItem $item): ?array
    {
        $options = $this->getVendorBidOptionsForSummary($purchaseRequest);
        $itemOptions = $options[$item->id] ?? [];
        $selectedOption = collect($itemOptions)->first(fn($option) => (bool) ($option['is_selected'] ?? false));

        if (! $selectedOption) {
            return null;
        }

        return [
            'vendor_name' => $selectedOption['vendor_name'] ?? '-',
            'unit_price' => (float) ($selectedOption['unit_price'] ?? 0),
            'quantity' => (float) ($selectedOption['quantity'] ?? $item->quantity ?? 0),
            'total_price' => (float) ($selectedOption['total_price'] ?? 0),
        ];
    }

    private function getSelectedVendorKeyForSummary(PurchaseRequest $purchaseRequest, PurchaseRequestItem $item): string
    {
        $selectedVendorItem = $this->getSelectedVendorItemForSummary($purchaseRequest, $item);

        return strtolower(trim((string) ($selectedVendorItem['vendor_name'] ?? '')));
    }

    private function formatRupiahForSummary($value): string
    {
        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }

    private function formatQtyForSummary($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function formatPaymentMethodForSummary(?string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'credit' => 'Credit',
            'transfer' => 'Transfer',
            default => '-',
        };
    }

    private function makePaymentSummaryPdf(PurchaseRequest $purchaseRequest, array $rows, float $grandTotal): string
    {
        $pageWidth = 842;
        $pageHeight = 595;
        $left = 30;
        $top = 555;
        $content = "0.2 w\n";

        $content .= $this->pdfText('Payment Summary', $left, $top, 14, true);
        $content .= $this->pdfText('PR Number: ' . ($purchaseRequest->pr_number ?? '-'), $left, $top - 22, 9);
        $content .= $this->pdfText('Title: ' . ($purchaseRequest->title ?? '-'), $left, $top - 36, 9);
        $content .= $this->pdfText('Requester: ' . ($purchaseRequest->requester_name ?? '-'), $left + 300, $top - 22, 9);
        $content .= $this->pdfText('Department: ' . ($purchaseRequest->department_name ?? '-'), $left + 300, $top - 36, 9);
        $content .= $this->pdfText('Date Needed: ' . ($purchaseRequest->date_needed ? \Carbon\Carbon::parse($purchaseRequest->date_needed)->format('d M Y') : '-'), $left + 560, $top - 22, 9);

        $columns = [
            ['key' => 'no', 'label' => 'No', 'width' => 28, 'align' => 'center'],
            ['key' => 'item', 'label' => 'Item', 'width' => 150, 'align' => 'left'],
            ['key' => 'qty', 'label' => 'Qty', 'width' => 38, 'align' => 'right'],
            ['key' => 'unit', 'label' => 'Unit', 'width' => 45, 'align' => 'left'],
            ['key' => 'vendor', 'label' => 'Vendor', 'width' => 145, 'align' => 'left'],
            ['key' => 'price', 'label' => 'Price / Unit', 'width' => 90, 'align' => 'right'],
            ['key' => 'total', 'label' => 'Total', 'width' => 90, 'align' => 'right'],
            ['key' => 'payment', 'label' => 'Payment', 'width' => 70, 'align' => 'left'],
            ['key' => 'note', 'label' => 'Note', 'width' => 126, 'align' => 'left'],
        ];

        $x = $left;
        $y = $top - 70;
        $headerHeight = 24;
        $rowHeight = 34;

        $content .= "0.94 0.96 0.98 rg\n";
        $content .= $this->pdfRect($x, $y - $headerHeight, array_sum(array_column($columns, 'width')), $headerHeight, true);
        $content .= "0 0 0 RG\n0 g\n";

        $columnX = $x;

        foreach ($columns as $column) {
            $content .= $this->pdfRect($columnX, $y - $headerHeight, $column['width'], $headerHeight);
            $content .= $this->pdfText($column['label'], $columnX + 4, $y - 15, 8, true);
            $columnX += $column['width'];
        }

        $y -= $headerHeight;

        foreach ($rows as $row) {
            if ($y - $rowHeight < 45) {
                break;
            }

            $columnX = $x;

            foreach ($columns as $column) {
                $width = $column['width'];
                $value = (string) ($row[$column['key']] ?? '-');
                $content .= $this->pdfRect($columnX, $y - $rowHeight, $width, $rowHeight);

                $textX = $columnX + 4;

                if ($column['align'] === 'right') {
                    $textX = $columnX + $width - 4 - min(strlen($value) * 4.2, $width - 8);
                } elseif ($column['align'] === 'center') {
                    $textX = $columnX + max(4, ($width - strlen($value) * 4.2) / 2);
                }

                $wrappedLines = $this->wrapPdfLine($value, max(8, (int) floor($width / 5)));
                $wrappedLines = array_slice($wrappedLines, 0, 2);
                $textY = $y - 13;

                foreach ($wrappedLines as $wrappedLine) {
                    $content .= $this->pdfText($wrappedLine, $textX, $textY, 7);
                    $textY -= 10;
                }

                $columnX += $width;
            }

            $y -= $rowHeight;
        }

        $totalWidth = array_sum(array_column($columns, 'width'));
        $grandTotalHeight = 28;
        $content .= "0.94 0.96 0.98 rg\n";
        $content .= $this->pdfRect($x, $y - $grandTotalHeight, $totalWidth, $grandTotalHeight, true);
        $content .= "0 0 0 RG\n0 g\n";
        $content .= $this->pdfRect($x, $y - $grandTotalHeight, $totalWidth - 126, $grandTotalHeight);
        $content .= $this->pdfRect($x + $totalWidth - 126, $y - $grandTotalHeight, 126, $grandTotalHeight);
        $content .= $this->pdfText('Grand Total', $x + $totalWidth - 220, $y - 17, 9, true);
        $content .= $this->pdfText($this->formatRupiahForSummary($grandTotal), $x + $totalWidth - 112, $y - 17, 9, true);

        return $this->buildPdf($content, $pageWidth, $pageHeight);
    }

    private function makeGeneralPaymentSummaryPdf(array $rows, float $grandTotal): string
    {
        $pageWidth = 842;
        $pageHeight = 595;
        $left = 24;
        $top = 555;
        $bottom = 45;
        $pages = [];
        $content = '';

        $columns = [
            ['key' => 'no', 'label' => 'No', 'width' => 25, 'align' => 'center'],
            ['key' => 'pr', 'label' => 'PR Number', 'width' => 105, 'align' => 'left'],
            ['key' => 'item', 'label' => 'Item', 'width' => 145, 'align' => 'left'],
            ['key' => 'qty', 'label' => 'Qty', 'width' => 36, 'align' => 'right'],
            ['key' => 'vendor', 'label' => 'Vendor', 'width' => 130, 'align' => 'left'],
            ['key' => 'price', 'label' => 'Price / Unit', 'width' => 88, 'align' => 'right'],
            ['key' => 'total', 'label' => 'Total', 'width' => 88, 'align' => 'right'],
            ['key' => 'payment', 'label' => 'Payment', 'width' => 68, 'align' => 'left'],
            ['key' => 'note', 'label' => 'Note', 'width' => 110, 'align' => 'left'],
        ];

        $x = $left;
        $y = 0;
        $headerHeight = 24;
        $rowHeight = 32;
        $totalWidth = array_sum(array_column($columns, 'width'));
        $generatedAt = now()->format('d M Y H:i');

        $grandTotalHeight = 28;
        $totalColumnX = $x;
        $totalColumnWidth = 0;

        foreach ($columns as $column) {
            if ($column['key'] === 'total') {
                $totalColumnWidth = $column['width'];
                break;
            }

            $totalColumnX += $column['width'];
        }

        $afterTotalWidth = $totalWidth - (($totalColumnX - $x) + $totalColumnWidth);

        $drawHeader = function () use (&$content, &$y, $left, $top, $x, $columns, $headerHeight, $totalWidth, $generatedAt) {
            $content .= "0.2 w\n";
            $content .= $this->pdfText('General Payment Summary', $left, $top, 14, true);
            $content .= $this->pdfText('Generated: ' . $generatedAt, $left, $top - 22, 9);

            $y = $top - 50;
            $content .= "0.94 0.96 0.98 rg\n";
            $content .= $this->pdfRect($x, $y - $headerHeight, $totalWidth, $headerHeight, true);
            $content .= "0 0 0 RG\n0 g\n";

            $columnX = $x;

            foreach ($columns as $column) {
                $content .= $this->pdfRect($columnX, $y - $headerHeight, $column['width'], $headerHeight);
                $content .= $this->pdfText($column['label'], $columnX + 4, $y - 15, 8, true);
                $columnX += $column['width'];
            }

            $y -= $headerHeight;
        };

        $startPage = function () use (&$pages, &$content, $drawHeader) {
            if ($content !== '') {
                $pages[] = $content;
            }

            $content = '';
            $drawHeader();
        };

        $ensureSpace = function (float $height) use (&$y, $bottom, $startPage) {
            if ($y - $height < $bottom) {
                $startPage();
            }
        };

        $drawCellText = function (array $column, string $value, float $columnX, float $cellTopY) use (&$content) {
            $width = $column['width'];
            $textX = $columnX + 4;

            if ($column['align'] === 'right') {
                $textX = $columnX + $width - 4 - min(strlen($value) * 4.2, $width - 8);
            } elseif ($column['align'] === 'center') {
                $textX = $columnX + max(4, ($width - strlen($value) * 4.2) / 2);
            }

            $wrappedLines = array_slice($this->wrapPdfLine($value, max(8, (int) floor($width / 5))), 0, 2);
            $textY = $cellTopY - 12;

            foreach ($wrappedLines as $wrappedLine) {
                $content .= $this->pdfText($wrappedLine, $textX, $textY, 7);
                $textY -= 10;
            }
        };

        $drawTotalRow = function (string $label, float $amount) use (&$content, &$y, $x, $totalWidth, $grandTotalHeight, $totalColumnX, $totalColumnWidth, $afterTotalWidth) {
            $totalText = $this->formatRupiahForSummary($amount);
            $content .= "0.94 0.96 0.98 rg\n";
            $content .= $this->pdfRect($x, $y - $grandTotalHeight, $totalWidth, $grandTotalHeight, true);
            $content .= "0 0 0 RG\n0 g\n";
            $content .= $this->pdfRect($x, $y - $grandTotalHeight, $totalColumnX - $x, $grandTotalHeight);
            $content .= $this->pdfRect($totalColumnX, $y - $grandTotalHeight, $totalColumnWidth, $grandTotalHeight);

            if ($afterTotalWidth > 0) {
                $content .= $this->pdfRect($totalColumnX + $totalColumnWidth, $y - $grandTotalHeight, $afterTotalWidth, $grandTotalHeight);
            }

            $content .= $this->pdfText($label, $totalColumnX - 92, $y - 17, 9, true);
            $content .= $this->pdfText($totalText, $totalColumnX + $totalColumnWidth - 4 - min(strlen($totalText) * 4.8, $totalColumnWidth - 8), $y - 17, 9, true);
            $y -= $grandTotalHeight;
        };

        $paymentCategories = [
            'Cash' => collect($rows)->filter(fn($row) => ($row['payment'] ?? '-') === 'Cash')->values(),
            'Credit' => collect($rows)->filter(fn($row) => ($row['payment'] ?? '-') === 'Credit')->values(),
            'Transfer' => collect($rows)->filter(fn($row) => ($row['payment'] ?? '-') === 'Transfer')->values(),
            'No Payment Method' => collect($rows)->filter(fn($row) => ! in_array(($row['payment'] ?? '-'), ['Cash', 'Credit', 'Transfer'], true))->values(),
        ];

        $startPage();

        foreach ($paymentCategories as $paymentCategoryLabel => $categoryRows) {
            if ($categoryRows->isEmpty()) {
                continue;
            }

            $categoryTotal = (float) $categoryRows->sum(fn($row) => (float) ($row['total_value'] ?? 0));
            $categoryHeaderHeight = 20;
            $ensureSpace($categoryHeaderHeight);

            $content .= "0.90 0.93 0.97 rg\n";
            $content .= $this->pdfRect($x, $y - $categoryHeaderHeight, $totalWidth, $categoryHeaderHeight, true);
            $content .= "0 0 0 RG\n0 g\n";
            $content .= $this->pdfRect($x, $y - $categoryHeaderHeight, $totalWidth, $categoryHeaderHeight);
            $content .= $this->pdfText($paymentCategoryLabel, $x + 6, $y - 13, 9, true);
            $y -= $categoryHeaderHeight;

            foreach ($categoryRows as $row) {
                $ensureSpace($rowHeight);
                $columnX = $x;

                foreach ($columns as $column) {
                    $width = $column['width'];
                    $value = (string) ($row[$column['key']] ?? '-');
                    $content .= $this->pdfRect($columnX, $y - $rowHeight, $width, $rowHeight);
                    $drawCellText($column, $value, $columnX, $y);
                    $columnX += $width;
                }

                $y -= $rowHeight;
            }

            $ensureSpace($grandTotalHeight);
            $drawTotalRow($paymentCategoryLabel . ' Total', $categoryTotal);
        }

        $ensureSpace($grandTotalHeight);
        $drawTotalRow('Grand Total', $grandTotal);

        if ($content !== '') {
            $pages[] = $content;
        }

        return $this->buildPdfPages($pages, $pageWidth, $pageHeight);
    }

    private function pdfText(string $text, float $x, float $y, int $size = 8, bool $bold = false): string
    {
        $font = $bold ? 'F2' : 'F1';

        return "BT\n/{$font} {$size} Tf\n1 0 0 1 {$x} {$y} Tm\n(" . $this->escapePdfText($text) . ") Tj\nET\n";
    }

    private function pdfRect(float $x, float $y, float $width, float $height, bool $fill = false): string
    {
        return "{$x} {$y} {$width} {$height} re " . ($fill ? "f\n" : "S\n");
    }

    private function buildPdf(string $content, int $pageWidth, int $pageHeight): string
    {
        return $this->buildPdfPages([$content], $pageWidth, $pageHeight);
    }

    private function buildPdfPages(array $pages, int $pageWidth, int $pageHeight): string
    {
        $objects = [];
        $pageCount = count($pages);
        $kids = [];

        foreach ($pages as $index => $pageContent) {
            $kids[] = (5 + ($index * 2)) . ' 0 R';
        }

        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$pageCount} >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        foreach ($pages as $index => $pageContent) {
            $contentObjectNumber = 6 + ($index * 2);

            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentObjectNumber} 0 R >>";
            $objects[] = "<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function wrapPdfLine(string $line, int $length): array
    {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        if ($line === '') {
            return [''];
        }

        return explode("\n", wordwrap($line, $length, "\n", true));
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function generatePrNumber(): string
    {
        $timestamp = now()->format('Ymd-His');
        $prefix = 'PR-' . $timestamp . '-';

        $existingPrNumbers = PurchaseRequest::query()
            ->where('pr_number', 'like', $prefix . '%')
            ->pluck('pr_number');

        $latestNumber = 0;

        foreach ($existingPrNumbers as $prNumber) {
            $prNumber = (string) $prNumber;

            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{4})$/', $prNumber, $matches)) {
                $number = (int) $matches[1];

                if ($number > $latestNumber) {
                    $latestNumber = $number;
                }
            }
        }

        $nextNumber = $latestNumber + 1;

        do {
            $newPrNumber = $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);

            if (! PurchaseRequest::where('pr_number', $newPrNumber)->exists()) {
                return $newPrNumber;
            }

            $nextNumber++;
        } while (true);
    }
}
