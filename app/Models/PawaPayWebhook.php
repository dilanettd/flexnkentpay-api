<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PawaPayWebhook extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pawapay_webhooks';

    protected $fillable = [
        'transaction_id',
        'transaction_type',
        'timestamp',
        'phone_number',
        'amount',
        'currency',
        'country',
        'correspondent',
        'status',
        'description',
        'customer_timestamp',
        'created_timestamp',
        'received_timestamp',
        'failure_reason',
        'metadata',
        'suspicious_activity_report',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'customer_timestamp' => 'datetime',
        'created_timestamp' => 'datetime',
        'received_timestamp' => 'datetime',
    ];
}