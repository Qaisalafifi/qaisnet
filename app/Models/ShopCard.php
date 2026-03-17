<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShopCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'card_id',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function card()
    {
        return $this->belongsTo(Card::class);
    }
}
