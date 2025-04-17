<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'seller_id',
        'product_id',
        'quantity',
        'total_cost',
        'installment_amount',
        'payment_frequency',
        'payment_duration_in_days',
        'penalty_type',
        'penalty_percentage',
        'remaining_amount',
        'installment_count',
        'remaining_installments',
        'is_completed',

    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderPayments()
    {
        return $this->hasMany(OrderPayment::class);
    }
}
