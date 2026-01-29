<?php

// app/Models/Subscription.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Subscription extends Model
{    
    protected $table = 'subscriptions';
    protected $fillable = [
        'user_id','plan_id',
        'stripe_customer_id','stripe_subscription_id',
        'status','cancel_at_period_end',
        'currency','interval','price_id',
        'current_period_end','metadata'
    ];

    protected $casts = [
        'cancel_at_period_end' => 'boolean',
        'current_period_end' => 'datetime',
        'metadata' => 'array',
    ];

    public function plan() { return $this->belongsTo(Plan::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function isActive(): bool
    {
        if (!in_array($this->status, ['active','trialing'], true)) return false;
        if (!$this->current_period_end) return true;
        return Carbon::now()->lt($this->current_period_end);
    }
}
