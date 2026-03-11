<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $table = 'stripe_webhook_events';
    public $timestamps = false;
    protected $fillable = ['event_id', 'type', 'processed_at'];
    
    protected $casts = ['processed_at' => 'datetime'];
}
