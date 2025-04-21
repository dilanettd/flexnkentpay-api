@component('mail::message')
<h1 style="text-align: center;">{{ __('email.final_payment_subject', ['orderNumber' => $order->id]) }}</h1>
<p>{{ __('email.final_payment_greeting', ['name' => $userName]) }}</p>

<p>{{ __('email.final_payment_congrats', ['orderNumber' => $order->id]) }}</p>

<div
    style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background-color: #f8f8f8;">
    <h2 style="margin-top: 0; color: #333;">{{ __('email.final_payment_details') }}</h2>
    <p><strong>{{ __('email.final_payment_amount') }}:</strong> {{ number_format($payment->amount_paid, 2) }}
        {{ $product->currency }}
    </p>
    <p><strong>{{ __('email.final_payment_date') }}:</strong> {{ $payment->payment_date->format('d/m/Y H:i') }}</p>
</div>

<div
    style="margin-bottom: 20px; padding: 15px; border: 1px solid #0167F3; border-radius: 5px; background-color: #e6f0ff;">
    <h2 style="margin-top: 0; color: #0167F3;">{{ __('email.final_payment_collect') }}</h2>

</div>

<p style="font-weight: bold;">{{ __('email.final_payment_invoice') }}</p>

<p>{{ __('email.final_payment_thanks') }}</p>

<p>{{ __('email.regards') }}<br>{{ __('email.sales_team') }}</p>
@endcomponent