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
        // Traemos todos los chats ordenados por la última actualización (mensaje más reciente)
        $chats = Chat::with(['messages' => function ($q) {
                $q->latest()->limit(1); // Solo necesitamos el último mensaje para la vista previa
            }])
            ->whereHas('messages') // Solo chats que tengan mensajes
            ->latest('updated_at') // Ordenar por actividad reciente
            ->get()
            ->map(function ($chat) {
                // Lógica para determinar el estado
                $lastUserMsg = $chat->messages()->where('type', 'user')->latest()->first();
                
                $isExpired = true;
                if ($lastUserMsg && $lastUserMsg->created_at >= now()->subHours(24)) {
                    $isExpired = false;
                }

                // Determinar etiqueta de estado
                $status = 'active'; // Default
                if ($chat->status === 'waiting_agent') {
                    $status = 'human_required';
                } elseif ($isExpired) {
                    $status = 'expired';
                }

                return [
                    'id' => $chat->id,
                    'user_number' => $chat->user_number,
                    'last_message' => $chat->messages->first()->message ?? '',
                    'time' => $chat->messages->first()->created_at->format('H:i d/m') ?? '',
                    'status' => $status, // 'active', 'expired', 'human_required'
                    'can_reply' => !$isExpired
                ];
            });

        return response()->json($chats);
    }

    public function show(Chat $chat)
    {
        // Traemos mensajes ordenados cronológicamente para el chat
        $messages = $chat->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        // Validar ventana de 24h
        $lastUserMsg = $chat->messages()
            ->where('type', 'user')
            ->latest()
            ->first();

        $canReply = $lastUserMsg && $lastUserMsg->created_at >= now()->subHours(24);

        return view('agent.chats.show', compact('chat', 'messages', 'canReply'));
    }

    public function send(Request $request, Chat $chat)
    {
        $request->validate(['message' => 'required|string']);

        // 1. Validar ventana 24h
        $lastUserMsg = $chat->messages()->where('type', 'user')->latest()->first();
        if (!$lastUserMsg || $lastUserMsg->created_at < now()->subHours(24)) {
            return back()->with('error', '⛔ Ventana de 24h cerrada.');
        }

        // 2. Guardar mensaje local
        Message::create([
            'chat_id' => $chat->id,
            'message' => $request->message,
            'type' => 'agent', // Mensaje del humano
            'handled' => true
        ]);

        // 3. Actualizar timestamp del chat para que suba en la lista
        $chat->touch(); 

        // 4. Si estaba esperando agente, lo marcamos como atendido
        if ($chat->status === 'waiting_agent') {
            $chat->update(['status' => 'open']);
        }

        // 5. Enviar a WhatsApp API
        try {
            Http::withToken(config('services.whatsapp.token'))
                ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => $chat->user_number,
                    'type' => 'text',
                    'text' => ['body' => $request->message],
                ]);
        } catch (\Exception $e) {
            return back()->with('error', 'Error al conectar con WhatsApp API');
        }

        return redirect()->route('agent.chats.show', $chat);
    }
}