<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    protected $fillable = [
        'original_name',
        'table_name',
        'status',
        'headers', // Добавили поле
    ];

    // Указываем Laravel кастить JSON в обычный ассоциативный массив
    protected $casts = [
        'headers' => 'array',
    ];
}
