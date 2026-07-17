@extends('layouts.purchasing-lite')

@section('title', 'Cost Control Review - Purchasing Lite')

@section('content')
@php
$selectedOfferItemIds = $selectedOfferItemIds ?? [];
$vendorBids = $vendorBids ?? [];

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

$allItemsHaveSelectedVendor = true;

if ($purchaseRequest->items->count() < 1) { $allItemsHaveSelectedVendor=false; } else { foreach ($purchaseRequest->items as $checkItem) {
    if (empty($selectedOfferItemIds[$checkItem->id])) {
    $allItemsHaveSelectedVendor = false;
    break;
    }
    }
    }

    $statusLabel = ucwords(str_replace('_', ' ', (string) $purchaseRequest->status));
    $prNumber = $purchaseRequest->pr_number ?? $purchaseRequest->request_number ?? '-';

    $latestGmReturnLog = null;

    if ($purchaseRequest->relationLoaded('logs')) {
    $latestGmReturnLog = $purchaseRequest->logs
    ->whereIn('action', [
    'returned_to_cost_control_from_gm',
    'return_to_cost_control_from_gm',
    ])
    ->sortByDesc(function ($log) {
    return $log->acted_at ?? $log->created_at;
    })
    ->first();
    } elseif (method_exists($purchaseRequest, 'logs')) {
    $latestGmReturnLog = $purchaseRequest->logs()
    ->with('user')
    ->whereIn('action', [
    'returned_to_cost_control_from_gm',
    'return_to_cost_control_from_gm',
    ])
    ->latest('acted_at')
    ->latest('created_at')
    ->first();
    }

    $gmReturnRemark =
    $latestGmReturnLog->remarks
    ?? $latestGmReturnLog->remark
    ?? $latestGmReturnLog->notes
    ?? null;

    $showGmReturnRemark =
    in_array((string) $purchaseRequest->status, [
    'revision_to_cost_control_from_gm',
    'revision_from_gm',
    ], true)
    && filled($gmReturnRemark);
    @endphp

    <section class="mb-6 border border-slate-300 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-slate-950">
                    Cost Control Review
                </h2>

                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <p class="text-base text-slate-600">
                        {{ $prNumber }} - {{ $purchaseRequest->title }}
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
                    {{ $purchaseRequest->date_needed ? \Carbon\Carbon::parse($purchaseRequest->date_needed)->format('d M Y') : '-' }}
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
                    Status
                </p>

                <p class="mt-2 text-base font-bold text-slate-950">
                    {{ $statusLabel }}
                </p>
            </div>

            <div>
                <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    Created Date
                </p>

                <p class="mt-2 text-base font-bold text-slate-950">
                    {{ $purchaseRequest->created_at ? \Carbon\Carbon::parse($purchaseRequest->created_at)->format('d M Y') : '-' }}
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

    @if ($showGmReturnRemark)
    <section class="mb-6 border border-orange-300 bg-white shadow-sm">
        <div class="border-b border-orange-300 bg-orange-50 px-5 py-4">
            <h3 class="text-lg font-bold text-orange-900">
                GM Return Remark
            </h3>
        </div>

        <div class="p-5">
            <p class="text-sm font-bold uppercase tracking-wide text-orange-700">
                General Manager returned this PR to Cost Control
            </p>

            <div class="mt-3 border border-orange-300 bg-orange-50 p-4">
                <p class="whitespace-pre-line text-base font-bold text-orange-950">
                    {{ $gmReturnRemark }}
                </p>
            </div>

            @if ($latestGmReturnLog)
            <p class="mt-3 text-sm text-slate-600">
                Returned by
                <span class="font-bold text-slate-900">
                    {{ $latestGmReturnLog->user->name ?? 'General Manager' }}
                </span>

                @if (! empty($latestGmReturnLog->acted_at))
                on
                <span class="font-bold text-slate-900">
                    {{ \Carbon\Carbon::parse($latestGmReturnLog->acted_at)->format('d M Y H:i') }}
                </span>
                @elseif (! empty($latestGmReturnLog->created_at))
                on
                <span class="font-bold text-slate-900">
                    {{ \Carbon\Carbon::parse($latestGmReturnLog->created_at)->format('d M Y H:i') }}
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
                Choose Vendor
            </h3>

            <p class="mt-1 text-sm text-slate-600">
                Select one vendor for each item. If the vendor comparison is not enough, return the PR to Purchasing with remarks.
            </p>
        </div>

        <form id="cost-control-selection-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.cost-control.select-vendor', $purchaseRequest) }}">
            @csrf

            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-100">
                            <th class="w-16 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                No
                            </th>

                            <th class="min-w-[260px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Item
                            </th>

                            <th class="w-40 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Files
                            </th>

                            <th class="min-w-[260px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Specification
                            </th>

                            <th class="w-24 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Qty
                            </th>

                            <th class="w-24 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Unit
                            </th>

                            <th class="w-24 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Stock
                            </th>

                            <th class="w-36 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Last Purchase
                            </th>

                            <th class="min-w-[520px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                                Vendor Bids
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($purchaseRequest->items as $item)
                        @php
                        $itemPhotos = $item->item_photos;

                        if (! is_array($itemPhotos) || count($itemPhotos) < 1) { $itemPhotos=$item->item_photo ? [$item->item_photo] : [];
                            }

                            $currentSelectedOfferItemId = old(
                            'selected_offer_item_ids.' . $item->id,
                            $selectedOfferItemIds[$item->id] ?? null
                            );

                            $bids = $vendorBids[$item->id] ?? [];
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

                                <td class="border border-slate-300 px-3 py-3 text-right text-slate-800">
                                    {{ $formatQty($item->quantity) }}
                                </td>

                                <td class="border border-slate-300 px-3 py-3 text-slate-800">
                                    {{ $item->unit ?: '-' }}
                                </td>

                                <td class="border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                                    {{ $item->stock !== null ? $formatQty($item->stock) : '-' }}
                                </td>

                                <td class="border border-slate-300 px-3 py-3 text-center text-slate-800">
                                    {{ $item->last_purchase_date ? $item->last_purchase_date->format('d M Y') : '-' }}
                                </td>

                                <td class="border border-slate-300 px-3 py-3">
                                    @if (! empty($bids))
                                    <div class="space-y-2">
                                        @foreach ($bids as $bid)
                                        @php
                                        $offerItemId = $bid['offer_item_id'] ?? null;
                                        $bidNumber = $bid['bid_number'] ?? $loop->iteration;
                                        $vendorName = $bid['vendor_name'] ?? '-';
                                        $unitPrice = $bid['unit_price'] ?? 0;
                                        $quantity = $bid['quantity'] ?? $item->quantity;
                                        $totalPrice = $bid['total_price'] ?? ((float) $unitPrice * (float) $quantity);
                                        @endphp

                                        <label class="block cursor-pointer border border-slate-300 bg-white px-3 py-3 hover:bg-slate-50">
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="flex items-start gap-3">
                                                    <input type="radio" name="selected_offer_item_ids[{{ $item->id }}]" value="{{ $offerItemId }}" required class="mt-1" @checked((string) $currentSelectedOfferItemId===(string) $offerItemId)>

                                                    <div>
                                                        <p class="font-bold text-slate-950">
                                                            Bid {{ $bidNumber }} - {{ $vendorName }}
                                                        </p>

                                                        <p class="mt-1 text-xs text-slate-600">
                                                            Qty: {{ $formatQty($quantity) }}
                                                        </p>
                                                    </div>
                                                </div>

                                                <div class="text-right">
                                                    <p class="font-bold text-slate-950">
                                                        {{ $formatRupiah($unitPrice) }}
                                                    </p>

                                                    <p class="mt-2 text-xs text-slate-600">
                                                        Total:
                                                        <span class="font-bold">
                                                            {{ $formatRupiah($totalPrice) }}
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                        </label>
                                        @endforeach
                                    </div>
                                    @else
                                    <div class="border border-red-300 bg-red-50 px-3 py-3 text-sm font-bold text-red-700">
                                        No vendor bid found for this item.
                                    </div>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="border border-slate-300 px-4 py-6 text-center text-base text-slate-500">
                                    No item data.
                                </td>
                            </tr>
                            @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end border-t border-slate-300 bg-white p-5">
                <button type="submit" class="inline-flex h-11 w-full items-center justify-center bg-slate-950 px-8 text-sm font-bold text-white transition hover:bg-slate-800 md:w-auto">
                    Save Vendor Selection
                </button>
            </div>
        </form>
    </section>

    <section class="mt-6 border border-slate-300 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 md:flex-row md:justify-end">
            <button type="button" data-open-modal="return-purchasing-modal" class="inline-flex h-11 w-full items-center justify-center border border-blue-700 bg-white px-6 text-sm font-bold text-blue-800 transition hover:bg-blue-50 md:w-auto">
                Return to Purchasing
            </button>

            @if ($allItemsHaveSelectedVendor)
            <form method="POST" action="{{ route('purchasing-lite.purchase-requests.cost-control.send-to-gm', $purchaseRequest) }}" onsubmit="return confirm('Send this PR to GM?');">
                @csrf

                <button type="submit" class="inline-flex h-11 w-full items-center justify-center bg-green-700 px-6 text-sm font-bold text-white transition hover:bg-green-800 md:w-auto">
                    Send to GM
                </button>
            </form>
            @else
            <button type="button" onclick="alert('Please save selected vendor for every item first. After saving, the Send to GM button will be enabled.')" class="inline-flex h-11 w-full items-center justify-center bg-slate-400 px-6 text-sm font-bold text-white md:w-auto">
                Send to GM
            </button>
            @endif
        </div>
    </section>

    <div id="return-purchasing-modal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/50 px-4">
        <div class="w-full max-w-lg border border-slate-300 bg-white shadow-xl">
            <div class="border-b border-slate-300 px-5 py-4">
                <h3 class="text-lg font-bold text-slate-950">
                    Return to Purchasing
                </h3>

                <p class="mt-1 text-sm text-slate-600">
                    Please write why this PR needs to be returned to Purchasing.
                </p>
            </div>

            <form method="POST" action="{{ route('purchasing-lite.purchase-requests.cost-control.return-to-purchasing', $purchaseRequest) }}">
                @csrf

                <div class="p-5">
                    <label class="mb-2 block text-sm font-bold text-slate-800">
                        Remarks
                    </label>

                    <textarea name="remarks" rows="5" required class="w-full border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Example: Vendor comparison is not enough. Please add another vendor."></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-300 p-5">
                    <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
                        Cancel
                    </button>

                    <button type="submit" class="inline-flex h-10 items-center justify-center bg-blue-700 px-6 text-sm font-bold text-white transition hover:bg-blue-800">
                        Return to Purchasing
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endsection

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
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

        document.querySelectorAll('.fixed').forEach(function (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                }
            });
        });
    });
    </script>
    @endpush
