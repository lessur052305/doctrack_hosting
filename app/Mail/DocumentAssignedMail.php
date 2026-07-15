<?php

namespace App\Mail;

use App\Models\DocumentRepository;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Section 3: Submission Alerts — external (email) half of the notification. */
class DocumentAssignedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentRepository $document,
        public WorkflowStage $stage,
        public User $approver,
    ) {
    }

    public function build(): self
    {
        return $this->subject("New document for review: {$this->document->title}")
            ->markdown('emails.document-assigned');
    }
}
