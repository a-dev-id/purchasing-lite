<?php

namespace App\Console\Commands;

use App\Models\PurchaseRequest;
use App\Services\PurchasingLite\PurchasingLiteEmailService;
use Illuminate\Console\Command;

class SendPurchasingLitePrReminders extends Command
{
    protected $signature = 'purchasing-lite:send-pr-reminders {--dry-run : Show due reminders without sending email}';

    protected $description = 'Send Purchasing Lite reminder emails for purchase requests stuck at a workflow step.';

    private array $stepRoles = [
        'purchasing' => ['purchasing'],
        'cost_control' => ['cost_control'],
        'owner' => ['owner'],
        'financial_controller' => ['financial_controller'],
    ];

    public function handle(PurchasingLiteEmailService $emailService): int
    {
        $now = now();
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;

        PurchaseRequest::query()
            ->whereIn('current_step', array_keys($this->stepRoles))
            ->whereNotIn('status', ['draft', 'rejected', 'handed_over_to_requester'])
            ->orderBy('id')
            ->chunkById(100, function ($purchaseRequests) use ($emailService, $now, $dryRun, &$sent) {
                foreach ($purchaseRequests as $purchaseRequest) {
                    $intervalDays = $this->priorityIntervalDays($purchaseRequest->priority);
                    $stuckSince = $purchaseRequest->current_status_at
                        ?? $purchaseRequest->updated_at
                        ?? $purchaseRequest->created_at;

                    if (! $stuckSince) {
                        continue;
                    }

                    $lastReminderAt = $purchaseRequest->last_reminder_sent_at;
                    $baseline = $lastReminderAt && $lastReminderAt->greaterThan($stuckSince)
                        ? $lastReminderAt
                        : $stuckSince;

                    if ($baseline->copy()->addDays($intervalDays)->greaterThan($now)) {
                        continue;
                    }

                    $step = (string) $purchaseRequest->current_step;
                    $roles = $this->stepRoles[$step] ?? [];

                    if (empty($roles)) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            'Due: %s | %s | %s | stuck since %s',
                            $purchaseRequest->pr_number ?: ('PR #' . $purchaseRequest->id),
                            $this->priorityLabel($purchaseRequest->priority),
                            $this->stepLabel($step),
                            $stuckSince->format('Y-m-d H:i:s')
                        ));
                    } else {
                        $emailService->sendToRoles(
                            purchaseRequest: $purchaseRequest,
                            roles: $roles,
                            subject: $step === 'owner'
                                ? 'PR Waiting for OR Approval'
                                : '[Reminder] PR waiting for ' . $this->stepLabel($step) . ' - ' . $this->prNumber($purchaseRequest),
                            title: 'PR Reminder',
                            messageText: $this->messageText($purchaseRequest, $step, $intervalDays, $stuckSince),
                            buttonLabel: 'Open PR',
                            buttonUrl: $this->buttonUrl($purchaseRequest, $step),
                            remarks: 'This reminder is sent automatically based on PR priority.',
                            threadKey: $step === 'owner' ? 'purchasing-lite-or-approval' : null
                        );

                        $purchaseRequest->forceFill([
                            'last_reminder_sent_at' => $now,
                        ])->save();
                    }

                    $sent++;
                }
            });

        $this->info(($dryRun ? 'Due reminders' : 'Reminder emails sent') . ': ' . $sent);

        return self::SUCCESS;
    }

    private function priorityIntervalDays(?string $priority): int
    {
        return match (strtolower((string) $priority)) {
            'urgent' => 1,
            'important' => 2,
            default => 3,
        };
    }

    private function priorityLabel(?string $priority): string
    {
        return match (strtolower((string) $priority)) {
            'urgent' => 'Urgent',
            'important' => 'Important',
            default => 'Regular',
        };
    }

    private function stepLabel(string $step): string
    {
        return match ($step) {
            'cost_control' => 'Cost Control',
            'gm' => 'GM',
            'owner' => 'OR',
            'financial_controller' => 'Financial Controller',
            default => 'Purchasing',
        };
    }

    private function prNumber(PurchaseRequest $purchaseRequest): string
    {
        return $purchaseRequest->pr_number ?: ('PR #' . $purchaseRequest->id);
    }

    private function messageText(PurchaseRequest $purchaseRequest, string $step, int $intervalDays, $stuckSince): string
    {
        return sprintf(
            '%s has been waiting at %s since %s. Priority is %s, so a reminder is sent every %d day(s) while it remains pending.',
            $this->prNumber($purchaseRequest),
            $this->stepLabel($step),
            $stuckSince->format('d M Y H:i'),
            $this->priorityLabel($purchaseRequest->priority),
            $intervalDays
        );
    }

    private function buttonUrl(PurchaseRequest $purchaseRequest, string $step): string
    {
        return match ($step) {
            'purchasing' => route('purchasing-lite.purchase-requests.vendors', $purchaseRequest),
            'cost_control' => route('purchasing-lite.purchase-requests.cost-control.show', $purchaseRequest),
            'gm' => route('purchasing-lite.purchase-requests.gm.show', $purchaseRequest),
            'owner' => route('purchasing-lite.purchase-requests.owner.show', $purchaseRequest),
            'financial_controller' => route('purchasing-lite.financial-controller.dashboard'),
            default => route('purchasing-lite.dashboard'),
        };
    }
}
