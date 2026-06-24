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

class PurchaseRequestFinancialControllerController extends Controller
{
    public function dashboard(): View
    {
        $user = Auth::user();

        $purchaseRequests = PurchaseRequest::query()
            ->with([
                'items' => function ($query) {
                    $query->orderBy('id');
                },
            ])
            ->withCount('items')
            ->where('current_step', 'financial_controller')
            ->latest('id')
            ->get();

        return view('purchasing-lite.financial-controller-dashboard', [
            'user' => $user,
            'purchaseRequests' => $purchaseRequests,
        ]);
    }

    public function markOnProgress(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $remarks = $this->updateFinancialStatus(
            purchaseRequest: $purchaseRequest,
            status: 'on_progress',
            label: 'On progress',
            currentStep: 'financial_controller'
        );

        $this->sendFinancialStatusEmail(
            purchaseRequest: $purchaseRequest,
            statusLabel: 'On Progress',
            remarks: $remarks,
            buttonLabel: 'Open PR'
        );

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been marked as On Progress.');
    }

    public function markWaitingPayment(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $remarks = $this->updateFinancialStatus(
            purchaseRequest: $purchaseRequest,
            status: 'waiting_payment',
            label: 'Waiting payment',
            currentStep: 'financial_controller'
        );

        $this->sendFinancialStatusEmail(
            purchaseRequest: $purchaseRequest,
            statusLabel: 'Waiting Payment',
            remarks: $remarks,
            buttonLabel: 'Open PR'
        );

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been marked as Waiting Payment.');
    }

    public function markPaidToVendor(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $remarks = $this->updateFinancialStatus(
            purchaseRequest: $purchaseRequest,
            status: 'paid_to_vendor',
            label: 'Paid to vendor',
            currentStep: 'purchasing'
        );

        $this->sendFinancialStatusEmail(
            purchaseRequest: $purchaseRequest,
            statusLabel: 'Paid To Vendor',
            remarks: $remarks,
            buttonLabel: 'Open Purchasing Follow Up'
        );

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been marked as Paid to Vendor and sent to Purchasing.');
    }

    public function sendToPurchasing(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $remarks = $this->updateFinancialStatus(
            purchaseRequest: $purchaseRequest,
            status: 'paid_to_vendor',
            label: 'Paid to vendor',
            currentStep: 'purchasing',
            remarks: trim((string) $request->input('remarks'))
        );

        $this->sendFinancialStatusEmail(
            purchaseRequest: $purchaseRequest,
            statusLabel: 'Paid To Vendor',
            remarks: $remarks,
            buttonLabel: 'Open Purchasing Follow Up'
        );

        return redirect()
            ->route('purchasing-lite.dashboard')
            ->with('success', 'PR has been sent to Purchasing for follow up.');
    }

    private function updateFinancialStatus(
        PurchaseRequest $purchaseRequest,
        string $status,
        string $label,
        string $currentStep = 'financial_controller',
        ?string $remarks = null
    ): string {
        $oldStatus = (string) ($purchaseRequest->status ?? '');

        $remarks = $remarks !== null && trim($remarks) !== ''
            ? trim($remarks)
            : $label;

        $this->safeFill($purchaseRequest, [
            'status' => $status,
            'current_step' => $currentStep,
            'financial_controller_status' => $status,
            'financial_controller_remarks' => $remarks,
            'current_status_at' => now(),
        ]);

        $purchaseRequest->save();

        $this->createLog($purchaseRequest, [
            'action' => 'financial_controller_' . $status,
            'from_status' => $oldStatus,
            'to_status' => $status,
            'remarks' => $remarks,
        ]);

        return $remarks;
    }

    private function sendFinancialStatusEmail(
        PurchaseRequest $purchaseRequest,
        string $statusLabel,
        string $remarks,
        string $buttonLabel
    ): void {
        $prNumber = $this->getPurchaseRequestNumber($purchaseRequest);

        app(PurchasingLiteEmailService::class)->sendToRolesAndRequester(
            purchaseRequest: $purchaseRequest,
            roles: [
                'cost_control',
                'purchasing',
            ],
            subject: 'PR Updated by Financial Controller - ' . $prNumber,
            title: 'PR Status Updated by Financial Controller',
            messageText: 'Financial Controller has updated this purchase request status to ' . $statusLabel . '.',
            buttonLabel: $buttonLabel,
            buttonUrl: route('purchasing-lite.purchase-requests.show', $purchaseRequest),
            remarks: $remarks
        );
    }

    private function safeFill(PurchaseRequest $purchaseRequest, array $values): void
    {
        $table = $purchaseRequest->getTable();

        foreach ($values as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
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

        $table = $log->getTable();

        $values = [
            'purchase_request_id' => $purchaseRequest->id,
            'user_id' => Auth::id(),
            'user_name' => Auth::user()->name ?? null,
            'role' => Auth::user()->role ?? Auth::user()->role_name ?? 'financial_controller',
            'role_name' => Auth::user()->role ?? Auth::user()->role_name ?? 'financial_controller',
            'action' => $data['action'] ?? null,
            'from_status' => $data['from_status'] ?? null,
            'to_status' => $data['to_status'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'remark' => $data['remarks'] ?? null,
            'notes' => $data['remarks'] ?? null,
            'acted_at' => Carbon::now(),
        ];

        foreach ($values as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $log->{$column} = $value;
            }
        }

        $log->save();
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
