<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Sent the moment an admin creates an account — see User::sendEmailVerificationNotification(). */
class VerifyAccountMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $verificationUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Verify your account — ' . config('app.name'))
            ->markdown('emails.verify-account');
    }
}
