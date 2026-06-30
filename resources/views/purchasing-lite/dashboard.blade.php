@extends('layouts.purchasing-lite')

@section('title', 'Dashboard - Purchasing Lite')

@section('content')
@php
$userRole = strtolower((string) ($user->role ?? $user->role_name ?? ''));
$normalizedRole = str_replace(['-', '_'], ' ', trim($userRole));

$canCreatePr =
$normalizedRole === 'admin'
|| $normalizedRole === 'requester'
|| str_contains($normalizedRole, 'requester')
|| in_array($normalizedRole, [
'it',
'housekeeping',
'housekeeping & garden',
'sales',
'sales & marketing',
'spa',
'essence spa',
], true);

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
'draft' => 'border-slate-400 bg-slate-100 text-slate-800',
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
'rejected',
'cancelled' => 'border-red-600 bg-red-50 text-red-900',
default => 'border-slate-400 bg-white text-slate-800',
};
};

$stepBadgeClass = function ($step) {
$step = strtolower((string) $step);

return match ($step) {
'requester' => 'border-slate-400 bg-slate-100 text-slate-800',
'purchasing' => 'border-blue-500 bg-blue-50 text-blue-900',
'cost_control' => 'border-purple-500 bg-purple-50 text-purple-900',
'gm' => 'border-indigo-500 bg-indigo-50 text-indigo-900',
'owner' => 'border-cyan-500 bg-cyan-50 text-cyan-900',
'financial_controller' => 'border-violet-500 bg-violet-50 text-violet-900',
'completed' => 'border-emerald-600 bg-emerald-50 text-emerald-900',
default => 'border-slate-400 bg-white text-slate-800',
};
};
@endphp

<section class="mb-6 border border-slate-300 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-950">
                Dashboard
            </h2>

            <p class="mt-1 text-base text-slate-600">
                Welcome, {{ $user->name }}.
            </p>
        </div>

        <div class="flex items-center gap-3">
            @if (in_array($normalizedRole, ['admin', 'purchasing', 'financial controller', 'financialcontroller', 'fc', 'accounting'], true))
            <a href="{{ route('purchasing-lite.purchasing.payment-summary') }}" class="inline-flex h-10 items-center justify-center border border-blue-700 bg-white px-6 text-sm font-bold text-blue-800 transition hover:bg-blue-50">
                General Payment Summary
            </a>
            @endif

            <a href="{{ route('purchasing-lite.purchase-requests.meeting-list') }}" class="inline-flex h-10 items-center justify-center border border-slate-950 bg-white px-6 text-sm font-bold text-slate-950 transition hover:bg-slate-100">
                PR List
            </a>

            @if ($canCreatePr)
            <a href="{{ route('purchasing-lite.purchase-requests.create') }}" class="inline-flex h-10 items-center justify-center bg-slate-950 px-6 text-sm font-bold text-white transition hover:bg-slate-800">
                + Create New PR
            </a>
            @endif
        </div>
    </div>
</section>

<section class="border border-slate-300 bg-white shadow-sm">
    <div class="border-b border-slate-300 px-4 py-3">
        <h3 class="text-base font-bold text-slate-950">
            Purchase Request List
        </h3>

        <p class="mt-1 text-xs text-slate-600">
            This table shows PR data based on your account.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="border-collapse text-xs" style="min-width: 1360px;">
            <thead>
                <tr class="bg-slate-100">
                    <th class="w-12 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        No
                    </th>

                    <th class="w-36 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        PR Number
                    </th>

                    <th class="w-32 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Requester
                    </th>

                    <th class="min-w-[220px] border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Title
                    </th>

                    <th class="w-40 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Department
                    </th>

                    <th class="w-28 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Date Needed
                    </th>

                    <th class="w-28 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Priority
                    </th>

                    <th class="w-20 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Items
                    </th>

                    <th class="w-40 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Status
                    </th>

                    <th class="w-36 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Current Step
                    </th>

                    <th class="w-32 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Received Date
                    </th>

                    <th class="w-32 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Hand Over Date
                    </th>

                    <th class="w-28 border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        Action
                    </th>
                </tr>
            </thead>

            <tbody>
                @forelse ($purchaseRequests ?? [] as $purchaseRequest)
                @php
                $status = strtolower((string) ($purchaseRequest->status ?? ''));
                $currentStep = strtolower((string) ($purchaseRequest->current_step ?? ''));
                $priority = strtolower((string) ($purchaseRequest->priority ?? 'regular'));

                $isPurchasingUser = in_array($normalizedRole, ['purchasing', 'admin'], true);

                $needsPurchasingSummary =
                $isPurchasingUser
                && $status === 'on_progress';

                $needsPurchasingFollowUp =
                $currentStep === 'purchasing'
                && in_array($status, [
                'paid_to_vendor',
                'on_shipping',
                'on_delivery',
                'received',
                ], true);

                $isCompletedPr =
                in_array($status, [
                'handed_over_to_requester',
                'completed',
                'done',
                ], true)
                || $currentStep === 'completed';
                @endphp

                <tr class="{{ $isCompletedPr ? 'bg-emerald-50/40' : 'hover:bg-slate-50' }}">
                    <td class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-700">
                        {{ $loop->iteration }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 font-bold text-slate-900">
                        {{ $purchaseRequest->pr_number }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-slate-800">
                        {{ $purchaseRequest->requester_name ?? '-' }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-slate-800">
                        {{ $purchaseRequest->title }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-slate-800">
                        {{ $purchaseRequest->department_name ?? '-' }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center text-slate-800">
                        {{ $formatDate($purchaseRequest->date_needed ?? null) }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center">
                        <span class="inline-flex min-w-[82px] items-center justify-center border px-2 py-1 text-[11px] font-bold uppercase leading-tight {{ $priorityBadgeClass($priority) }}">
                            {{ $formatPriority($priority) }}
                        </span>
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center text-slate-800">
                        {{ $purchaseRequest->items_count ?? 0 }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center">
                        <span class="inline-flex min-w-[125px] items-center justify-center border px-2 py-1 text-[11px] font-bold uppercase leading-tight {{ $statusBadgeClass($purchaseRequest->status) }}">
                            {{ $formatStatus($purchaseRequest->status) }}
                        </span>
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center">
                        <span class="inline-flex min-w-[110px] items-center justify-center border px-2 py-1 text-[11px] font-bold uppercase leading-tight {{ $stepBadgeClass($purchaseRequest->current_step) }}">
                            {{ $formatStatus($purchaseRequest->current_step) }}
                        </span>
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        {{ $formatDate($purchaseRequest->received_date ?? $purchaseRequest->received_at ?? null) }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center font-bold text-slate-800">
                        {{ $formatDate($purchaseRequest->handover_date ?? $purchaseRequest->handed_over_at ?? null) }}
                    </td>

                    <td class="border border-slate-300 px-2 py-2 text-center">
                        @if ($needsPurchasingSummary)
                        <a href="{{ route('purchasing-lite.purchasing.payment-summary') }}" class="inline-flex h-8 items-center justify-center border border-blue-700 bg-white px-3 text-xs font-bold text-blue-800 transition hover:bg-blue-50">
                            Summary
                        </a>
                        @elseif ($isPurchasingUser && $needsPurchasingFollowUp)
                        <a href="{{ route('purchasing-lite.purchase-requests.show', $purchaseRequest) }}" class="inline-flex h-8 items-center justify-center bg-green-700 px-3 text-xs font-bold text-white transition hover:bg-green-800">
                            Follow Up
                        </a>
                        @elseif ($isPurchasingUser && ! $isCompletedPr)
                        <a href="{{ route('purchasing-lite.purchase-requests.vendors', $purchaseRequest) }}" class="inline-flex h-8 items-center justify-center border border-slate-950 bg-white px-3 text-xs font-bold text-slate-950 transition hover:bg-slate-100">
                            Vendors
                        </a>
                        @else
                        <a href="{{ route('purchasing-lite.purchase-requests.show', $purchaseRequest) }}" class="inline-flex h-8 items-center justify-center border border-slate-950 bg-white px-3 text-xs font-bold text-slate-950 transition hover:bg-slate-100">
                            View
                        </a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="13" class="border border-slate-300 px-2 py-4 text-center text-sm text-slate-500">
                        No purchase request data yet.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
