<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'card_id',
        'shop_id',
        'network_id',
        'package_id',
        'price',
        'sold_by_user_id',
        'sold_at',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'price' => 'decimal:2',
    ];

    public function card()
    {
        return $this->belongsTo(Card::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function soldBy()
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }
}
