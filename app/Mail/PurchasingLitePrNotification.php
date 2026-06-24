<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PurchasingLitePrNotification extends Mailable
{
    use Queueable, SerializesModels;

    public PurchaseRequest $purchaseRequest;

    public string $mailSubject;

    public string $title;

    public string $messageText;

    public string $buttonLabel;

    public string $buttonUrl;

    public ?string $remarks;

    public ?string $threadKey;

    public ?string $threadMessageId;

    public ?string $threadReferenceId;

    /**
     * Create a new message instance.
     */
    public function __construct(
        PurchaseRequest $purchaseRequest,
        string $mailSubject,
        string $title,
        string $messageText,
        string $buttonLabel,
        string $buttonUrl,
        ?string $remarks = null,
        ?string $threadKey = null
    ) {
        $this->purchaseRequest = $purchaseRequest;
        $this->mailSubject = $mailSubject;
        $this->title = $title;
        $this->messageText = $messageText;
        $this->buttonLabel = $buttonLabel;
        $this->buttonUrl = $buttonUrl;
        $this->remarks = $remarks;
        $this->threadKey = $threadKey;
        $this->threadReferenceId = $threadKey ? $this->makeHeaderId($threadKey) : null;
        $this->threadMessageId = $threadKey ? $this->makeHeaderId($threadKey . '-' . ($purchaseRequest->id ?? 'pr') . '-' . Str::uuid()) : null;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        if (! $this->threadKey || ! $this->threadMessageId || ! $this->threadReferenceId) {
            return new Headers();
        }

        return new Headers(
            messageId: $this->threadMessageId,
            references: [$this->threadReferenceId],
            text: [
                'In-Reply-To' => '<' . $this->threadReferenceId . '>',
            ],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchasing-lite.pr-notification',
            with: [
                'purchaseRequest' => $this->purchaseRequest,
                'title' => $this->title,
                'messageText' => $this->messageText,
                'buttonLabel' => $this->buttonLabel,
                'buttonUrl' => $this->buttonUrl,
                'remarks' => $this->remarks,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }

    private function makeHeaderId(string $value): string
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'purchasing-lite.local';
        $key = preg_replace('/[^a-z0-9]+/i', '-', strtolower($value));
        $key = trim((string) $key, '-');

        return ($key ?: 'notification') . '@' . $domain;
    }
}
