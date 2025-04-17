<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Seller extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'rating', 'is_verified'];

    public function shop()
    {
        return $this->hasOne(Shop::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
