<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'role',
        'phone',
        'shop_name',
        'subscription_status',
        'subscription_ends_at',
        'subscription_type',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'subscription_ends_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::updated(function (User $user) {
            if ($user->role !== 'network_owner') {
                return;
            }

            $updates = [];

            if ($user->wasChanged('subscription_type')) {
                $updates['subscription_type'] = $user->subscription_type;
            }

            if ($user->wasChanged('subscription_ends_at')) {
                $updates['subscription_end_at'] = $user->subscription_ends_at;
            }

            if ($user->wasChanged('subscription_status')) {
                $updates['status'] = match ($user->subscription_status) {
                    'active' => 'active',
                    'expired' => 'expired',
                    'trial' => 'active',
                    'inactive' => 'suspended',
                    default => 'suspended',
                };
            }

            if (!empty($updates)) {
                Network::where('owner_id', $user->id)->update($updates);
            }
        });
    }

    // Roles
    public function isAdmin(): bool          { return $this->role === 'admin'; }
    public function isNetworkOwner(): bool   { return $this->role === 'network_owner'; }
    public function isShop(): bool           { return $this->role === 'shop'; }

    // Plans / Features
    public function planKey(): string
    {
        if (! $this->isNetworkOwner()) {
            return 'paid';
        }

        if ($this->subscription_status === 'trial') {
            return 'trial';
        }

        if ($this->subscription_status === 'active') {
            return 'paid';
        }

        // Fallback: treat unknown/expired as trial-limited
        return 'trial';
    }

    public function isTrial(): bool
    {
        return $this->isNetworkOwner() && $this->planKey() === 'trial';
    }

    public function planConfig(): array
    {
        return config('plans.' . $this->planKey(), []);
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->planConfig(), 'features.' . $feature, false);
    }

    public function planLimit(string $key, $default = null)
    {
        return data_get($this->planConfig(), 'limits.' . $key, $default);
    }

    /**
     * Networks owned by this user (if network_owner)
     */
    public function ownedNetworks()
    {
        return $this->hasMany(Network::class, 'owner_id');
    }

    /**
     * Networks linked to this shop owner (if shop role)
     */
    public function networks()
    {
        return $this->belongsToMany(Network::class, 'network_shop', 'user_id', 'network_id')
                    ->withTimestamps();
    }

    /**
     * Shops owned by this user (if shop role)
     */
    public function shops()
    {
        return $this->hasMany(Shop::class, 'owner_id');
    }

    public function subscriptionRequests()
    {
        return $this->hasMany(SubscriptionRequest::class);
    }
}
