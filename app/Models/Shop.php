<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'network_id',
        'owner_id',
        'network_owner_id',
        'access_code',
        'link_code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function networkOwner()
    {
        return $this->belongsTo(User::class, 'network_owner_id');
    }

    public function cards()
    {
        return $this->hasMany(Card::class, 'assigned_shop_id');
    }

    public function shopCards()
    {
        return $this->hasMany(ShopCard::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
