<?php

use App\Http\Controllers\PurchasingLite\AuthController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestCostControlController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestFinancialControllerController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestGmController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestMeetingController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestOwnerController;
use App\Http\Controllers\PurchasingLite\PurchaseRequestVendorController;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan as ArtisanRunner;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return redirect('/purchasing-lite');
// });

Route::get('/', fn() => view('app-select'))->name('app.select');

Route::get('/login', function () {
    return redirect('/purchasing-lite/login');
})->name('login');

Route::prefix('purchasing-lite')->name('purchasing-lite.')->group(function () {
    Route::get('/', function () {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        return redirect('/purchasing-lite/dashboard');
    })->name('entry');

    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');

    Route::get('/cron/pr-reminders', function () {
        $expectedToken = hash('sha256', (string) config('app.key') . '|purchasing-lite-pr-reminders');

        abort_unless(hash_equals($expectedToken, (string) request('token')), 403);

        ArtisanRunner::call('purchasing-lite:send-pr-reminders');

        return response(ArtisanRunner::output(), 200)
            ->header('Content-Type', 'text/plain');
    })->name('cron.pr-reminders');

    Route::get('/dashboard', function () {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        $role = strtolower(trim((string) ($user->role ?? $user->role_name ?? '')));
        $roleStep = str_replace(['-', ' '], '_', $role);

        if (in_array($roleStep, ['general_manager', 'generalmanager'], true)) {
            $roleStep = 'gm';
        }

        if (in_array($roleStep, ['financial_controller', 'financialcontroller', 'fc'], true)) {
            $roleStep = 'financial_controller';
        }

        if ($roleStep === 'financial_controller') {
            return app(PurchaseRequestFinancialControllerController::class)->dashboard();
        }

        $baseQuery = PurchaseRequest::query()
            ->with([
                'items' => function ($query) {
                    $query->orderBy('id');
                },
            ])
            ->withCount('items')
            ->latest('id');

        if ($roleStep === 'requester' || str_contains($roleStep, 'requester')) {
            $baseQuery->where('requested_by', $user->id);
        } elseif ($roleStep === 'purchasing') {
            $baseQuery->where(function ($query) {
                $query->where('current_step', 'purchasing')
                    ->orWhere('status', 'on_progress');
            });
        } elseif ($roleStep === 'cost_control') {
            $baseQuery->where('current_step', 'cost_control');
        } elseif ($roleStep === 'gm') {
            $baseQuery->where('current_step', 'gm');
        } elseif ($roleStep === 'owner') {
            $baseQuery->where('current_step', 'owner');
        }

        $purchaseRequestsQuery = clone $baseQuery;

        if ($roleStep !== 'owner') {
            $purchaseRequestsQuery->limit(50);
        }

        $purchaseRequests = $purchaseRequestsQuery->get();

        $totalPr = (clone $baseQuery)->count();

        $waitingAction = (clone $baseQuery)
            ->whereNotIn('status', [
                'completed',
                'approved',
                'rejected',
                'cancelled',
            ])
            ->count();

        if ($roleStep === 'owner') {
            return view('purchasing-lite.owner-dashboard', [
                'user' => $user,
                'purchaseRequests' => $purchaseRequests,
                'totalPr' => $totalPr,
                'waitingAction' => $waitingAction,
            ]);
        }

        return view('purchasing-lite.dashboard', [
            'user' => $user,
            'purchaseRequests' => $purchaseRequests,
            'totalPr' => $totalPr,
            'waitingAction' => $waitingAction,
        ]);
    })->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | PR Meeting List
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-requests/meeting/list', [PurchaseRequestMeetingController::class, 'index'])
        ->name('purchase-requests.meeting-list');

    Route::post('/purchase-requests/{purchaseRequest}/meeting/update', [PurchaseRequestMeetingController::class, 'updateFromMeeting'])
        ->name('purchase-requests.meeting.update');

    Route::get('/vendors/search', [PurchaseRequestVendorController::class, 'searchVendors'])
        ->name('vendors.search');

    Route::get('/items/search', [PurchaseRequestController::class, 'searchItems'])
        ->name('items.search');

    Route::get('/purchase-requests/create', [PurchaseRequestController::class, 'create'])
        ->name('purchase-requests.create');

    Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])
        ->name('purchase-requests.store');

    Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])
        ->name('purchase-requests.submit');

    Route::get('/purchase-requests/{purchaseRequest}/edit', [PurchaseRequestController::class, 'edit'])
        ->name('purchase-requests.edit');

    Route::put('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])
        ->name('purchase-requests.update');

    Route::delete('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'destroy'])
        ->name('purchase-requests.destroy');

    /*
    |--------------------------------------------------------------------------
    | Purchasing Follow Up
    |--------------------------------------------------------------------------
    */
    Route::post('/purchase-requests/{purchaseRequest}/purchasing/on-shipping', [PurchaseRequestController::class, 'markOnShipping'])
        ->name('purchase-requests.purchasing.on-shipping');

    Route::get('/purchasing/payment-summary', [PurchaseRequestController::class, 'generalPaymentSummary'])
        ->name('purchasing.payment-summary');

    Route::post('/purchasing/payment-summary', [PurchaseRequestController::class, 'saveGeneralPaymentSummary'])
        ->name('purchasing.payment-summary.save');

    Route::get('/purchasing/payment-summary.pdf', [PurchaseRequestController::class, 'downloadGeneralPaymentSummaryPdf'])
        ->name('purchasing.payment-summary.pdf');

    Route::post('/purchase-requests/{purchaseRequest}/purchasing/payment-summary', [PurchaseRequestController::class, 'savePaymentSummary'])
        ->name('purchase-requests.purchasing.payment-summary.save');

    Route::get('/purchase-requests/{purchaseRequest}/purchasing/payment-summary.pdf', [PurchaseRequestController::class, 'downloadPaymentSummaryPdf'])
        ->name('purchase-requests.purchasing.payment-summary.pdf');

    Route::post('/purchase-requests/{purchaseRequest}/purchasing/received', [PurchaseRequestController::class, 'markReceived'])
        ->name('purchase-requests.purchasing.received');

    Route::post('/purchase-requests/{purchaseRequest}/purchasing/handover-to-requester', [PurchaseRequestController::class, 'markHandedOverToRequester'])
        ->name('purchase-requests.purchasing.handover-to-requester');

    /*
    |--------------------------------------------------------------------------
    | Purchasing Vendor Comparison
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-requests/{purchaseRequest}/vendors', [PurchaseRequestVendorController::class, 'index'])
        ->name('purchase-requests.vendors');

    Route::post('/purchase-requests/{purchaseRequest}/vendors', [PurchaseRequestVendorController::class, 'store'])
        ->name('purchase-requests.vendors.store');

    Route::post('/purchase-requests/{purchaseRequest}/vendors/send-to-cost-control', [PurchaseRequestVendorController::class, 'sendToCostControl'])
        ->name('purchase-requests.vendors.send-to-cost-control');

    Route::post('/purchase-requests/{purchaseRequest}/vendors/send-back-to-requester', [PurchaseRequestVendorController::class, 'sendBackToRequester'])
        ->name('purchase-requests.vendors.send-back-to-requester');

    Route::post('/purchase-requests/{purchaseRequest}/vendors/reject-to-requester', [PurchaseRequestVendorController::class, 'rejectToRequester'])
        ->name('purchase-requests.vendors.reject-to-requester');

    /*
    |--------------------------------------------------------------------------
    | Cost Control
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-requests/{purchaseRequest}/cost-control', [PurchaseRequestCostControlController::class, 'show'])
        ->name('purchase-requests.cost-control.show');

    Route::post('/purchase-requests/{purchaseRequest}/cost-control/select-vendor', [PurchaseRequestCostControlController::class, 'selectVendor'])
        ->name('purchase-requests.cost-control.select-vendor');

    Route::post('/purchase-requests/{purchaseRequest}/cost-control/send-to-gm', [PurchaseRequestCostControlController::class, 'sendToGm'])
        ->name('purchase-requests.cost-control.send-to-gm');

    Route::post('/purchase-requests/{purchaseRequest}/cost-control/return-to-purchasing', [PurchaseRequestCostControlController::class, 'returnToPurchasing'])
        ->name('purchase-requests.cost-control.return-to-purchasing');

    Route::post('/purchase-requests/{purchaseRequest}/cost-control/send-back-to-requester', [PurchaseRequestCostControlController::class, 'sendBackToRequester'])
        ->name('purchase-requests.cost-control.send-back-to-requester');

    Route::post('/purchase-requests/{purchaseRequest}/cost-control/reject-to-requester', [PurchaseRequestCostControlController::class, 'rejectToRequester'])
        ->name('purchase-requests.cost-control.reject-to-requester');

    /*
    |--------------------------------------------------------------------------
    | General Manager
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-requests/{purchaseRequest}/gm', [PurchaseRequestGmController::class, 'show'])
        ->name('purchase-requests.gm.show');

    Route::post('/purchase-requests/{purchaseRequest}/gm/approve', [PurchaseRequestGmController::class, 'approve'])
        ->name('purchase-requests.gm.approve');

    Route::post('/purchase-requests/{purchaseRequest}/gm/split-approve', [PurchaseRequestGmController::class, 'splitApprove'])
        ->name('purchase-requests.gm.split-approve');

    Route::post('/purchase-requests/{purchaseRequest}/gm/save-quantities', [PurchaseRequestGmController::class, 'saveQuantities'])
        ->name('purchase-requests.gm.save-quantities');

    Route::post('/purchase-requests/{purchaseRequest}/gm/return-to-cost-control', [PurchaseRequestGmController::class, 'returnToCostControl'])
        ->name('purchase-requests.gm.return-to-cost-control');

    Route::post('/purchase-requests/{purchaseRequest}/gm/send-back-to-requester', [PurchaseRequestGmController::class, 'sendBackToRequester'])
        ->name('purchase-requests.gm.send-back-to-requester');

    Route::post('/purchase-requests/{purchaseRequest}/gm/reject-to-requester', [PurchaseRequestGmController::class, 'rejectToRequester'])
        ->name('purchase-requests.gm.reject-to-requester');

    /*
    |--------------------------------------------------------------------------
    | OR
    |--------------------------------------------------------------------------
    */
    Route::post('/purchase-requests/owner/bulk-approve', [PurchaseRequestOwnerController::class, 'bulkApprove'])
        ->name('purchase-requests.owner.bulk-approve');

    Route::post('/purchase-requests/owner/return-to-purchasing', [PurchaseRequestOwnerController::class, 'bulkReturnToPurchasing'])
        ->name('purchase-requests.owner.return-to-purchasing');

    Route::post('/purchase-requests/owner/save-quantities', [PurchaseRequestOwnerController::class, 'saveQuantities'])
        ->name('purchase-requests.owner.save-quantities');

    Route::post('/purchase-requests/owner/save-remarks', [PurchaseRequestOwnerController::class, 'saveRemarks'])
        ->name('purchase-requests.owner.save-remarks');

    Route::get('/purchase-requests/{purchaseRequest}/owner', [PurchaseRequestOwnerController::class, 'show'])
        ->name('purchase-requests.owner.show');

    Route::post('/purchase-requests/{purchaseRequest}/owner/approve', [PurchaseRequestOwnerController::class, 'approve'])
        ->name('purchase-requests.owner.approve');

    Route::post('/purchase-requests/{purchaseRequest}/owner/split-approve', [PurchaseRequestOwnerController::class, 'splitApprove'])
        ->name('purchase-requests.owner.split-approve');

    Route::post('/purchase-requests/{purchaseRequest}/owner/return-to-gm', [PurchaseRequestOwnerController::class, 'returnToGm'])
        ->name('purchase-requests.owner.return-to-gm');

    Route::post('/purchase-requests/{purchaseRequest}/owner/reject-to-requester', [PurchaseRequestOwnerController::class, 'rejectToRequester'])
        ->name('purchase-requests.owner.reject-to-requester');

    /*
    |--------------------------------------------------------------------------
    | Financial Controller
    |--------------------------------------------------------------------------
    */
    Route::get('/financial-controller/dashboard', [PurchaseRequestFinancialControllerController::class, 'dashboard'])
        ->name('financial-controller.dashboard');

    Route::post('/purchase-requests/{purchaseRequest}/financial-controller/on-progress', [PurchaseRequestFinancialControllerController::class, 'markOnProgress'])
        ->name('purchase-requests.financial-controller.on-progress');

    Route::post('/purchase-requests/{purchaseRequest}/financial-controller/waiting-payment', [PurchaseRequestFinancialControllerController::class, 'markWaitingPayment'])
        ->name('purchase-requests.financial-controller.waiting-payment');

    Route::post('/purchase-requests/{purchaseRequest}/financial-controller/paid-to-vendor', [PurchaseRequestFinancialControllerController::class, 'markPaidToVendor'])
        ->name('purchase-requests.financial-controller.paid-to-vendor');

    Route::post('/purchase-requests/{purchaseRequest}/financial-controller/send-to-purchasing', [PurchaseRequestFinancialControllerController::class, 'sendToPurchasing'])
        ->name('purchase-requests.financial-controller.send-to-purchasing');

    /*
    |--------------------------------------------------------------------------
    | Smart PR Detail Redirect
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-requests/{purchaseRequest}', function (PurchaseRequest $purchaseRequest) {
        if (! Auth::check()) {
            return redirect('/purchasing-lite/login');
        }

        $user = Auth::user();

        $role = strtolower(trim((string) ($user->role ?? $user->role_name ?? '')));
        $roleStep = str_replace(['-', ' '], '_', $role);

        if (in_array($roleStep, ['general_manager', 'generalmanager'], true)) {
            $roleStep = 'gm';
        }

        if (in_array($roleStep, ['financial_controller', 'financialcontroller', 'fc'], true)) {
            $roleStep = 'financial_controller';
        }

        $currentStep = strtolower(trim((string) $purchaseRequest->current_step));
        $currentStep = str_replace(['-', ' '], '_', $currentStep);

        if ($roleStep === 'cost_control' && $currentStep === 'cost_control') {
            return redirect()->route('purchasing-lite.purchase-requests.cost-control.show', $purchaseRequest);
        }

        if ($roleStep === 'gm' && $currentStep === 'gm') {
            return redirect()->route('purchasing-lite.purchase-requests.gm.show', $purchaseRequest);
        }

        if ($roleStep === 'owner' && $currentStep === 'owner') {
            return redirect()->route('purchasing-lite.purchase-requests.owner.show', $purchaseRequest);
        }

        if ($roleStep === 'financial_controller' && $currentStep === 'financial_controller') {
            return redirect()->route('purchasing-lite.financial-controller.dashboard');
        }

        return app(PurchaseRequestController::class)->show($purchaseRequest);
    })->name('purchase-requests.show');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
