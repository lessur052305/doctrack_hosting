<?php

namespace App\Mail;

use App\Models\DocumentRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Section 3: Decision Alerts — external (email) half of the notification. */
class DocumentDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentRepository $document,
        public string $decision,
        public ?string $comments,
        public string $stageName,
    ) {
    }

    public function build(): self
    {
        return $this->subject("Update on '{$this->document->title}': " . ucfirst($this->decision))
            ->markdown('emails.document-decision');
    }
}
