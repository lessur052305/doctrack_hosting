@component('mail::message')
# Reset Your Password

We received a request to reset the password for your {{ config('app.name') }} account ({{ $user->email }}).

@component('mail::button', ['url' => $resetUrl])
Reset Password
@endcomponent

This link expires in 60 minutes. If you didn't request this, no action is needed — your password won't change unless you click the link above and set a new one.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
