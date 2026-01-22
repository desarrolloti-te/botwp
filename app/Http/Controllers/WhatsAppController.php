<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
use App\Services\GroqService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    protected $groqService;

    public function __construct(GroqService $groqService)
    {
        $this->groqService = $groqService;
    }

    public function receive(Request $request)
    {
        $entry = $request->input('entry.0.changes.0.value');
        
        if (!$entry || empty($entry['messages'])) {
            return response()->json(['status' => 'ok']);
        }

        $messageData = $entry['messages'][0];
        $from = $messageData['from'];
        $text = $messageData['text']['body'] ?? ''; // Mantener mayúsculas/minúsculas original para Groq

        // 1. Obtener o crear chat
        $chat = Chat::firstOrCreate(
            ['user_number' => $from],
            ['status' => 'open', 'context' => 'INITIAL', 'conversation_history' => json_encode([])]
        );

        // 2. Guardar mensaje entrante
        $this->saveMessage($chat, $text, 'user');

        // 3. Verificar si está en un flujo estricto de captura de datos (Ej. pidiendo email)
        if ($this->isInDataCaptureMode($chat)) {
            return $this->handleDataCapture($chat, $from, $text);
        }

        // 4. Verificar palabras clave urgentes (Human Handoff)
        if (preg_match('/(asesor|humano|persona|agente)/i', $text)) {
            $this->sendMessage($from, "👨‍💼 Entendido. Te voy a conectar con un consultor especializado. Un momento por favor...");
            $chat->update(['status' => 'waiting_agent', 'context' => 'HUMAN_SUPPORT']);
            // Aquí notificarías a tu equipo
            return response()->json(['status' => 'ok']);
        }

        // 5. MODO CONVERSACIONAL CON GROQ (IA)
        $this->processAIConversation($chat, $from, $text);

        return response()->json(['status' => 'ok']);
    }

    private function processAIConversation($chat, $from, $text)
    {
        $history = json_decode($chat->conversation_history, true) ?? [];
        
        // Generar respuesta con Groq usando el conocimiento de la empresa
        $aiResponse = $this->groqService->generateContextualResponse($history, $text);

        if ($aiResponse) {
            // Limpiar etiquetas internas si las hubiera
            $cleanResponse = str_replace('[ACTION_REQUIRED]', '', $aiResponse);
            $this->sendMessage($from, $cleanResponse);

            // Detectar si la IA sugiere que se requiere una acción de venta (Cotización/Cita)
            if (str_contains($aiResponse, '[ACTION_REQUIRED]')) {
                sleep(1);
                $this->triggerSalesFlow($chat, $from);
            }
        } else {
            // Fallback si falla la IA
            $this->sendMessage($from, "Disculpa, estoy procesando mucha información. ¿Podrías reformular tu pregunta o escribir 'Menú'?");
        }
    }

    private function triggerSalesFlow($chat, $from)
    {
        // La IA detectó interés fuerte. Iniciamos captura de datos.
        $this->sendMessage($from, "📝 Para darte la mejor atención, me gustaría tomar tus datos para una propuesta formal. ¿Cuál es tu *Nombre Completo*?");
        
        $chat->update([
            'context' => 'CAPTURE_NAME', // Cambiamos el contexto para atrapar el siguiente mensaje
            'metadata' => json_encode(['lead_source' => 'AI_CONVERSATION'])
        ]);
    }

    // --- MANEJO DE FLUJOS DE CAPTURA DE DATOS (NO IA) ---
    
    private function isInDataCaptureMode($chat)
    {
        return in_array($chat->context, ['CAPTURE_NAME', 'CAPTURE_COMPANY', 'CAPTURE_EMAIL']);
    }

    private function handleDataCapture($chat, $from, $text)
    {
        $metadata = json_decode($chat->metadata ?? '{}', true);

        switch ($chat->context) {
            case 'CAPTURE_NAME':
                $metadata['name'] = $text;
                $this->sendMessage($from, "Gracias {$text}. 🏢 ¿Cuál es el nombre de tu *Empresa*?");
                $chat->update(['context' => 'CAPTURE_COMPANY', 'metadata' => json_encode($metadata)]);
                break;

            case 'CAPTURE_COMPANY':
                $metadata['company'] = $text;
                $this->sendMessage($from, "Excelente. 📧 Por último, ¿cuál es tu *Correo Electrónico*?");
                $chat->update(['context' => 'CAPTURE_EMAIL', 'metadata' => json_encode($metadata)]);
                break;

            case 'CAPTURE_EMAIL':
                $metadata['email'] = $text;
                $this->sendMessage($from, "✅ ¡Perfecto! Hemos registrado tus datos.\n\nUn consultor analizará tu caso y te contactará en breve. Mientras tanto, ¿tienes alguna otra duda sobre nuestros servicios?");
                
                // Volvemos a modo conversación normal
                $chat->update(['context' => 'INITIAL', 'metadata' => json_encode($metadata)]);
                
                // AQUÍ: Guardar Lead en BD o enviar email de notificación
                Log::info("🎯 Nuevo Lead Capturado", $metadata);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    // --- UTILITIES ---

    private function sendMessage($to, $message)
    {
        // Guardar mensaje del bot en historial
        $chat = Chat::where('user_number', $to)->first();
        if ($chat) $this->saveMessage($chat, $message, 'bot');

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message]
            ]);
    }

    private function saveMessage($chat, $message, $sender)
    {
        // Guardar en BD (Tabla Messages)
        Message::create([
            'chat_id' => $chat->id,
            'message' => $message,
            'type' => $sender
        ]);

        // Actualizar historial JSON para contexto de la IA
        $history = json_decode($chat->conversation_history ?? '[]', true);
        $history[] = [
            'sender' => $sender,
            'message' => $message,
            'timestamp' => now()->toDateTimeString()
        ];
        
        // Mantener historial ligero (últimos 10 mensajes)
        if (count($history) > 10) $history = array_slice($history, -10);

        $chat->update(['conversation_history' => json_encode($history)]);
    }
}