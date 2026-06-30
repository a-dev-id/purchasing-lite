@extends('layouts.purchasing-lite')

@section('title', 'OR Dashboard - Purchasing Lite')

@section('content')
@php
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

$formatPriority = function ($priority) {
$priority = strtolower((string) ($priority ?: 'regular'));

return match ($priority) {
'urgent' => 'Urgent',
'important' => 'Important',
default => 'Regular',
};
};

$priorityBadgeClass = function ($priority) {
$priority = strtolower((string) ($priority ?: 'regular'));

return match ($priority) {
'urgent' => 'border-red-600 bg-red-50 text-red-900',
'important' => 'border-yellow-500 bg-yellow-50 text-yellow-900',
default => 'border-slate-400 bg-slate-100 text-slate-800',
};
};

$getVendorNameFromVendorId = function ($vendorId) {
if (! $vendorId || ! \Illuminate\Support\Facades\Schema::hasTable('vendors')) {
return null;
}

$vendor = \Illuminate\Support\Facades\DB::table('vendors')
->where('id', $vendorId)
->first();

return $vendor->name ?? null;
};

$getVendorNameFromRow = function ($row, string $table) use ($getVendorNameFromVendorId) {
$directNameColumns = [
'vendor_name',
'selected_vendor_name',
'supplier_name',
'seller_name',
];

foreach ($directNameColumns as $column) {
if (isset($row->{$column}) && filled($row->{$column})) {
return $row->{$column};
}
}

$directVendorIdColumns = [
'vendor_id',
'selected_vendor_id',
'supplier_id',
];

foreach ($directVendorIdColumns as $column) {
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

$parentNameColumns = [
'vendor_name',
'selected_vendor_name',
'supplier_name',
'seller_name',
'name',
];

foreach ($parentNameColumns as $parentNameColumn) {
if (isset($parentRow->{$parentNameColumn}) && filled($parentRow->{$parentNameColumn})) {
return $parentRow->{$parentNameColumn};
}
}

$parentVendorIdColumns = [
'vendor_id',
'selected_vendor_id',
'supplier_id',
];

foreach ($parentVendorIdColumns as $parentVendorIdColumn) {
if (isset($parentRow->{$parentVendorIdColumn}) && filled($parentRow->{$parentVendorIdColumn})) {
$vendorName = $getVendorNameFromVendorId($parentRow->{$parentVendorIdColumn});

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

$vendorName = $getVendorNameFromRow($row, $table);

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
};
@endphp

@push('styles')
<style>
    .owner-pr-table {
        min-width: 2510px;
        table-layout: fixed;
        font-size: 10px;
        line-height: 1.15;
    }

    .owner-pr-table th,
    .owner-pr-table td {
        padding: 3px 5px;
        font-size: 10px;
        line-height: 1.15;
    }

    .owner-pr-table thead th {
        position: sticky;
        top: 0;
        z-index: 20;
        background: #f1f5f9;
    }

    .owner-pr-table thead {
        position: sticky;
        top: 0;
        z-index: 30;
    }

    .owner-pr-table textarea,
    .owner-pr-table input,
    .owner-pr-table span,
    .owner-pr-table a {
        font-size: 10px;
        line-height: 1.15;
    }

    .owner-pr-table .owner-pr-strong {
        font-size: 10px;
        line-height: 1.15;
    }

    .owner-pr-table .owner-pr-remark {
        min-height: 92px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: normal;
    }

    .owner-pr-table .owner-pr-thumb {
        height: 28px;
        width: 28px;
    }

    .owner-pr-table .owner-pr-checkbox {
        height: 18px;
        width: 18px;
    }
</style>
@endpush

<section class="mb-6 border border-slate-300 bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-slate-950">
                OR Dashboard
            </h2>

            <p class="mt-1 text-sm text-slate-600">
                Welcome, {{ strtolower((string) ($user->role ?? $user->role_name ?? '')) === 'owner' ? 'OR' : $user->name }}.
            </p>
        </div>

        <a href="{{ route('purchasing-lite.purchase-requests.meeting-list') }}" class="inline-flex h-11 items-center justify-center border border-slate-950 bg-white px-6 text-sm font-bold text-slate-950 transition hover:bg-slate-100">
            All PR List
        </a>
    </div>
</section>

@if ($errors->any())
<section class="mb-3 border border-red-300 bg-red-50 px-3 py-2 text-xs font-medium text-red-800">
    <p class="mb-2 font-bold">Please check:</p>

    <ul class="list-inside list-disc space-y-1">
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</section>
@endif

<section class="border border-slate-300 bg-white shadow-sm">
    <div class="flex items-center justify-between gap-4 border-b border-slate-300 px-5 py-4">
        <h3 class="text-base font-bold text-slate-950">
            Purchase Request List
        </h3>

        <div class="flex items-center gap-2">
            <button type="submit" form="owner-remarks-save-form" id="owner-save-remarks-button" class="hidden h-8 items-center justify-center border border-blue-700 bg-white px-3 text-xs font-bold text-blue-800 transition hover:bg-blue-50">
                Save Remarks
            </button>

            <button type="submit" form="owner-qty-save-form" id="owner-save-qty-button" class="hidden h-8 items-center justify-center border border-blue-700 bg-white px-3 text-xs font-bold text-blue-800 transition hover:bg-blue-50">
                Save PR
            </button>

            <button type="submit" form="owner-return-purchasing-form" id="owner-return-purchasing-button" class="inline-flex h-8 items-center justify-center border-2 border-red-600 bg-white px-4 text-xs font-bold text-red-700 transition hover:bg-red-50">
                Return to Purchasing
            </button>

            <button type="submit" form="owner-bulk-approve-form" id="owner-approve-selected-button" class="inline-flex h-8 items-center justify-center bg-green-700 px-4 text-xs font-bold text-white transition hover:bg-green-800">
                Approve Selected
            </button>
        </div>
    </div>

    <div class="overflow-auto" style="max-height: calc(100vh - 150px);">
        <table class="owner-pr-table border-collapse">
            <colgroup>
                <col style="width: 45px;">
                <col style="width: 150px;">
                <col style="width: 130px;">
                <col style="width: 150px;">
                <col style="width: 100px;">
                <col style="width: 100px;">
                <col style="width: 130px;">
                <col style="width: 170px;">
                <col style="width: 340px;">
                <col style="width: 85px;">
                <col style="width: 70px;">
                <col style="width: 260px;">
                <col style="width: 55px;">
                <col style="width: 80px;">
                <col style="width: 70px;">
                <col style="width: 180px;">
                <col style="width: 130px;">
                <col style="width: 130px;">
                <col style="width: 140px;">
            </colgroup>
            <thead>
                <tr class="bg-slate-100">
                    <th class="w-10 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        No
                    </th>

                    <th class="w-32 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        PR Number
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Requester
                    </th>

                    <th class="w-28 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Department
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Date Needed
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Created Date
                    </th>

                    <th class="w-24 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        PR Priority
                    </th>

                    <th class="w-32 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        PR Title
                    </th>

                    <th class="w-64 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Remarks
                    </th>

                    <th class="w-14 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Approve
                    </th>

                    <th class="w-14 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        File
                    </th>

                    <th class="w-44 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Item
                    </th>

                    <th class="w-12 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Qty
                    </th>

                    <th class="w-16 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Unit
                    </th>

                    <th class="w-14 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Stock
                    </th>

                    <th class="w-36 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Vendor
                    </th>

                    <th class="w-28 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Price / Unit
                    </th>

                    <th class="w-28 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Total
                    </th>

                    <th class="w-32 align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-800">
                        Grand Total
                    </th>
                </tr>
            </thead>

            <tbody>
                @forelse ($purchaseRequests ?? [] as $purchaseRequest)
                @php
                $prNumber = $purchaseRequest->pr_number ?? $purchaseRequest->request_number ?? '-';
                $items = $purchaseRequest->items ?? collect();
                $rowspan = max($items->count(), 1);
                $priority = strtolower((string) ($purchaseRequest->priority ?? 'regular'));

                $grandTotal = 0;

                foreach ($items as $checkItem) {
                $selectedVendorItem = $getSelectedVendorItem($purchaseRequest, $checkItem);

                if ($selectedVendorItem) {
                $grandTotal += (float) ($selectedVendorItem['total_price'] ?? 0);
                }
                }
                @endphp

                @if ($items->count() > 0)
                @foreach ($items as $item)
                @php
                $itemPhotos = $item->item_photos;

                if (! is_array($itemPhotos) || count($itemPhotos) < 1) { $itemPhotos=$item->item_photo ? [$item->item_photo] : [];
                    }

                    $selectedVendorItem = $getSelectedVendorItem($purchaseRequest, $item);
                    $ownerQuantityValue = $selectedVendorItem['quantity'] ?? $item->quantity;
                    $ownerUnitPriceValue = $selectedVendorItem['unit_price'] ?? 0;
                    $ownerLineTotalValue = (float) $ownerQuantityValue * (float) $ownerUnitPriceValue;
                    @endphp

                    <tr data-owner-pr-row data-pr-id="{{ $purchaseRequest->id }}">
                        @if ($loop->first)
                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-700">
                            {{ $loop->parent->iteration }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 font-bold text-slate-950">
                            {{ $prNumber }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 text-slate-800">
                            {{ $purchaseRequest->requester_name ?? '-' }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 text-slate-800">
                            {{ $purchaseRequest->department_name ?? '-' }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 text-center text-slate-800">
                            {{ $purchaseRequest->date_needed ? \Carbon\Carbon::parse($purchaseRequest->date_needed)->format('d M Y') : '-' }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 text-center text-slate-800">
                            {{ $purchaseRequest->created_at ? \Carbon\Carbon::parse($purchaseRequest->created_at)->format('d M Y') : '-' }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 text-center">
                            <span class="inline-flex min-w-[68px] items-center justify-center border px-1.5 py-0.5 text-[10px] font-bold uppercase leading-tight {{ $priorityBadgeClass($priority) }}">
                                {{ $formatPriority($priority) }}
                            </span>
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-1.5 py-1 font-bold text-slate-950">
                            {{ $purchaseRequest->title }}
                        </td>

                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 p-0">
                            <textarea name="owner_requester_remarks[{{ $purchaseRequest->id }}]" rows="6" autocomplete="off" spellcheck="false" form="owner-remarks-save-form" data-owner-remarks-input class="owner-pr-remark h-full w-full resize-none border-0 bg-white text-slate-800 outline-none focus:ring-2 focus:ring-blue-100">{{ $purchaseRequest->requester_remarks }}</textarea>
                        </td>
                        @endif

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-center">
                            @if ($selectedVendorItem)
                            <input type="checkbox" name="approved_item_ids[{{ $purchaseRequest->id }}][]" value="{{ $item->id }}" form="owner-bulk-approve-form" data-owner-item-checkbox data-pr-id="{{ $purchaseRequest->id }}" class="owner-pr-checkbox cursor-pointer">
                            @else
                            <span class="text-slate-400">-</span>
                            @endif
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1">
                            @if (! empty($itemPhotos))
                            <div class="flex flex-wrap justify-center gap-1">
                                @foreach ($itemPhotos as $photo)
                                <a href="{{ asset('storage/' . ltrim($photo, '/')) }}" target="_blank" class="block">
                                    @if ($isAttachmentImage($photo))
                                    <img src="{{ asset('storage/' . ltrim($photo, '/')) }}" alt="" class="owner-pr-thumb border border-slate-300 object-cover">
                                    @else
                                    <span class="flex h-8 w-20 items-center border border-slate-300 bg-slate-50 px-1.5 text-[10px] font-bold text-slate-700">
                                        {{ basename($photo) }}
                                    </span>
                                    @endif
                                </a>
                                @endforeach
                            </div>
                            @else
                            <span class="block text-center text-slate-400">-</span>
                            @endif
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 font-bold text-slate-950">
                            {{ $item->item_name }}
                        </td>

                        <td class="align-middle border border-slate-300 p-0">
                            @if ($selectedVendorItem)
                            <input type="text" name="owner_quantities[{{ $purchaseRequest->id }}][{{ $item->id }}]" value="{{ $formatQty($ownerQuantityValue) }}" inputmode="decimal" autocomplete="off" form="owner-qty-save-form" data-owner-qty-input data-pr-id="{{ $purchaseRequest->id }}" data-unit-price="{{ $ownerUnitPriceValue }}" data-saved-value="{{ $formatQty($ownerQuantityValue) }}" class="h-8 w-full border-0 bg-white px-1.5 text-right text-[10px] font-bold text-slate-950 outline-none focus:ring-2 focus:ring-blue-100">
                            @else
                            <span class="block px-1.5 py-1 text-right text-slate-800">{{ $formatQty($item->quantity) }}</span>
                            @endif
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-slate-800">
                            {{ $item->unit ?: '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-right font-bold text-slate-950">
                            {{ $item->stock !== null ? $formatQty($item->stock) : '-' }}
                        </td>

                        @if ($selectedVendorItem)
                        <td class="align-middle border border-slate-300 px-1.5 py-1 font-bold text-slate-950">
                            {{ $selectedVendorItem['vendor_name'] }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-right font-bold text-slate-950">
                            {{ $formatRupiah($ownerUnitPriceValue) }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-right font-bold text-slate-950" data-owner-line-total data-pr-id="{{ $purchaseRequest->id }}">
                            {{ $formatRupiah($ownerLineTotalValue) }}
                        </td>
                        @else
                        <td colspan="3" class="align-middle border border-red-300 bg-red-50 px-1.5 py-1 text-center font-bold text-red-700">
                            No selected vendor
                        </td>
                        @endif

                        @if ($loop->first)
                        <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 bg-slate-50 px-1.5 py-1 text-right text-xs font-bold text-slate-950" data-owner-grand-total data-pr-id="{{ $purchaseRequest->id }}">
                            {{ $formatRupiah($grandTotal) }}
                        </td>
                        @endif
                    </tr>
                    @endforeach
                    @else
                    <tr>
                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-center font-bold text-slate-700">
                            {{ $loop->iteration }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 font-bold text-slate-950">
                            {{ $prNumber }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-slate-800">
                            {{ $purchaseRequest->requester_name ?? '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-slate-800">
                            {{ $purchaseRequest->department_name ?? '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-center text-slate-800">
                            {{ $purchaseRequest->date_needed ? \Carbon\Carbon::parse($purchaseRequest->date_needed)->format('d M Y') : '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-center text-slate-800">
                            {{ $purchaseRequest->created_at ? \Carbon\Carbon::parse($purchaseRequest->created_at)->format('d M Y') : '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 text-center">
                            <span class="inline-flex min-w-[68px] items-center justify-center border px-1.5 py-0.5 text-[10px] font-bold uppercase leading-tight {{ $priorityBadgeClass($priority) }}">
                                {{ $formatPriority($priority) }}
                            </span>
                        </td>

                        <td class="align-middle border border-slate-300 px-1.5 py-1 font-bold text-slate-950">
                            {{ $purchaseRequest->title }}
                        </td>

                        <td class="align-middle border border-slate-300 p-0">
                            <textarea name="owner_requester_remarks[{{ $purchaseRequest->id }}]" rows="5" autocomplete="off" spellcheck="false" form="owner-remarks-save-form" data-owner-remarks-input class="owner-pr-remark w-full resize-none border-0 bg-white text-slate-800 outline-none focus:ring-2 focus:ring-blue-100">{{ $purchaseRequest->requester_remarks }}</textarea>
                        </td>

                        <td colspan="9" class="align-middle border border-slate-300 px-1.5 py-1 text-center text-slate-500">
                            No item data.
                        </td>

                        <td class="align-middle border border-slate-300 bg-slate-50 px-1.5 py-1 text-right font-bold text-slate-950">
                            Rp 0
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr>
                        <td colspan="19" class="align-middle border border-slate-300 px-2 py-5 text-center text-sm text-slate-500">
                            No PR waiting for OR approval.
                        </td>
                    </tr>
                    @endforelse
            </tbody>
        </table>
    </div>
</section>

<form id="owner-bulk-approve-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.bulk-approve') }}" onsubmit="return validateOwnerBulkApprove(this);">
    @csrf
    <div class="hidden" data-owner-approve-qty-fields></div>
    <textarea name="remarks" rows="3" class="hidden" data-owner-approve-remarks></textarea>
</form>

<form id="owner-return-purchasing-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.return-to-purchasing') }}" onsubmit="return validateOwnerReturnToPurchasing(this);">
    @csrf
    <div class="hidden" data-owner-return-selected-fields></div>
    <textarea name="remarks" rows="3" class="hidden" data-owner-return-remarks></textarea>
</form>

<form id="owner-qty-save-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.save-quantities') }}" onsubmit="return validateOwnerQtySave();">
    @csrf
</form>

<form id="owner-remarks-save-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.save-remarks') }}" onsubmit="return validateOwnerRemarksSave();">
    @csrf
</form>
@endsection

@push('scripts')
<script>
    function parseOwnerNumber(value) {
        const normalized = String(value ?? '')
            .replace(',', '.')
            .replace(/[^0-9.]/g, '');

        const number = Number.parseFloat(normalized);

        return Number.isFinite(number) ? number : 0;
    }

    function formatOwnerQty(value) {
        return String(Number(value || 0).toFixed(2)).replace(/\.?0+$/, '');
    }

    function formatOwnerRupiah(value) {
        const number = Math.round(Number(value || 0));

        return 'Rp ' + String(number).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function recalculateOwnerTotals(prId = null) {
        const selector = prId
            ? '[data-owner-qty-input][data-pr-id="' + prId + '"]'
            : '[data-owner-qty-input]';

        document.querySelectorAll(selector).forEach(function (input) {
            const unitPrice = parseOwnerNumber(input.getAttribute('data-unit-price'));
            const quantity = parseOwnerNumber(input.value);
            const row = input.closest('tr');
            const lineTotal = row ? row.querySelector('[data-owner-line-total]') : null;

            if (lineTotal) {
                lineTotal.textContent = formatOwnerRupiah(quantity * unitPrice);
            }
        });

        const prIds = prId
            ? [prId]
            : Array.from(document.querySelectorAll('[data-owner-grand-total]')).map(function (cell) {
                return cell.getAttribute('data-pr-id');
            });

        prIds.forEach(function (currentPrId) {
            let grandTotal = 0;

            document.querySelectorAll('[data-owner-qty-input][data-pr-id="' + currentPrId + '"]').forEach(function (input) {
                grandTotal += parseOwnerNumber(input.value) * parseOwnerNumber(input.getAttribute('data-unit-price'));
            });

            const grandTotalCell = document.querySelector('[data-owner-grand-total][data-pr-id="' + currentPrId + '"]');

            if (grandTotalCell) {
                grandTotalCell.textContent = formatOwnerRupiah(grandTotal);
            }
        });
    }

    function quantityIsDirty(input) {
        return parseOwnerNumber(input.value) !== parseOwnerNumber(input.getAttribute('data-saved-value'));
    }

    function remarksIsDirty(input) {
        return String(input.value ?? '') !== String(input.defaultValue ?? '');
    }

    function updateOwnerSaveButton() {
        const saveButton = document.getElementById('owner-save-qty-button');

        if (! saveButton) {
            return;
        }

        const hasChanges = Array.from(document.querySelectorAll('[data-owner-qty-input]')).some(quantityIsDirty);

        saveButton.classList.toggle('hidden', ! hasChanges);
        saveButton.classList.toggle('inline-flex', hasChanges);
    }

    function updateOwnerRemarksSaveButton() {
        const saveButton = document.getElementById('owner-save-remarks-button');

        if (! saveButton) {
            return;
        }

        const hasChanges = Array.from(document.querySelectorAll('[data-owner-remarks-input]')).some(remarksIsDirty);

        saveButton.classList.toggle('hidden', ! hasChanges);
        saveButton.classList.toggle('inline-flex', hasChanges);
    }

    function syncOwnerQuantitiesToApproveForm(form) {
        const container = form.querySelector('[data-owner-approve-qty-fields]');

        if (! container) {
            return;
        }

        container.innerHTML = '';

        document.querySelectorAll('[data-owner-qty-input]').forEach(function (sourceInput) {
            const hiddenInput = document.createElement('input');

            hiddenInput.type = 'hidden';
            hiddenInput.name = sourceInput.name;
            hiddenInput.value = sourceInput.value;

            container.appendChild(hiddenInput);
        });
    }

    function syncOwnerSelectedItemsToReturnForm(form) {
        const container = form.querySelector('[data-owner-return-selected-fields]');

        if (! container) {
            return;
        }

        container.innerHTML = '';

        document.querySelectorAll('[data-owner-item-checkbox]:checked').forEach(function (sourceInput) {
            const hiddenInput = document.createElement('input');

            hiddenInput.type = 'hidden';
            hiddenInput.name = sourceInput.name;
            hiddenInput.value = sourceInput.value;

            container.appendChild(hiddenInput);
        });
    }

    document.addEventListener('input', function (event) {
        const input = event.target.closest('[data-owner-qty-input]');

        if (input) {
            recalculateOwnerTotals(input.getAttribute('data-pr-id'));
            updateOwnerSaveButton();
        }

        const remarksInput = event.target.closest('[data-owner-remarks-input]');

        if (remarksInput) {
            updateOwnerRemarksSaveButton();
        }
    });

    document.addEventListener('blur', function (event) {
        const input = event.target.closest('[data-owner-qty-input]');

        if (! input) {
            return;
        }

        let quantity = parseOwnerNumber(input.value);

        if (quantity <= 0) {
            quantity = 1;
        }

        input.value = formatOwnerQty(quantity);
        recalculateOwnerTotals(input.getAttribute('data-pr-id'));
        updateOwnerSaveButton();
    }, true);

    function validateOwnerQtySave() {
        const dirtyInputs = Array.from(document.querySelectorAll('[data-owner-qty-input]')).filter(quantityIsDirty);

        if (dirtyInputs.length < 1) {
            return false;
        }

        return confirm('Save OR quantity changes?');
    }

    function validateOwnerRemarksSave() {
        const dirtyInputs = Array.from(document.querySelectorAll('[data-owner-remarks-input]')).filter(remarksIsDirty);

        if (dirtyInputs.length < 1) {
            return false;
        }

        return confirm('Save OR remarks changes?');
    }

    function validateOwnerBulkApprove(form) {
        syncOwnerQuantitiesToApproveForm(form);

        const checkedBoxes = Array.from(document.querySelectorAll('[data-owner-item-checkbox]:checked'));

        if (checkedBoxes.length < 1) {
            alert('Please tick at least one item to approve.');
            return false;
        }

        const allBoxes = Array.from(document.querySelectorAll('[data-owner-item-checkbox]'));
        const prIds = [...new Set(allBoxes.map(function (checkbox) {
            return checkbox.getAttribute('data-pr-id');
        }))];

        let hasPartialApproval = false;

        prIds.forEach(function (prId) {
            const prBoxes = allBoxes.filter(function (checkbox) {
                return checkbox.getAttribute('data-pr-id') === prId;
            });

            const checkedPrBoxes = prBoxes.filter(function (checkbox) {
                return checkbox.checked;
            });

            if (checkedPrBoxes.length > 0 && checkedPrBoxes.length < prBoxes.length) {
                hasPartialApproval = true;
            }
        });

        if (hasPartialApproval) {
            const remark = prompt('Please write remarks for item(s) not approved yet.');

            if (! remark || ! remark.trim()) {
                alert('Remarks are required when only some items are approved.');
                return false;
            }

            const remarksInput = form.querySelector('[data-owner-approve-remarks]');

            if (remarksInput) {
                remarksInput.value = remark.trim();
            }

            return confirm('Approve selected item(s)? Partial PRs will be split. Unselected items will stay on OR dashboard.');
        }

        return confirm('Approve selected item(s) and send to Financial Controller?');
    }

    function validateOwnerReturnToPurchasing(form) {
        const checkedBoxes = Array.from(document.querySelectorAll('[data-owner-item-checkbox]:checked'));

        if (checkedBoxes.length < 1) {
            alert('Please tick at least one item from the PR to return to Purchasing.');
            return false;
        }

        if (! confirm('Return selected item(s) to Purchasing? Unselected items will stay on OR dashboard.')) {
            return false;
        }

        const remark = prompt('Please write a note for Purchasing.');

        if (! remark || ! remark.trim()) {
            alert('Note is required to return PR to Purchasing.');
            return false;
        }

        syncOwnerSelectedItemsToReturnForm(form);

        const remarksInput = form.querySelector('[data-owner-return-remarks]');

        if (remarksInput) {
            remarksInput.value = remark.trim();
        }
        return true;
    }
</script>
@endpush
