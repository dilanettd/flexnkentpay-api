@component('mail::message')
<h1 style="text-align: center;">{{ __('email.regular_payment_subject', ['orderNumber' => $order->id]) }}</h1>
<p>{{ __('email.regular_payment_greeting', ['name' => $userName]) }}</p>

<p>{{ __('email.regular_payment_thanks', ['orderNumber' => $order->id]) }}</p>

<div
    style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background-color: #f8f8f8;">
    <h2 style="margin-top: 0; color: #333;">{{ __('email.regular_payment_details') }}</h2>
    <p><strong>{{ __('email.regular_payment_amount') }}:</strong> {{ number_format($payment->amount_paid, 2) }}
        {{ $product->currency }}</p>
    <p><strong>{{ __('email.regular_payment_date') }}:</strong> {{ $payment->payment_date->format('d/m/Y H:i') }}</p>
    <p><strong>{{ __('email.regular_payment_status') }}:</strong> <span
            style="color: green;">{{ __('email.regular_payment_confirmed') }}</span></p>
</div>

@if($nextPayment)
    <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px;">
        <p style="font-weight: bold; color: #0167F3;">
            {{ __('email.regular_payment_next', [
            'amount' => number_format($nextPayment->amount_paid, 2),
            'currency' => $product->currency,
            'date' => $nextPayment->due_date->format('d/m/Y')
        ]) }}
        </p>
        <p>
            {{ __('email.regular_payment_remaining', ['count' => $remainingPayments]) }}
        </p>
    </div>
@endif

<p>{{ __('email.regular_payment_contact') }}</p>

<p>{{ __('email.regards') }}<br>{{ __('email.sales_team') }}</p>
@endcomponent