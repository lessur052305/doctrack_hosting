<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Sent on a self-service "Forgot password?" request — see User::sendPasswordResetNotification(). */
class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $resetUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Reset your password — ' . config('app.name'))
            ->markdown('emails.reset-password');
    }
}
