<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Card extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'package_id',
        'code',
        'password',
        'status',
        'generated_batch_id',
        'assigned_shop_id',
        'sold_at',
        'serial_number',
        'category',
        'data_amount',
        'duration',
        'price',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function generatedBatch()
    {
        return $this->belongsTo(CardBatch::class, 'generated_batch_id');
    }

    public function assignedShop()
    {
        return $this->belongsTo(Shop::class, 'assigned_shop_id');
    }

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    public function shopCards()
    {
        return $this->hasMany(ShopCard::class);
    }

    /**
     * Check if card is available for sale
     */
    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }
}
