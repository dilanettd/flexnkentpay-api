<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Events\FirstOrderPaymentSuccessful;
use App\Events\OrderPaymentSuccessful;
use Carbon\Carbon;

class OrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount_paid',
        'penalty_fees',
        'payment_date',
        'is_late',
        'due_date',
        'momo_transaction_id',
        'status',
        'installment_number'
    ];

    protected $casts = [
        'is_late' => 'boolean',
        'due_date' => 'datetime',
        'payment_date' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function momoTransaction()
    {
        return $this->belongsTo(MomoTransaction::class);
    }

    /**
     * Calcule et met à jour les frais de pénalité si le paiement est en retard.
     */
    public function calculatePenaltyFees()
    {
        if (!$this->due_date || $this->status === 'success') {
            return;
        }

        $now = Carbon::now();

        if ($now->gt($this->due_date)) {
            $this->is_late = true;
            $penaltyPercentage = $this->order->penalty_percentage;
            $penaltyAmount = $this->amount_paid * ($penaltyPercentage / 100);

            $this->penalty_fees = $penaltyAmount;
            $this->save();
        }
    }

    /**
     * Marque le paiement comme effectué et met à jour les statuts associés.
     * 
     * @param string $transactionId
     * @return bool
     */
    public function markAsPaid(string $transactionId)
    {
        $this->status = 'success';
        $this->payment_date = Carbon::now();
        $this->momo_transaction_id = $transactionId;
        $saved = $this->save();

        if ($saved) {
            $order = $this->order;
            $order->remaining_amount -= ($this->amount_paid + $this->penalty_fees);
            $order->remaining_installments -= 1;

            // Vérifier si c'est le dernier paiement
            $isLastPayment = $order->remaining_installments <= 0 || $order->remaining_amount <= 0;

            // Si c'est le premier paiement, confirmer la commande et envoyer l'email de premier paiement
            if ($this->installment_number == 1) {
                $order->is_confirmed = true;

                // Déclencher explicitement l'événement du premier paiement
                Log::info('Envoi de l\'email pour le premier paiement', [
                    'order_id' => $order->id,
                    'payment_id' => $this->id
                ]);
                event(new FirstOrderPaymentSuccessful($order, $this));

                // Si le premier paiement est aussi le dernier, on marque la commande comme complétée
                if ($isLastPayment) {
                    $order->is_completed = true;

                    // Dans ce cas, envoyer aussi l'email de paiement final
                    Log::info('Envoi de l\'email de paiement final (premier et dernier paiement)', [
                        'order_id' => $order->id,
                        'payment_id' => $this->id
                    ]);
                    event(new OrderPaymentSuccessful($order, $this, true));
                }
            }
            // Pour les paiements suivants (pas le premier)
            else if ($this->installment_number > 1) {
                if ($isLastPayment) {
                    $order->is_completed = true;
                }

                Log::info('Envoi de l\'email de paiement régulier ou final', [
                    'order_id' => $order->id,
                    'payment_id' => $this->id,
                    'is_last_payment' => $isLastPayment
                ]);
                event(new OrderPaymentSuccessful($order, $this, $isLastPayment));
            }

            $order->save();
        }

        return $saved;
    }
}
