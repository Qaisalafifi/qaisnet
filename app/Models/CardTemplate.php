<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CardTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'name',
        'image_path',
        'include_password',
        'cards_per_page',
        'columns',
        'code_x_mm',
        'code_y_mm',
        'password_x_mm',
        'password_y_mm',
        'card_width_mm',
        'card_height_mm',
        'code_font_size',
        'password_font_size',
        'created_by',
    ];

    protected $casts = [
        'include_password' => 'boolean',
        'cards_per_page' => 'integer',
        'columns' => 'integer',
        'code_x_mm' => 'decimal:2',
        'code_y_mm' => 'decimal:2',
        'password_x_mm' => 'decimal:2',
        'password_y_mm' => 'decimal:2',
        'card_width_mm' => 'decimal:2',
        'card_height_mm' => 'decimal:2',
        'code_font_size' => 'decimal:2',
        'password_font_size' => 'decimal:2',
    ];

    public function network()
    {
        return $this->belongsTo(Network::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
