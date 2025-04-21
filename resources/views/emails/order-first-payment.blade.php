@component('mail::message')
<h1 style="text-align: center;">{{ __('email.order_payment_subject', ['orderNumber' => $order->id]) }}</h1>
<p>{{ __('email.order_payment_greeting', ['name' => $userName]) }}</p>

<p>{{ __('email.order_payment_thanks', ['orderNumber' => $order->id]) }}</p>

<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px;">
    <h2 style="margin-top: 0; color: #333;">{{ __('email.order_details_title') }}</h2>
    <p><strong>{{ __('email.product') }}:</strong> {{ $product->name }}</p>
    <p><strong>{{ __('email.quantity') }}:</strong> {{ $order->quantity }}</p>
    <p><strong>{{ __('email.total_price') }}:</strong> {{ number_format($order->total_cost, 2) }}
        {{ $product->currency }}
    </p>
    <p><strong>{{ __('email.payment_plan') }}:</strong> {{ $order->installment_count }} {{ __('email.installments') }}
    </p>
</div>

<div
    style="margin-bottom: 20px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background-color: #f8f8f8;">
    <h2 style="margin-top: 0; color: #333;">{{ __('email.payment_details_title') }}</h2>
    <p><strong>{{ __('email.amount_paid') }}:</strong> {{ number_format($payment->amount_paid, 2) }}
        {{ $product->currency }}
    </p>
    <p><strong>{{ __('email.payment_date') }}:</strong> {{ $payment->payment_date->format('d/m/Y H:i') }}</p>
    <p><strong>{{ __('email.status') }}:</strong> <span style="color: green;">{{ __('email.confirmed') }}</span></p>
</div>


<p>{{ __('email.contact_info') }}</p>

<p>{{ __('email.regards') }}<br>{{ __('email.sales_team') }}</p>
@endcomponent