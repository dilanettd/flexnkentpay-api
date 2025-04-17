<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_id',
        'name',
        'product_code',
        'brand',
        'slug',
        'category',
        'subcategory',
        'description',
        'currency',
        'price',
        'rating',
        'visit',
        'stock_quantity',
        'installment_count',
        'min_installment_price',
        'is_active',
        "product_code_url",
        "product_code"
    ];

    /**
     * Boot method to handle model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically generate a unique slug based on the product name
        static::creating(function ($product) {
            $product->slug = Str::slug($product->name);
        });

        // Automatically generate a unique product code
        static::creating(function ($product) {
            $product->product_code = uniqid('prod_');
        });
    }

    /**
     * Get the shop that owns the product.
     */
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Calculate the minimum installment price based on the price and installment count.
     *
     * @return float
     */
    public function calculateMinInstallmentPrice()
    {
        return $this->price / $this->installment_count;
    }

    /**
     * Get the full price formatted with currency.
     *
     * @return string
     */
    public function getFormattedPriceAttribute()
    {
        return number_format($this->price, 2) . ' ' . $this->currency;
    }

    // Relationship with reviews
    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    // Calculate the average rating of the product
    public function calculateAverageRating()
    {
        return $this->reviews()->avg('rating');
    }
}
