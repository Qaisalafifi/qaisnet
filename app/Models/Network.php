<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Network extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'owner_id',
        'linking_code',
        'ip_address',
        'api_port',
        'mikrotik_user',
        'mikrotik_password',
        'mikrotik_mode',
        'user_manager_customer',
        'subscription_type',
        'subscription_start_at',
        'subscription_end_at',
        'status',
    ];

    protected $casts = [
        'subscription_start_at' => 'datetime',
        'subscription_end_at' => 'datetime',
        'api_port' => 'integer',
    ];

    protected $hidden = [
        'mikrotik_password',
    ];

    /**
     * Get decrypted MikroTik password
     */
    public function getDecryptedPasswordAttribute()
    {
        try {
            return decrypt($this->mikrotik_password);
        } catch (\Exception $e) {
            return $this->mikrotik_password; // Return as is if decryption fails
        }
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Users (Shops) that linked this network
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'network_shop', 'network_id', 'user_id')
                    ->withTimestamps();
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function shops()
    {
        return $this->hasMany(Shop::class);
    }

    public function cardBatches()
    {
        return $this->hasMany(CardBatch::class);
    }

    public function cardTemplates()
    {
        return $this->hasMany(CardTemplate::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function subscriptionLogs()
    {
        return $this->hasMany(SubscriptionLog::class);
    }

    /**
     * Check if subscription is active
     */
    public function isSubscriptionActive(): bool
    {
        if ($this->status === 'suspended') {
            return false;
        }

        if ($this->subscription_end_at && $this->subscription_end_at->isFuture()) {
            return true;
        }

        $owner = $this->owner;
        if ($owner && in_array($owner->subscription_status, ['active', 'trial'], true)) {
            if ($owner->subscription_ends_at && $owner->subscription_ends_at->isFuture()) {
                return true;
            }
        }

        return false;
    }
}
