<!DOCTYPE html>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Facture {{ $order->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 0;
            position: relative;
        }

        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.1;
            z-index: -1;
        }

        .container {
            width: 100%;
            padding: 20px;
        }

        .invoice-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .invoice-title {
            font-size: 24px;
            color: #0167F3;
            margin-bottom: 10px;
        }

        .invoice-details {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
        }

        .invoice-details div {
            width: 48%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        table th,
        table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        table th {
            background-color: #f8f8f8;
        }

        .total-section {
            text-align: right;
            margin-top: 30px;
        }

        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 12px;
            color: #777;
        }

        .seller-info {
            margin-top: 40px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .payment-history {
            margin-top: 40px;
        }
    </style>
</head>

<body>
    <div class="watermark">
        <img src="{{ $logoPath }}" width="500">
    </div>

    <div class="container">
        <div class="invoice-header">
            <div class="invoice-title">FACTURE</div>
            <div>N° {{ $order->id }} | Date: {{ $date }}</div>
        </div>

        <div class="invoice-details">
            <div>
                <strong>VENDEUR</strong><br>
                {{ $sellerName }}<br>
                {{ $shop->name }}<br>
                Téléphone: {{ $sellerPhone }}<br>
                @if($shop->contact_number)
                    Contact boutique: {{ $shop->contact_number }}<br>
                @endif
                @if($shop->location)
                    Adresse: {{ $shop->location }}
                @endif
            </div>
            <div>
                <strong>CLIENT</strong><br>
                {{ $user->name }}<br>
                Email: {{ $user->email }}<br>
                @if($user->phone)
                    Téléphone: {{ $user->phone }}
                @endif
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Quantité</th>
                    <th>Prix unitaire</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $order->quantity }}</td>
                    <td>{{ number_format($product->price, 2) }} {{ $product->currency }}</td>
                    <td>{{ number_format($product->price * $order->quantity, 2) }} {{ $product->currency }}</td>
                </tr>
            </tbody>
        </table>

        <div class="total-section">
            <p>Sous-total: {{ number_format($product->price * $order->quantity, 2) }} {{ $product->currency }}</p>
            <p>Frais: {{ number_format($order->fees, 2) }} {{ $product->currency }}</p>
            <p><strong>Total: {{ number_format($order->total_cost, 2) }} {{ $product->currency }}</strong></p>
        </div>

        <div class="payment-history">
            <h3>Historique des paiements</h3>
            <table>
                <thead>
                    <tr>
                        <th>Versement</th>
                        <th>Date</th>
                        <th>Montant</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payments as $payment)
                        <tr>
                            <td>Versement #{{ $payment->installment_number }}</td>
                            <td>{{ $payment->payment_date ? $payment->payment_date->format('d/m/Y') : ($payment->due_date ? $payment->due_date->format('d/m/Y') : 'N/A') }}
                            </td>
                            <td>{{ number_format($payment->amount_paid, 2) }} {{ $product->currency }}</td>
                            <td>{{ $payment->status === 'success' ? 'Payé' : 'En attente' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="seller-info">
            <h3>Instructions pour la récupération du produit</h3>
            <p>Veuillez présenter cette facture au vendeur pour récupérer votre produit.</p>
            <p>Boutique: {{ $shop->name }}</p>
            @if($shop->location)
                <p>Adresse: {{ $shop->location }}</p>
            @endif
            <p>Contact: {{ $sellerPhone }}</p>
        </div>

        <div class="footer">
            <p>Merci pour votre achat!</p>
            <p>Pour toute question, veuillez contacter notre service client.</p>
        </div>
    </div>
</body>

</html>