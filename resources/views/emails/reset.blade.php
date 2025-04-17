@component('mail::message')
<h1 style="text-align: center;">{{ __('Reset Your Password') }}</h1>
<p>{{ __('Hello, :name', ['name' => $userName]) }}</p>
<p>{{ __('You requested to reset your password. Click the button below to proceed:') }}</p>
<div style="text-align: center; margin-top: 10px; margin-bottom: 40px;">
    <a href="{{ $link }}"
        style="text-decoration:none; padding: 10px 20px; background-color: #0167F3; color: #fff; border-radius: 5px;">{{ __('Reset Password') }}</a>
</div>
<p>{{ __('If you did not request a password reset, no further action is required.') }}</p>
@endcomponent