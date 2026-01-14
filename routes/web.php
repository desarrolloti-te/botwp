<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/politica-privacidad', function () {
    return view('politicy');
});
Route::get('/webhook', [WhatsAppController::class, 'verify']);
Route::post('/webhook', [WhatsAppController::class, 'receive'])
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::get('/panel/agentes', function () {
return \App\Models\Message::where('requires_human', true)
    ->where('handled', false)
    ->with('chat')
    ->latest()
    ->get();
});

