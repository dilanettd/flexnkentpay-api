<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_id',
        'name',
        'logo_url',
        'contact_number',
        'cover_photo_url',
        'website_url',
        'rating',
        'visit_count',
        'description',
        'coordinate',
        'location'
    ];
    /**
     * Get the seller that owns the shop.
     */
    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }

    /**
     * Get the products associated with the shop.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Increment the shop's visit count.
     */
    public function incrementVisitCount()
    {
        $this->increment('visit_count');
    }

    /**
     * Format the shop's rating.
     *
     * @return string
     */
    public function getFormattedRatingAttribute()
    {
        return number_format($this->rating, 1);
    }

    // Relationship with reviews
    public function reviews()
    {
        return $this->hasMany(ShopReview::class);
    }

    // Calculate the average rating of the shop
    public function calculateAverageRating()
    {
        return $this->reviews()->avg('rating');
    }


}