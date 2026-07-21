<?php

namespace App\Mail;

use App\Models\DocumentRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * An Admin disputed the auto-approval(s) on a document — see
 * AdminController::reviewAutoApproval(). $stageNames covers every
 * auto-approved stage the dispute applied to (a document can have more
 * than one), since review now acts on the whole document at once.
 */
class AutoApprovalDisputedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public DocumentRepository $document,
        public array $stageNames,
        public string $reason,
    ) {
    }

    public function build(): self
    {
        return $this->subject("Action needed: '{$this->document->title}' was disputed after auto-approval")
            ->markdown('emails.auto-approval-disputed');
    }
}
