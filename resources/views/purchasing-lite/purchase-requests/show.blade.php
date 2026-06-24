@extends('layouts.purchasing-lite')

@section('title', $purchaseRequest->pr_number . ' - Purchasing Lite')

@section('content')
@php
$user = auth()->user();

$userRole = strtolower((string) ($user->role ?? $user->role_name ?? ''));
$normalizedRole = str_replace(['-', '_'], ' ', $userRole);

$isPurchasingAccount =
$normalizedRole === 'purchasing'
|| $normalizedRole === 'admin'
|| (
$user
&& method_exists($user, 'hasRole')
&& (
$user->hasRole('purchasing')
|| $user->hasRole('admin')
)
);

$currentStatus = strtolower((string) ($purchaseRequest->status ?? ''));
$currentStep = strtolower((string) ($purchaseRequest->current_step ?? ''));

$canPurchasingFollowUp =
$isPurchasingAccount
&& $currentStep === 'purchasing'
&& in_array($currentStatus, [
'paid_to_vendor',
'on_shipping',
'on_delivery',
'received',
], true);

$nextPurchasingAction = null;

if ($canPurchasingFollowUp && $currentStatus === 'paid_to_vendor') {
$nextPurchasingAction = [
'label' => 'On Shipping',
'type' => 'form',
'route' => route('purchasing-lite.purchase-requests.purchasing.on-shipping', $purchaseRequest),
'confirm' => 'Mark this PR as On Shipping?',
'class' => 'bg-blue-700 text-white hover:bg-blue-800',
'description' => 'Use this when the vendor has started sending or delivering the item.',
];
} elseif ($canPurchasingFollowUp && in_array($currentStatus, ['on_shipping', 'on_delivery'], true)) {
$nextPurchasingAction = [
'label' => 'Received',
'type' => 'modal',
'modal_id' => 'received-modal',
'class' => 'bg-green-700 text-white hover:bg-green-800',
'description' => 'Use this when the item has been received.',
];
} elseif ($canPurchasingFollowUp && $currentStatus === 'received') {
$nextPurchasingAction = [
'label' => 'Hand Over To Requester',
'type' => 'modal',
'modal_id' => 'handover-modal',
'class' => 'bg-green-700 text-white hover:bg-green-800',
'description' => 'Use this when the item has been given to the requester.',
];
}

$formatRupiah = function ($value) {
return 'Rp ' . number_format((float) $value, 0, ',', '.');
};

$formatQty = function ($value) {
return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
};

$isAttachmentImage = function ($path) {
return in_array(strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)), [
'jpg',
'jpeg',
'png',
'gif',
'webp',
'bmp',
'svg',
], true);
};

$formatDateForDisplay = function ($value) {
if (! filled($value)) {
return '-';
}

try {
return \Carbon\Carbon::parse($value)->format('d M Y');
} catch (\Throwable $e) {
return (string) $value;
}
};

$formatDateTimeForDisplay = function ($value) {
if (! filled($value)) {
return '-';
}

try {
return \Carbon\Carbon::parse($value)->format('d M Y H:i');
} catch (\Throwable $e) {
return (string) $value;
}
};

$formatActionLabel = function ($value) {
$value = trim((string) $value);

if ($value === '') {
return '-';
}

return str_replace('Owner', 'OR', ucwords(str_replace(['_', '-'], ' ', $value)));
};

$getLogRemarks = function ($log) {
return $log->remarks
?? $log->remark
?? $log->notes
?? '-';
};

$getLogUserName = function ($log) {
return $log->user->name
?? $log->user_name
?? $log->created_by_name
?? '-';
};

$getLogRoleName = function ($log) {
return $log->role_name
?? $log->role
?? '-';
};

$historyLogs = collect();

if (method_exists($purchaseRequest, 'logs')) {
if ($purchaseRequest->relationLoaded('logs')) {
$historyLogs = collect($purchaseRequest->logs);
} else {
$historyLogs = $purchaseRequest->logs()
->with('user')
->latest('acted_at')
->latest('created_at')
->latest('id')
->get();
}
}

$historyLogs = $historyLogs
->sortByDesc(function ($log) {
return $log->acted_at ?? $log->created_at ?? $log->id;
})
->values();

$financialControllerRemarksValue =
$purchaseRequest->financial_controller_remarks
?? $purchaseRequest->fc_remarks
?? $purchaseRequest->meeting_remarks
?? null;

if (! filled($financialControllerRemarksValue)) {
$latestFcRemarkLog = $historyLogs
->filter(function ($log) {
$action = strtolower((string) ($log->action ?? ''));
$roleName = strtolower((string) ($log->role_name ?? $log->role ?? ''));

return str_contains($action, 'financial_controller')
|| str_contains($action, 'fc')
|| str_contains($roleName, 'financial')
|| $roleName === 'fc';
})
->filter(function ($log) use ($getLogRemarks) {
$remarks = trim((string) $getLogRemarks($log));

return $remarks !== '' && $remarks !== '-';
})
->first();

if ($latestFcRemarkLog) {
$financialControllerRemarksValue = $getLogRemarks($latestFcRemarkLog);
}
}

$getVendorNameFromVendorId = function ($vendorId) {
if (! $vendorId || ! \Illuminate\Support\Facades\Schema::hasTable('vendors')) {
return null;
}

$vendor = \Illuminate\Support\Facades\DB::table('vendors')
->where('id', $vendorId)
->first();

return $vendor->name ?? null;
};

$getVendorNameFromRow = function ($row) use ($getVendorNameFromVendorId) {
foreach (['vendor_name', 'selected_vendor_name', 'supplier_name', 'seller_name'] as $column) {
if (isset($row->{$column}) && filled($row->{$column})) {
return $row->{$column};
}
}

foreach (['vendor_id', 'selected_vendor_id', 'supplier_id'] as $column) {
if (isset($row->{$column}) && filled($row->{$column})) {
$vendorName = $getVendorNameFromVendorId($row->{$column});

if (filled($vendorName)) {
return $vendorName;
}
}
}

$parentLookups = [
'purchase_request_vendor_offer_id' => [
'purchase_request_vendor_offers',
'purchase_request_offers',
'vendor_offers',
],
'vendor_offer_id' => [
'purchase_request_vendor_offers',
'purchase_request_offers',
'vendor_offers',
],
'offer_id' => [
'purchase_request_vendor_offers',
'purchase_request_offers',
'vendor_offers',
],
'purchase_request_vendor_id' => [
'purchase_request_vendors',
'purchase_request_vendor_offers',
'purchase_request_offers',
],
'vendor_bid_id' => [
'purchase_request_vendor_bids',
'purchase_request_bids',
'vendor_bids',
],
'bid_id' => [
'purchase_request_vendor_bids',
'purchase_request_bids',
'vendor_bids',
],
];

foreach ($parentLookups as $rowColumn => $parentTables) {
if (! isset($row->{$rowColumn}) || ! filled($row->{$rowColumn})) {
continue;
}

foreach ($parentTables as $parentTable) {
if (! \Illuminate\Support\Facades\Schema::hasTable($parentTable)) {
continue;
}

$parentRow = \Illuminate\Support\Facades\DB::table($parentTable)
->where('id', $row->{$rowColumn})
->first();

if (! $parentRow) {
continue;
}

foreach (['vendor_name', 'selected_vendor_name', 'supplier_name', 'seller_name', 'name'] as $column) {
if (isset($parentRow->{$column}) && filled($parentRow->{$column})) {
return $parentRow->{$column};
}
}

foreach (['vendor_id', 'selected_vendor_id', 'supplier_id'] as $column) {
if (isset($parentRow->{$column}) && filled($parentRow->{$column})) {
$vendorName = $getVendorNameFromVendorId($parentRow->{$column});

if (filled($vendorName)) {
return $vendorName;
}
}
}
}
}

return null;
};

$getSelectedVendorItem = function ($purchaseRequest, $item) use ($getVendorNameFromRow) {
$candidateTables = [
'purchase_request_offer_items',
'purchase_request_vendor_offer_items',
'purchase_request_vendor_items',
'purchase_request_vendor_bids',
'purchase_request_bids',
'vendor_bids',
];

foreach ($candidateTables as $table) {
if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
continue;
}

$query = \Illuminate\Support\Facades\DB::table($table);

if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'purchase_request_id')) {
$query->where('purchase_request_id', $purchaseRequest->id);
}

if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'purchase_request_item_id')) {
$query->where('purchase_request_item_id', $item->id);
} elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'item_id')) {
$query->where('item_id', $item->id);
}

if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'is_selected')) {
$query->where('is_selected', 1);
} elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'is_selected_by_cost_control')) {
$query->where('is_selected_by_cost_control', 1);
} elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'selected_by_cost_control')) {
$query->where('selected_by_cost_control', 1);
} elseif (\Illuminate\Support\Facades\Schema::hasColumn($table, 'selected_offer_item_id')) {
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
'vendor_name' => $getVendorNameFromRow($row) ?: '-',
'unit_price' => (float) $unitPrice,
'quantity' => (float) $quantity,
'total_price' => (float) $totalPrice,
];
}

return null;
};

$selectedGrandTotal = 0;

foreach ($purchaseRequest->items as $grandTotalItem) {
$selectedVendorItemForTotal = $getSelectedVendorItem($purchaseRequest, $grandTotalItem);

if ($selectedVendorItemForTotal) {
$selectedGrandTotal += (float) ($selectedVendorItemForTotal['total_price'] ?? 0);
}
}

$showSelectedVendorDetail =
$canPurchasingFollowUp
|| in_array($currentStatus, [
'paid_to_vendor',
'on_shipping',
'on_delivery',
'received',
'handed_over_to_requester',
], true)
|| $selectedGrandTotal > 0;

$isRequesterAccount =
str_contains($normalizedRole, 'requester')
|| (
$user
&& method_exists($user, 'hasRole')
&& (
$user->hasRole('requester')
|| $user->hasRole('it requester')
|| $user->hasRole('it_requester')
)
);

$returnStatuses = [
'revision_to_requester_from_purchasing',
'revision_from_purchasing',
];

$rejectedStatuses = [
'rejected',
'rejected_by_purchasing',
];

$canEditReturnedPr =
$isRequesterAccount
&& in_array((string) $purchaseRequest->status, $returnStatuses, true)
&& (string) $purchaseRequest->current_step === 'requester'
&& (
(int) $purchaseRequest->requested_by === (int) ($user->id ?? 0)
|| (
! empty($user->department_name)
&& $purchaseRequest->department_name === $user->department_name
)
);

$latestPurchasingReturnLog = null;
$latestPurchasingRejectLog = null;

if (method_exists($purchaseRequest, 'logs')) {
$latestPurchasingReturnLog = $purchaseRequest->logs()
->where(function ($query) {
$query->where('action', 'send_back_to_requester')
->orWhere('action', 'returned_to_requester')
->orWhere('action', 'revision_to_requester_from_purchasing')
->orWhere('to_status', 'revision_to_requester_from_purchasing')
->orWhere('to_status', 'revision_from_purchasing');
})
->latest('acted_at')
->latest('created_at')
->first();

$latestPurchasingRejectLog = $purchaseRequest->logs()
->where(function ($query) {
$query->where('action', 'reject')
->orWhere('action', 'rejected')
->orWhere('action', 'rejected_by_purchasing')
->orWhere('action', 'reject_to_requester')
->orWhere('action', 'rejected_to_requester')
->orWhere('action', 'purchase_request_rejected')
->orWhere('to_status', 'rejected')
->orWhere('to_status', 'rejected_by_purchasing');
})
->latest('acted_at')
->latest('created_at')
->first();
}

$purchasingReturnRemark =
$latestPurchasingReturnLog->remarks
?? $latestPurchasingReturnLog->remark
?? $latestPurchasingReturnLog->notes
?? $purchaseRequest->purchasing_remarks
?? $purchaseRequest->remarks
?? null;

$purchasingRejectReason =
$latestPurchasingRejectLog->remarks
?? $latestPurchasingRejectLog->remark
?? $latestPurchasingRejectLog->notes
?? $latestPurchasingRejectLog->reason
?? $purchaseRequest->reject_reason
?? $purchaseRequest->rejection_reason
?? $purchaseRequest->rejected_reason
?? $purchaseRequest->rejected_remarks
?? $purchaseRequest->purchasing_reject_reason
?? $purchaseRequest->purchasing_rejection_reason
?? $purchaseRequest->purchasing_remarks
?? $purchaseRequest->remarks
?? null;

$showPurchasingReturnRemark =
in_array((string) $purchaseRequest->status, $returnStatuses, true)
&& filled($purchasingReturnRemark);

$showPurchasingRejectReason =
in_array((string) $purchaseRequest->status, $rejectedStatuses, true)
&& filled($purchasingRejectReason);

$requesterRemarksText = (string) ($purchaseRequest->requester_remarks ?? '');
$prNumber = $purchaseRequest->pr_number ?? $purchaseRequest->request_number ?? '-';

$parentPrNumber = null;

if (preg_match('/Split(?:ted|ed)? from\s+([A-Za-z0-9\-]+)/i', $requesterRemarksText, $matches)) {
$parentPrNumber = $matches[1] ?? null;
}

$isSplitPr =
str_contains((string) $prNumber, '-S')
|| filled($parentPrNumber)
|| str_contains(strtolower($requesterRemarksText), 'split');

$cleanGmSplitRemark = function ($remark) {
$remark = trim((string) ($remark ?? ''));

if ($remark === '') {
return null;
}

$lowerRemark = strtolower($remark);

if (
str_starts_with($lowerRemark, 'split from ')
|| str_starts_with($lowerRemark, 'splited from ')
|| str_starts_with($lowerRemark, 'splitted from ')
) {
return null;
}

return $remark;
};

$getRemarkFromLog = function ($log) use ($cleanGmSplitRemark) {
return $cleanGmSplitRemark(
$log->remarks
?? $log->remark
?? $log->notes
?? null
);
};

$findGmSplitLogFromCollection = function ($logs) use ($getRemarkFromLog) {
return collect($logs)
->filter(function ($log) use ($getRemarkFromLog) {
$action = strtolower((string) ($log->action ?? ''));
$remark = $getRemarkFromLog($log);

return filled($remark)
&& str_contains($action, 'split');
})
->sortByDesc(function ($log) {
return $log->acted_at ?? $log->created_at;
})
->first();
};

$getGmSplitRemarkFromModelLogs = function ($model) use ($findGmSplitLogFromCollection, $getRemarkFromLog) {
if (! $model || ! method_exists($model, 'logs')) {
return null;
}

if ($model->relationLoaded('logs')) {
$latestLog = $findGmSplitLogFromCollection($model->logs);
} else {
$logs = $model->logs()
->where('action', 'like', '%split%')
->latest('acted_at')
->latest('created_at')
->limit(30)
->get();

$latestLog = $findGmSplitLogFromCollection($logs);
}

return $latestLog ? $getRemarkFromLog($latestLog) : null;
};

$gmSplitRemark = $cleanGmSplitRemark(
$purchaseRequest->gm_split_remarks
?? $purchaseRequest->gm_split_remark
?? $purchaseRequest->split_remarks
?? $purchaseRequest->split_remark
?? $purchaseRequest->gm_remarks
?? $purchaseRequest->gm_remark
?? null
);

if (! filled($gmSplitRemark)) {
$gmSplitRemark = $getGmSplitRemarkFromModelLogs($purchaseRequest);
}

$parentPurchaseRequest = null;
$purchaseRequestModelClass = get_class($purchaseRequest);

$parentIdFields = [
'parent_purchase_request_id',
'split_from_id',
'source_purchase_request_id',
'original_purchase_request_id',
];

foreach ($parentIdFields as $parentIdField) {
$parentIdValue = $purchaseRequest->{$parentIdField} ?? null;

if (filled($parentIdValue)) {
$parentPurchaseRequest = $purchaseRequestModelClass::query()->find($parentIdValue);
break;
}
}

if (! $parentPurchaseRequest && filled($parentPrNumber)) {
$purchaseRequestTable = method_exists($purchaseRequest, 'getTable') ? $purchaseRequest->getTable() : null;
$parentSearchColumns = [];

if (
$purchaseRequestTable
&& \Illuminate\Support\Facades\Schema::hasColumn($purchaseRequestTable, 'pr_number')
) {
$parentSearchColumns[] = 'pr_number';
}

if (
$purchaseRequestTable
&& \Illuminate\Support\Facades\Schema::hasColumn($purchaseRequestTable, 'request_number')
) {
$parentSearchColumns[] = 'request_number';
}

if (! empty($parentSearchColumns)) {
$parentPurchaseRequest = $purchaseRequestModelClass::query()
->where(function ($query) use ($parentSearchColumns, $parentPrNumber) {
foreach ($parentSearchColumns as $index => $column) {
if ($index === 0) {
$query->where($column, $parentPrNumber);
} else {
$query->orWhere($column, $parentPrNumber);
}
}
})
->first();
}
}

if (! filled($gmSplitRemark) && $parentPurchaseRequest) {
$gmSplitRemark = $getGmSplitRemarkFromModelLogs($parentPurchaseRequest);
}

$showGmSplitRemark = $isSplitPr && filled($gmSplitRemark);

$getLogText = function ($log) {
if (! $log) {
return null;
}

return $log->remarks
?? $log->remark
?? $log->notes
?? null;
};

$getDateFromLogText = function ($text, string $label) {
$text = (string) $text;

if ($text === '') {
return null;
}

if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*(.+?)(\n|$)/i', $text, $matches)) {
return trim($matches[1]);
}

return null;
};

$getRemarksFromLogText = function ($text) {
$text = (string) $text;

if ($text === '') {
return null;
}

if (preg_match('/Remarks\s*:\s*(.+)$/is', $text, $matches)) {
return trim($matches[1]);
}

return null;
};

$latestReceivedLog = null;
$latestHandoverLog = null;

if (method_exists($purchaseRequest, 'logs')) {
$latestReceivedLog = $purchaseRequest->logs()
->where(function ($query) {
$query->where('action', 'marked_received')
->orWhere('to_status', 'received');
})
->latest('acted_at')
->latest('created_at')
->first();

$latestHandoverLog = $purchaseRequest->logs()
->where(function ($query) {
$query->where('action', 'handed_over_to_requester')
->orWhere('to_status', 'handed_over_to_requester');
})
->latest('acted_at')
->latest('created_at')
->first();
}

$receivedLogText = $getLogText($latestReceivedLog);
$handoverLogText = $getLogText($latestHandoverLog);

$receivedDateValue =
$purchaseRequest->received_date
?? $purchaseRequest->received_at
?? $getDateFromLogText($receivedLogText, 'Received date')
?? null;

$receivedRemarksValue =
$purchaseRequest->received_remarks
?? $purchaseRequest->purchasing_received_remarks
?? $getRemarksFromLogText($receivedLogText)
?? null;

$handoverDateValue =
$purchaseRequest->handover_date
?? $purchaseRequest->handed_over_at
?? $getDateFromLogText($handoverLogText, 'Hand over date')
?? null;

$handoverRemarksValue =
$purchaseRequest->handover_remarks
?? $purchaseRequest->handover_to_requester_remarks
?? $getRemarksFromLogText($handoverLogText)
?? null;
@endphp

<section class="mb-6 border border-slate-300 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-950">
                {{ $purchaseRequest->pr_number }}
            </h2>

            <p class="mt-1 text-base text-slate-600">
                {{ $purchaseRequest->title }}
            </p>
        </div>

        <a href="/purchasing-lite/dashboard" class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
            Back
        </a>
    </div>
</section>

<section class="border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-5 py-4">
        <h3 class="text-lg font-bold text-slate-950">
            PR Information
        </h3>
    </div>

    <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-3">
        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Requester Name
            </p>

            <p class="mt-2 text-base font-bold text-slate-950">
                {{ $purchaseRequest->requester_name ?? '-' }}
            </p>
        </div>

        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Department
            </p>

            <p class="mt-2 text-base font-bold text-slate-950">
                {{ $purchaseRequest->department_name ?? '-' }}
            </p>
        </div>

        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Date Needed
            </p>

            <p class="mt-2 text-base font-bold text-slate-950">
                {{ $purchaseRequest->date_needed ? $purchaseRequest->date_needed->format('d M Y') : '-' }}
            </p>
        </div>

        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Status
            </p>

            <p class="mt-2 text-base font-bold capitalize text-slate-950">
                {{ str_replace('_', ' ', $purchaseRequest->status) }}
            </p>
        </div>

        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Current Step
            </p>

            <p class="mt-2 text-base font-bold capitalize text-slate-950">
                {{ $formatActionLabel($purchaseRequest->current_step) }}
            </p>
        </div>

        <div>
                <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Created Date
                </p>

            <p class="mt-2 text-base font-bold text-slate-950">
                {{ $purchaseRequest->created_at ? $purchaseRequest->created_at->format('d M Y H:i') : '-' }}
            </p>
        </div>

        <div class="md:col-span-3">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Requester Remarks
            </p>

            <p class="mt-2 whitespace-pre-line text-base text-slate-800">
                {{ $purchaseRequest->requester_remarks ?: '-' }}
            </p>
        </div>

        @if (filled($financialControllerRemarksValue))
        <div class="md:col-span-3">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Financial Controller Remarks
            </p>

            <div class="mt-2 border border-violet-300 bg-violet-50 px-3 py-3">
                <p class="whitespace-pre-line text-base font-bold text-violet-950">
                    {{ $financialControllerRemarksValue }}
                </p>
            </div>
        </div>
        @endif

        @if (filled($receivedDateValue) || filled($receivedRemarksValue))
        <div class="md:col-span-3">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Received Information
            </p>

            <div class="mt-2 border border-slate-300 bg-slate-50 px-3 py-3">
                <p class="text-base font-bold text-slate-950">
                    Date: {{ $formatDateForDisplay($receivedDateValue) }}
                </p>

                @if (filled($receivedRemarksValue))
                <p class="mt-2 whitespace-pre-line text-base text-slate-800">
                    {{ $receivedRemarksValue }}
                </p>
                @endif
            </div>
        </div>
        @endif

        @if (filled($handoverDateValue) || filled($handoverRemarksValue))
        <div class="md:col-span-3">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Hand Over Information
            </p>

            <div class="mt-2 border border-slate-300 bg-slate-50 px-3 py-3">
                <p class="text-base font-bold text-slate-950">
                    Date: {{ $formatDateForDisplay($handoverDateValue) }}
                </p>

                @if (filled($handoverRemarksValue))
                <p class="mt-2 whitespace-pre-line text-base text-slate-800">
                    {{ $handoverRemarksValue }}
                </p>
                @endif
            </div>
        </div>
        @endif

        @if ($showGmSplitRemark)
        <div class="md:col-span-3">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                GM Split Remarks
            </p>

            <div class="mt-2 border border-orange-300 bg-orange-50 px-3 py-3">
                <p class="whitespace-pre-line text-base font-bold text-orange-950">
                    {{ $gmSplitRemark }}
                </p>
            </div>
        </div>
        @endif
    </div>
</section>

@if ($canPurchasingFollowUp && $nextPurchasingAction)
<section class="mt-6 border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-5 py-4">
        <h3 class="text-lg font-bold text-slate-950">
            Purchasing Follow Up
        </h3>
    </div>

    <div class="flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between">
        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Next Action
            </p>

            <p class="mt-2 text-base text-slate-700">
                {{ $nextPurchasingAction['description'] }}
            </p>
        </div>

        @if (($nextPurchasingAction['type'] ?? 'form') === 'modal')
        <button type="button" data-open-modal="{{ $nextPurchasingAction['modal_id'] }}" class="inline-flex h-12 min-w-[220px] items-center justify-center px-8 text-sm font-bold {{ $nextPurchasingAction['class'] }}">
            {{ $nextPurchasingAction['label'] }}
        </button>
        @else
        <form method="POST" action="{{ $nextPurchasingAction['route'] }}" onsubmit="return confirm('{{ $nextPurchasingAction['confirm'] }}');">
            @csrf

            <button type="submit" class="inline-flex h-12 min-w-[180px] items-center justify-center px-8 text-sm font-bold {{ $nextPurchasingAction['class'] }}">
                {{ $nextPurchasingAction['label'] }}
            </button>
        </form>
        @endif
    </div>
</section>
@endif

@if ($showPurchasingRejectReason)
<section class="mt-6 border border-red-400 bg-white shadow-sm">
    <div class="border-b border-red-400 bg-red-50 px-5 py-4">
        <h3 class="text-lg font-bold text-red-900">
            Purchasing Reject Reason
        </h3>
    </div>

    <div class="p-5">
        <p class="text-sm font-bold uppercase tracking-wide text-red-700">
            This PR was rejected by Purchasing
        </p>

        <div class="mt-3 border border-red-400 bg-red-50 p-4">
            <p class="whitespace-pre-line text-base font-bold text-red-900">
                {{ $purchasingRejectReason }}
            </p>
        </div>

        @if ($latestPurchasingRejectLog)
        <p class="mt-3 text-sm text-slate-600">
            Rejected by
            <span class="font-bold text-slate-900">
                {{ $latestPurchasingRejectLog->user->name ?? 'Purchasing' }}
            </span>

            @if (! empty($latestPurchasingRejectLog->acted_at))
            on
            <span class="font-bold text-slate-900">
                {{ \Carbon\Carbon::parse($latestPurchasingRejectLog->acted_at)->format('d M Y H:i') }}
            </span>
            @endif
        </p>
        @endif
    </div>
</section>
@endif

@if ($showPurchasingReturnRemark)
<section class="mt-6 border border-red-300 bg-white shadow-sm">
    <div class="border-b border-red-300 bg-red-50 px-5 py-4">
        <h3 class="text-lg font-bold text-red-800">
            Purchasing Return Remark
        </h3>
    </div>

    <div class="p-5">
        <p class="text-sm font-bold uppercase tracking-wide text-red-700">
            Please revise this PR based on the note from Purchasing
        </p>

        <div class="mt-3 border border-red-300 bg-red-50 p-4">
            <p class="whitespace-pre-line text-base font-bold text-red-900">
                {{ $purchasingReturnRemark }}
            </p>
        </div>

        @if ($latestPurchasingReturnLog)
        <p class="mt-3 text-sm text-slate-600">
            Sent back by
            <span class="font-bold text-slate-900">
                {{ $latestPurchasingReturnLog->user->name ?? 'Purchasing' }}
            </span>

            @if (! empty($latestPurchasingReturnLog->acted_at))
            on
            <span class="font-bold text-slate-900">
                {{ \Carbon\Carbon::parse($latestPurchasingReturnLog->acted_at)->format('d M Y H:i') }}
            </span>
            @endif
        </p>
        @endif
    </div>
</section>
@endif

<section class="mt-6 border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-5 py-4">
        <h3 class="text-lg font-bold text-slate-950">
            PR Detail
        </h3>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <tr class="bg-slate-100">
                    <th class="w-16 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        No
                    </th>

                    <th class="w-28 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Files
                    </th>

                    <th class="min-w-[260px] align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Item Name
                    </th>

                    <th class="min-w-[320px] align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Specification
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Qty
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Unit
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Stock
                    </th>

                    @if ($showSelectedVendorDetail)
                    <th class="min-w-[220px] align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Vendor
                    </th>

                    <th class="w-36 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Price / Unit
                    </th>

                    <th class="w-40 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Total
                    </th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @forelse ($purchaseRequest->items as $item)
                @php
                $photos = $item->item_photos;

                if (! is_array($photos) || count($photos) < 1) { $photos=$item->item_photo ? [$item->item_photo] : [];
                    }

                    $selectedVendorItem = $getSelectedVendorItem($purchaseRequest, $item);
                    @endphp

                    <tr>
                        <td class="align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-700">
                            {{ $loop->iteration }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3">
                            @if (count($photos))
                            <div class="flex flex-wrap justify-center gap-1">
                                @foreach ($photos as $photo)
                                <a href="{{ asset('storage/' . ltrim($photo, '/')) }}" target="_blank">
                                    @if ($isAttachmentImage($photo))
                                    <img src="{{ asset('storage/' . ltrim($photo, '/')) }}" alt="" class="h-16 w-16 border border-slate-300 object-cover">
                                    @else
                                    <span class="flex h-16 w-28 items-center border border-slate-300 bg-slate-50 px-2 text-xs font-bold text-slate-700">
                                        {{ basename($photo) }}
                                    </span>
                                    @endif
                                </a>
                                @endforeach
                            </div>
                            @else
                            <span class="block text-center text-slate-400">No file</span>
                            @endif
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 font-bold text-slate-900">
                            {{ $item->item_name }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 text-slate-800">
                            {{ $item->specification ?: '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 text-right text-slate-800">
                            {{ $formatQty($item->quantity) }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 text-slate-800">
                            {{ $item->unit ?: '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                            {{ $item->stock !== null ? $formatQty($item->stock) : '-' }}
                        </td>

                        @if ($showSelectedVendorDetail)
                        @if ($selectedVendorItem)
                        <td class="align-middle border border-slate-300 px-3 py-3 font-bold text-slate-950">
                            {{ $selectedVendorItem['vendor_name'] }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                            {{ $formatRupiah($selectedVendorItem['unit_price']) }}
                        </td>

                        <td class="align-middle border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                            {{ $formatRupiah($selectedVendorItem['total_price']) }}
                        </td>
                        @else
                        <td colspan="3" class="align-middle border border-red-300 bg-red-50 px-3 py-3 text-center font-bold text-red-700">
                            No selected vendor
                        </td>
                        @endif
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $showSelectedVendorDetail ? 10 : 7 }}" class="border border-slate-300 px-4 py-6 text-center text-base text-slate-500">
                            No item data.
                        </td>
                    </tr>
                    @endforelse
            </tbody>

            @if ($showSelectedVendorDetail && ($purchaseRequest->items ?? collect())->count() > 0)
            <tfoot>
                <tr class="bg-slate-100">
                    <td colspan="9" class="align-middle border border-slate-300 px-3 py-4 text-right text-base font-bold text-slate-950">
                        Grand Total
                    </td>

                    <td class="align-middle border border-slate-300 px-3 py-4 text-right text-base font-bold text-slate-950">
                        {{ $formatRupiah($selectedGrandTotal) }}
                    </td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</section>

@if ($canEditReturnedPr)
<section class="mt-6 border border-slate-300 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h3 class="text-lg font-bold text-yellow-950">

            </h3>

            <p class="mt-1 text-sm font-medium text-yellow-800">
            </p>
        </div>

        <a href="/purchasing-lite/purchase-requests/{{ $purchaseRequest->id }}/edit" class="inline-flex h-11 items-center justify-center border border-yellow-600 bg-yellow-500 px-8 text-sm font-bold text-slate-950 transition hover:bg-yellow-400">
            Edit PR
        </a>
    </div>
</section>
@endif

<section class="mt-6 border border-slate-300 bg-white shadow-sm">
    <button type="button" id="toggle-history-button" class="flex w-full items-center justify-between border-b border-slate-300 px-5 py-4 text-left">
        <span>
            <span class="block text-lg font-bold text-slate-950">
                PR History
            </span>

            <span class="mt-1 block text-sm text-slate-600">
                Show status changes, approvals, remarks, and handover history.
            </span>
        </span>

        <span id="toggle-history-label" class="inline-flex h-10 items-center justify-center border border-slate-950 bg-white px-5 text-sm font-bold text-slate-950 hover:bg-slate-100">
            Show History
        </span>
    </button>

    <div id="history-table-wrapper" class="hidden overflow-x-auto">
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <tr class="bg-slate-100">
                    <th class="w-44 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Date
                    </th>

                    <th class="w-44 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        User
                    </th>

                    <th class="w-40 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Role
                    </th>

                    <th class="w-56 align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Action
                    </th>

                    <th class="min-w-[360px] align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        Remarks
                    </th>
                </tr>
            </thead>

            <tbody>
                @forelse ($historyLogs as $log)
                <tr class="hover:bg-slate-50">
                    <td class="align-middle border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                        {{ $formatDateTimeForDisplay($log->acted_at ?? $log->created_at ?? null) }}
                    </td>

                    <td class="align-middle border border-slate-300 px-3 py-3 text-slate-800">
                        {{ $getLogUserName($log) }}
                    </td>

                    <td class="align-middle border border-slate-300 px-3 py-3 text-center text-slate-800">
                        {{ $formatActionLabel($getLogRoleName($log)) }}
                    </td>

                    <td class="align-middle border border-slate-300 px-3 py-3 font-bold text-slate-950">
                        {{ $formatActionLabel($log->action ?? '-') }}
                    </td>

                    <td class="align-middle whitespace-pre-line border border-slate-300 px-3 py-3 text-slate-800">
                        {{ $getLogRemarks($log) }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="border border-slate-300 px-4 py-6 text-center text-base text-slate-500">
                        No history data.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<div id="received-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4" data-modal>
    <div class="w-full max-w-xl border border-slate-300 bg-white shadow-lg">
        <div class="border-b border-slate-300 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">
                Received Item
            </h3>
        </div>

        <form method="POST" action="{{ route('purchasing-lite.purchase-requests.purchasing.received', $purchaseRequest) }}">
            @csrf

            <div class="space-y-4 p-5">
                <div>
                    <label class="block text-sm font-bold uppercase tracking-wide text-slate-600">
                        Received Date
                    </label>

                    <input type="date" name="received_date" value="{{ now()->format('Y-m-d') }}" required class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm font-medium text-slate-900">
                </div>

                <div>
                    <label class="block text-sm font-bold uppercase tracking-wide text-slate-600">
                        Remarks
                    </label>

                    <textarea name="received_remarks" rows="4" class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm text-slate-900" placeholder="Example: item received in good condition"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-300 px-5 py-4">
                <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50">
                    Cancel
                </button>

                <button type="submit" class="inline-flex h-10 items-center justify-center bg-green-700 px-6 text-sm font-bold text-white hover:bg-green-800">
                    Save Received
                </button>
            </div>
        </form>
    </div>
</div>

<div id="handover-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4" data-modal>
    <div class="w-full max-w-xl border border-slate-300 bg-white shadow-lg">
        <div class="border-b border-slate-300 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">
                Hand Over To Requester
            </h3>
        </div>

        <form method="POST" action="{{ route('purchasing-lite.purchase-requests.purchasing.handover-to-requester', $purchaseRequest) }}">
            @csrf

            <div class="space-y-4 p-5">
                <div>
                    <label class="block text-sm font-bold uppercase tracking-wide text-slate-600">
                        Hand Over Date
                    </label>

                    <input type="date" name="handover_date" value="{{ now()->format('Y-m-d') }}" required class="mt-2 h-11 w-full border border-slate-300 px-3 text-sm font-medium text-slate-900">
                </div>

                <div>
                    <label class="block text-sm font-bold uppercase tracking-wide text-slate-600">
                        Remarks
                    </label>

                    <textarea name="handover_remarks" rows="4" class="mt-2 w-full border border-slate-300 px-3 py-2 text-sm text-slate-900" placeholder="Example: item handed over to Komang HK"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-300 px-5 py-4">
                <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-5 text-sm font-bold text-slate-800 hover:bg-slate-50">
                    Cancel
                </button>

                <button type="submit" class="inline-flex h-10 items-center justify-center bg-green-700 px-6 text-sm font-bold text-white hover:bg-green-800">
                    Save Hand Over
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('click', function (event) {
        const openButton = event.target.closest('[data-open-modal]');

        if (openButton) {
            const modalId = openButton.getAttribute('data-open-modal');
            const modal = document.getElementById(modalId);

            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            return;
        }

        const closeButton = event.target.closest('[data-close-modal]');

        if (closeButton) {
            const modal = closeButton.closest('[data-modal]');

            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            return;
        }

        if (event.target.matches('[data-modal]')) {
            event.target.classList.add('hidden');
            event.target.classList.remove('flex');
        }
    });

    document.addEventListener('click', function (event) {
        const toggleHistoryButton = event.target.closest('#toggle-history-button');

        if (! toggleHistoryButton) {
            return;
        }

        const historyWrapper = document.getElementById('history-table-wrapper');
        const historyLabel = document.getElementById('toggle-history-label');

        if (! historyWrapper || ! historyLabel) {
            return;
        }

        const isHidden = historyWrapper.classList.contains('hidden');

        if (isHidden) {
            historyWrapper.classList.remove('hidden');
            historyLabel.textContent = 'Hide History';
        } else {
            historyWrapper.classList.add('hidden');
            historyLabel.textContent = 'Show History';
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('[data-modal]').forEach(function (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        });
    });
</script>
@endpush
