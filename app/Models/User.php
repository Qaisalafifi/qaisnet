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
        ];
    }

    // Roles
    public function isAdmin(): bool          { return $this->role === 'admin'; }
    public function isNetworkOwner(): bool   { return $this->role === 'network_owner'; }
    public function isShop(): bool           { return $this->role === 'shop'; }

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
}
