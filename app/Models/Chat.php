<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
   protected $fillable = ['user_number', 'status'];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
