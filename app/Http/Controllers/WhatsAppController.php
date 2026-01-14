<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
   public function verify(Request $request)
    {
        if (
            $request->get('hub_mode') === 'subscribe' &&
            $request->get('hub_verify_token') === config('services.whatsapp.verify_token')
        ) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('Unauthorized', 403);
    }

    public function receive(Request $request)
    {
        $entry = $request->input('entry.0.changes.0.value');
        \Log::info('📩 Mensaje entrante WhatsApp', $request->all());

        if (!$entry || empty($entry['messages'])) {
            return response()->json(['status' => 'ok']);
        }

        $message = $entry['messages'][0];
        $from = $message['from'];
        $text = strtolower($message['text']['body'] ?? '');

        match ($text) {
            'hola' => $this->sendMessage($from, '¡Hola! 👋 ¿En qué puedo ayudarte?'),
            'info' => $this->sendMessage($from, 'Somos una empresa que ofrece servicios.'),
            default => $this->sendMessage($from, 'No entendí tu mensaje 😅. Escribe *hola* o *info*.'),
        };

        return response()->json(['status' => 'ok']);
    }

    private function sendMessage(string $to, string $message): void
    {
        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);
    }
}
