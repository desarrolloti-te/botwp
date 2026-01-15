<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;    

class ChatPanelController extends Controller
{
    public function index()
    {
        return view('panelChats');
    }

     public function data()
    {
        $limit24 = now()->subHours(24);

        return response()->json([
            'activos_24h' => Chat::whereHas('messages', function ($q) use ($limit24) {
                    $q->where('type', 'user')
                      ->where('created_at', '>=', $limit24);
                })
                ->with(['messages' => function ($q) {
                    $q->latest()->limit(1);
                }])
                ->latest()
                ->get(),

            'caducados' => Chat::whereHas('messages', function ($q) use ($limit24) {
                    $q->where('type', 'user')
                      ->where('created_at', '<', $limit24);
                })
                ->with(['messages' => function ($q) {
                    $q->latest()->limit(1);
                }])
                ->latest()
                ->get(),

            'requieren_humano' => Chat::whereHas('messages', function ($q) {
                    $q->where('requires_human', true)
                      ->where('handled', false);
                })
                ->with(['messages' => function ($q) {
                    $q->where('requires_human', true)
                      ->where('handled', false)
                      ->latest();
                }])
                ->latest()
                ->get(),
        ]);
    }

    public function show(Chat $chat)
{
    $messages = $chat->messages()
        ->orderBy('created_at')
        ->get();

    $canReply = $chat->messages()
        ->where('type', 'user')
        ->where('created_at', '>=', now()->subHours(24))
        ->exists();

    return view('agent.chats.show', compact('chat', 'messages', 'canReply'));
}

public function send(Request $request, Chat $chat)
{
    $request->validate([
        'message' => 'required|string'
    ]);

    // validar ventana 24h
    $lastUserMsg = $chat->messages()
        ->where('type', 'user')
        ->latest()
        ->first();

    if (!$lastUserMsg || $lastUserMsg->created_at < now()->subHours(24)) {
        return back()->with('error', '⛔ Ventana de 24h cerrada.');
    }

    // guardar mensaje del agente
    Message::create([
        'chat_id' => $chat->id,
        'message' => $request->message,
        'type' => 'agent',
        'handled' => true
    ]);

    // enviar a WhatsApp
    Http::withToken(config('services.whatsapp.token'))
        ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $chat->user_number,
            'type' => 'text',
            'text' => ['body' => $request->message],
        ]);

    return redirect()->route('agent.chats.show', $chat);
}
}
