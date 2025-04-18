<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MomoTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'provider_transaction_id',
        'phone_number',
        'amount',
        'fees',
        'status',
        'provider_type',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->hasOne(OrderPayment::class, 'momo_transaction_id');
    }
}