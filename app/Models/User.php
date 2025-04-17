<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Support\Str;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'facebookId',
        'googleId',
        'phone',
        'is_email_verified',
        'is_phone_verified',
        'profile_url',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'phone_verification_token',
        'email_verification_token',
        'phone_verification_token_expires_at',
        'email_verification_token_expires_at',
        'email_verified_at',
        'phone_verified_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'is_email_verified' => 'boolean',
        'is_phone_verified' => 'boolean',
    ];

    /**
     * The attributes that should be appended to the JSON form.
     *
     * @var array<string>
     */
    protected $appends = [
        'is_admin',
    ];

    public function getIsAdminAttribute()
    {
        return $this->isAdmin();
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }

    public function seller()
    {
        return $this->hasOne(Seller::class);
    }

    public function isAdmin()
    {
        return Admin::where('user_id', $this->id)->exists();
    }



    protected $keyType = 'string';
    public $incrementing = false;
}
