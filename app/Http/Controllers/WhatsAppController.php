<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\LeadProfile;
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

    // public function receive(Request $request)
    // {
    //     // ... (Validación inicial estándar de WhatsApp) ...
    //     $entry = $request->input('entry.0.changes.0.value');
    //     if (empty($entry['messages'])) {
    //         return response()->json(['status' => 'ignored']);
    //     }
    //     $messageData = $entry['messages'][0];
    //     $from = $messageData['from'];
    //     $text = $messageData['text']['body'] ?? '';

    //     // 1. Obtener Chat y PERFIL
    //     $chat = Chat::firstOrCreate(['user_number' => $from]);
    //     $profile = LeadProfile::firstOrCreate(['user_number' => $from]);

    //     // 2. Guardar mensaje usuario
    //     $this->saveMessage($chat, $text, 'user');

    //     if ($chat->status === 'waiting_agent') {
    //         // Si el usuario sigue escribiendo mientras espera
    //         $this->sendMessage($from, "⏳ *Seguimos transfiriendo tu solicitud con urgencia.*\n\nUn agente ya fue notificado y está revisando tu historial. Por favor espera un momento, te contactarán en breve.");

    //         // NO enviamos a la IA, terminamos aquí para evitar bucles.
    //         return response()->json(['status' => 'ok']);
    //     }

    //     // 3. Consultar a la IA con el contexto del Perfil
    //     $history = json_decode($chat->conversation_history, true) ?? [];
    //     $aiResponse = $this->groqService->generateContextualResponse($history, $text, $profile);

    //     if ($aiResponse) {
    //         // A. Procesar etiquetas de Actualización de Perfil (La IA aprendió algo nuevo)
    //         $this->handleProfileUpdates($profile, $aiResponse);

    //         // B. Procesar etiquetas de Multimedia (La IA quiere enviar un video)
    //         $aiResponse = $this->handleMediaTags($from, $aiResponse);

    //         // C. Procesar notificaciones de Soporte (Cliente identificado completamente)
    //         if (str_contains($aiResponse, '[ACTION: NOTIFY_SUPPORT]')) {
    //             $this->notifyHumanAgent($profile, $text);
    //             $aiResponse = str_replace('[ACTION: NOTIFY_SUPPORT]', '', $aiResponse);
    //         }

    //         // D. Limpiar etiquetas técnicas antes de enviar al usuario
    //         $cleanText = preg_replace('/\[UPDATE_PROFILE:.*?\]/', '', $aiResponse);

    //         if (! empty(trim($cleanText))) {
    //             $this->sendMessage($from, $cleanText);
    //         }
    //     }

    //     return response()->json(['status' => 'ok']);
    // }

    public function receive(Request $request)
    {
        try {

            $payload = $request->all();

            $entry = data_get($payload, 'entry.0.changes.0.value');
            $messageData = data_get($entry, 'messages.0');

            if (! $messageData) {
                return response()->json(['status' => 'ignored'], 200);
            }

            $waMessageId = $messageData['id'] ?? null;

            if (! $waMessageId) {
                return response()->json(['status' => 'ignored'], 200);
            }

            // ⛔ DEDUPLICACIÓN REAL
            if (Message::where('wa_message_id', $waMessageId)->exists()) {
                return response()->json(['status' => 'duplicate'], 200);
            }

            $from = $messageData['from'];
            $text = $messageData['text']['body'] ?? '';

            if (trim($text) === '') {
                return response()->json(['status' => 'ignored'], 200);
            }

            // Chat y perfil
            $chat = Chat::firstOrCreate(['user_number' => $from]);
            $profile = LeadProfile::firstOrCreate(['user_number' => $from]);

            // ✅ GUARDAR MENSAJE CON wa_message_id
             Message::create([
                'chat_id' => $chat->id,
                'message' => $text,
                'type' => 'user',
                'wa_message_id' => $waMessageId,
            ]);

            // ⏳ Ya en handoff
            if ($chat->status === 'waiting_agent') {
                $this->sendMessage(
                    $from,
                    "⏳ *Tu solicitud ya está siendo atendida.*\nUn asesor fue notificado."
                );

                return response()->json(['status' => 'ok'], 200);
            }

            // 📜 Historial REAL desde BD
           $history = $chat->messages()
                    ->orderBy('created_at')
                    ->get()
                    ->map(function ($m) {
                        return [
                            'role' => $m->type === 'user' ? 'user' : 'assistant',
                            'content' => $m->message,
                        ];
                    })
                    ->toArray();


            // 🤖 IA
            $result = $this->groqService->generateContextualResponse(
                $history,
                $text,
                $profile
            );

            // Enviar texto
            if (! empty($result['text'])) {
                $this->sendMessage($from, $result['text']);

                

            }

            // Acciones
            foreach ($result['actions'] ?? [] as $action) {
                match ($action['type']) {
                    'UPDATE_PROFILE' => $profile->update($action['payload']),
                    'ACTION' => $this->handleAction($chat, $profile, $action['payload']),
                    'MEDIA' => $this->sendMedia($from, $action['payload']),
                    default => null,
                };
            }

        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function handleAction(Chat $chat, LeadProfile $profile, $action)
    {
        if ($action === 'NOTIFY_SUPPORT') {
            $chat->update(['status' => 'waiting_agent']);
            $this->notifyHumanAgent($profile, 'Solicitud de soporte');
        }

        if ($action === 'HUMAN_HANDOFF') {
            $chat->update(['status' => 'waiting_agent']);
            $this->sendMessage(
                $profile->user_number,
                '👤 Te contacto con un especialista en este momento.'
            );
            $this->notifyHumanAgent($profile, 'Handoff solicitado');
        }
    }
    // --- LÓGICA DE INTELIGENCIA ---

    private function handleProfileUpdates(LeadProfile $profile, string $response)
    {
        // Buscamos: [UPDATE_PROFILE: {"campo": "valor"}]
        preg_match('/\[UPDATE_PROFILE: (.*?)\]/', $response, $matches);

        if (! empty($matches[1])) {
            $data = json_decode($matches[1], true);
            if ($data) {
                // Actualizamos la tabla lead_profiles dinámicamente
                $profile->update($data);
                \Log::info('✅ Perfil actualizado por IA', $data);
            }
        }
    }

    // private function handleMediaTags($to, $text)
    // {
    //     // Tu dominio base
    //     $baseUrl = 'https://botwp.tecnologiaempresarial.mx';

    //     // Mapeo de claves de la IA -> Archivos reales en tu servidor
    //     // La estructura es: $baseUrl . '/carpeta/archivo.ext'
    //     $mediaLibrary = [
    //         'pdf_contabilidad' => [
    //             'type' => 'document',
    //             'url' => $baseUrl.'/docs/XPLUS.pdf',
    //             'filename' => 'Ficha_Tecnica_Contabilidad.pdf',
    //         ],
    //         // LA IA PIDIÓ ESTO: "video_contabilidad"
    //         'video_contabilidad' => [
    //             'type' => 'video',
    //             'url' => $baseUrl.'/videos/XPLUS.mp4',
    //             'caption' => 'Conoce Contabilidad 📊',
    //         ],
    //         // --- CONTPAQi Contabilidad ---
    //         'video_contabilidad_intro' => [
    //             'type' => 'video',
    //             'url' => $baseUrl.'/videos/XPLUS.mp4', // Ejemplo: Pon aquí tu video real de contabilidad
    //             'caption' => 'Conoce Contabilidad 📊',
    //         ],
    //         'pdf_ficha_tecnica_contabilidad' => [
    //             'type' => 'document',
    //             'url' => $baseUrl.'/docs/XPLUS.pdf',   // Ejemplo: Pon aquí tu PDF real
    //             'filename' => 'Ficha_Tecnica_Contabilidad.pdf',
    //         ],
    //         'img_infografia_contabilidad' => [
    //             'type' => 'image',
    //             'url' => $baseUrl.'/images/XPLUS.png', // Ejemplo
    //             'caption' => 'Beneficios Clave',
    //         ],

    //         // --- EJEMPLOS CON TUS RUTAS XPLUS (Si XPLUS fuera un producto) ---
    //         'pdf_xplus' => [
    //             'type' => 'document',
    //             'url' => $baseUrl.'/docs/XPLUS.pdf',
    //             'filename' => 'Documentacion_XPLUS.pdf',
    //         ],
    //         'img_xplus' => [
    //             'type' => 'image',
    //             'url' => $baseUrl.'/images/XPLUS.png',
    //             'caption' => 'Imagen XPLUS',
    //         ],
    //         'video_xplus' => [
    //             'type' => 'video',
    //             'url' => $baseUrl.'/videos/XPLUS.mp4',
    //             'caption' => 'Video Demo XPLUS',
    //         ],
    //     ];

    //     // Lógica de reemplazo (No cambiar)
    //     preg_match_all('/\[MEDIA: (.*?)\]/', $text, $matches);

    //     if (! empty($matches[1])) {
    //         foreach ($matches[1] as $tag) {
    //             if (isset($mediaLibrary[$tag])) {
    //                 $media = $mediaLibrary[$tag];

    //                 if ($media['type'] == 'video') {
    //                     $this->sendVideo($to, $media['url'], $media['caption'] ?? '');
    //                 }
    //                 if ($media['type'] == 'document') {
    //                     $this->sendDocument($to, $media['url'], $media['filename'] ?? 'documento.pdf');
    //                 }
    //                 if ($media['type'] == 'image') {
    //                     $this->sendImage($to, $media['url'], $media['caption'] ?? '');
    //                 }
    //             } else {
    //                 // Log para depurar si la IA pide un archivo que no tienes mapeado
    //                 \Log::warning("⚠️ La IA pidió [MEDIA: $tag] pero no existe en \$mediaLibrary");
    //             }
    //         }
    //         // Limpiamos la etiqueta del texto final
    //         $text = preg_replace('/\[MEDIA: .*?\]/', '', $text);
    //     }

    //     return $text;
    // }

    private function sendMedia($to, $mediaKey)
    {
        $baseUrl = 'https://botwp.tecnologiaempresarial.mx';

        $mediaLibrary = [
            'video_contabilidad' => [
                'type' => 'video',
                'url' => $baseUrl.'/videos/XPLUS.mp4',
                'caption' => 'Conoce Contabilidad 📊',
            ],
            'pdf_contabilidad' => [
                'type' => 'document',
                'url' => $baseUrl.'/docs/XPLUS.pdf',
                'filename' => 'Ficha_Tecnica_Contabilidad.pdf',
            ],
        ];

        if (! isset($mediaLibrary[$mediaKey])) {
            Log::warning("MEDIA no mapeado: $mediaKey");

            return;
        }

        $media = $mediaLibrary[$mediaKey];

        match ($media['type']) {
            'video' => $this->sendVideo($to, $media['url'], $media['caption']),
            'document' => $this->sendDocument($to, $media['url'], $media['filename']),
            'image' => $this->sendImage($to, $media['url'], $media['caption']),
        };
    }

    private function notifyHumanAgent($profile, $lastMessage)
    {
        $msg = "🚨 *NUEVA SOLICITUD DE CLIENTE*\n\n";
        $msg .= "👤 Nombre: {$profile->full_name}\n";
        $msg .= "🏢 Empresa: {$profile->company}\n";
        $msg .= "💼 Puesto: {$profile->role}\n";
        $msg .= "💻 Sistema: {$profile->current_system}\n";
        $msg .= "💬 Último msg: {$lastMessage}";

        // Enviar a tu número de staff
        $this->sendMessage(config('services.whatsapp.admin_number'), $msg);
    }

    private function processAIConversation($chat, $from, $text)
    {
        $history = json_decode($chat->conversation_history, true) ?? [];

        // Generar respuesta con Groq usando el conocimiento de la empresa
        $aiResponse = $this->groqService->generateContextualResponse($history, $text);

        if ($aiResponse) {

            $cleanText = preg_replace('/\[.*?\]/', '', $aiResponse);
            $cleanText = trim($cleanText);

            // 2. RED DE SEGURIDAD: Si la IA actualizó perfil pero no dijo nada
            if (empty($cleanText)) {
                if (str_contains($aiResponse, '"prospect"')) {
                    // Si la IA detectó que es NUEVO pero no saludó
                    $cleanText = "🎉 ¡Bienvenido a Tecnología Empresarial!\n\nSomos expertos en digitalización y sistemas CONTPAQi. Cuéntame, ¿qué solución estás buscando hoy? (Ej: Contabilidad, Nóminas, Nube...)";
                } elseif (str_contains($aiResponse, '"client"')) {
                    // Si la IA detectó que es CLIENTE pero no preguntó datos
                    $cleanText = '¡Gracias! Para ubicar tu contrato y ayudarte, ¿podrías decirme tu *Nombre Completo* y *Empresa*?';
                }
            }

            // 3. Enviar mensaje si ya tenemos texto (de la IA o de la Red de Seguridad)
            if (! empty($cleanText)) {
                $this->sendMessage($from, $cleanText);
            }
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
        $this->sendMessage($from, '📝 Para darte la mejor atención, me gustaría tomar tus datos para una propuesta formal. ¿Cuál es tu *Nombre Completo*?');

        $chat->update([
            'context' => 'CAPTURE_NAME', // Cambiamos el contexto para atrapar el siguiente mensaje
            'metadata' => json_encode(['lead_source' => 'AI_CONVERSATION']),
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
                $this->sendMessage($from, 'Excelente. 📧 Por último, ¿cuál es tu *Correo Electrónico*?');
                $chat->update(['context' => 'CAPTURE_EMAIL', 'metadata' => json_encode($metadata)]);
                break;

            case 'CAPTURE_EMAIL':
                $metadata['email'] = $text;
                $this->sendMessage($from, "✅ ¡Perfecto! Hemos registrado tus datos.\n\nUn consultor analizará tu caso y te contactará en breve. Mientras tanto, ¿tienes alguna otra duda sobre nuestros servicios?");

                // Volvemos a modo conversación normal
                $chat->update(['context' => 'INITIAL', 'metadata' => json_encode($metadata)]);

                // AQUÍ: Guardar Lead en BD o enviar email de notificación
                Log::info('🎯 Nuevo Lead Capturado', $metadata);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    // --- UTILITIES ---

    private function sendMessage($to, $message)
    {
        // Guardar mensaje del bot en historial
        $chat = Chat::where('user_number', $to)->first();
        if ($chat) {
            $this->saveMessage($chat, $message, 'bot');
        }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message],
            ]);
    }

    private function saveMessage($chat, $message, $sender)
    {
        // Guardar en BD (Tabla Messages)
        Message::create([
            'chat_id' => $chat->id,
            'message' => $message,
            'type' => $sender,
        ]);

        // Actualizar historial JSON para contexto de la IA
        $history = json_decode($chat->conversation_history ?? '[]', true);
        $history[] = [
            'sender' => $sender,
            'message' => $message,
            'timestamp' => now()->toDateTimeString(),
        ];

        // Mantener historial ligero (últimos 10 mensajes)
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }

        $chat->update(['conversation_history' => json_encode($history)]);
    }
    // --- FUNCIONES PARA ENVIAR MULTIMEDIA (FALTABAN ESTAS) ---

    private function sendDocument($to, $link, $filename)
    {
        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'document',
                'document' => [
                    'link' => $link,
                    'filename' => $filename,
                ],
            ]);
    }

    private function sendVideo($to, $link, $caption)
    {
        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'video',
                'video' => [
                    'link' => $link,
                    'caption' => $caption,
                ],
            ]);
    }

    private function sendImage($to, $link, $caption)
    {
        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'image',
                'image' => [
                    'link' => $link,
                    'caption' => $caption,
                ],
            ]);
    }
}
