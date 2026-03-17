<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CardBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'package_id',
        'count',
        'card_length',
        'prefix',
        'suffix',
        'first_code',
        'last_code',
        'created_by',
    ];

    protected $casts = [
        'count' => 'integer',
        'card_length' => 'integer',
    ];

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cards()
    {
        return $this->hasMany(Card::class, 'generated_batch_id');
    }
}
