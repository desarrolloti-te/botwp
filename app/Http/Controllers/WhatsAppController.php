<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
use App\Services\GroqService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    protected $groqService;

    public function __construct(GroqService $groqService)
    {
        $this->groqService = $groqService;
    }

    public function handleMessage(Request $request)
    {
        $mensajeUsuario = $request->input('message');
        $conversationHistory = [
            ['sender' => 'user', 'message' => 'Hola'],
            ['sender' => 'bot', 'message' => 'Hola, ¿en qué puedo ayudarte?'],
        ];
        $context = $this->detectContext($mensajeUsuario);
        $respuestaIA = $this->groqService->generateContextualResponse(
            $conversationHistory,
            $mensajeUsuario,
            $context
        );

        return response()->json([
            'reply' => $respuestaIA ?? 'Un asesor se pondrá en contacto contigo 😊',
        ]);
    }

    private function detectContext(string $message): string
    {
        $message = strtolower($message);
        if (str_contains($message, 'contpaqi')) {
            return 'CONTPAQI';
        }
        if (str_contains($message, 'nube')) {
            return 'NUBE';
        }
        if (str_contains($message, 'capacita')) {
            return 'CAPACITACION';
        }
        if (str_contains($message, 'soporte') || str_contains($message, 'error')) {
            return 'SOPORTE';
        }

        return 'INITIAL';
    }

    private $contextTriggers = [
        'CONTPAQI' => ['contpaqi', 'conpaq', 'sistema administrativo', 'contabilidad', 'nomina', 'nominas', 'comercial', 'facturacion', 'inventario', 'bancos', 'tesoreria', 'modulo', 'licencia'],
        'NUBE' => ['nube', 'escritorio', 'virtual', 'servidor', 'hosting', 'cloud', 'respaldo'],
        'REDISEÑO' => ['rediseño', 'rediseno', 'blindaje', 'fiscal', 'materialidad', 'automatizacion', 'sat', 'auditoria', 'petrolero', 'construccion'],
        'CAPACITACION' => ['capacitacion', 'curso', 'taller', 'entrenamiento', 'aprender', 'certificacion', 'stps'],
        'SOPORTE' => ['soporte', 'ayuda', 'tecnico', 'falla', 'problema', 'error', 'ticket', 'urgente'],
    ];

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

        if (! $entry || empty($entry['messages'])) {
            return response()->json(['status' => 'ok']);
        }

        $message = $entry['messages'][0];
        $from = $message['from'];
        $text = strtolower($message['text']['body'] ?? '');

        $chat = Chat::firstOrCreate(
            ['user_number' => $from],
            ['status' => 'open', 'context' => 'INITIAL', 'conversation_history' => json_encode([])]
        );

        $this->updateConversationHistory($chat, $text, 'user');

        $isFirstMessage = Message::where('chat_id', $chat->id)->where('type', 'user')->count() === 0;

        $isHumanRequest = in_array($text, ['asesor', 'humano', 'agente', 'ejecutivo', 'persona']);
        Message::create([
            'chat_id' => $chat->id,
            'message' => $text,
            'type' => 'user',
            'requires_human' => $isHumanRequest,
        ]);

        if ($isHumanRequest) {
            $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
            $this->sendMessage($from, "👨‍💼 Perfecto, estoy conectando tu conversación con un ejecutivo especializado. En breve te atenderá personalmente.\n\n⏱️ Tiempo promedio de respuesta: 2-5 minutos.");
            $this->notifyAgent("🔔 Nuevo cliente requiere atención humana\n📱 Número: $from\n💬 Último mensaje: $text");

            return response()->json(['status' => 'ok']);
        }

        if ($this->handleAgentCommands($from, $text)) {
            return response()->json(['status' => 'ok']);
        }

        if ($chat->status === 'waiting_agent') {
            return response()->json(['status' => 'ok']);
        }

        return $this->processIntelligentMessage($chat, $from, $text, $isFirstMessage);
    }

    private function processIntelligentMessage($chat, $from, $text, $isFirstMessage)
    {
        if ($isFirstMessage && ! $this->isGreeting($text)) {
            $this->sendMessage($from, "¡Hola! 👋 Bienvenido a *Tecnología Empresarial*.\n\n🚀 Estamos aquí para ayudarte a blindar y digitalizar tu empresa.");
            sleep(1);
        }

        if ($this->isResetCommand($text)) {
            $chat->update(['context' => 'START', 'last_bot_question' => null, 'metadata' => null]);

            return $this->handleInitialGreeting($chat, $from);
        }

        if ($this->isGreeting($text) && $chat->context === 'INITIAL') {
            return $this->handleInitialGreeting($chat, $from);
        }

        $currentContext = $chat->context ?? 'INITIAL';

        if (in_array($currentContext, ['CONTPAQI', 'NUBE', 'REDISEÑO', 'CAPACITACION', 'SOPORTE'])) {
            return $this->handleContextualFlow($chat, $from, $text, $currentContext);
        }

        if (in_array($currentContext, ['QUOTE', 'QUOTE_WAITING_EMAIL'])) {
            return $this->handleQuoteFlow($chat, $from, $text);
        }

        $detectedContext = $this->detectContextFromMessage($text);

        if ($detectedContext !== null) {
            $this->updateChatFull($chat, [
                'context' => $detectedContext,
                'last_bot_question' => null,
            ]);

            return $this->initializeContextFlow($chat, $from, $text, $detectedContext);
        }

        if (in_array($currentContext, ['INITIAL', 'START', 'INFO'])) {
            $catalogResponse = $this->findGeneralResponse($text);

            if ($catalogResponse !== null) {
                if (isset($catalogResponse['context'])) {
                    $this->updateChatContext($chat, $catalogResponse['context']);
                }

                return $this->sendCatalogResponse($from, $catalogResponse, $chat);
            }
        }

        // 🤖 AQUÍ SE USA LA IA
        return $this->handleUnknownMessage($chat, $from, $text);
    }

    private function isResetCommand($text)
    {
        $resetCommands = ['menu', 'inicio', 'empezar', 'principal', 'volver', 'salir', 'cancelar'];

        return in_array($text, $resetCommands);
    }

    private function handleInitialGreeting($chat, $from)
    {
        $messageCount = Message::where('chat_id', $chat->id)->count();

        if ($messageCount > 5) {
            $greeting = "¡Qué gusto verte de nuevo! 👋\n\n";
        } else {
            $greeting = "¡Hola! 👋 Qué gusto saludarte. Bienvenido a *Tecnología Empresarial*.\n\n";
        }

        $this->updateChatContext($chat, 'START');

        $message = $greeting."Estamos encantados de acompañarte en este *2026* para que tu negocio no solo crezca, sino que esté totalmente blindado y a la vanguardia. 🚀\n\n¿Cómo podemos apoyarte hoy?\n\n1️⃣ *Conocer Tecnología Empresarial*\n2️⃣ *Explorar servicios* (CONTPAQi, Rediseño, Capacitación)\n3️⃣ *Soporte Técnico*\n4️⃣ *Hablar con un ejecutivo*\n\n_También puedes escribirme directamente lo que necesitas y procesaré tu solicitud. 😊_";

        $this->sendMessage($from, $message);

        return response()->json(['status' => 'ok']);
    }

    private function detectContextFromMessage($text)
    {
        foreach ($this->contextTriggers as $context => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $context;
                }
            }
        }

        return null;
    }

    private function initializeContextFlow($chat, $from, $text, $context)
    {
        switch ($context) {
            case 'CONTPAQI':
                return $this->startContPaqiFlow($chat, $from, $text);
            case 'NUBE':
                return $this->startNubeFlow($chat, $from, $text);
            case 'REDISEÑO':
                return $this->startRedisenoFlow($chat, $from, $text);
            case 'CAPACITACION':
                return $this->startCapacitacionFlow($chat, $from, $text);
            case 'SOPORTE':
                return $this->startSoporteFlow($chat, $from, $text);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleContextualFlow($chat, $from, $text, $context)
    {
        // 🤖 PRIMERO: Intentar responder preguntas generales con IA dentro del contexto
        $lastQuestion = $chat->last_bot_question ?? '';

        // Si NO hay una pregunta específica pendiente, el usuario podría estar haciendo consultas generales
        if (empty($lastQuestion) || $this->isOpenEndedQuestion($text)) {
            $aiResponse = $this->tryAIResponseInContext($chat, $text, $context);
            if ($aiResponse) {
                return response()->json(['status' => 'ok']);
            }
        }

        // Si la IA no puede responder o hay pregunta específica, seguir flujo normal
        switch ($context) {
            case 'CONTPAQI':
                return $this->handleContPaqiFlow($chat, $from, $text);
            case 'NUBE':
                return $this->handleNubeFlow($chat, $from, $text);
            case 'REDISEÑO':
                return $this->handleRedisenoFlow($chat, $from, $text);
            case 'CAPACITACION':
                return $this->handleCapacitacionFlow($chat, $from, $text);
            case 'SOPORTE':
                return $this->handleSoporteFlow($chat, $from, $text);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * 🤖 Intenta responder con IA dentro de un contexto específico
     */
    private function tryAIResponseInContext($chat, $text, $context)
    {
        // Solo usar IA para preguntas abiertas, no para respuestas esperadas
        $keywords = ['que', 'como', 'cual', 'por que', 'donde', 'cuando', 'cuanto', '?'];
        $isQuestion = false;
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                $isQuestion = true;
                break;
            }
        }

        if (! $isQuestion) {
            return false;
        }

        \Log::info("🤖 Intentando respuesta IA en contexto: $context", ['message' => $text]);

        $history = json_decode($chat->conversation_history ?? '[]', true);
        $aiResponse = $this->groqService->generateContextualResponse($history, $text, $context);

        if ($aiResponse) {
            $this->sendMessage($chat->user_number, $aiResponse);

            // Después de responder, guiar de vuelta al flujo
            sleep(2);
            $metadata = json_decode($chat->metadata ?? '{}', true);
            $modulo = $metadata['modulo_interes'] ?? $context;

            $this->sendMessage(
                $chat->user_number,
                "¿Tienes alguna otra pregunta sobre *{$modulo}* o quieres continuar con la cotización? 😊"
            );

            return true;
        }

        return false;
    }

    /**
     * Detecta si es una pregunta abierta
     */
    private function isOpenEndedQuestion($text): bool
    {
        $patterns = [
            'que es', 'que hace', 'como funciona', 'cual es', 'por que',
            'puedes explicar', 'me dices', 'informacion sobre', 'ventajas',
            'beneficios', 'diferencia', 'mejor', 'recomiendas', '?',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // ============================================
    // FLUJO CONTPAQI
    // ============================================

    private function startContPaqiFlow($chat, $from, $text)
    {
        $intro = "📊 *¡Excelente elección! CONTPAQi*\n\n";
        $intro .= "Somos *Socios Máster Nivel Oro* con 30 años implementando soluciones administrativas.\n\n";

        if (str_contains($text, 'contab')) {
            $this->updateChatQuestion($chat, 'contpaqi_detalle_contabilidad');
            $metadata = ['modulo_interes' => 'Contabilidad'];
            $this->updateChatMetadata($chat, $metadata);
            $this->sendMessage($from, $intro."📊 *CONTPAQi Contabilidad* - Excelente elección.\n\nPerfecto para:\n✅ Control fiscal total\n✅ Contabilidad electrónica SAT\n✅ Estados financieros automáticos\n✅ Pólizas automatizadas\n\n¿Necesitas implementación nueva, actualización o migración desde otro sistema?");
        } elseif (str_contains($text, 'nomin')) {
            $this->updateChatQuestion($chat, 'contpaqi_detalle_nominas');
            $metadata = ['modulo_interes' => 'Nóminas'];
            $this->updateChatMetadata($chat, $metadata);
            $this->sendMessage($from, $intro."👥 *CONTPAQi Nóminas* - La mejor decisión.\n\nIdeal para:\n✅ Cálculo automático de nómina\n✅ Timbrado CFDI 4.0\n✅ IMSS, Infonavit, ISR\n✅ Finiquitos y liquidaciones\n\n¿Cuántos empleados tienes actualmente?");
        } else {
            $this->updateChatQuestion($chat, 'contpaqi_modulo');
            $this->sendMessage($from, $intro."¿Qué módulo te interesa?\n\n📊 *Contabilidad*\n👥 *Nóminas*\n🏪 *Comercial*\n🏦 *Bancos*\n🎯 *Suite completa*\n\nEscribe el nombre del módulo.");
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleContPaqiFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        $metadata = json_decode($chat->metadata ?? '{}', true);

        // 🤖 Si es pregunta general dentro de CONTPAQI, intentar con IA primero
        if ($this->isOpenEndedQuestion($text) && ! in_array($lastQuestion, [
            'contpaqi_modulo', 'contpaqi_usuarios', 'contpaqi_modalidad',
        ])) {
            $aiResponse = $this->tryAIResponseInContext($chat, $text, 'CONTPAQI');
            if ($aiResponse) {
                return response()->json(['status' => 'ok']);
            }
        }

        // FLUJO NORMAL DE CONTPAQI
        if ($lastQuestion === 'contpaqi_modulo') {
            $modulo = '';

            if (str_contains($text, 'contab') || str_contains($text, '1')) {
                $modulo = 'Contabilidad';
                $nextQuestion = 'contpaqi_detalle_contabilidad';
                $message = "📊 *CONTPAQi Contabilidad* - Excelente elección.\n\nPerfecto para:\n✅ Control fiscal total\n✅ Contabilidad electrónica SAT\n\n¿Necesitas implementación nueva, actualización o migración?";
            } elseif (str_contains($text, 'nomin') || str_contains($text, '2')) {
                $modulo = 'Nóminas';
                $nextQuestion = 'contpaqi_detalle_nominas';
                $message = "👥 *CONTPAQi Nóminas* - La mejor decisión.\n\n¿Cuántos empleados tienes actualmente?";
            } else {
                $this->sendMessage($from, "No estoy seguro de entender. Por favor elige:\n\n📊 Contabilidad\n👥 Nóminas\n🏪 Comercial");

                return response()->json(['status' => 'ok']);
            }

            $metadata['modulo_interes'] = $modulo;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => $nextQuestion]);
            $this->sendMessage($from, $message);

            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'contpaqi_usuarios') {
            preg_match('/\d+/', $text, $matches);
            $usuarios = $matches[0] ?? null;

            if ($usuarios) {
                $metadata['num_usuarios'] = $usuarios;
                $chat->update(['metadata' => json_encode($metadata)]);
                $this->sendMessage($from, "Excelente, con $usuarios usuario(s). 👥\n\n¿Prefieres:\n\n1️⃣ *Licencia perpetua* (compra única)\n2️⃣ *Renta mensual*\n\nEscribe el número.");
                $chat->update(['last_bot_question' => 'contpaqi_modalidad']);
            } else {
                $this->sendMessage($from, '¿Cuántos usuarios trabajarán con el sistema? (Solo el número)');
            }

            return response()->json(['status' => 'ok']);
        }

        // Continuar con resto del flujo...
        $this->sendMessage($from, "¿Podrías ser más específico? También puedes escribir *'menú'* para volver.");

        return response()->json(['status' => 'ok']);
    }

    // Resto de flujos (Nube, Rediseño, etc.) - mantener igual

    private function startNubeFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function handleNubeFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function startRedisenoFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function handleRedisenoFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function startCapacitacionFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function handleCapacitacionFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function startSoporteFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    private function handleSoporteFlow($chat, $from, $text)
    { /* ... código existente ... */ return response()->json(['status' => 'ok']);
    }

    // ============================================
    // 🤖 MANEJO DE MENSAJES DESCONOCIDOS CON IA
    // ============================================

    private function handleUnknownMessage($chat, $from, $text)
    {
        \Log::info('🤖 Mensaje desconocido, usando IA', ['message' => $text, 'context' => $chat->context]);

        $history = json_decode($chat->conversation_history ?? '[]', true);
        $context = $chat->context ?? 'INITIAL';

        $aiResponse = $this->groqService->generateContextualResponse($history, $text, $context);

        if ($aiResponse) {
            $this->sendMessage($from, $aiResponse);

            sleep(2);
            $this->sendMessage($from, "¿Te gustaría conocer más sobre alguno de nuestros servicios?\n\n• *CONTPAQi*\n• *Nube*\n• *Rediseño*\n• *Capacitación*\n• *Soporte*\n\nO escribe *'Asesor'* para hablar con un ejecutivo.");

            return response()->json(['status' => 'ok']);
        }

        // Fallback si IA falla
        $this->sendMessage($from, "🤔 Disculpa, no estoy seguro de entender.\n\nPuedes escribir:\n\n• *'CONTPAQi'*\n• *'Nube'*\n• *'Asesor'*\n• *'Menú'*");

        return response()->json(['status' => 'ok']);
    }

    // ============================================
    // FLUJO DE COTIZACIÓN
    // ============================================

    private function handleQuoteFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        $metadata = json_decode($chat->metadata ?? '{}', true);

        if ($lastQuestion === 'nombre') {
            $metadata['nombre'] = $text;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'empresa']);
            $this->sendMessage($from, "Mucho gusto, *$text*. 👋\n\n2️⃣ ¿Cuál es el nombre de tu *empresa*?");

            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'empresa') {
            $metadata['empresa'] = $text;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'email']);
            $this->sendMessage($from, '3️⃣ ¿A qué *correo electrónico* te envío la propuesta?');

            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'email') {
            if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $metadata['email'] = $text;
                $this->sendMessage($from, "✅ ¡Perfecto!\n\n📋 *Resumen:*\nNombre: {$metadata['nombre']}\nEmpresa: {$metadata['empresa']}\nEmail: $text\n\n📧 Un asesor te enviará la cotización en las próximas 2 horas.\n\n¿Algo más en que pueda ayudarte?");
                $this->notifyAgent("🎯 Nueva cotización\n👤 {$metadata['nombre']}\n🏢 {$metadata['empresa']}\n📧 $text\n📱 $from");
                $chat->update(['context' => 'START', 'last_bot_question' => null, 'metadata' => json_encode($metadata)]);
            } else {
                $this->sendMessage($from, "❌ Correo inválido. Intenta de nuevo.\n\nEjemplo: nombre@empresa.com");
            }

            return response()->json(['status' => 'ok']);
        }

        return response()->json(['status' => 'ok']);
    }

    // ============================================
    // RESPUESTAS GENERALES
    // ============================================

    private function findGeneralResponse($text)
    {
        $generalCatalog = [
            [
                'keys' => ['quienes son', 'que hacen', 'sobre ustedes', 'empresa', 'conocer'],
                'response' => "Somos *Tecnología Empresarial*, consultores con 30 años de experiencia.\n\n🎯 Blindamos tu empresa con:\n🚀 Tecnología\n📊 Automatización\n🎓 Capacitación\n\n¿Te gustaría conocer nuestros servicios?",
                'context' => 'INFO',
            ],
            [
                'keys' => ['servicios', 'que ofrecen'],
                'response' => "🚀 *Servicios:*\n\n📊 CONTPAQi\n☁️ Nube\n🛡️ Rediseño\n🎓 Capacitación\n🛠️ Soporte\n\n¿Cuál te interesa?",
                'context' => 'START',
            ],
        ];

        foreach ($generalCatalog as $item) {
            foreach ($item['keys'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function sendCatalogResponse($from, $response, $chat)
    {
        if (isset($response['context'])) {
            $chat->update(['context' => $response['context']]);
        }
        $this->sendMessage($from, $response['response']);

        return response()->json(['status' => 'ok']);
    }

    // ============================================
    // COMANDOS DE AGENTES
    // ============================================

    private function handleAgentCommands($from, $text)
    {
        $agentNumbers = config('services.whatsapp.agent_numbers', []);
        if (! in_array($from, $agentNumbers)) {
            return false;
        }

        if ($text === '/pendientes') {
            $pending = Message::where('requires_human', true)->where('handled', false)->with('chat')->get();
            $response = '📋 *Consultas pendientes:* '.$pending->count()."\n\n";
            foreach ($pending as $msg) {
                $response .= "🆔 {$msg->id}\n👤 {$msg->chat->user_number}\n💬 {$msg->message}\n\n";
            }
            $this->sendMessage($from, $response ?: 'No hay pendientes. ✅');

            return true;
        }

        return false;
    }

    private function notifyAgent($message)
    {
        $agentNumbers = config('services.whatsapp.agent_numbers', []);
        foreach ($agentNumbers as $agentNumber) {
            $this->sendMessage($agentNumber, $message);
        }
    }

    // ============================================
    // UTILIDADES
    // ============================================

    private function isGreeting($text)
    {
        $greetings = ['hola', 'buenos dias', 'buenas tardes', 'hey', 'saludos'];
        foreach ($greetings as $greeting) {
            if (str_contains($text, $greeting)) {
                return true;
            }
        }

        return false;
    }

    private function isPositiveResponse($text)
    {
        $positives = ['si', 'sí', 'claro', 'ok', 'vale', 'perfecto', 'quiero', '👍', '✅', '1'];
        foreach ($positives as $positive) {
            if (str_contains($text, $positive) || $text === $positive) {
                return true;
            }
        }

        return false;
    }

    private function updateChatContext($chat, $context)
    {
        $chat->context = $context;
        $chat->save();
        $chat->refresh();
    }

    private function updateChatQuestion($chat, $question)
    {
        $chat->last_bot_question = $question;
        $chat->save();
        $chat->refresh();
    }

    private function updateChatMetadata($chat, array $metadata)
    {
        $chat->metadata = json_encode($metadata);
        $chat->save();
        $chat->refresh();
    }

    private function updateChatFull($chat, $data)
    {
        foreach ($data as $key => $value) {
            $chat->$key = $value;
        }
        $chat->save();
        $chat->refresh();
    }

    private function updateConversationHistory($chat, $message, $sender)
    {
        // Decodificar historial existente o iniciar array vacío
        $history = json_decode($chat->conversation_history ?? '[]', true);

        // Agregar nuevo mensaje con metadatos
        $history[] = [
            'sender' => $sender,           // 'user', 'bot', 'agent'
            'message' => $message,         // Contenido del mensaje
            'timestamp' => now()->toDateTimeString(), // Fecha y hora
            'topic' => $chat->context,     // Contexto actual (CONTPAQI, NUBE, etc.)
        ];

        // Mantener solo los últimos 20 mensajes para evitar exceder límites
        // Esto optimiza el uso de tokens en llamadas a la IA
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        // Guardar en base de datos
        $chat->conversation_history = json_encode($history);
        $chat->save();
        $chat->refresh();

        // Log para debugging (opcional, puedes comentar en producción)
        \Log::info('✅ Conversation history actualizado', [
            'chat_id' => $chat->id,
            'total_messages' => count($history),
            'last_sender' => $sender,
            'context' => $chat->context,
        ]);
    }

    private function sendMessage(string $to, string $message): void
    {
        $chat = Chat::where('user_number', $to)->first();

        if ($chat) {
            Message::create([
                'chat_id' => $chat->id,
                'message' => $message,
                'type' => 'bot',
                'handled' => true,
            ]);
            $this->updateConversationHistory($chat, $message, 'bot');
        }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message],
            ]);
    }
}
