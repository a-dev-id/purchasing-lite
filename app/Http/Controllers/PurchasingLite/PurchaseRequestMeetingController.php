<?php

namespace App\Http\Controllers\PurchasingLite;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use App\Services\PurchasingLite\PurchasingLiteEmailService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PurchaseRequestMeetingController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        $selectedMonth = (int) $request->input('month', now()->month);
        $selectedYear = (int) $request->input('year', now()->year);
        $selectedStatus = trim((string) $request->input('status', ''));

        if ($selectedMonth < 1 || $selectedMonth > 12) {
            $selectedMonth = now()->month;
        }

        if ($selectedYear < 2020 || $selectedYear > ((int) now()->year + 1)) {
            $selectedYear = now()->year;
        }

        $purchaseRequestsQuery = PurchaseRequest::query()
            ->with([
                'items' => function ($query) {
                    $query->orderBy('id');
                },
            ])
            ->withCount('items')
            ->whereYear('created_at', $selectedYear)
            ->whereMonth('created_at', $selectedMonth)
            ->latest('id');

        if ($selectedStatus !== '') {
            $purchaseRequestsQuery->where('status', $selectedStatus);
        }

        $purchaseRequests = $purchaseRequestsQuery->get();

        $availableStatuses = PurchaseRequest::query()
            ->select('status')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->values();

        return view('purchasing-lite.purchase-requests.meeting-list', [
            'user' => $user,
            'purchaseRequests' => $purchaseRequests,
            'availableStatuses' => $availableStatuses,
            'selectedMonth' => $selectedMonth,
            'selectedYear' => $selectedYear,
            'selectedStatus' => $selectedStatus,
            'isFinancialController' => $this->userIsFinancialController($user),
        ]);
    }

    public function updateFromMeeting(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        if (! $this->userIsFinancialController($user)) {
            return redirect()
                ->route('purchasing-lite.purchase-requests.meeting-list', $request->only(['month', 'year', 'status', 'department']))
                ->with('error', 'Only Financial Controller can update PR status from the meeting page.');
        }

        $validated = $request->validate([
            'new_status' => ['required', 'string', 'max:100'],
            'financial_controller_remarks' => ['nullable', 'string', 'max:5000'],
            'month' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:191'],
        ]);

        $newStatus = trim((string) $validated['new_status']);
        $remarks = trim((string) ($validated['financial_controller_remarks'] ?? ''));

        $oldStatus = (string) ($purchaseRequest->status ?? '');
        $oldStep = (string) ($purchaseRequest->current_step ?? '');

        $newStep = $this->stepForStatus($newStatus, $oldStep);

        $this->safeFill($purchaseRequest, [
            'status' => $newStatus,
            'current_step' => $newStep,
            'financial_controller_remarks' => $remarks,
            'fc_remarks' => $remarks,
            'meeting_remarks' => $remarks,
            'current_status_at' => now(),
        ]);

        $purchaseRequest->save();

        $emailRemarks = $remarks !== '' ? $remarks : 'Updated from PR meeting page.';

        $this->createLog($purchaseRequest, [
            'action' => 'financial_controller_meeting_update',
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'from_step' => $oldStep,
            'to_step' => $newStep,
            'remarks' => $emailRemarks,
        ]);

        app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
            purchaseRequest: $purchaseRequest,
            roles: [
                'cost_control',
                'purchasing',
            ],
            subject: 'PR Updated from Meeting List - ' . $this->getPurchaseRequestNumber($purchaseRequest),
            title: 'PR Updated by Financial Controller',
            messageText: 'Financial Controller has updated this purchase request from the PR Meeting List. New status: ' . $this->formatStatus($newStatus) . '.',
            buttonLabel: 'Open PR',
            buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
            remarks: $emailRemarks
        );

        return redirect()
            ->route('purchasing-lite.purchase-requests.meeting-list', [
                'month' => $validated['month'] ?? now()->month,
                'year' => $validated['year'] ?? now()->year,
                'status' => $validated['status'] ?? '',
                'department' => $validated['department'] ?? '',
            ])
            ->with('success', 'PR status has been updated.');
    }

    private function userIsFinancialController($user): bool
    {
        $role = strtolower(trim((string) ($user->role ?? $user->role_name ?? '')));
        $role = str_replace(['-', '_'], ' ', $role);

        return in_array($role, [
            'admin',
            'financial controller',
            'financialcontroller',
            'fc',
        ], true);
    }

    private function stepForStatus(string $status, string $currentStep): string
    {
        return match ($status) {
            'draft',
            'revision_to_requester_from_purchasing',
            'revision_from_purchasing',
            'rejected',
            'cancelled' => 'requester',

            'submitted_to_purchasing',
            'paid_to_vendor',
            'on_shipping',
            'on_delivery' => 'purchasing',

            'submitted_to_cost_control',
            'cost_control_review' => 'cost_control',

            'submitted_to_gm' => 'gm',

            'submitted_to_owner' => 'owner',

            'submitted_to_financial_controller',
            'on_progress',
            'waiting_payment' => 'financial_controller',

            'received',
            'handed_over_to_requester',
            'completed',
            'done' => 'completed',

            default => $currentStep,
        };
    }

    private function safeFill(PurchaseRequest $purchaseRequest, array $values): void
    {
        $columns = Schema::getColumnListing($purchaseRequest->getTable());

        foreach ($values as $column => $value) {
            if (in_array($column, $columns, true)) {
                $purchaseRequest->{$column} = $value;
            }
        }
    }

    private function createLog(PurchaseRequest $purchaseRequest, array $data): void
    {
        if (! class_exists(\App\Models\PurchaseRequestLog::class)) {
            return;
        }

        $log = new \App\Models\PurchaseRequestLog();

        $columns = Schema::getColumnListing($log->getTable());

        $values = [
            'purchase_request_id' => $purchaseRequest->id,
            'user_id' => Auth::id(),
            'user_name' => Auth::user()->name ?? null,
            'role_name' => Auth::user()->role ?? Auth::user()->role_name ?? null,
            'role' => Auth::user()->role ?? Auth::user()->role_name ?? null,
            'action' => $data['action'] ?? null,
            'from_status' => $data['from_status'] ?? null,
            'to_status' => $data['to_status'] ?? null,
            'from_step' => $data['from_step'] ?? null,
            'to_step' => $data['to_step'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'remark' => $data['remarks'] ?? null,
            'notes' => $data['remarks'] ?? null,
            'acted_at' => Carbon::now(),
        ];

        foreach ($values as $column => $value) {
            if (in_array($column, $columns, true)) {
                $log->{$column} = $value;
            }
        }

        $log->save();
    }

    private function formatStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    private function getPurchaseRequestNumber(PurchaseRequest $purchaseRequest): string
    {
        return (string) (
            $purchaseRequest->pr_number
            ?? $purchaseRequest->request_number
            ?? ('PR-' . $purchaseRequest->id)
        );
    }
}
