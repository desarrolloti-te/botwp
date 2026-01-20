<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
// models
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    private $conversationContexts = [
        'CONTPAQI' => ['licencias', 'precios', 'funciones', 'versiones', 'modulos'],
        'NUBE' => ['escritorios', 'virtuales', 'servidor', 'hosting', 'respaldo'],
        'REDISEÑO' => ['blindaje', 'fiscal', 'automatizacion', 'procesos', 'materialidad'],
        'CAPACITACION' => ['cursos', 'talleres', 'entrenamiento', 'stps', 'certificacion'],
        'SOPORTE' => ['error', 'falla', 'problema', 'ticket', 'ayuda'],
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

        // \Log::emergency('¡ENTRÓ ALGO!'); // Log de emergencia
        // \Log::info('Payload completo:', $request->all());
        // \Log::info('Webhook detectado', ['data' => $request->all()]);

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
            $this->sendMessage($from, "👨‍💼 Perfecto, estoy conectando tu conversación con un ejecutivo especializado. En breve te atenderá. \n\n⏱️");
            $this->notifyAgent("🔔 Nuevo cliente requiere atención humana\n📱 Número: $from\n💬 Último mensaje: $text");
            return response()->json(['status' => 'ok']);
        }
        if ($this->handleAgentCommands($from, $text)) {
            return response()->json(['status' => 'ok']);
        }
        if ($chat->status === 'waiting_agent') {
            return response()->json(['status' => 'ok']);
        }

        // if (in_array($text, ['hola', 'menu', 'inicio', 'empezar'])) {
        //     $chat->update(['context' => 'START']);
        //     return $this->handleStartFlow($chat, $from, $text);
        // }

        // if ($chat->context === 'QUOTE_WAITING_EMAIL') {
        //     return $this->handleQuoteFlow($chat, $from, $text);
        // }
        // if ($chat->context === 'SUPPORT_WAITING_TICKET') {
        //     return $this->handleSupportFlow($chat, $from, $text);
        // }

       
        // return match ($chat->context) {
        //     'START' => $this->handleStartFlow($chat, $from, $text), // Maneja opciones 1, 2, 3
        //     'SERVICES' => $this->handleServicesFlow($chat, $from, $text),
        //     'QUOTE' => $this->handleQuoteFlow($chat, $from, $text),
        // };
        
        // $catalogResponse = $this->findResponseInCatalog($text);

        // if ($catalogResponse !== null) {
           
        //     switch ($catalogResponse['type']) {
        //         case 'image':
        //             $this->sendImage($from, $catalogResponse['url'], $catalogResponse['caption'] ?? '');
        //             break;
        //         case 'video':
        //             $this->sendVideo($from, $catalogResponse['url'], $catalogResponse['caption'] ?? '');
        //             break;
        //         case 'document':
        //             $this->sendDocument($from, $catalogResponse['url'], $catalogResponse['filename'] ?? 'documento.pdf');
        //             break;
        //         default: // text
        //             $this->sendMessage($from, $catalogResponse['response']);
        //             break;
        //     }

            
        //     $chat->update(['context' => 'START']); 
            
        //     return response()->json(['status' => 'ok']);
        // }

        


        // if ($text === '/agente' && in_array($from, config('services.whatsapp.agent_numbers'))) {
        //     $pending = \App\Models\Message::where('requires_human', true)
        //         ->where('handled', false)
        //         ->with('chat')
        //         ->get();

        //     $response = "📋 Consultas pendientes:\n\n";

        //     foreach ($pending as $msg) {
        //         $response .= "ID: {$msg->id}\n";
        //         $response .= "Usuario: {$msg->chat->user_number}\n";
        //         $response .= "Mensaje: {$msg->message}\n\n";
        //     }

        //     $this->sendMessage($from, $response ?: 'No hay consultas pendientes.');

        //     return response()->json(['status' => 'ok']);
        // }

        // if (
        //     str_starts_with($text, '/responder') &&
        //     in_array($from, config('services.whatsapp.agent_numbers'))
        // ) {

        //     preg_match('/^\/responder (\d+) (.+)$/s', $text, $matches);

        //     if (count($matches) !== 3) {
        //         $this->sendMessage($from, '❌ Usa: /responder <ID> <mensaje>');

        //         return response()->json(['status' => 'ok']);
        //     }

        //     [, $msgId, $replyText] = $matches;

        //     $msg = Message::with('chat')->find($msgId);

        //     if (! $msg) {
        //         $this->sendMessage($from, '❌ Mensaje no encontrado.');

        //         return response()->json(['status' => 'ok']);
        //     }

        //     Message::create([
        //         'chat_id' => $msg->chat->id,
        //         'message' => $replyText,
        //         'type' => 'agent',
        //         'handled' => true,
        //     ]);

        //     $msg->update(['handled' => true]);

        //     $this->sendMessage($msg->chat->user_number, $replyText);

        //     $this->sendMessage($from,
        //         "✅ Respuesta enviada al usuario {$msg->chat->user_number}"
        //     );

        //     return response()->json(['status' => 'ok']);
        // }

        // return response()->json(['status' => 'ok']);

        return $this->processIntelligentMessage($chat, $from, $text, $isFirstMessage);
    }

    private function processIntelligentMessage($chat, $from, $text, $isFirstMessage)
    {
        // 1. Si es primer mensaje y no es saludo, enviar saludo + respuesta
        if ($isFirstMessage && !$this->isGreeting($text)) {
            $this->sendMessage($from, "¡Hola! 👋 Bienvenido a *Tecnología Empresarial*.\n\n🚀 Estamos aquí para ayudarte a blindar y digitalizar tu empresa.");
            sleep(1); // Pausa breve para simular conversación natural
        }

        // 2. Detectar si es un saludo o reset
        if ($this->isGreeting($text) || in_array($text, ['menu', 'inicio', 'empezar', 'principal'])) {
            return $this->handleInitialGreeting($chat, $from);
        }

        // 3. Buscar en el catálogo de respuestas rápidas
        $catalogResponse = $this->findResponseInCatalog($text);
        
        if ($catalogResponse !== null && $catalogResponse['type'] !== 'fallback') {
            // Actualizar contexto basado en la respuesta
            $this->updateContextFromResponse($chat, $catalogResponse);
            
            return $this->sendCatalogResponse($from, $catalogResponse, $chat);
        }

        // 4. Análisis de intención basado en contexto actual
        $currentContext = $chat->context ?? 'INITIAL';
        
        // Si estamos en un contexto específico, intentar entender dentro de ese contexto
        if ($currentContext !== 'INITIAL' && $currentContext !== 'START') {
            $contextualResponse = $this->handleContextualMessage($chat, $from, $text, $currentContext);
            if ($contextualResponse !== null) {
                return $contextualResponse;
            }
        }

        // 5. Buscar en otros contextos si no hay match en el actual
        $newContext = $this->detectContextFromMessage($text);
        
        if ($newContext !== null) {
            $chat->update(['context' => $newContext]);
            return $this->handleContextSwitch($chat, $from, $text, $newContext);
        }

        // 6. Respuesta por defecto inteligente
        return $this->handleUnknownMessage($chat, $from, $text);
    }

    private function handleInitialGreeting($chat, $from)
    {
        // Detectar si es cliente recurrente
        $messageCount = Message::where('chat_id', $chat->id)->count();
        
        if ($messageCount > 5) {
            $greeting = "¡Qué gusto verte de nuevo! 👋\n\n";
        } else {
            $greeting = "¡Hola! 👋 Qué gusto saludarte. Bienvenido a *Tecnología Empresarial*.\n\n";
        }

        $chat->update(['context' => 'START']);
        
        $message = $greeting . "Estamos encantados de acompañarte en este *2026* para que tu negocio no solo crezca, sino que esté totalmente blindado y a la vanguardia. 🚀\n\n¿Cómo podemos apoyarte hoy?\n\n1️⃣ *Conocer Tecnología Empresarial*\n2️⃣ *Explorar servicios* (CONTPAQi, Rediseño, Capacitación)\n3️⃣ *Soporte Técnico*\n4️⃣ *Hablar con un ejecutivo*\n\n_También puedes escribirme directamente lo que necesitas y procesaré tu solicitud. 😊_";
        
        $this->sendMessage($from, $message);
        return response()->json(['status' => 'ok']);
    }

    private function handleContextualMessage($chat, $from, $text, $context)
    {
        // Manejo específico según el contexto actual
        switch ($context) {
            case 'QUOTE':
            case 'QUOTE_WAITING_EMAIL':
                return $this->handleQuoteFlow($chat, $from, $text);
            
            case 'SUPPORT':
            case 'SUPPORT_WAITING_TICKET':
                return $this->handleSupportFlow($chat, $from, $text);
            
            case 'CONTPAQI':
                return $this->handleContPaqiContext($chat, $from, $text);
            
            case 'NUBE':
                return $this->handleNubeContext($chat, $from, $text);
            
            case 'REDISEÑO':
                return $this->handleRedisenoContext($chat, $from, $text);
            
            case 'CAPACITACION':
                return $this->handleCapacitacionContext($chat, $from, $text);
        }
        
        return null;
    }

    private function handleContPaqiContext($chat, $from, $text)
    {
        if (str_contains($text, 'precio') || str_contains($text, 'costo') || str_contains($text, 'cuanto')) {
            $this->sendMessage($from, "💰 Los precios de CONTPAQi varían según:\n• Número de usuarios\n• Módulos requeridos (Contabilidad, Nóminas, Comercial, etc.)\n• Modalidad (compra o renta)\n\n¿Te gustaría que un asesor comercial te prepare una cotización personalizada? Escribe *'Sí'* o *'Cotización'*");
            $chat->update(['context' => 'QUOTE']);
            return response()->json(['status' => 'ok']);
        }
        
        if (str_contains($text, 'modulo') || str_contains($text, 'funcion') || str_contains($text, 'caracteristica')) {
            $this->sendMessage($from, "📊 CONTPAQi cuenta con módulos especializados:\n\n*Contabilidad* - Control fiscal y financiero\n*Nóminas* - Gestión de capital humano\n*Comercial* - Facturación e inventarios\n*Bancos* - Conciliación bancaria\n*Producción* - Control de manufactura\n\n¿Sobre cuál módulo te gustaría saber más?");
            return response()->json(['status' => 'ok']);
        }
        
        return null;
    }

    private function handleNubeContext($chat, $from, $text)
    {
        if (str_contains($text, 'precio') || str_contains($text, 'costo')) {
            $this->sendMessage($from, "☁️ Nuestros planes de Escritorios Virtuales son flexibles:\n\n• *Plan Básico*: 1 usuario - Ideal para emprendedores\n• *Plan Empresarial*: 3-10 usuarios\n• *Plan Corporativo*: +10 usuarios\n\nTodos incluyen:\n✅ Respaldos diarios automáticos\n✅ Soporte técnico 24/7\n✅ Actualizaciones incluidas\n\n¿Te gustaría una cotización personalizada?");
            $chat->update(['context' => 'QUOTE']);
            return response()->json(['status' => 'ok']);
        }
        
        if (str_contains($text, 'ventaja') || str_contains($text, 'beneficio') || str_contains($text, 'porque')) {
            $this->sendMessage($from, "🌟 *Beneficios de la Nube:*\n\n✅ Acceso desde cualquier lugar\n✅ Sin inversión en servidores físicos\n✅ Respaldos automáticos diarios\n✅ Escalable según tu crecimiento\n✅ Eliminación de costos de mantenimiento\n✅ Máxima seguridad de información\n\n¿Te gustaría ver una demostración?");
            return response()->json(['status' => 'ok']);
        }
        
        return null;
    }

    private function handleRedisenoContext($chat, $from, $text)
    {
        if (str_contains($text, 'como') || str_contains($text, 'proceso') || str_contains($text, 'pasos')) {
            $this->sendMessage($from, "🔄 *Proceso de Rediseño 360°:*\n\n1️⃣ *Diagnóstico Operativo* - Identificamos vulnerabilidades\n2️⃣ *Diseño de Arquitectura* - Creamos tu modelo óptimo\n3️⃣ *Implementación Tecnológica* - Automatizamos procesos\n4️⃣ *Capacitación del Equipo* - Empoderamos a tu personal\n5️⃣ *Acompañamiento Post-Implementación*\n\n¿Te gustaría agendar un diagnóstico sin costo?");
            return response()->json(['status' => 'ok']);
        }
        
        return null;
    }

    private function handleCapacitacionContext($chat, $from, $text)
    {
        if (str_contains($text, 'duracion') || str_contains($text, 'horario') || str_contains($text, 'cuando')) {
            $this->sendMessage($from, "🎓 *Modalidades de Capacitación:*\n\n• *Presencial* - En tus instalaciones o las nuestras\n• *Virtual* - Sesiones en vivo por Zoom\n• *Híbrida* - Combinación de ambas\n\nDuración: 8-40 horas según el curso\nHorarios: Flexibles, adaptados a tu operación\n\n¿Qué curso te interesa? (Contabilidad, Nóminas, Excel, etc.)");
            return response()->json(['status' => 'ok']);
        }
        
        return null;
    }

    private function detectContextFromMessage($text)
    {
        foreach ($this->conversationContexts as $context => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $context;
                }
            }
        }
        
        return null;
    }

    private function handleContextSwitch($chat, $from, $text, $newContext)
    {
        // Transición suave de contexto
        $transitions = [
            'CONTPAQI' => "Entiendo que te interesa *CONTPAQi*. 🎯\n\nSomos Socios Máster con 30 años implementando soluciones administrativas. ¿Te gustaría conocer sobre licencias, funcionalidades o precios?",
            'NUBE' => "¡Excelente elección! ☁️\n\nNuestros *Escritorios Virtuales* transformarán tu forma de trabajar. ¿Quieres conocer los beneficios, planes o ver una demo?",
            'REDISEÑO' => "Perfecto, hablemos de *Rediseño Empresarial*. 🛡️\n\nNo solo implementamos software, reestructuramos tu operación completa. ¿Te interesa saber cómo funciona el proceso?",
            'CAPACITACION' => "¡Invertir en tu equipo es la mejor decisión! 🎓\n\n¿Buscas cursos de CONTPAQi, Excel, Fiscales o certificaciones STPS?",
            'SOPORTE' => "Entiendo que necesitas *soporte técnico*. 🛠️\n\nPara ayudarte mejor, ¿tu sistema está en servidor físico o en la nube?",
        ];
        
        $message = $transitions[$newContext] ?? "Entiendo tu interés. ¿Cómo puedo ayudarte específicamente?";
        $this->sendMessage($from, $message);
        
        return response()->json(['status' => 'ok']);
    }

    private function handleUnknownMessage($chat, $from, $text)
    {
        $history = json_decode($chat->conversation_history ?? '[]', true);
        $recentTopics = array_slice($history, -3);
        
        $contextHint = "";
        if (!empty($recentTopics)) {
            $contextHint = "\n\n_Nota: Estábamos hablando sobre " . end($recentTopics)['topic'] . "_";
        }
        
        $this->sendMessage($from, "Disculpa, no estoy seguro de entender exactamente. 🤔" . $contextHint . "\n\n¿Podrías reformular tu pregunta? O prueba:\n\n• *'CONTPAQi'* - Sistemas administrativos\n• *'Nube'* - Escritorios virtuales\n• *'Rediseño'* - Blindaje fiscal\n• *'Asesor'* - Hablar con ejecutivo\n• *'Menú'* - Ver opciones principales");
        
        return response()->json(['status' => 'ok']);
    }

    private function isGreeting($text)
    {
        $greetings = ['hola', 'buenos dias', 'buenas tardes', 'buenas noches', 'buen dia', 'hey', 'saludos', 'que tal'];
        
        foreach ($greetings as $greeting) {
            if (str_contains($text, $greeting)) {
                return true;
            }
        }
        
        return false;
    }

    private function updateConversationHistory($chat, $message, $sender)
    {
        $history = json_decode($chat->conversation_history ?? '[]', true);
        
        $history[] = [
            'sender' => $sender,
            'message' => $message,
            'timestamp' => now()->toDateTimeString(),
            'topic' => $chat->context,
        ];
        
        // Mantener solo los últimos 20 mensajes
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }
        
        $chat->update(['conversation_history' => json_encode($history)]);
    }

    private function updateContextFromResponse($chat, $response)
    {
        // Actualizar contexto basado en palabras clave de la respuesta
        if (isset($response['context'])) {
            $chat->update(['context' => $response['context']]);
        }
    }

    private function sendCatalogResponse($from, $catalogResponse, $chat)
    {
        switch ($catalogResponse['type']) {
            case 'image':
                $this->sendImage($from, $catalogResponse['url'], $catalogResponse['caption'] ?? '');
                break;
            case 'video':
                $this->sendVideo($from, $catalogResponse['url'], $catalogResponse['caption'] ?? '');
                break;
            case 'document':
                $this->sendDocument($from, $catalogResponse['url'], $catalogResponse['filename'] ?? 'documento.pdf');
                break;
            default:
                $this->sendMessage($from, $catalogResponse['response']);
                break;
        }
        
        return response()->json(['status' => 'ok']);
    }


    private function handleQuoteFlow($chat, $from, $text)
    {
        if (in_array($text, ['cancelar', 'salir', '0', 'no'])) {
            $chat->update(['context' => 'START']);
            $this->sendMessage($from, 'Cotización cancelada. ¿En qué más puedo ayudarte?');
            return response()->json(['status' => 'ok']);
        }

        $lastQuestion = $chat->last_bot_question ?? '';

        // Flujo de recolección de datos
        if (empty($lastQuestion)) {
            $chat->update(['last_bot_question' => 'nombre']);
            $this->sendMessage($from, "Perfecto, para preparar tu cotización personalizada necesito algunos datos.\n\n1️⃣ ¿Cuál es tu *nombre completo*?");
            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'nombre') {
            $chat->update(['last_bot_question' => 'empresa', 'metadata' => json_encode(['nombre' => $text])]);
            $this->sendMessage($from, "Mucho gusto, *$text*. 👋\n\n2️⃣ ¿Cuál es el nombre de tu *empresa*?");
            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'empresa') {
            $metadata = json_decode($chat->metadata, true);
            $metadata['empresa'] = $text;
            $chat->update(['last_bot_question' => 'email', 'metadata' => json_encode($metadata)]);
            $this->sendMessage($from, "Excelente. 3️⃣ ¿A qué *correo electrónico* te envío la propuesta?");
            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'email') {
            if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $metadata = json_decode($chat->metadata, true);
                $metadata['email'] = $text;
                
                $this->sendMessage($from, "✅ ¡Perfecto! Hemos recibido tu solicitud.\n\n📋 *Resumen:*\nNombre: {$metadata['nombre']}\nEmpresa: {$metadata['empresa']}\nEmail: $text\n\n📧 Un asesor comercial te enviará la cotización personalizada en las próximas 2 horas.\n\n¿Hay algo más en lo que pueda ayudarte?");
                
                $this->notifyAgent("🎯 Nueva solicitud de cotización\n👤 {$metadata['nombre']}\n🏢 {$metadata['empresa']}\n📧 $text\n📱 $from");
                
                $chat->update(['context' => 'START', 'last_bot_question' => null, 'metadata' => json_encode($metadata)]);
            } else {
                $this->sendMessage($from, "❌ El correo no parece válido. Por favor, verifica e inténtalo de nuevo.\n\nEjemplo: nombre@empresa.com");
            }
            
            return response()->json(['status' => 'ok']);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleStartFlow($chat, $from, $text)
    {
        if (str_contains($text, '1') || str_contains($text, 'conocer')) {
            $chat->update(['context' => 'INFO']); // Podrías crear este contexto
            $this->sendMessage($from, "Somos especialistas en *blindar tu empresa* y asegurar que duermas tranquilo con *tu cumplimiento fiscal*, es por ello que nos especializamos en tres pilares clave:\n\n 🚀 *1. Ecosistema CONTPAQi®* Somos _Distribuidor Asociado Nivel Oro_. No solo te damos la licencia; te acompañamos en la digitalización total de tu empresa con soluciones en la nube, soporte técnico especializado y la experiencia de verdaderos expertos en la marca. \n\n 📊 *2. Rediseño Empresarial* Transformamos la estructura de tu negocio. Implementamos tecnología para garantizar *_la materialidad, trazabilidad y razón de negocio de tus operaciones_*. Logramos que tu administración sea sólida, automatizando los procesos de tu empresa, cumpliendo con las normativas fiscales actuales.\n\n 🎓 *3. Capacitación Especializada* El talento humano es _el motor de tu empresa_. Nos encargamos de entrenar a tu equipo para que *enfrenten* los retos del mercado, *dominen* las herramientas digitales y *alcancen su máximo nivel de eficiencia*.");
        } elseif (str_contains($text, '2') || str_contains($text, 'servicios')) {
            $chat->update(['context' => 'SERVICES']);
            $this->sendMessage($from, "Somos especialistas en *blindar tu empresa* y asegurar que duermas tranquilo con *tu cumplimiento fiscal*, es por ello que nos especializamos en tres pilares clave:\n\n 🚀 *1. Ecosistema CONTPAQi®* Somos _Distribuidor Asociado Nivel Oro_. No solo te damos la licencia; te acompañamos en la digitalización total de tu empresa con soluciones en la nube, soporte técnico especializado y la experiencia de verdaderos expertos en la marca. \n\n 📊 *2. Rediseño Empresarial* Transformamos la estructura de tu negocio. Implementamos tecnología para garantizar *_la materialidad, trazabilidad y razón de negocio de tus operaciones_*. Logramos que tu administración sea sólida, automatizando los procesos de tu empresa, cumpliendo con las normativas fiscales actuales.\n\n 🎓 *3. Capacitación Especializada* El talento humano es _el motor de tu empresa_. Nos encargamos de entrenar a tu equipo para que *enfrenten* los retos del mercado, *dominen* las herramientas digitales y *alcancen su máximo nivel de eficiencia*.");
        } elseif (str_contains($text, '3') || str_contains($text, 'soporte')) {
            $chat->update(['context' => 'SUPPORT']);
            $this->sendMessage($from, "🛠️ Bienvenido a Soporte Técnico.\n1. Reportar falla nueva\n2. Consultar ticket existente\n0. Volver al menú");
        } else {
            $this->sendMessage($from, "¡Hola! 👋 Qué gusto saludarte. Bienvenido a *Tecnología Empresarial*.\n\nEstamos encantados de acompañarte en este *2026* para que tu negocio no solo crezca, sino que esté totalmente blindado y a la vanguardia. 🚀\n\n¿Cómo podemos apoyarte hoy?\n\n1️⃣ *Conoce Tecnología Empresarial* (Quiénes somos y nuestro compromiso contigo)\n2️⃣ *Explora nuestros servicios* (CONTPAQi, Rediseño y Capacitación)\n3️⃣ *Soporte Técnico* (Asistencia ejecutiva para tus sistemas)\n\n_Solo escribe el **número** o la **palabra** de lo que necesites y procesaré tu solicitud de inmediato. 😊_");
        }

        return response()->json(['status' => 'ok']);
    }

   private function handleSupportFlow($chat, $from, $text)
    {
        if (in_array($text, ['cancelar', 'salir', '0', 'menu'])) {
            $chat->update(['context' => 'START']);
            return $this->handleInitialGreeting($chat, $from);
        }

        if ($chat->context === 'SUPPORT' && !isset($chat->last_bot_question)) {
            $this->sendMessage($from, "🛠️ *Soporte Técnico*\n\nPara brindarte la mejor asistencia:\n\n1️⃣ Reportar nueva falla\n2️⃣ Consultar ticket existente\n3️⃣ Preguntas frecuentes\n0️⃣ Volver al menú\n\n_Escribe el número de tu opción_");
            $chat->update(['last_bot_question' => 'support_option']);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '1') {
            $chat->update(['context' => 'SUPPORT_WAITING_TICKET', 'last_bot_question' => 'describe_error']);
            $this->sendMessage($from, "Por favor, describe tu problema:\n\n• ¿Qué sistema está fallando?\n• ¿Qué mensaje de error recibes?\n• ¿Cuándo comenzó el problema?\n\nPuedes adjuntar capturas de pantalla si es posible.");
            return response()->json(['status' => 'ok']);
        }

        if ($text === '2') {
            $this->sendMessage($from, "Introduce tu número de ticket (ejemplo: TKT-2026-001):");
            $chat->update(['last_bot_question' => 'check_ticket']);
            return response()->json(['status' => 'ok']);
        }

        if ($text === '3') {
            $this->sendMessage($from, "❓ *Preguntas Frecuentes*\n\n1. Mi sistema está lento\n2. No puedo acceder\n3. Error de timbrado\n4. Problemas de conexión\n\n¿Cuál es tu situación?");
            return response()->json(['status' => 'ok']);
        }

        // Si está describiendo el error
        if ($chat->last_bot_question === 'describe_error') {
            $ticketNumber = 'TKT-' . now()->format('Y') . '-' . rand(1000, 9999);
            
            $this->sendMessage($from, "✅ *Ticket creado exitosamente*\n\n🎫 Número: $ticketNumber\n⏱️ Un técnico lo atenderá en breve\n\n📧 Recibirás actualizaciones por WhatsApp.\n\n¿Necesitas algo más?");
            
            $this->notifyAgent("🔧 Nuevo ticket de soporte\n🎫 $ticketNumber\n📱 $from\n💬 $text");
            
            $chat->update(['context' => 'START', 'last_bot_question' => null]);
            return response()->json(['status' => 'ok']);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleAgentCommands($from, $text)
    {
        $agentNumbers = config('services.whatsapp.agent_numbers', []);
        
        if (!in_array($from, $agentNumbers)) {
            return false;
        }

        if ($text === '/pendientes' || $text === '/agente') {
            $pending = Message::where('requires_human', true)
                ->where('handled', false)
                ->with('chat')
                ->get();

            $response = "📋 *Consultas pendientes:* " . $pending->count() . "\n\n";

            foreach ($pending as $msg) {
                $response .= "🆔 ID: {$msg->id}\n";
                $response .= "👤 Usuario: {$msg->chat->user_number}\n";
                $response .= "💬 Mensaje: {$msg->message}\n";
                $response .= "⏰ Hora: {$msg->created_at->format('H:i')}\n\n";
            }

            $this->sendMessage($from, $response ?: 'No hay consultas pendientes. ✅');
            return true;
        }

        if (str_starts_with($text, '/responder')) {
            preg_match('/^\/responder (\d+) (.+)$/s', $text, $matches);

            if (count($matches) !== 3) {
                $this->sendMessage($from, '❌ Formato incorrecto.\n\nUsa: /responder <ID> <mensaje>');
                return true;
            }

            [, $msgId, $replyText] = $matches;
            $msg = Message::with('chat')->find($msgId);

            if (!$msg) {
                $this->sendMessage($from, '❌ Mensaje no encontrado.');
                return true;
            }

            Message::create([
                'chat_id' => $msg->chat->id,
                'message' => $replyText,
                'type' => 'agent',
                'handled' => true,
            ]);

            $msg->update(['handled' => true]);
            $msg->chat->update(['status' => 'open', 'context' => 'START']);

            $this->sendMessage($msg->chat->user_number, $replyText);
            $this->sendMessage($from, "✅ Respuesta enviada a {$msg->chat->user_number}");

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
                'text' => [
                    'body' => $message,
                ],
            ]);
    }

    private function findResponseInCatalog(string $input): array
    {

        // $catalog = [
        //     // GREETING
        //     [
        //         'keys' => ['servicios', 'ofrecen', 'ofertan'],
        //         'type' => 'text',
        //         'response' => "Somos especialistas en *blindar tu empresa* y asegurar que duermas tranquilo con *tu cumplimiento fiscal*, es por ello que nos especializamos en tres pilares clave:\n\n 🚀 *1. Ecosistema CONTPAQi®* Somos _Distribuidor Asociado Nivel Oro_. No solo te damos la licencia; te acompañamos en la digitalización total de tu empresa con soluciones en la nube, soporte técnico especializado y la experiencia de verdaderos expertos en la marca. \n\n 📊 *2. Rediseño Empresarial* Transformamos la estructura de tu negocio. Implementamos tecnología para garantizar *_la materialidad, trazabilidad y razón de negocio de tus operaciones_*. Logramos que tu administración sea sólida, automatizando los procesos de tu empresa, cumpliendo con las normativas fiscales actuales.\n\n 🎓 *3. Capacitación Especializada* El talento humano es _el motor de tu empresa_. Nos encargamos de entrenar a tu equipo para que *enfrenten* los retos del mercado, *dominen* las herramientas digitales y *alcancen su máximo nivel de eficiencia*.",
        //     ],
        //     [
        //         'keys' => ['conoce', 'Tecnologia', 'sobre'],
        //         'type' => 'text',
        //         'response' => '',
        //     ],
        //     [
        //         'keys' => ['hola', 'inicio', 'buenos', 'buenas', 'menu', 'empezar'],
        //         'type' => 'text',
        //         'response' => "¡Hola! 👋 Qué gusto saludarte. Bienvenido a *Tecnología Empresarial*.\n\nEstamos encantados de acompañarte en este *2026* para que tu negocio no solo crezca, sino que esté totalmente blindado y a la vanguardia. 🚀\n\n¿Cómo podemos apoyarte hoy?\n\n1️⃣ *Conoce Tecnología Empresarial* (Quiénes somos y nuestro compromiso contigo)\n2️⃣ *Explora nuestros servicios* (CONTPAQi, Rediseño y Capacitación)\n3️⃣ *Soporte Técnico* (Asistencia ejecutiva para tus sistemas)\n\n_Solo escribe el **número** o la **palabra** de lo que necesites y procesaré tu solicitud de inmediato. 😊_",
        //     ],
        //     [
        //         'keys' => ['gracias', 'adios', 'bye', 'hasta luego'],
        //         'type' => 'text',
        //         'response' => '¡Gracias a ti! Estamos para blindar tu operación. Si necesitas algo más, aquí seguiremos. 🛡️',
        //     ],
        //     [
        //         'keys' => ['ubicacion', 'direccion', 'donde estan', 'oficina'],
        //         'type' => 'text',
        //         'response' => "📍 Nos encontramos listos para atenderte. Si requieres una visita presencial o consultoría en sitio, por favor escribe *'Cita'* para coordinar con un asesor.",
        //     ],

        //     // --- GRUPO 2: REDISEÑO Y BLINDAJE ---
        //     [
        //         'keys' => ['rediseño', 'rediseno', 'blindaje', 'reingenieria'],
        //         'type' => 'text',
        //         'response' => "🛡️ Nuestro *Rediseño 360°* no es solo software. Reestructuramos tus procesos administrativos para garantizar la *Materialidad* y *Razón de Negocios* que exige el SAT.\n\n¿Te gustaría agendar un diagnóstico de vulnerabilidad?",
        //     ],
        //     [
        //         'keys' => ['reforma', '2026', 'fiscal', 'sat', 'hacienda'],
        //         'type' => 'text',
        //         'response' => "⚠️ *Alerta 2026:* La fiscalización será inteligente. Lo que no está documentado digitalmente, no existe.\nTe ayudamos a generar la evidencia operativa necesaria para evitar multas. Escribe *'Diagnóstico'* para empezar.",
        //     ],
        //     [
        //         'keys' => ['materialidad', 'razon de negocio', 'evidencia'],
        //         'type' => 'text',
        //         'response' => 'La *Materialidad* es la clave para deducir impuestos hoy. Nosotros alineamos tu operación (compras, ventas, inventarios) para que cada movimiento genere su evidencia automática. ¿Quieres saber cómo?',
        //     ],
        //     [
        //         'keys' => ['auditoria', 'revision', 'multa', 'miedo', 'sancion'],
        //         'type' => 'text',
        //         'response' => 'No esperes a la notificación. 🛑 Nuestro servicio preventivo detecta inconsistencias antes que la autoridad. Actuamos como un escudo fiscal mediante tecnología y procesos.',
        //     ],

        //     // --- GRUPO 3: CONTPAQI Y NUBE ---
        //     [
        //         'keys' => ['contpaqi', 'sistema', 'programa', 'software'],
        //         'type' => 'text',
        //         'response' => "Somos *Socios Máster* con 30 años de experiencia. 🏅 Implementamos, configuramos y damos soporte a toda la suite CONTPAQi.\n¿Buscas una licencia nueva o renovar?",
        //     ],
        //     [
        //         'keys' => ['nube', 'escritorio', 'virtual', 'remoto', 'vdi', 'escritorios virtuales'],
        //         'type' => 'text',
        //         'response' => '☁️ *¡Lleva tu oficina a cualquier lugar!* Con nuestros Escritorios Virtuales olvídate de servidores físicos, fallas de luz y mantenimientos. Tu información segura y respaldada diariamente. ¿Te interesa ver los paquetes?',
        //     ],
        //     [
        //         'keys' => ['contabilidad', 'contable'],
        //         'type' => 'text',
        //         'response' => '*CONTPAQi Contabilidad* es el líder fiscal. Nosotros no solo lo instalamos, te enseñamos a usarlo para generar reportes financieros reales, no solo para cumplir. 📊',
        //     ],
        //     [
        //         'keys' => ['nominas', 'nomina', 'empleados'],
        //         'type' => 'text',
        //         'response' => 'Gestiona tu capital humano sin errores. *CONTPAQi Nóminas* cumple con todas las leyes laborales vigentes. ¿Necesitas ayuda con timbrado o cálculo?',
        //     ],
        //     [
        //         'keys' => ['comercial', 'facturacion', 'factura', 'inventario'],
        //         'type' => 'text',
        //         'response' => 'Controla inventarios, cuentas por cobrar y facturación al día con *CONTPAQi Comercial*. Ideal para integrar tu operación administrativa. 📦',
        //     ],
        //     [
        //         'keys' => ['bancos', 'tesoreria', 'flujo'],
        //         'type' => 'text',
        //         'response' => 'Conecta tus bancos con tu contabilidad automáticamente. Evita la talacha manual y ten tu flujo de efectivo al día con *CONTPAQi Bancos*. 💸',
        //     ],

        //     // --- GRUPO 4: CAPACITACIÓN ---
        //     [
        //         'keys' => ['capacitacion', 'curso', 'aprender', 'enseñar', 'taller'],
        //         'type' => 'text',
        //         'response' => '🎓 El software no comete errores, las personas sí. Ofrecemos capacitación para convertir a tu equipo en expertos operativos. ¿Buscas cursos para Contabilidad, Nóminas o Administración?',
        //     ],
        //     [
        //         'keys' => ['stps', 'certificado', 'constancia', 'diploma'],
        //         'type' => 'text',
        //         'response' => 'Nuestros cursos tienen valor curricular y registro ante la *STPS*. Capacitación formal para profesionalizar a tu empresa y cumplir con la normativa laboral.',
        //     ],

        //     // --- GRUPO 5: SOPORTE TÉCNICO ---
        //     [
        //         'keys' => ['soporte', 'ayuda', 'tecnico', 'fallando', 'apoyo'],
        //         'type' => 'text',
        //         'response' => '🛠️ Entendemos la urgencia. Para soporte técnico inmediato, por favor describe tu problema o envía una foto del error. Un ingeniero te atenderá en breve.',
        //     ],
        //     [
        //         'keys' => ['error', 'no abre', 'lento', 'mensaje'],
        //         'type' => 'text',
        //         'response' => "Detectamos que tienes un problema técnico. ¿Es en servidor físico o en la Nube? Escribe *'Físico'* o *'Nube'* para orientarte mejor.",
        //     ],
        //     [
        //         'keys' => ['actualizacion', 'version', 'actualizar'],
        //         'type' => 'text',
        //         'response' => 'Mantenerse actualizado es obligatorio por el SAT. ¿Deseas cotizar la actualización a la última versión de tu sistema?',
        //     ],
        //     [
        //         'keys' => ['migracion', 'cambio', 'mover'],
        //         'type' => 'text',
        //         'response' => '¿Quieres mover tu información a un nuevo servidor o a la Nube? Somos expertos en migraciones sin pérdida de datos. 💾',
        //     ],

        //     // --- GRUPO 6: VENTAS Y CIERRE ---
        //     [
        //         'keys' => ['precio', 'costo', 'cuanto cuesta', 'cotizacion', 'valor'],
        //         'type' => 'text',
        //         'response' => "Cada empresa es única. Para darte un precio justo, necesitamos saber el número de usuarios y el tipo de servicio.\n\n¿Te gustaría hablar con un asesor comercial ahora?",
        //     ],
        //     [
        //         'keys' => ['comprar', 'adquirir', 'contratar', 'quiero'],
        //         'type' => 'text',
        //         'response' => '¡Excelente decisión! 🎉 Estás a un paso de blindar tu empresa. Por favor compártenos tu *Nombre* y *Correo* para enviarte la propuesta formal.',
        //     ],
        //     [
        //         'keys' => ['cita', 'reunion', 'agendar', 'visita'],
        //         'type' => 'text',
        //         'response' => '🗓️ Claro, agendemos una sesión para analizar tus necesidades. ¿Prefieres cita presencial o videollamada?',
        //     ],
        //     [
        //         'keys' => ['diagnostico', 'analisis', 'evaluacion'],
        //         'type' => 'text',
        //         'response' => "Nuestro *Diagnóstico Operativo* revela tus riesgos fiscales actuales. Es el primer paso hacia el Rediseño. Escribe *'Si'* para coordinarlo.",
        //     ],

        //     // --- GRUPO 7: SECTORES ---
        //     [
        //         'keys' => ['petrolero', 'energia', 'gas', 'petroleo'],
        //         'type' => 'text',
        //         'response' => 'Tenemos amplia experiencia en el sector *Petrolero*. Sabemos manejar la complejidad de tus volúmenes de operación y requisitos fiscales específicos. 🛢️',
        //     ],
        //     [
        //         'keys' => ['construccion', 'obra', 'constructor'],
        //         'type' => 'text',
        //         'response' => 'El sector *Construcción* requiere controles de obra precisos. Te ayudamos a integrar tus presupuestos con tu contabilidad para evitar desvíos. 🏗️',
        //     ],
        //     [
        //         'keys' => ['administrativo', 'servicios', 'despacho'],
        //         'type' => 'text',
        //         'response' => 'Optimizamos empresas de *Servicios* para que la facturación y cobranza sean automáticas. Recupera tu tiempo y enfócate en tus clientes. ⏱️',
        //     ],

        //     // --- GRUPO 8: INFO EMPRESA ---
        //     [
        //         'keys' => ['quien eres', 'que hacen', 'nosotros', 'empresa'],
        //         'type' => 'text',
        //         'response' => 'Somos *Tecnología Empresarial*. No somos simples distribuidores; somos consultores con 30 años de experiencia liderados por la L.C.P. Verónica De León. Organizamos tu negocio.',
        //     ],
        //     [
        //         'keys' => ['veronica', 'dueña', 'fundadora', 'lcp'],
        //         'type' => 'text',
        //         'response' => 'La *L.C.P. Verónica De León* es nuestra socia fundadora, especialista fiscal y creadora de metodologías de cálculo automático. Estás en manos expertas.',
        //     ],
        //     [
        //         'keys' => ['telefono', 'llamar', 'celular', 'numero'],
        //         'type' => 'text',
        //         'response' => '📞 Puedes llamarnos al número 99. Nuestro horario es de 9:00 AM a 6:00 PM. ¿Prefieres que te llamemos nosotros?',
        //     ],

        //     [
        //         'keys' => ['foto', 'imagen', 'producto'],
        //         'type' => 'image',
        //         'url' => 'https://botwp.tecnologiaempresarial.mx/images/XPLUS.png',
        //         'caption' => '📸 Nuestro producto destacado',
        //     ],
        //     [
        //         'keys' => ['video', 'demostracion', 'demo'],
        //         'type' => 'video',
        //         'url' => 'https://botwp.tecnologiaempresarial.mx/videos/XPLUS.mp4',
        //         'caption' => '🎥 Mira cómo funciona nuestro servicio',
        //     ],
        //     [
        //         'keys' => ['catalogo', 'pdf', 'documento'],
        //         'type' => 'document',
        //         'url' => 'https://botwp.tecnologiaempresarial.mx/docs/XPLUS.pdf',
        //         'filename' => 'Catalogo2026.pdf',
        //     ],
        //     // AGREGAR AQUÍ EL RESTO DE LOS 30 MENSAJES DEL CATÁLOGO ARRIBA...
        // ];
$catalog = [
            // SALUDOS Y BIENVENIDA
            [
                'keys' => ['hola', 'inicio', 'buenos', 'buenas', 'menu', 'empezar'],
                'type' => 'greeting',
                'response' => '',
                'context' => 'START',
            ],
            
            // INFORMACIÓN GENERAL
            [
                'keys' => ['quienes son', 'que hacen', 'sobre ustedes', 'conocer'],
                'type' => 'text',
                'response' => "Somos *Tecnología Empresarial*, consultores especializados con 30 años de experiencia, liderados por la L.C.P. Verónica De León.\n\n🎯 *Nuestra misión:* Blindar tu empresa y garantizar tu cumplimiento fiscal mediante:\n\n🚀 Tecnología de vanguardia\n📊 Automatización de procesos\n🎓 Capacitación especializada\n\n¿Te gustaría conocer nuestros servicios específicos?",
                'context' => 'INFO',
            ],
            
            // CONTPAQI
            [
                'keys' => ['contpaqi', 'sistema', 'programa', 'software administrativo'],
                'type' => 'text',
                'response' => "💼 Somos *Socios Máster CONTPAQi®* - Nivel Oro.\n\nNo solo vendemos licencias, te acompañamos en la transformación digital completa de tu empresa.\n\n✅ Implementación personalizada\n✅ Migración de datos\n✅ Capacitación del equipo\n✅ Soporte técnico especializado\n\n¿Qué módulo te interesa? (Contabilidad, Nóminas, Comercial, etc.)",
                'context' => 'CONTPAQI',
            ],
            
            // NUBE Y ESCRITORIOS VIRTUALES
            [
                'keys' => ['nube', 'escritorio', 'virtual', 'servidor', 'hosting', 'cloud'],
                'type' => 'text',
                'response' => "☁️ *Escritorios Virtuales en la Nube*\n\n¡Lleva tu oficina a cualquier lugar! Olvídate de:\n\n❌ Servidores físicos costosos\n❌ Fallas de luz que detienen tu operación\n❌ Mantenimientos complejos\n❌ Pérdida de información\n\n✅ Acceso 24/7 desde cualquier dispositivo\n✅ Respaldos automáticos diarios\n✅ Máxima seguridad\n✅ Soporte técnico incluido\n\n¿Te gustaría conocer nuestros planes?",
                'context' => 'NUBE',
            ],
            
            // REDISEÑO Y BLINDAJE
            [
                'keys' => ['rediseño', 'rediseno', 'blindaje', 'fiscal', 'materialidad', 'automatizacion'],
                'type' => 'text',
                'response' => "🛡️ *Rediseño Empresarial 360°*\n\nNo solo implementamos software, transformamos tu empresa para que esté blindada ante el SAT.\n\n🎯 Garantizamos:\n• *Materialidad* de operaciones\n• *Trazabilidad* completa\n• *Razón de negocio* justificada\n\nTransformamos tu administración en un sistema sólido, automatizado y cumplidor.\n\n¿Te gustaría un diagnóstico sin costo?",
                'context' => 'REDISEÑO',
            ],
            
            // CAPACITACIÓN
            [
                'keys' => ['capacitacion', 'curso', 'taller', 'entrenamiento', 'aprender'],
                'type' => 'text',
                'response' => "🎓 *Capacitación Empresarial Especializada*\n\nEl software no comete errores, las personas sí. Por eso capacitamos a tu equipo para alcanzar su máximo nivel de eficiencia.\n\n📚 Cursos disponibles:\n• CONTPAQi (todos los módulos)\n• Excel Empresarial\n• Fiscales y tributarios\n• Administración\n\n🏆 Certificados con validez STPS\n\n¿Qué curso necesita tu equipo?",
                'context' => 'CAPACITACION',
            ],
            
            // SOPORTE TÉCNICO
            [
                'keys' => ['soporte', 'ayuda', 'tecnico', 'falla', 'problema', 'error'],
                'type' => 'text',
                'response' => "🛠️ *Soporte Técnico Especializado*\n\nEntendemos que tu operación no puede detenerse.\n\n¿Qué necesitas?\n1️⃣ Reportar nueva falla\n2️⃣ Consultar ticket existente\n3️⃣ Preguntas frecuentes\n\nEscribe el número o describe tu problema directamente.",
                'context' => 'SOPORTE',
            ],
            
            // PRECIOS Y COTIZACIONES
            [
                'keys' => ['precio', 'costo', 'cuanto', 'cotizacion', 'cotización'],
                'type' => 'text',
                'response' => "💰 Cada empresa es única y merece una solución personalizada.\n\nPara brindarte un precio justo necesitamos conocer:\n• Tamaño de tu empresa\n• Servicios específicos que requieres\n• Número de usuarios\n\n¿Te gustaría que un asesor comercial prepare tu cotización personalizada? Escribe *'Sí'* o *'Cotización'*",
                'context' => 'QUOTE',
            ],
            
            // MÓDULOS ESPECÍFICOS
            [
                'keys' => ['contabilidad', 'contable', 'fiscal'],
                'type' => 'text',
                'response' => "📊 *CONTPAQi Contabilidad*\n\nEl sistema líder en México para control fiscal y financiero.\n\n✅ Contabilidad electrónica\n✅ Pólizas automáticas\n✅ Estados financieros en tiempo real\n✅ Cumplimiento SAT garantizado\n✅ Integración bancaria\n\n¿Necesitas implementación, actualización o capacitación?",
                'context' => 'CONTPAQI',
            ],
            
            [
                'keys' => ['nomina', 'nominas', 'empleados', 'rrhh'],
                'type' => 'text',
                'response' => "👥 *CONTPAQi Nóminas*\n\nGestiona tu capital humano sin errores.\n\n✅ Cálculo automático de nómina\n✅ Timbrado CFDI\n✅ IMSS e Infonavit\n✅ Finiquitos y liquidaciones\n✅ Reportes ejecutivos\n\n¿Cuántos empleados tiene tu empresa?",
                'context' => 'CONTPAQI',
            ],
            
            [
                'keys' => ['comercial', 'facturacion', 'inventario', 'ventas'],
                'type' => 'text',
                'response' => "🏪 *CONTPAQi Comercial*\n\nControla tu operación comercial completa.\n\n✅ Facturación electrónica 4.0\n✅ Control de inventarios\n✅ Cuentas por cobrar/pagar\n✅ Punto de venta\n✅ Múltiples almacenes\n\n¿Manejas inventarios o solo servicios?",
                'context' => 'CONTPAQI',
            ],
            
            [
                'keys' => ['bancos', 'tesoreria', 'conciliacion'],
                'type' => 'text',
                'response' => "🏦 *CONTPAQi Bancos*\n\nConecta tus bancos con tu contabilidad automáticamente.\n\n✅ Conciliación bancaria automática\n✅ Flujo de efectivo en tiempo real\n✅ Pagos electrónicos\n✅ Proyecciones financieras\n\nElimina la talacha manual y ten control total. 💸",
                'context' => 'CONTPAQI',
            ],
            
            // SECTORES
            [
                'keys' => ['petrolero', 'energia', 'gas', 'petroleo'],
                'type' => 'text',
                'response' => "🛢️ Tenemos amplia experiencia en el sector *Petrolero y Energético*.\n\nSabemos manejar:\n• Altos volúmenes de operación\n• Requisitos fiscales específicos\n• Normativas del sector\n• Trazabilidad completa\n\n¿Qué tipo de operación realizas?",
                'context' => 'REDISEÑO',
            ],
            
            [
                'keys' => ['construccion', 'obra', 'constructor'],
                'type' => 'text',
                'response' => "🏗️ Especializados en el sector *Construcción*.\n\n✅ Control de obras y proyectos\n✅ Presupuestos vs real\n✅ Subcontratistas\n✅ Materiales y mano de obra\n✅ Deducción correcta de gastos\n\nIntegramos todo con tu contabilidad para evitar desvíos.",
                'context' => 'REDISEÑO',
            ],
            
            // CONTACTO Y CITAS
            [
                'keys' => ['cita', 'reunion', 'agendar', 'visita', 'demo'],
                'type' => 'text',
                'response' => "🗓️ *¡Perfecto! Agendemos una sesión.*\n\n¿Qué prefieres?\n1️⃣ Cita presencial en tu empresa\n2️⃣ Videollamada por Zoom\n3️⃣ Llamada telefónica\n\nEscribe el número de tu preferencia.",
                'context' => 'QUOTE',
            ],
            
            [
                'keys' => ['telefono', 'llamar', 'celular', 'contacto'],
                'type' => 'text',
                'response' => "📞 *Contáctanos:*\n\nTeléfono: [Tu número]\nHorario: Lunes a Viernes 9:00 AM - 6:00 PM\n\n¿Prefieres que te llamemos nosotros? Escribe *'Sí'* y tu nombre completo.",
                'context' => 'QUOTE',
            ],
            
            [
                'keys' => ['ubicacion', 'direccion', 'donde', 'oficina'],
                'type' => 'text',
                'response' => "📍 *Nuestra ubicación:*\n\n[Tu dirección completa]\n\nSi requieres una visita presencial o consultoría en sitio, escribe *'Cita'* para coordinar.",
                'context' => 'INFO',
            ],
            
            // URGENCIAS Y ALERTAS
            [
                'keys' => ['urgente', 'rapido', 'inmediato', 'ya'],
                'type' => 'text',
                'response' => "⚡ Entiendo la urgencia.\n\n¿Es un problema técnico o comercial?\n\n• Si es *técnico* → Escribe 'Soporte'\n• Si es *comercial* → Escribe 'Asesor'\n\nUn ejecutivo te atenderá de inmediato.",
                'context' => 'SUPPORT',
            ],
            
            [
                'keys' => ['sat', 'auditoria', 'revision', 'fiscalizacion'],
                'type' => 'text',
                'response' => "🚨 *Alerta SAT*\n\nSi estás bajo revisión fiscal:\n\n1. No esperes - actúa ahora\n2. Necesitas evidencia digital\n3. Materialidad de operaciones\n\nNuestro servicio de blindaje preventivo puede ayudarte.\n\nEscribe *'Urgente'* para atención inmediata.",
                'context' => 'REDISEÑO',
            ],
            
            // DESPEDIDAS
            [
                'keys' => ['gracias', 'adios', 'bye', 'hasta luego', 'nos vemos'],
                'type' => 'text',
                'response' => "¡Gracias a ti! 🙏\n\nEstamos aquí para blindar tu operación 24/7.\n\nSi necesitas algo más, solo escríbeme. ¡Hasta pronto! 🚀",
                'context' => 'START',
            ],
            
            // MULTIMEDIA
            [
                'keys' => ['foto', 'imagen', 'ver producto'],
                'type' => 'image',
                'url' => 'https://botwp.tecnologiaempresarial.mx/images/XPLUS.png',
                'caption' => '📸 Nuestras soluciones empresariales',
                'context' => 'INFO',
            ],
            
            [
                'keys' => ['video', 'demostracion', 'demo visual'],
                'type' => 'video',
                'url' => 'https://botwp.tecnologiaempresarial.mx/videos/XPLUS.mp4',
                'caption' => '🎥 Mira cómo transformamos empresas',
                'context' => 'INFO',
            ],
            
            [
                'keys' => ['catalogo', 'pdf', 'documento', 'brochure'],
                'type' => 'document',
                'url' => 'https://botwp.tecnologiaempresarial.mx/docs/XPLUS.pdf',
                'filename' => 'Catalogo_Tecnologia_Empresarial_2026.pdf',
                'context' => 'INFO',
            ],
        ];
        // Recorremos el catálogo buscando coincidencias
        foreach ($catalog as $item) {
            foreach ($item['keys'] as $keyword) {
                if (str_contains($input, $keyword)) {
                    return $item;
                }
            }
        }

        // Respuesta por defecto (Default Fallback)
        // return [
        //     'type' => 'text',
        //     'response' => "👋 No estoy seguro de cómo responder a eso, pero quiero ayudarte.\n\nPrueba escribiendo:\n- *'Rediseño'* para blindaje fiscal.\n- *'Nube'* para escritorios virtuales.\n- *'Asesor'* para hablar con un humano.",
        // ];
        return ['type' => 'fallback'];
    }

    private function sendDocument(string $to, string $docUrl, ?string $filename = null): void
    {

        $chat = Chat::where('user_number', $to)->first();

        if ($chat) {
            Message::create([
                'chat_id' => $chat->id,
                'message' => $filename ?? '[Documento enviado]',
                'type' => 'bot',
                'handled' => true,
            ]);
        }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'document',
                'document' => [
                    'link' => $docUrl,
                    'filename' => $filename,
                ],
            ]);
    }

    private function sendVideo(string $to, string $videoUrl, ?string $caption = null): void
    {
        $chat = Chat::where('user_number', $to)->first();

        if ($chat) {
            Message::create([
                'chat_id' => $chat->id,
                'message' => $caption ?? '[Video enviado]',
                'type' => 'bot',
                'handled' => true,
            ]);
        }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'video',
                'video' => [
                    'link' => $videoUrl,
                    'caption' => $caption,
                ],
            ]);
    }

    private function sendImage(string $to, string $imageUrl, ?string $caption = null): void
    {
        $chat = Chat::where('user_number', $to)->first();

        if ($chat) {
            Message::create([
                'chat_id' => $chat->id,
                'message' => $caption ?? '[Imagen enviada]',
                'type' => 'bot',
                'handled' => true,
            ]);
        }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url').'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'image',
                'image' => [
                    'link' => $imageUrl,
                    'caption' => $caption,
                ],
            ]);
    }
}
