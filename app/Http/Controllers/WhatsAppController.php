<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;

class WhatsAppController extends Controller
{
    public function verify(Request $request)
    {
        $verify_token = env('WHATSAPP_VERIFY_TOKEN');

        if ($request->hub_mode === 'subscribe' && $request->hub_verify_token === $verify_token) {
            return response($request->hub_challenge, 200);
        }

        return response('Error, token no válido', 403);
    }

    public function handle(Request $request)
    {
        DriverManager::loadDriver(\BotMan\Drivers\Web\WebDriver::class);
        $botman = app('botman');

        $botman->hears('Hola', function (BotMan $bot) {
            $bot->reply("¡Hola! Gracias por comunicarte con nosotros ¿Con qué te puedo ayudar hoy? 
            Ingresa un número: \n 1. Nuestros servicios \n 2. Nuestros productos \n 3.Soporte técnico. 
            Si quieres volver a este menú, envía hola en cualquier momento.");
        });

        $botman->listen();
    }
}
