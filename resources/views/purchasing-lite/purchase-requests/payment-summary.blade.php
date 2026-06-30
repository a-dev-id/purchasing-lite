@extends('layouts.purchasing-lite')

@section('title', 'General Payment Summary - Purchasing Lite')

@section('content')
@php
$formatRupiah = function ($value) {
return 'Rp ' . number_format((float) $value, 0, ',', '.');
};

$formatQty = function ($value) {
return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
};

$grandTotal = 0;
@endphp

<section class="mb-4 border border-slate-300 bg-white p-4 shadow-sm">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-950">
                General Payment Summary
            </h2>

            <p class="mt-1 text-sm text-slate-600">
                All PR items marked On Progress by Accounting/Financial Controller.
            </p>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row">
            <a href="{{ route('purchasing-lite.dashboard') }}" class="inline-flex h-9 items-center justify-center border border-slate-300 bg-white px-5 text-xs font-bold text-slate-800 transition hover:bg-slate-50">
                Back
            </a>

            <a href="{{ route('purchasing-lite.purchasing.payment-summary.pdf') }}" class="inline-flex h-9 items-center justify-center bg-blue-700 px-5 text-xs font-bold text-white transition hover:bg-blue-800">
                Download PDF
            </a>
        </div>
    </div>
</section>

<section class="border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-4 py-3">
        <h3 class="text-base font-bold text-slate-950">
            Summary Detail
        </h3>
    </div>

    @if ($canEditPaymentSummary)
    <form method="POST" action="{{ route('purchasing-lite.purchasing.payment-summary.save') }}">
        @csrf
    @endif

    <div class="overflow-x-auto">
        <table class="border-collapse text-xs" style="width: 2300px; min-width: 2300px; table-layout: fixed;">
            <thead>
                <tr class="bg-slate-100">
                    <th style="width: 46px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">No</th>
                    <th style="width: 150px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">PR Number</th>
                    <th style="width: 190px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Requester</th>
                    <th style="width: 190px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Department</th>
                    <th style="width: 260px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Item Name</th>
                    <th style="width: 330px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Specification</th>
                    <th style="width: 60px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Qty</th>
                    <th style="width: 70px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Unit</th>
                    <th style="width: 420px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Vendor</th>
                    <th style="width: 145px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Price / Unit</th>
                    <th style="width: 145px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Total</th>
                    <th style="width: 150px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Payment Method</th>
                    <th style="width: 340px;" class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">Note</th>
                </tr>
            </thead>

            <tbody>
                @php $rowNumber = 1; @endphp
                @forelse ($purchaseRequests as $purchaseRequest)
                @php
                $items = $purchaseRequest->items ?? collect();
                $rowspan = max($items->count(), 1);
                @endphp
                @foreach ($purchaseRequest->items as $item)
                @php
                $itemVendorOptions = $vendorBidOptions[$purchaseRequest->id][$item->id] ?? [];
                $itemVendorOptionsByBid = collect($itemVendorOptions)->keyBy(fn($vendorOption) => (int) ($vendorOption['bid_number'] ?? 0));
                $selectedVendorOption = collect($itemVendorOptions)->first(fn($vendorOption) => (bool) ($vendorOption['is_selected'] ?? false));

                if (! $selectedVendorOption && ! empty($itemVendorOptions)) {
                $selectedVendorOption = $itemVendorOptions[0];
                }

                $selectedOfferItemId = old('items.' . $item->id . '.selected_offer_item_id', $selectedVendorOption['offer_item_id'] ?? null);
                $oldUnitPriceValue = old('items.' . $item->id . '.unit_price');
                $unitPriceValue = $oldUnitPriceValue !== null
                ? (str_starts_with(trim((string) $oldUnitPriceValue), 'Rp') ? $oldUnitPriceValue : 'Rp ' . $oldUnitPriceValue)
                : ($selectedVendorOption ? $formatRupiah($selectedVendorOption['unit_price'] ?? 0) : '');
                $quantityValue = (float) ($selectedVendorOption['quantity'] ?? $item->quantity ?? 0);
                $totalValue = $selectedVendorOption ? (float) ($selectedVendorOption['total_price'] ?? 0) : 0;
                $grandTotal += $totalValue;
                $paymentMethodValue = old('items.' . $item->id . '.payment_method', $item->purchasing_payment_method);
                $paymentNoteValue = old('items.' . $item->id . '.payment_note', $item->purchasing_payment_note);
                @endphp

                <tr class="hover:bg-slate-50">
                    @if ($loop->first)
                    <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-2 py-2 text-center font-bold text-slate-700">{{ $rowNumber }}</td>
                    <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-2 py-2 font-bold text-slate-950">{{ $purchaseRequest->pr_number }}</td>
                    <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-2 py-2 text-slate-800">{{ $purchaseRequest->requester_name ?? '-' }}</td>
                    <td rowspan="{{ $rowspan }}" class="align-middle border border-slate-300 px-2 py-2 text-slate-800">{{ $purchaseRequest->department_name ?? '-' }}</td>
                    @endif
                    <td class="border border-slate-300 px-2 py-2 font-bold text-slate-950">{{ $item->item_name }}</td>
                    <td class="border border-slate-300 px-2 py-2 text-slate-800">{{ $item->specification ?: '-' }}</td>
                    <td class="border border-slate-300 px-2 py-2 text-right text-slate-800">{{ $formatQty($item->quantity) }}</td>
                    <td class="border border-slate-300 px-2 py-2 text-slate-800">{{ $item->unit ?: '-' }}</td>
                    <td class="border border-slate-300 px-2 py-2">
                        @if ($canEditPaymentSummary)
                        <div class="grid grid-cols-3 gap-1.5">
                            @for ($bidNumber = 1; $bidNumber <= 3; $bidNumber++)
                            @php $vendorOption = $itemVendorOptionsByBid->get($bidNumber); @endphp

                            @if ($vendorOption)
                            <label class="flex min-h-16 cursor-pointer items-start gap-2 border border-slate-300 bg-white px-2 py-1.5 hover:bg-slate-50">
                                <input type="radio" name="items[{{ $item->id }}][selected_offer_item_id]" value="{{ $vendorOption['offer_item_id'] }}" data-summary-vendor-option data-unit-price="{{ (float) ($vendorOption['unit_price'] ?? 0) }}" data-quantity="{{ (float) ($vendorOption['quantity'] ?? $item->quantity ?? 0) }}" class="mt-0.5" @checked((string) $selectedOfferItemId === (string) ($vendorOption['offer_item_id'] ?? ''))>
                                <span class="min-w-0">
                                    <span class="block text-xs font-bold text-slate-950">Bid {{ $bidNumber }}</span>
                                    <span class="mt-0.5 block truncate text-[11px] font-bold text-slate-800">{{ $vendorOption['vendor_name'] ?? '-' }}</span>
                                    <span class="mt-0.5 block text-[11px] font-bold text-slate-600">{{ $formatRupiah($vendorOption['unit_price'] ?? 0) }}</span>
                                </span>
                            </label>
                            @else
                            <div class="min-h-16 border border-slate-200 bg-slate-50 px-2 py-1.5">
                                <span class="block text-xs font-bold text-slate-500">Bid {{ $bidNumber }}</span>
                                <span class="mt-0.5 block text-[11px] font-bold text-slate-400">No bid</span>
                            </div>
                            @endif
                            @endfor
                        </div>
                        @else
                        <p class="font-bold text-slate-950">{{ $selectedVendorOption['vendor_name'] ?? 'No selected vendor' }}</p>
                        @endif
                    </td>
                    <td class="border border-slate-300 p-0">
                        @if ($canEditPaymentSummary)
                        <input type="text" name="items[{{ $item->id }}][unit_price]" value="{{ $unitPriceValue }}" data-summary-price-input data-quantity="{{ $quantityValue }}" class="h-9 w-full border-0 bg-white px-2 text-right text-xs font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">
                        @else
                        <p class="px-2 py-2 text-right font-bold text-slate-950">{{ $selectedVendorOption ? $formatRupiah($selectedVendorOption['unit_price'] ?? 0) : '-' }}</p>
                        @endif
                    </td>
                    <td class="border border-slate-300 px-2 py-2 text-right font-bold text-slate-950" data-summary-row-total>{{ $formatRupiah($totalValue) }}</td>
                    <td class="border border-slate-300 p-0">
                        @if ($canEditPaymentSummary)
                        <select name="items[{{ $item->id }}][payment_method]" class="h-9 w-full border-0 bg-white px-2 text-xs font-bold text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">
                            <option value="">Select</option>
                            <option value="cash" @selected($paymentMethodValue === 'cash')>Cash</option>
                            <option value="credit" @selected($paymentMethodValue === 'credit')>Credit</option>
                            <option value="transfer" @selected($paymentMethodValue === 'transfer')>Transfer</option>
                        </select>
                        @else
                        <p class="px-2 py-2 font-bold text-slate-950">{{ $paymentMethodValue ? ucfirst($paymentMethodValue) : '-' }}</p>
                        @endif
                    </td>
                    <td class="border border-slate-300 p-0">
                        @if ($canEditPaymentSummary)
                        <textarea name="items[{{ $item->id }}][payment_note]" rows="2" class="min-h-9 w-full resize-y border-0 bg-white px-2 py-1.5 text-xs text-slate-900 outline-none focus:ring-2 focus:ring-blue-100">{{ $paymentNoteValue }}</textarea>
                        @else
                        <p class="whitespace-pre-line px-2 py-2 text-slate-800">{{ $paymentNoteValue ?: '-' }}</p>
                        @endif
                    </td>
                </tr>
                @endforeach
                @php $rowNumber++; @endphp
                @empty
                <tr>
                    <td colspan="13" class="border border-slate-300 px-3 py-6 text-center text-sm text-slate-500">
                        No On Progress PR available for payment summary.
                    </td>
                </tr>
                @endforelse
            </tbody>

            <tfoot>
                <tr class="bg-slate-100">
                    <td colspan="10" class="border border-slate-300 px-2 py-3 text-right text-sm font-bold text-slate-950">Grand Total</td>
                    <td class="border border-slate-300 px-2 py-3 text-right text-sm font-bold text-slate-950" data-summary-grand-total>{{ $formatRupiah($grandTotal) }}</td>
                    <td colspan="2" class="border border-slate-300 px-2 py-3"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($canEditPaymentSummary)
    <div class="flex justify-end border-t border-slate-300 bg-white p-3">
        <button type="submit" class="inline-flex h-9 items-center justify-center bg-slate-950 px-5 text-xs font-bold text-white transition hover:bg-slate-800">
            Save General Summary
        </button>
    </div>
    </form>
    @endif
</section>
@endsection

@push('scripts')
<script>
    const parseSummaryMoney = function (value) {
        value = String(value || '').trim().replace(/[^0-9.,]/g, '');

        if (value === '') {
            return 0;
        }

        const hasComma = value.includes(',');
        const hasDot = value.includes('.');

        if (hasComma && hasDot) {
            value = value.lastIndexOf(',') > value.lastIndexOf('.')
                ? value.replace(/\./g, '').replace(',', '.')
                : value.replace(/,/g, '');

            return Number(value) || 0;
        }

        if (hasComma) {
            const parts = value.split(',');
            const lastPart = parts[parts.length - 1] || '';
            value = lastPart.length === 3 ? value.replace(/,/g, '') : value.replace(',', '.');

            return Number(value) || 0;
        }

        if (hasDot) {
            const parts = value.split('.');
            const lastPart = parts[parts.length - 1] || '';

            if (lastPart.length === 3) {
                value = value.replace(/\./g, '');
            }
        }

        return Number(value) || 0;
    };

    const formatSummaryRupiah = function (value) {
        return 'Rp ' + Math.round(Number(value) || 0).toLocaleString('id-ID');
    };

    const updatePaymentSummaryTotals = function () {
        let grandTotal = 0;

        document.querySelectorAll('[data-summary-price-input]').forEach(function (input) {
            const row = input.closest('tr');
            const quantity = Number(input.getAttribute('data-quantity') || 0);
            const total = parseSummaryMoney(input.value) * quantity;
            const totalCell = row ? row.querySelector('[data-summary-row-total]') : null;

            if (totalCell) {
                totalCell.textContent = formatSummaryRupiah(total);
            }

            grandTotal += total;
        });

        const grandTotalCell = document.querySelector('[data-summary-grand-total]');

        if (grandTotalCell) {
            grandTotalCell.textContent = formatSummaryRupiah(grandTotal);
        }
    };

    document.addEventListener('change', function (event) {
        const vendorOption = event.target.closest('[data-summary-vendor-option]');

        if (! vendorOption) {
            return;
        }

        const row = vendorOption.closest('tr');
        const priceInput = row ? row.querySelector('[data-summary-price-input]') : null;

        if (priceInput) {
            priceInput.value = formatSummaryRupiah(Number(vendorOption.getAttribute('data-unit-price') || 0));
            priceInput.setAttribute('data-quantity', String(Number(vendorOption.getAttribute('data-quantity') || 0)));
        }

        updatePaymentSummaryTotals();
    });

    document.addEventListener('input', function (event) {
        if (event.target.closest('[data-summary-price-input]')) {
            updatePaymentSummaryTotals();
        }
    });

    document.addEventListener('blur', function (event) {
        const priceInput = event.target.closest('[data-summary-price-input]');

        if (priceInput) {
            priceInput.value = formatSummaryRupiah(parseSummaryMoney(priceInput.value));
            updatePaymentSummaryTotals();
        }
    }, true);

    updatePaymentSummaryTotals();
</script>
@endpush
