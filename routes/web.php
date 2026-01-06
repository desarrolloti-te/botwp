<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/whatsapp/webhook', [WhatsAppController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppController::class, 'handle']);
