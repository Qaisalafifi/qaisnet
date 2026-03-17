<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscriptionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'old_end_at',
        'new_end_at',
        'amount',
    ];

    protected $casts = [
        'old_end_at' => 'datetime',
        'new_end_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function network()
    {
        return $this->belongsTo(Network::class);
    }
}
