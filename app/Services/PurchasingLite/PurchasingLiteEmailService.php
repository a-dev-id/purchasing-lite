<?php

namespace App\Services\PurchasingLite;

use App\Mail\PurchasingLitePrNotification;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PurchasingLiteEmailService
{
    public function sendToRoles(
        PurchaseRequest $purchaseRequest,
        array $roles,
        string $subject,
        string $title,
        string $messageText,
        string $buttonLabel,
        string $buttonUrl,
        ?string $remarks = null,
        ?string $threadKey = null
    ): void {
        $recipients = $this->usersByRoles($roles);

        $this->send(
            purchaseRequest: $purchaseRequest,
            recipients: $recipients,
            subject: $subject,
            title: $title,
            messageText: $messageText,
            buttonLabel: $buttonLabel,
            buttonUrl: $buttonUrl,
            remarks: $remarks,
            threadKey: $threadKey
        );
    }

    public function sendToRequester(
        PurchaseRequest $purchaseRequest,
        string $subject,
        string $title,
        string $messageText,
        string $buttonLabel,
        string $buttonUrl,
        ?string $remarks = null,
        ?string $threadKey = null
    ): void {
        $recipients = collect();

        if (! empty($purchaseRequest->requested_by)) {
            $requester = User::query()
                ->where('id', $purchaseRequest->requested_by)
                ->first();

            if ($requester) {
                $recipients->push($requester);
            }
        }

        $this->send(
            purchaseRequest: $purchaseRequest,
            recipients: $recipients,
            subject: $subject,
            title: $title,
            messageText: $messageText,
            buttonLabel: $buttonLabel,
            buttonUrl: $buttonUrl,
            remarks: $remarks,
            threadKey: $threadKey
        );
    }

    public function sendToRolesAndRequester(
        PurchaseRequest $purchaseRequest,
        array $roles,
        string $subject,
        string $title,
        string $messageText,
        string $buttonLabel,
        string $buttonUrl,
        ?string $remarks = null,
        ?string $threadKey = null
    ): void {
        $recipients = $this->usersByRoles($roles);

        if (! empty($purchaseRequest->requested_by)) {
            $requester = User::query()
                ->where('id', $purchaseRequest->requested_by)
                ->first();

            if ($requester) {
                $recipients->push($requester);
            }
        }

        $this->send(
            purchaseRequest: $purchaseRequest,
            recipients: $recipients,
            subject: $subject,
            title: $title,
            messageText: $messageText,
            buttonLabel: $buttonLabel,
            buttonUrl: $buttonUrl,
            remarks: $remarks,
            threadKey: $threadKey
        );
    }

    public function send(
        PurchaseRequest $purchaseRequest,
        Collection $recipients,
        string $subject,
        string $title,
        string $messageText,
        string $buttonLabel,
        string $buttonUrl,
        ?string $remarks = null,
        ?string $threadKey = null
    ): void {
        $recipients = $recipients
            ->filter(function ($user) {
                return $user
                    && ! empty($user->email)
                    && filter_var($user->email, FILTER_VALIDATE_EMAIL);
            })
            ->unique('email')
            ->values();

        if ($recipients->isEmpty()) {
            Log::warning('Purchasing Lite email skipped. No valid recipients.', [
                'purchase_request_id' => $purchaseRequest->id ?? null,
                'pr_number' => $purchaseRequest->pr_number ?? null,
                'subject' => $subject,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(
                    new PurchasingLitePrNotification(
                        purchaseRequest: $purchaseRequest,
                        mailSubject: $subject,
                        title: $title,
                        messageText: $messageText,
                        buttonLabel: $buttonLabel,
                        buttonUrl: $buttonUrl,
                        remarks: $remarks,
                        threadKey: $threadKey
                    )
                );
            } catch (\Throwable $e) {
                Log::error('Purchasing Lite email failed.', [
                    'purchase_request_id' => $purchaseRequest->id ?? null,
                    'pr_number' => $purchaseRequest->pr_number ?? null,
                    'recipient_email' => $recipient->email ?? null,
                    'subject' => $subject,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function usersByRoles(array $roles): Collection
    {
        $roles = collect($roles)
            ->map(function ($role) {
                return $this->normalizeRole($role);
            })
            ->filter()
            ->unique()
            ->values();

        if ($roles->isEmpty()) {
            return collect();
        }

        return User::query()
            ->get()
            ->filter(function ($user) use ($roles) {
                $userRole = $this->normalizeRole($user->role ?? $user->role_name ?? '');

                return $roles->contains($userRole);
            })
            ->values();
    }

    private function normalizeRole(?string $role): string
    {
        $role = strtolower(trim((string) $role));
        $role = str_replace(['-', '_'], ' ', $role);
        $role = preg_replace('/\s+/', ' ', $role);

        if (in_array($role, ['general manager', 'generalmanager'], true)) {
            return 'gm';
        }

        if (in_array($role, ['financial controller', 'financialcontroller'], true)) {
            return 'financial controller';
        }

        if (in_array($role, ['cost control', 'costcontrol', 'accounting'], true)) {
            return 'cost control';
        }

        if (in_array($role, ['purchase', 'purchasing staff'], true)) {
            return 'purchasing';
        }

        return $role;
    }
}
