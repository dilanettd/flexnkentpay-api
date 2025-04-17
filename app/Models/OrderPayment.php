<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount_paid',
        'payment_date',
        'is_late',
        'due_date',
        'momo_transaction_id',
        'status'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function momoTransaction()
    {
        return $this->belongsTo(MomoTransaction::class);
    }
}
