@extends('layouts.purchasing-lite')

@section('title', 'Vendor Comparison - Purchasing Lite')

@section('content')
@php
$savedBids = $savedBids ?? [];

$costControlReturnStatuses = [
'revision_to_purchasing_from_cost_control',
'revision_from_cost_control',
];

$latestCostControlReturnLog = null;

if (method_exists($purchaseRequest, 'logs')) {
$latestCostControlReturnLog = $purchaseRequest->logs()
->whereIn('action', [
'returned_to_purchasing_from_cost_control',
'return_to_purchasing_from_cost_control',
])
->latest('acted_at')
->latest('created_at')
->first();
}

$costControlReturnRemark =
$latestCostControlReturnLog->remarks
?? $latestCostControlReturnLog->remark
?? $latestCostControlReturnLog->notes
?? $purchaseRequest->cost_control_remarks
?? null;

$showCostControlReturnRemark =
in_array((string) $purchaseRequest->status, $costControlReturnStatuses, true)
&& filled($costControlReturnRemark);

$priority = strtolower((string) ($purchaseRequest->priority ?? 'regular'));

$priorityLabel = match ($priority) {
'urgent' => 'Urgent',
'important' => 'Important',
default => 'Regular',
};

$priorityBadgeClass = match ($priority) {
'urgent' => 'border-red-600 bg-red-50 text-red-900',
'important' => 'border-yellow-500 bg-yellow-50 text-yellow-900',
default => 'border-slate-400 bg-slate-100 text-slate-800',
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
@endphp

<section class="mb-6 border border-slate-300 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-950">
                Vendor Comparison
            </h2>

            <div class="mt-1 flex flex-wrap items-center gap-3">
                <p class="text-base text-slate-600">
                    {{ $purchaseRequest->pr_number }} - {{ $purchaseRequest->title }}
                </p>
            </div>
        </div>

        <a href="/purchasing-lite/dashboard" class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
            Back
        </a>
    </div>
</section>

@if ($errors->any())
<section class="mb-6 border border-red-300 bg-red-50 px-5 py-4 text-sm font-medium text-red-800">
    <p class="mb-2 font-bold">Please check the form:</p>

    <ul class="list-inside list-disc space-y-1">
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</section>
@endif

<section class="mb-6 border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-5 py-4">
        <h3 class="text-lg font-bold text-slate-950">
            PR Information
        </h3>
    </div>

    <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-6">
        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Requester
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
                PR Priority
            </p>

            <div class="mt-2">
                <span class="inline-flex min-w-[105px] items-center justify-center border px-3 py-2 text-xs font-bold uppercase leading-tight {{ $priorityBadgeClass }}">
                    {{ $priorityLabel }}
                </span>
            </div>
        </div>

        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Current Step
            </p>

            <p class="mt-2 text-base font-bold capitalize text-slate-950">
                {{ str_replace('_', ' ', $purchaseRequest->current_step) }}
            </p>
        </div>

        <div>
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Created Date
            </p>

            <p class="mt-2 text-base font-bold text-slate-950">
                {{ $purchaseRequest->created_at ? $purchaseRequest->created_at->format('d M Y') : '-' }}
            </p>
        </div>

        <div class="md:col-span-6">
            <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                Requester Remarks
            </p>

            <div class="mt-2 min-h-14 border border-slate-300 bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-800">
                {!! nl2br(e($purchaseRequest->requester_remarks ?: '-')) !!}
            </div>
        </div>
    </div>
</section>

@if ($showCostControlReturnRemark)
<section class="mb-6 border border-orange-300 bg-white shadow-sm">
    <div class="border-b border-orange-300 bg-orange-50 px-5 py-4">
        <h3 class="text-lg font-bold text-orange-900">
            Cost Control Return Remark
        </h3>
    </div>

    <div class="p-5">
        <p class="text-sm font-bold uppercase tracking-wide text-orange-700">
            Cost Control returned this PR to Purchasing
        </p>

        <div class="mt-3 border border-orange-300 bg-orange-50 p-4">
            <p class="whitespace-pre-line text-base font-bold text-orange-950">
                {{ $costControlReturnRemark }}
            </p>
        </div>

        @if ($latestCostControlReturnLog)
        <p class="mt-3 text-sm text-slate-600">
            Returned by
            <span class="font-bold text-slate-900">
                {{ $latestCostControlReturnLog->user->name ?? 'Cost Control' }}
            </span>

            @if (! empty($latestCostControlReturnLog->acted_at))
            on
            <span class="font-bold text-slate-900">
                {{ \Carbon\Carbon::parse($latestCostControlReturnLog->acted_at)->format('d M Y H:i') }}
            </span>
            @endif
        </p>
        @endif
    </div>
</section>
@endif

<section class="border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-5 py-4">
        <h3 class="text-lg font-bold text-slate-950">
            Vendor Bids
        </h3>

        <p class="mt-1 text-sm text-slate-600">
            Search the vendor name. If the vendor does not exist, it will be created when saving.
            Bid 2 and Bid 3 are optional. Cost Control will validate the vendor comparison later.
        </p>
    </div>

    <form id="vendor-bids-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.vendors.store', $purchaseRequest) }}" autocomplete="off">
        @csrf

        <div class="overflow-x-auto">
            <table class="border-collapse text-sm" style="width: 2634px; min-width: 2634px; table-layout: fixed;">
                <colgroup>
                    <col style="width: 56px;">
                    <col style="width: 250px;">
                    <col style="width: 100px;">
                    <col style="width: 280px;">
                    <col style="width: 70px;">
                    <col style="width: 72px;">
                    <col style="width: 76px;">
                    <col style="width: 170px;">
                    <col style="width: 200px;">
                    <col style="width: 320px;">
                    <col style="width: 200px;">
                    <col style="width: 320px;">
                    <col style="width: 200px;">
                    <col style="width: 320px;">
                </colgroup>
                <thead>
                    <tr class="bg-slate-100">
                        <th rowspan="2" class="w-16 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            No
                        </th>

                        <th rowspan="2" class="min-w-[250px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Item Name
                        </th>

                        <th rowspan="2" class="w-40 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Files
                        </th>

                        <th rowspan="2" class="min-w-[250px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Specification
                        </th>

                        <th rowspan="2" class="w-24 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Qty
                        </th>

                        <th rowspan="2" class="w-24 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Unit
                        </th>

                        <th rowspan="2" class="w-24 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Stock
                        </th>

                        <th rowspan="2" class="border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Last Purchase
                        </th>

                        <th colspan="2" class="border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Bid 1
                        </th>

                        <th colspan="2" class="border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Bid 2
                        </th>

                        <th colspan="2" class="border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Bid 3
                        </th>
                    </tr>

                    <tr class="bg-slate-100">
                        <th class="min-w-[220px] border border-slate-300 px-3 py-2 text-center font-bold text-slate-800">
                            Vendor
                        </th>

                        <th class="border border-slate-300 px-3 py-2 text-center font-bold text-slate-800">
                            Price / Unit
                        </th>

                        <th class="min-w-[220px] border border-slate-300 px-3 py-2 text-center font-bold text-slate-800">
                            Vendor
                        </th>

                        <th class="border border-slate-300 px-3 py-2 text-center font-bold text-slate-800">
                            Price / Unit
                        </th>

                        <th class="min-w-[220px] border border-slate-300 px-3 py-2 text-center font-bold text-slate-800">
                            Vendor
                        </th>

                        <th class="border border-slate-300 px-3 py-2 text-center font-bold text-slate-800">
                            Price / Unit
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($purchaseRequest->items as $item)
                    @php
                    $itemPhotos = $item->item_photos;

                    if (! is_array($itemPhotos) || count($itemPhotos) < 1) { $itemPhotos=$item->item_photo ? [$item->item_photo] : [];
                        }

                        $quantityValue = old(
                        'items.' . $item->id . '.quantity',
                        rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.')
                        );

                        $unitValue = old('items.' . $item->id . '.unit', $item->unit);
                        $lastPurchaseDateValue = old(
                        'items.' . $item->id . '.last_purchase_date',
                        $item->last_purchase_date ? $item->last_purchase_date->format('Y-m-d') : ''
                        );
                        @endphp

                        <tr>
                            <td class="border border-slate-300 px-3 py-3 text-center font-bold text-slate-700">
                                {{ $loop->iteration }}
                            </td>

                            <td class="border border-slate-300 px-3 py-3 font-bold text-slate-900">
                                {{ $item->item_name }}
                            </td>

                            <td class="border border-slate-300 px-3 py-2 align-top">
                                @if (! empty($itemPhotos))
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($itemPhotos as $photo)
                                    <a href="{{ asset('storage/' . ltrim($photo, '/')) }}" target="_blank" class="block">
                                        @if ($isAttachmentImage($photo))
                                        <img src="{{ asset('storage/' . ltrim($photo, '/')) }}" alt="" class="h-12 w-12 border border-slate-300 object-cover">
                                        @else
                                        <span class="flex h-12 w-24 items-center border border-slate-300 bg-slate-50 px-2 text-xs font-bold text-slate-700">
                                            {{ basename($photo) }}
                                        </span>
                                        @endif
                                    </a>
                                    @endforeach
                                </div>
                                @else
                                <span class="text-slate-400">-</span>
                                @endif
                            </td>

                            <td class="border border-slate-300 px-3 py-3 text-slate-800">
                                {{ $item->specification ?: '-' }}
                            </td>

                            <td class="border border-slate-300 p-0 align-top">
                                <input type="text" name="items[{{ $item->id }}][quantity]" value="{{ $quantityValue }}" inputmode="decimal" autocomplete="off" class="h-11 w-full border-0 bg-white px-3 text-right text-sm font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">
                            </td>

                            <td class="border border-slate-300 p-0 align-top">
                                <input type="text" name="items[{{ $item->id }}][unit]" value="{{ $unitValue }}" autocomplete="off" spellcheck="false" class="h-11 w-full border-0 bg-white px-3 text-sm font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">
                            </td>

                            <td class="border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                                {{ $item->stock !== null ? rtrim(rtrim(number_format((float) $item->stock, 2, '.', ''), '0'), '.') : '-' }}
                            </td>

                            <td class="border border-slate-300 p-0 align-top">
                                <input type="date" name="items[{{ $item->id }}][last_purchase_date]" value="{{ $lastPurchaseDateValue }}" class="h-11 w-full border-0 bg-white px-3 text-sm font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">
                            </td>

                            @for ($bidNumber = 1; $bidNumber <= 3; $bidNumber++) @php $savedVendorName=$savedBids[$item->id][$bidNumber]['vendor_name'] ?? '';
                                $savedUnitPrice = $savedBids[$item->id][$bidNumber]['unit_price'] ?? '';

                                if ($savedUnitPrice !== '') {
                                $savedUnitPrice = (string) (int) $savedUnitPrice;
                                }

                                $vendorNameValue = old('bids.' . $item->id . '.' . $bidNumber . '.vendor_name', $savedVendorName);
                                $unitPriceValue = old('bids.' . $item->id . '.' . $bidNumber . '.unit_price', $savedUnitPrice);
                                @endphp

                                <td class="relative align-top border border-slate-300 p-0">
                                    <input type="text" name="bids[{{ $item->id }}][{{ $bidNumber }}][vendor_name]" value="{{ $vendorNameValue }}" placeholder="Vendor {{ $bidNumber }}" autocomplete="off" spellcheck="false" class="js-vendor-search h-11 w-full border-0 bg-white px-3 text-sm font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">

                                    <div class="js-vendor-results absolute left-0 top-full z-[9999] hidden w-[360px] border border-slate-300 bg-white shadow-lg"></div>
                                </td>

                                <td class="align-top border border-slate-300 p-0">
                                    <input type="text" name="bids[{{ $item->id }}][{{ $bidNumber }}][unit_price]" value="{{ $unitPriceValue }}" inputmode="numeric" autocomplete="off" placeholder="Rp 0" class="js-rupiah-input h-11 w-full border-0 bg-white px-3 text-right text-sm font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">
                                </td>
                                @endfor
                        </tr>
                        @empty
                        <tr>
                            <td colspan="14" class="border border-slate-300 px-4 py-6 text-center text-base text-slate-500">
                                No item data.
                            </td>
                        </tr>
                        @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex justify-end border-t border-slate-300 bg-white p-5">
            <button type="submit" class="inline-flex h-11 w-full items-center justify-center bg-slate-950 px-8 text-sm font-bold text-white transition hover:bg-slate-800 md:w-auto">
                Save Bids
            </button>
        </div>
    </form>
</section>

<section class="mt-6 border border-slate-300 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-3 md:flex-row md:justify-end">
        <button type="button" data-open-modal="send-back-requester-modal" class="inline-flex h-11 w-full items-center justify-center border border-blue-700 bg-white px-6 text-sm font-bold text-blue-800 transition hover:bg-blue-50 md:w-auto">
            Send Back to Requester
        </button>

        <button type="button" data-open-modal="reject-requester-modal" class="inline-flex h-11 w-full items-center justify-center border border-red-700 bg-white px-6 text-sm font-bold text-red-800 transition hover:bg-red-50 md:w-auto">
            Reject PR
        </button>

        <form method="POST" action="{{ route('purchasing-lite.purchase-requests.vendors.send-to-cost-control', $purchaseRequest) }}" onsubmit="return confirm('Send this PR to Cost Control? Cost Control will validate if the vendor comparison is enough.');">
            @csrf

            <button type="submit" class="inline-flex h-11 w-full items-center justify-center bg-green-700 px-6 text-sm font-bold text-white transition hover:bg-green-800 md:w-auto">
                Send to Cost Control
            </button>
        </form>
    </div>
</section>

<div id="send-back-requester-modal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/50 px-4">
    <div class="w-full max-w-lg border border-slate-300 bg-white shadow-xl">
        <div class="border-b border-slate-300 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">
                Send Back to Requester
            </h3>

            <p class="mt-1 text-sm text-slate-600">
                Please write the reason or revision notes for the requester.
            </p>
        </div>

        <form method="POST" action="{{ route('purchasing-lite.purchase-requests.vendors.send-back-to-requester', $purchaseRequest) }}">
            @csrf

            <div class="p-5">
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Remarks
                </label>

                <textarea name="remarks" rows="5" required class="w-full border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Example: Please revise the item specification or quantity."></textarea>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-300 p-5">
                <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
                    Cancel
                </button>

                <button type="submit" class="inline-flex h-10 items-center justify-center bg-blue-700 px-6 text-sm font-bold text-white transition hover:bg-blue-800">
                    Send Back
                </button>
            </div>
        </form>
    </div>
</div>

<div id="reject-requester-modal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/50 px-4">
    <div class="w-full max-w-lg border border-slate-300 bg-white shadow-xl">
        <div class="border-b border-slate-300 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">
                Reject PR
            </h3>

            <p class="mt-1 text-sm text-slate-600">
                Please write the rejection reason for the requester.
            </p>
        </div>

        <form method="POST" action="{{ route('purchasing-lite.purchase-requests.vendors.reject-to-requester', $purchaseRequest) }}">
            @csrf

            <div class="p-5">
                <label class="mb-2 block text-sm font-bold text-slate-800">
                    Rejection Reason
                </label>

                <textarea name="remarks" rows="5" required class="w-full border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100" placeholder="Example: PR rejected because the item is not approved for purchase."></textarea>
            </div>

            <div class="flex justify-end gap-3 border-t border-slate-300 p-5">
                <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
                    Cancel
                </button>

                <button type="submit" class="inline-flex h-10 items-center justify-center bg-red-700 px-6 text-sm font-bold text-white transition hover:bg-red-800">
                    Reject PR
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const vendorSearchUrl = @json(route('purchasing-lite.vendors.search'));
        const vendorBidsForm = document.getElementById('vendor-bids-form');
        const searchTimers = {};

        function closeAllVendorResults() {
            document.querySelectorAll('.js-vendor-results').forEach(function (box) {
                box.classList.add('hidden');
                box.innerHTML = '';
            });

            document.querySelectorAll('td.z-\\[9999\\]').forEach(function (td) {
                td.classList.remove('z-[9999]');
            });
        }

        function onlyDigits(value) {
            return String(value ?? '').replace(/[^\d]/g, '');
        }

        function formatRupiah(value) {
            const digits = onlyDigits(value);

            if (digits === '') {
                return '';
            }

            return 'Rp ' + digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function unformatRupiah(value) {
            return onlyDigits(value);
        }

        function setupRupiahMasking() {
            document.querySelectorAll('.js-rupiah-input').forEach(function (input) {
                input.value = formatRupiah(input.value);

                input.addEventListener('input', function () {
                    input.value = formatRupiah(input.value);
                    input.setSelectionRange(input.value.length, input.value.length);
                });

                input.addEventListener('blur', function () {
                    input.value = formatRupiah(input.value);
                });
            });

            if (vendorBidsForm) {
                vendorBidsForm.addEventListener('submit', function () {
                    document.querySelectorAll('.js-rupiah-input').forEach(function (input) {
                        input.value = unformatRupiah(input.value);
                    });
                });
            }
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        document.querySelectorAll('.js-vendor-search').forEach(function (input) {
            const wrapper = input.closest('td');
            const resultBox = wrapper.querySelector('.js-vendor-results');

            input.addEventListener('focus', function () {
                wrapper.classList.add('z-[9999]');
            });

            input.addEventListener('input', function () {
                const query = input.value.trim();

                clearTimeout(searchTimers[input.name]);

                if (query.length < 2) {
                    resultBox.classList.add('hidden');
                    resultBox.innerHTML = '';
                    return;
                }

                searchTimers[input.name] = setTimeout(function () {
                    fetch(vendorSearchUrl + '?q=' + encodeURIComponent(query), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (vendors) {
                            resultBox.innerHTML = '';

                            if (! vendors.length) {
                                resultBox.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">No vendor found. It will be created when saved.</div>';
                                resultBox.classList.remove('hidden');
                                wrapper.classList.add('z-[9999]');
                                return;
                            }

                            vendors.forEach(function (vendor) {
                                const button = document.createElement('button');

                                button.type = 'button';
                                button.className = 'block w-full border-b border-slate-200 px-3 py-2 text-left text-sm hover:bg-slate-100';
                                button.innerHTML =
                                    '<div class="font-bold text-slate-900">' + escapeHtml(vendor.name) + '</div>' +
                                    (vendor.phone ? '<div class="text-xs text-slate-500">Phone: ' + escapeHtml(vendor.phone) + '</div>' : '') +
                                    (vendor.email ? '<div class="text-xs text-slate-500">Email: ' + escapeHtml(vendor.email) + '</div>' : '');

                                button.addEventListener('click', function () {
                                    input.value = vendor.name;
                                    resultBox.classList.add('hidden');
                                    resultBox.innerHTML = '';
                                    wrapper.classList.remove('z-[9999]');
                                });

                                resultBox.appendChild(button);
                            });

                            resultBox.classList.remove('hidden');
                            wrapper.classList.add('z-[9999]');
                        })
                        .catch(function () {
                            resultBox.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">Vendor search unavailable.</div>';
                            resultBox.classList.remove('hidden');
                            wrapper.classList.add('z-[9999]');
                        });
                }, 250);
            });
        });

        setupRupiahMasking();

        document.addEventListener('click', function (event) {
            if (! event.target.closest('.js-vendor-search') && ! event.target.closest('.js-vendor-results')) {
                closeAllVendorResults();
            }
        });

        document.querySelectorAll('[data-open-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                const modalId = button.getAttribute('data-open-modal');
                const modal = document.getElementById(modalId);

                if (modal) {
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                }
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                const modal = button.closest('.fixed');

                if (modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            });
        });
    });
</script>
@endpush
