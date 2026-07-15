<?php

namespace App\Mail;

use App\Models\DocumentAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Section 3: SLA Escalation — notifies Admin of the bottleneck by email. */
class SlaEscalationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public DocumentAssignment $assignment)
    {
    }

    public function build(): self
    {
        return $this->subject("SLA breach: '{$this->assignment->document->title}'")
            ->markdown('emails.sla-escalation');
    }
}
