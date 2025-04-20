@component('mail::message')
<h1 style="text-align: center;">{{ __('email.confirmation_subject') }}</h1>
<p>{{ __('email.confirmation_greeting', ['name' => $name]) }}</p>
<p>{{ __('email.confirmation_line2') }}</p>
<div style="text-align: center; margin-top: 20px; margin-bottom: 40px;">
    <a href="{{ $verificationUrl }}"
        style="text-decoration:none; padding: 10px 20px; background-color: #0167F3; color: #fff; border-radius: 5px;">{{ __('email.confirmation_action') }}</a>
</div>
<p>{{ __('email.confirmation_line3') }}</p>
@endcomponent