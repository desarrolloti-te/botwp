<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['user_number', 'status'];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
