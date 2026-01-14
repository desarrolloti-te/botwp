<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
     protected $fillable = [
        'chat_id',
        'message',
        'type',
        'requires_human',
        'handled'
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }
}
