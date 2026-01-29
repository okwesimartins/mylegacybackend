<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use  Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    public $timestamps= False;
    protected $fillable = [
        'name',
        'email',
        'gender',
        'phone',
        'password',
        'google_id',
        'apple_id',
        'email_verified_at',
        'avatar',
        'provider',
        'verified',
        'last_active_at',
        'notification_status',
        'pass_key',
        'security',
        'account_status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
   
    // app/Models/User.php
public function subscription()
{
    return $this->hasOne(\App\Models\Subscription::class);
}

public function currentPlan(): \App\Models\Plan
{
    $sub = $this->subscription;
    if ($sub && $sub->isActive() && $sub->plan) {
        return $sub->plan;
    }
    return \App\Models\Plan::free();
}

public function isPremium(): bool
{
    return $this->currentPlan()->slug === 'premium';
}

public function hasFeature(string $key): bool
{
    return $this->currentPlan()->hasFeature($key);
}

public function featureLimit(string $key, $default = null)
{
    return $this->currentPlan()->limit($key, $default);
}

    public function getJWTIdentifier()
        {
            return $this->getKey();
        }
        public function getJWTCustomClaims()
        {
            return [];
        }
}
