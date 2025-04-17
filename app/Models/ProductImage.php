<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'image_url',
        'width',
        'height',
        'thumbnail_url',
    ];

    /**
     * Get the product that owns the image.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the image's aspect ratio.
     *
     * @return float|null
     */
    public function getAspectRatioAttribute()
    {
        return ($this->width && $this->height) ? $this->width / $this->height : null;
    }

    /**
     * Check if the image has a thumbnail.
     *
     * @return bool
     */
    public function hasThumbnail()
    {
        return !is_null($this->thumbnail_url);
    }
}
