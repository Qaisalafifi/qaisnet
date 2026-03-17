<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'name',
        'price',
        'wholesale_price',
        'retail_price',
        'data_limit',
        'validity_days',
        'mikrotik_profile_name',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'validity_days' => 'integer',
    ];

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

    public function cardBatches()
    {
        return $this->hasMany(CardBatch::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Check if package is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
