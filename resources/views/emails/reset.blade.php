@component('mail::message')
<h1 style="text-align: center;">{{ __('email.reset_subject') }}</h1>
<p>{{ __('email.reset_greeting', ['name' => $name]) }}</p>
<p>{{ __('email.reset_line2') }}</p>
<div style="text-align: center; margin-top: 10px; margin-bottom: 40px;">
    <a href="{{ $resetUrl }}"
        style="text-decoration:none; padding: 10px 20px; background-color: #0167F3; color: #fff; border-radius: 5px;">{{ __('email.reset_action') }}</a>
</div>
<p>{{ __('email.reset_line4') }}</p>
@endcomponent