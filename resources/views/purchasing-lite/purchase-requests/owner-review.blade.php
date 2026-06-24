@extends('layouts.purchasing-lite')

@section('title', 'OR Review - Purchasing Lite')

@section('content')
@php
$selectedVendorItems = $selectedVendorItems ?? [];
$selectedGrandTotal = $selectedGrandTotal ?? 0;

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

$statusLabel = str_replace('Owner', 'OR', ucwords(str_replace('_', ' ', (string) $purchaseRequest->status)));
$currentStepLabel = str_replace('Owner', 'OR', ucwords(str_replace('_', ' ', (string) $purchaseRequest->current_step)));
$prNumber = $purchaseRequest->pr_number ?? $purchaseRequest->request_number ?? '-';

$allItemsHaveSelectedVendor = true;

if ($purchaseRequest->items->count() < 1) { $allItemsHaveSelectedVendor=false; } else { foreach ($purchaseRequest->items as $checkItem) {
    if (empty($selectedVendorItems[$checkItem->id])) {
    $allItemsHaveSelectedVendor = false;
    break;
    }
    }
    }

    $requesterRemarksText = (string) ($purchaseRequest->requester_remarks ?? '');

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
    @endphp

    <section class="mb-6 border border-slate-300 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-slate-950">
                    OR Review
                </h2>

                <p class="mt-1 text-base text-slate-600">
                    {{ $prNumber }} - {{ $purchaseRequest->title }}
                </p>
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
                    {{ $purchaseRequest->date_needed ? \Carbon\Carbon::parse($purchaseRequest->date_needed)->format('d M Y') : '-' }}
                </p>
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
                    Current Step
                </p>

                <p class="mt-2 text-base font-bold text-slate-950">
                    {{ $currentStepLabel }}
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
                    Remarks
                </p>

                <div class="mt-2 min-h-14 border border-slate-300 bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-800">
                    {!! nl2br(e($purchaseRequest->requester_remarks ?: '-')) !!}
                </div>
            </div>

            @if ($showGmSplitRemark)
            <div class="md:col-span-3">
                <p class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    GM Split Remarks
                </p>

                <div class="mt-2 min-h-14 border border-orange-300 bg-orange-50 px-3 py-3 text-sm font-bold leading-6 text-orange-950">
                    {!! nl2br(e($gmSplitRemark)) !!}
                </div>
            </div>
            @endif
        </div>
    </section>

    <section class="border border-slate-300 bg-white shadow-sm">
        <div class="border-b border-slate-300 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">
                Selected Vendor
            </h3>

            <p class="mt-1 text-sm text-slate-600">
                Review the vendor selected by Cost Control and approved by GM.
            </p>
        </div>

        @if (! $allItemsHaveSelectedVendor)
        <div class="border-b border-red-300 bg-red-50 px-5 py-4 text-sm font-bold text-red-800">
            Selected vendor data is incomplete. Please return this PR to GM.
        </div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-100">
                        <th class="w-16 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            No
                        </th>

                        <th class="w-40 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Files
                        </th>

                        <th class="min-w-[260px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Item Name
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

                        <th class="min-w-[220px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Selected Vendor
                        </th>

                        <th class="w-40 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Unit Price
                        </th>

                        <th class="w-44 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Total
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($purchaseRequest->items as $item)
                    @php
                    $itemPhotos = $item->item_photos;

                    if (! is_array($itemPhotos) || count($itemPhotos) < 1) { $itemPhotos=$item->item_photo ? [$item->item_photo] : [];
                        }

                        $selectedVendorItem = $selectedVendorItems[$item->id] ?? null;
                        @endphp

                        <tr>
                            <td class="border border-slate-300 px-3 py-3 text-center font-bold text-slate-700">
                                {{ $loop->iteration }}
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

                            <td class="border border-slate-300 px-3 py-3 font-bold text-slate-900">
                                {{ $item->item_name }}
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

                            @if ($selectedVendorItem)
                            <td class="border border-slate-300 px-3 py-3 font-bold text-slate-950">
                                {{ $selectedVendorItem['vendor_name'] ?? '-' }}
                            </td>

                            <td class="border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                                {{ $formatRupiah($selectedVendorItem['unit_price'] ?? 0) }}
                            </td>

                            <td class="border border-slate-300 px-3 py-3 text-right font-bold text-slate-950">
                                {{ $formatRupiah($selectedVendorItem['total_price'] ?? 0) }}
                            </td>
                            @else
                            <td colspan="3" class="border border-red-300 bg-red-50 px-3 py-3 text-center font-bold text-red-700">
                                No selected vendor
                            </td>
                            @endif
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="border border-slate-300 px-4 py-6 text-center text-base text-slate-500">
                                No item data.
                            </td>
                        </tr>
                        @endforelse
                </tbody>

                <tfoot>
                    <tr class="bg-slate-100">
                        <td colspan="9" class="border border-slate-300 px-3 py-4 text-right text-base font-bold text-slate-950">
                            Grand Total
                        </td>

                        <td class="border border-slate-300 px-3 py-4 text-right text-base font-bold text-slate-950">
                            {{ $formatRupiah($selectedGrandTotal) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex flex-col justify-end gap-3 border-t border-slate-300 p-5 md:flex-row">
            <button type="button" data-open-modal="return-gm-modal" class="inline-flex h-10 items-center justify-center border border-blue-700 bg-white px-6 text-sm font-bold text-blue-800 transition hover:bg-blue-50">
                Return to GM
            </button>

            <button type="button" data-open-modal="reject-requester-modal" class="inline-flex h-10 items-center justify-center border border-red-700 bg-white px-6 text-sm font-bold text-red-800 transition hover:bg-red-50">
                Reject PR
            </button>

            @if ($allItemsHaveSelectedVendor)
            <button type="button" data-open-modal="approve-modal" class="inline-flex h-10 items-center justify-center bg-green-700 px-6 text-sm font-bold text-white transition hover:bg-green-800">
                Approve
            </button>
            @else
            <button type="button" onclick="alert('Selected vendor data is incomplete. Please return this PR to GM.')" class="inline-flex h-10 items-center justify-center bg-slate-400 px-6 text-sm font-bold text-white">
                Approve
            </button>
            @endif
        </div>
    </section>

    <div id="approve-modal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/50 px-4">
        <div class="w-full max-w-lg border border-slate-300 bg-white shadow-xl">
            <div class="border-b border-slate-300 px-5 py-4">
                <h3 class="text-lg font-bold text-slate-950">
                    Approve PR
                </h3>

                <p class="mt-1 text-sm text-slate-600">
                    This will approve this PR.
                </p>
            </div>

            <form method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.approve', $purchaseRequest) }}" onsubmit="return confirm('Approve this PR?');">
                @csrf

                <div class="p-5">
                    <label class="mb-2 block text-sm font-bold text-slate-800">
                        Remarks
                    </label>

                    <textarea name="remarks" rows="5" class="w-full border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100" placeholder="Optional remarks."></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-300 p-5">
                    <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
                        Cancel
                    </button>

                    <button type="submit" class="inline-flex h-10 items-center justify-center bg-green-700 px-6 text-sm font-bold text-white transition hover:bg-green-800">
                        Approve
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="return-gm-modal" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/50 px-4">
        <div class="w-full max-w-lg border border-slate-300 bg-white shadow-xl">
            <div class="border-b border-slate-300 px-5 py-4">
                <h3 class="text-lg font-bold text-slate-950">
                    Return to GM
                </h3>

                <p class="mt-1 text-sm text-slate-600">
                    Please write why this PR needs to be returned to GM.
                </p>
            </div>

            <form method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.return-to-gm', $purchaseRequest) }}">
                @csrf

                <div class="p-5">
                    <label class="mb-2 block text-sm font-bold text-slate-800">
                        Remarks
                    </label>

                    <textarea name="remarks" rows="5" required class="w-full border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Example: Please review the selected vendor again."></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-300 p-5">
                    <button type="button" data-close-modal class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
                        Cancel
                    </button>

                    <button type="submit" class="inline-flex h-10 items-center justify-center bg-blue-700 px-6 text-sm font-bold text-white transition hover:bg-blue-800">
                        Return to GM
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

            <form method="POST" action="{{ route('purchasing-lite.purchase-requests.owner.reject-to-requester', $purchaseRequest) }}">
                @csrf

                <div class="p-5">
                    <label class="mb-2 block text-sm font-bold text-slate-800">
                        Rejection Reason
                    </label>

                    <textarea name="remarks" rows="5" required class="w-full border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100" placeholder="Example: PR rejected because the purchase is not approved."></textarea>
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
