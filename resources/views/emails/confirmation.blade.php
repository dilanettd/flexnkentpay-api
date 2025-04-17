@component('mail::message')
<h1 style="text-align: center;">{{ __('Confirm Your Account') }}</h1>
<p>{{ __('Hello, :name', ['name' => $userName]) }}</p>
<p>{{ __('Please click the button below to confirm your account:') }}</p>
<div style="text-align: center; margin-top: 20px; margin-bottom: 40px;">
    <a href="{{ $link }}"
        style="text-decoration:none; padding: 10px 20px; background-color: #0167F3; color: #fff; border-radius: 5px;">{{ __('Confirm Account') }}</a>
</div>
<p>{{ __('If you did not create this account, no further action is required.') }}</p>
@endcomponent