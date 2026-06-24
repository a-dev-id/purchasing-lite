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

        $purchaseRequest->load(['items', 'logs.user']);

        return view('purchasing-lite.purchase-requests.show', [
            'user' => $user,
            'purchaseRequest' => $purchaseRequest,
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
