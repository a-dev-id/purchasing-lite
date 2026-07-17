@extends('layouts.purchasing-lite')

@section('title', 'All PR List - Purchasing Lite')

@section('content')
@php
$monthNames = [
1 => 'January',
2 => 'February',
3 => 'March',
4 => 'April',
5 => 'May',
6 => 'June',
7 => 'July',
8 => 'August',
9 => 'September',
10 => 'October',
11 => 'November',
12 => 'December',
];

$hiddenStatuses = [
'draft',
'rejected',
'cancelled',
];

$selectedDepartment = trim((string) request('department', ''));
$selectedPrNumber = trim((string) ($selectedPrNumber ?? request('pr_number', '')));

$statusOptions = collect([
'submitted_to_purchasing',
'revision_to_requester_from_purchasing',
'submitted_to_cost_control',
'submitted_to_gm',
'submitted_to_owner',
'submitted_to_financial_controller',
'on_progress',
'waiting_payment',
'paid_to_vendor',
'on_shipping',
'received',
'handed_over_to_requester',
'completed',
])
->merge($availableStatuses ?? collect())
->filter()
->reject(function ($status) use ($hiddenStatuses) {
return in_array((string) $status, $hiddenStatuses, true);
})
->unique()
->values();

$departmentOptions = collect($purchaseRequests ?? [])
->pluck('department_name')
->filter()
->unique()
->sort()
->values();

$visiblePurchaseRequests = collect($purchaseRequests ?? [])
->reject(function ($purchaseRequest) use ($hiddenStatuses) {
return in_array((string) ($purchaseRequest->status ?? ''), $hiddenStatuses, true);
})
->filter(function ($purchaseRequest) use ($selectedDepartment) {
if ($selectedDepartment === '') {
return true;
}

return (string) ($purchaseRequest->department_name ?? '') === $selectedDepartment;
})
->values();

$formatStatus = function ($status) {
return str_replace('Owner', 'OR', ucwords(str_replace('_', ' ', (string) $status)));
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

$statusBadgeClass = function ($status) {
$status = strtolower((string) $status);

return match ($status) {
'submitted_to_purchasing' => 'border-blue-500 bg-blue-50 text-blue-900',
'revision_to_requester_from_purchasing',
'revision_from_purchasing' => 'border-orange-500 bg-orange-50 text-orange-900',
'submitted_to_cost_control' => 'border-purple-500 bg-purple-50 text-purple-900',
'submitted_to_gm' => 'border-indigo-500 bg-indigo-50 text-indigo-900',
'submitted_to_owner' => 'border-cyan-500 bg-cyan-50 text-cyan-900',
'submitted_to_financial_controller' => 'border-violet-500 bg-violet-50 text-violet-900',
'on_progress' => 'border-blue-600 bg-blue-100 text-blue-950',
'waiting_payment' => 'border-yellow-500 bg-yellow-50 text-yellow-900',
'paid_to_vendor' => 'border-green-600 bg-green-50 text-green-900',
'on_shipping',
'on_delivery' => 'border-sky-500 bg-sky-50 text-sky-900',
'received' => 'border-teal-500 bg-teal-50 text-teal-900',
'handed_over_to_requester',
'completed',
'done' => 'border-emerald-600 bg-emerald-50 text-emerald-900',
default => 'border-slate-400 bg-white text-slate-800',
};
};

$formatDate = function ($date) {
if (! $date) {
return '-';
}

try {
return \Carbon\Carbon::parse($date)->format('d M Y');
} catch (\Throwable $e) {
return '-';
}
};

$formatRupiah = function ($value) {
if ($value === null || $value === '') {
return '-';
}

return 'Rp ' . number_format((float) $value, 0, ',', '.');
};

$getPrRemarks = function ($purchaseRequest) {
return $purchaseRequest->requester_remarks
?? $purchaseRequest->remarks
?? '-';
};

$getFcRemarks = function ($purchaseRequest) {
return $purchaseRequest->financial_controller_remarks
?? $purchaseRequest->fc_remarks
?? $purchaseRequest->meeting_remarks
?? null;
};

$getItemUnitPrice = function ($purchaseRequest, $item) {
$directPriceFields = [
'selected_unit_price',
'approved_unit_price',
'final_unit_price',
'unit_price',
'estimated_unit_price',
];

foreach ($directPriceFields as $field) {
$value = $item->{$field} ?? null;

if (is_numeric($value) && (float) $value > 0) {
return (float) $value;
}
}

if (
! \Illuminate\Support\Facades\Schema::hasTable('purchase_request_vendor_offers')
|| ! \Illuminate\Support\Facades\Schema::hasTable('purchase_request_vendor_offer_items')
) {
return null;
}

$offerColumns = \Illuminate\Support\Facades\Schema::getColumnListing('purchase_request_vendor_offers');
$offerItemColumns = \Illuminate\Support\Facades\Schema::getColumnListing('purchase_request_vendor_offer_items');

if (
! in_array('id', $offerColumns, true)
|| ! in_array('purchase_request_id', $offerColumns, true)
|| ! in_array('purchase_request_vendor_offer_id', $offerItemColumns, true)
|| ! in_array('purchase_request_item_id', $offerItemColumns, true)
) {
return null;
}

$priceColumn = null;

foreach (['unit_price', 'price', 'price_per_unit', 'bid_price', 'offered_price', 'offered_unit_price', 'amount'] as $possiblePriceColumn) {
if (in_array($possiblePriceColumn, $offerItemColumns, true)) {
$priceColumn = $possiblePriceColumn;
break;
}
}

if (! $priceColumn) {
return null;
}

$query = \Illuminate\Support\Facades\DB::table('purchase_request_vendor_offer_items as offer_items')
->join('purchase_request_vendor_offers as offers', 'offers.id', '=', 'offer_items.purchase_request_vendor_offer_id')
->where('offers.purchase_request_id', $purchaseRequest->id)
->where('offer_items.purchase_request_item_id', $item->id)
->whereNotNull('offer_items.' . $priceColumn)
->where('offer_items.' . $priceColumn, '>', 0)
->select('offer_items.' . $priceColumn . ' as price');

foreach ([
'is_selected_by_cost_control',
'is_selected_by_accounting',
'is_selected',
'selected',
'is_winner',
'approved',
] as $selectedColumn) {
if (in_array($selectedColumn, $offerColumns, true)) {
$query->orderByDesc('offers.' . $selectedColumn);
break;
}
}

if (in_array('offer_rank', $offerColumns, true)) {
$query->orderBy('offers.offer_rank');
}

if (in_array('bid_number', $offerItemColumns, true)) {
$query->orderBy('offer_items.bid_number');
}

if (in_array('notes', $offerItemColumns, true)) {
$query->orderByRaw("
CASE
WHEN offer_items.notes LIKE 'Bid 1%' THEN 1
WHEN offer_items.notes LIKE 'Bid 2%' THEN 2
WHEN offer_items.notes LIKE 'Bid 3%' THEN 3
ELSE 9
END
");
}

$offerItem = $query->first();

if ($offerItem && is_numeric($offerItem->price) && (float) $offerItem->price > 0) {
return (float) $offerItem->price;
}

return null;
};

$getItemLineTotal = function ($purchaseRequest, $item) use ($getItemUnitPrice) {
$unitPrice = $getItemUnitPrice($purchaseRequest, $item);

if ($unitPrice === null) {
return null;
}

$quantity = (float) ($item->quantity ?? 0);

if ($quantity <= 0) { $quantity=1; } return $unitPrice * $quantity; }; $getGrandTotal=function ($purchaseRequest) use ($getItemLineTotal) { $total=0; $hasPrice=false; foreach (($purchaseRequest->items ?? collect()) as $item) {
    $itemTotal = $getItemLineTotal($purchaseRequest, $item);

    if ($itemTotal !== null) {
    $total += $itemTotal;
    $hasPrice = true;
    }
    }

    return $hasPrice ? $total : null;
    };
    @endphp

    @if (session('success'))
    <section class="mb-4 border border-green-300 bg-green-50 px-4 py-3 text-xs font-bold text-green-800">
        {{ session('success') }}
    </section>
    @endif

    @if (session('error'))
    <section class="mb-4 border border-red-300 bg-red-50 px-4 py-3 text-xs font-bold text-red-800">
        {{ session('error') }}
    </section>
    @endif

    @if ($errors->any())
    <section class="mb-4 border border-red-300 bg-red-50 px-4 py-3 text-xs font-bold text-red-800">
        <p class="mb-2">Please check:</p>

        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </section>
    @endif

    <section class="mb-4 border border-slate-300 bg-white p-4 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-lg font-bold text-slate-950">
                    All PR List
                </h2>

                <p class="mt-1 text-sm text-slate-600">
                    View all purchase requests for PR discussion.
                </p>
            </div>

            <a href="{{ route('purchasing-lite.dashboard') }}" class="inline-flex h-9 items-center justify-center border border-slate-300 bg-white px-4 text-xs font-bold text-slate-800 hover:bg-slate-50">
                Back
            </a>
        </div>
    </section>

    <section class="mb-4 border border-slate-300 bg-white shadow-sm">
        <button type="button" id="toggle-filter-button" class="flex w-full items-center justify-between border-b border-slate-300 px-4 py-3 text-left">
            <span>
                <span class="block text-base font-bold text-slate-950">
                    Filter
                </span>

                <span class="mt-1 block text-xs text-slate-600">
                    {{ $monthNames[(int) $selectedMonth] ?? '-' }} {{ $selectedYear }}
                    {{ filled($selectedStatus) ? ' - ' . $formatStatus($selectedStatus) : ' - All Status' }}
                    {{ filled($selectedDepartment) ? ' - ' . $selectedDepartment : ' - All Department' }}
                    {{ filled($selectedPrNumber) ? ' - PR: ' . $selectedPrNumber : '' }}
                </span>
            </span>

            <span id="toggle-filter-label" class="inline-flex h-9 items-center justify-center border border-slate-950 bg-white px-4 text-xs font-bold text-slate-950 hover:bg-slate-100">
                Show Filter
            </span>
        </button>

        <form id="filter-form" method="GET" action="{{ route('purchasing-lite.purchase-requests.meeting-list') }}" class="hidden grid-cols-1 gap-3 p-4 md:grid-cols-6">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">
                    Month
                </label>

                <select name="month" class="mt-1 h-9 w-full border border-slate-300 bg-white px-2 text-xs font-medium text-slate-900">
                    @foreach ($monthNames as $monthNumber => $monthName)
                    <option value="{{ $monthNumber }}" @selected((int) $selectedMonth===(int) $monthNumber)>
                        {{ $monthName }}
                    </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">
                    Year
                </label>

                <select name="year" class="mt-1 h-9 w-full border border-slate-300 bg-white px-2 text-xs font-medium text-slate-900">
                    @for ($year = now()->year + 1; $year >= 2020; $year--)
                    <option value="{{ $year }}" @selected((int) $selectedYear===(int) $year)>
                        {{ $year }}
                    </option>
                    @endfor
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">
                    PR Status
                </label>

                <select name="status" class="mt-1 h-9 w-full border border-slate-300 bg-white px-2 text-xs font-medium text-slate-900">
                    <option value="">All Status</option>

                    @foreach ($statusOptions as $status)
                    <option value="{{ $status }}" @selected((string) $selectedStatus===(string) $status)>
                        {{ $formatStatus($status) }}
                    </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">
                    Department
                </label>

                <select name="department" class="mt-1 h-9 w-full border border-slate-300 bg-white px-2 text-xs font-medium text-slate-900">
                    <option value="">All Department</option>

                    @foreach ($departmentOptions as $department)
                    <option value="{{ $department }}" @selected((string) $selectedDepartment===(string) $department)>
                        {{ $department }}
                    </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-600">
                    PR Number
                </label>

                <input type="search" name="pr_number" value="{{ $selectedPrNumber }}" class="mt-1 h-9 w-full border border-slate-300 bg-white px-2 text-xs font-medium text-slate-900" placeholder="Search PR number">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex h-9 w-full items-center justify-center bg-slate-950 px-4 text-xs font-bold text-white hover:bg-slate-800">
                    Apply
                </button>

                <a href="{{ route('purchasing-lite.purchase-requests.meeting-list') }}" class="inline-flex h-9 items-center justify-center border border-slate-300 bg-white px-4 text-xs font-bold text-slate-800 hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </form>
    </section>

    <section class="border border-slate-300 bg-white shadow-sm">
        <div class="border-b border-slate-300 px-4 py-3">
            <h3 class="text-base font-bold text-slate-950">
                Purchase Request List
            </h3>

            <p class="mt-1 text-xs text-slate-600">
                Showing PR for {{ $monthNames[(int) $selectedMonth] ?? '-' }} {{ $selectedYear }}.
            </p>
        </div>

        <div class="overflow-auto" style="max-height: calc(100vh - 220px);">
            <table class="border-collapse text-xs" style="min-width: 2140px;">
                <thead>
                    <tr class="bg-slate-100">
                        <th class="sticky top-0 z-10 w-12 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">No</th>
                        <th class="sticky top-0 z-10 w-44 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">PR Number</th>
                        <th class="sticky top-0 z-10 w-36 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Requester</th>
                        <th class="sticky top-0 z-10 w-44 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Department</th>
                        <th class="sticky top-0 z-10 w-28 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Date Needed</th>
                        <th class="sticky top-0 z-10 w-32 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">PR Priority</th>
                        <th class="sticky top-0 z-10 w-56 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">PR Title</th>
                        <th class="sticky top-0 z-10 w-64 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Requester Remarks</th>
                        <th class="sticky top-0 z-10 w-[360px] align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Items</th>
                        <th class="sticky top-0 z-10 w-40 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Grand Total</th>
                        <th class="sticky top-0 z-10 w-44 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Status</th>
                        <th class="sticky top-0 z-10 w-40 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">Current Step</th>
                        <th class="sticky top-0 z-10 w-64 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">FC Remarks</th>

                        @if ($isFinancialController)
                        <th class="sticky top-0 z-10 w-80 align-middle border border-slate-300 bg-slate-100 px-2 py-2 text-center font-bold text-slate-800">FC Update</th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @forelse ($visiblePurchaseRequests as $purchaseRequest)
                    @php
                    $prNumber = $purchaseRequest->pr_number ?? $purchaseRequest->request_number ?? '-';
                    $fcRemarks = $getFcRemarks($purchaseRequest);
                    $grandTotal = $getGrandTotal($purchaseRequest);
                    $priority = strtolower((string) ($purchaseRequest->priority ?? 'regular'));
                    @endphp

                    <tr class="hover:bg-slate-50">
                        <td class="align-middle border border-slate-300 px-2 py-2 text-center font-bold text-slate-700">
                            {{ $loop->iteration }}
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 font-bold text-slate-950">
                            {{ $prNumber }}
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-slate-800">
                            {{ $purchaseRequest->requester_name ?? '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-slate-800">
                            {{ $purchaseRequest->department_name ?? '-' }}
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-center text-slate-800">
                            {{ $formatDate($purchaseRequest->date_needed ?? null) }}
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-center">
                            <span class="inline-flex min-w-[82px] items-center justify-center border px-2 py-1 text-[11px] font-bold uppercase leading-tight {{ $priorityBadgeClass($priority) }}">
                                {{ $formatPriority($priority) }}
                            </span>
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 font-bold text-slate-950">
                            {{ $purchaseRequest->title ?? '-' }}
                        </td>

                        <td class="align-middle whitespace-pre-line border border-slate-300 px-2 py-2 text-slate-800">
                            {{ $getPrRemarks($purchaseRequest) }}
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-slate-800">
                            @if (($purchaseRequest->items ?? collect())->count() > 0)
                            <div class="space-y-1.5">
                                @foreach ($purchaseRequest->items as $item)
                                @php
                                $itemUnitPrice = $getItemUnitPrice($purchaseRequest, $item);
                                $itemLineTotal = $getItemLineTotal($purchaseRequest, $item);
                                @endphp

                                <div class="border border-slate-200 bg-white px-2 py-1.5">
                                    <p class="font-bold text-slate-950">
                                        {{ $loop->iteration }}. {{ $item->item_name }}
                                    </p>

                                    <p class="mt-0.5 text-slate-700">
                                        Qty:
                                        <span class="font-bold">
                                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }}
                                        </span>
                                        {{ $item->unit ?: '' }}
                                        <span class="px-1 text-slate-400">|</span>
                                        Last Purchase:
                                        <span class="font-bold text-slate-950">
                                            {{ $item->last_purchase_date ? $item->last_purchase_date->format('d M Y') : '-' }}
                                        </span>
                                        <span class="px-1 text-slate-400">|</span>
                                        Price / Item:
                                        <span class="font-bold text-slate-950">
                                            {{ $formatRupiah($itemUnitPrice) }}
                                        </span>
                                        <span class="px-1 text-slate-400">|</span>
                                        Total:
                                        <span class="font-bold text-slate-950">
                                            {{ $formatRupiah($itemLineTotal) }}
                                        </span>
                                    </p>
                                </div>
                                @endforeach
                            </div>
                            @else
                            -
                            @endif
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-right">
                            <span class="text-sm font-bold text-slate-950">
                                {{ $formatRupiah($grandTotal) }}
                            </span>
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-center">
                            <span class="inline-flex min-w-[125px] items-center justify-center border px-2 py-1 text-[11px] font-bold uppercase leading-tight {{ $statusBadgeClass($purchaseRequest->status) }}">
                                {{ $formatStatus($purchaseRequest->status) }}
                            </span>
                        </td>

                        <td class="align-middle border border-slate-300 px-2 py-2 text-center text-slate-800">
                            {{ $formatStatus($purchaseRequest->current_step) }}
                        </td>

                        <td class="align-middle whitespace-pre-line border border-slate-300 px-2 py-2 text-slate-800">
                            {{ filled($fcRemarks) ? $fcRemarks : '-' }}
                        </td>

                        @if ($isFinancialController)
                        <td class="align-middle border border-slate-300 px-2 py-2">
                            <form method="POST" action="{{ route('purchasing-lite.purchase-requests.meeting.update', $purchaseRequest) }}" class="space-y-2">
                                @csrf

                                <input type="hidden" name="month" value="{{ $selectedMonth }}">
                                <input type="hidden" name="year" value="{{ $selectedYear }}">
                                <input type="hidden" name="status" value="{{ $selectedStatus }}">
                                <input type="hidden" name="department" value="{{ $selectedDepartment }}">
                                <input type="hidden" name="pr_number" value="{{ $selectedPrNumber }}">

                                <select name="new_status" required class="h-9 w-full border border-slate-300 bg-white px-2 text-xs font-medium text-slate-900">
                                    @foreach ($statusOptions as $status)
                                    <option value="{{ $status }}" @selected((string) $purchaseRequest->status === (string) $status)>
                                        {{ $formatStatus($status) }}
                                    </option>
                                    @endforeach
                                </select>

                                <textarea name="financial_controller_remarks" rows="3" class="w-full border border-slate-300 px-2 py-1.5 text-xs text-slate-900" placeholder="FC remarks">{{ old('financial_controller_remarks', $fcRemarks) }}</textarea>

                                <button type="submit" class="inline-flex h-9 w-full items-center justify-center bg-green-700 px-3 text-xs font-bold text-white hover:bg-green-800">
                                    Save FC Update
                                </button>
                            </form>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $isFinancialController ? 14 : 13 }}" class="border border-slate-300 px-2 py-5 text-center text-sm text-slate-500">
                            No purchase request found for this filter.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @endsection

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
        const toggleButton = document.getElementById('toggle-filter-button');
        const toggleLabel = document.getElementById('toggle-filter-label');
        const filterForm = document.getElementById('filter-form');

        if (! toggleButton || ! toggleLabel || ! filterForm) {
            return;
        }

        toggleButton.addEventListener('click', function () {
            const isHidden = filterForm.classList.contains('hidden');

            if (isHidden) {
                filterForm.classList.remove('hidden');
                filterForm.classList.add('grid');
                toggleLabel.textContent = 'Hide Filter';
            } else {
                filterForm.classList.add('hidden');
                filterForm.classList.remove('grid');
                toggleLabel.textContent = 'Show Filter';
            }
        });
    });
    </script>
    @endpush
