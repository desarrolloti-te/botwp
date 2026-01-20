<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
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
        $entry = $request->input('entry.0.changes.0.value');
        \Log::info('📩 Mensaje entrante WhatsApp', $request->all());

        if (!$entry || empty($entry['messages'])) {
            return response()->json(['status' => 'ok']);
        }

        $message = $entry['messages'][0];
        $from = $message['from'];
        $text = strtolower($message['text']['body'] ?? '');

        $chat = Chat::firstOrCreate(
            ['user_number' => $from],
            ['status' => 'open', 'context' => 'INITIAL', 'conversation_history' => json_encode([])]
        );

        // Guardar historial de conversación
        $this->updateConversationHistory($chat, $text, 'user');

        // Detectar si es el primer mensaje del usuario
        $isFirstMessage = Message::where('chat_id', $chat->id)->where('type', 'user')->count() === 0;

        // Guardar mensaje del usuario
        $isHumanRequest = in_array($text, ['asesor', 'humano', 'agente', 'ejecutivo', 'persona']);
        Message::create([
            'chat_id' => $chat->id,
            'message' => $text,
            'type' => 'user',
            'requires_human' => $isHumanRequest,
        ]);

        // Si solicita hablar con humano
        if ($isHumanRequest) {
            $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
            $this->sendMessage($from, "👨‍💼 Perfecto, estoy conectando tu conversación con un ejecutivo especializado. En breve te atenderá personalmente.\n\n⏱️ Tiempo promedio de respuesta: 2-5 minutos.");
            $this->notifyAgent("🔔 Nuevo cliente requiere atención humana\n📱 Número: $from\n💬 Último mensaje: $text");
            return response()->json(['status' => 'ok']);
        }

        // Comandos para agentes
        if ($this->handleAgentCommands($from, $text)) {
            return response()->json(['status' => 'ok']);
        }

        // Si está esperando respuesta de agente, no procesar automáticamente
        if ($chat->status === 'waiting_agent') {
            return response()->json(['status' => 'ok']);
        }

        // Procesar el mensaje con inteligencia contextual
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

        // 3. PRIORIDAD: Si estamos en un contexto específico, procesar PRIMERO dentro de ese contexto
        $currentContext = $chat->context ?? 'INITIAL';
        
        if ($currentContext !== 'INITIAL' && $currentContext !== 'START') {
            $contextualResponse = $this->handleContextualMessage($chat, $from, $text, $currentContext);
            if ($contextualResponse !== null) {
                return $contextualResponse;
            }
        }

        // 4. Buscar en el catálogo de respuestas rápidas SOLO si no hay contexto activo
        // IMPORTANTE: Pasar el contexto actual para evitar conflictos
        $catalogResponse = $this->findResponseInCatalog($text, $currentContext);
        
        if ($catalogResponse !== null && $catalogResponse['type'] !== 'fallback') {
            // Actualizar contexto basado en la respuesta
            $this->updateContextFromResponse($chat, $catalogResponse);
            
            return $this->sendCatalogResponse($from, $catalogResponse, $chat);
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
        $lastQuestion = $chat->last_bot_question ?? '';
        
        // Si preguntamos sobre qué módulo le interesa
        if ($lastQuestion === 'contpaqi_modulo') {
            $modulo = '';
            if (str_contains($text, 'contab')) $modulo = 'Contabilidad';
            elseif (str_contains($text, 'nomin')) $modulo = 'Nóminas';
            elseif (str_contains($text, 'comerc')) $modulo = 'Comercial';
            elseif (str_contains($text, 'banco')) $modulo = 'Bancos';
            elseif (str_contains($text, 'todo') || str_contains($text, 'todos')) $modulo = 'Suite Completa';
            
            if ($modulo) {
                $metadata = json_decode($chat->metadata ?? '{}', true);
                $metadata['modulo_interes'] = $modulo;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $responses = [
                    'Contabilidad' => "📊 *CONTPAQi Contabilidad* - Excelente elección.\n\nPerfecto para:\n✅ Control fiscal total\n✅ Contabilidad electrónica SAT\n✅ Estados financieros automáticos\n✅ Pólizas automatizadas\n\n¿Necesitas implementación nueva, actualización o migración desde otro sistema?",
                    'Nóminas' => "👥 *CONTPAQi Nóminas* - La mejor decisión.\n\nIdeal para:\n✅ Cálculo automático de nómina\n✅ Timbrado CFDI 4.0\n✅ IMSS, Infonavit, ISR\n✅ Finiquitos y liquidaciones\n\n¿Cuántos empleados tienes actualmente?",
                    'Comercial' => "🏪 *CONTPAQi Comercial* - Perfecta elección.\n\nTe permite:\n✅ Facturación electrónica 4.0\n✅ Control total de inventarios\n✅ Cuentas por cobrar/pagar\n✅ Múltiples almacenes\n\n¿Manejas inventarios, servicios o ambos?",
                    'Bancos' => "🏦 *CONTPAQi Bancos* - Excelente.\n\nBeneficios:\n✅ Conciliación bancaria automática\n✅ Flujo de efectivo en tiempo real\n✅ Control de cheques\n✅ Pagos electrónicos\n\n¿Con cuántos bancos trabajas?",
                    'Suite Completa' => "🎯 *Suite Completa CONTPAQi* - La solución integral.\n\nIncluye:\n✅ Contabilidad\n✅ Nóminas\n✅ Comercial\n✅ Bancos\n\nTodo integrado automáticamente. ¿Cuántos usuarios necesitas?"
                ];
                
                $this->sendMessage($from, $responses[$modulo] ?? "Entiendo. ¿Qué necesitas saber específicamente?");
                $chat->update(['last_bot_question' => 'contpaqi_detalle_' . strtolower($modulo)]);
                return response()->json(['status' => 'ok']);
            }
        }
        
        // Seguimiento específico de Nóminas - preguntamos empleados
        if ($lastQuestion === 'contpaqi_detalle_nóminas') {
            preg_match('/\d+/', $text, $matches);
            $empleados = $matches[0] ?? null;
            
            if ($empleados) {
                $metadata = json_decode($chat->metadata ?? '{}', true);
                $metadata['num_empleados'] = $empleados;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $this->sendMessage($from, "Perfecto, con $empleados empleados te recomiendo:\n\n" . 
                    ($empleados <= 3 ? "💼 *Versión Start* - Ideal para tu equipo pequeño" : 
                    ($empleados <= 20 ? "🏢 *Versión Empresarial* - Perfecta para tu tamaño" : 
                    "🏭 *Versión Empresarial Plus* - Para operaciones grandes")) . 
                    "\n\n¿Te gustaría conocer el precio o agendar una demostración?");
                $chat->update(['last_bot_question' => 'contpaqi_siguiente_paso']);
                return response()->json(['status' => 'ok']);
            } else {
                $this->sendMessage($from, "Para recomendarte la versión correcta, ¿cuántos empleados tienes? (Solo el número)");
                return response()->json(['status' => 'ok']);
            }
        }
        
        // Seguimiento de Comercial - inventarios o servicios
        if ($lastQuestion === 'contpaqi_detalle_comercial') {
            if (str_contains($text, 'inventario') || str_contains($text, 'producto')) {
                $this->sendMessage($from, "Perfecto para control de inventarios. 📦\n\n¿Cuántos productos/SKUs manejas aproximadamente?\n\na) Menos de 100\nb) 100-500\nc) Más de 500");
                $chat->update(['last_bot_question' => 'contpaqi_inventario_size']);
            } elseif (str_contains($text, 'servicio')) {
                $this->sendMessage($from, "Ideal para empresas de servicios. 💼\n\nLa versión para servicios es más simple y económica.\n\n¿Te gustaría ver precios o una demo?");
                $chat->update(['last_bot_question' => 'contpaqi_siguiente_paso']);
            } elseif (str_contains($text, 'ambos')) {
                $this->sendMessage($from, "Entiendo, negocio híbrido. Necesitas la versión completa.\n\n¿Aproximadamente cuántos productos manejas en inventario?");
                $chat->update(['last_bot_question' => 'contpaqi_inventario_size']);
            }
            return response()->json(['status' => 'ok']);
        }
        
        // Manejo de siguiente paso
        if ($lastQuestion === 'contpaqi_siguiente_paso') {
            if (str_contains($text, 'precio') || str_contains($text, '1')) {
                $metadata = json_decode($chat->metadata ?? '{}', true);
                $this->sendMessage($from, "💰 Para darte un precio exacto de *CONTPAQi {$metadata['modulo_interes']}* necesito saber:\n\n¿Cuántos usuarios usarán el sistema simultáneamente?");
                $chat->update(['last_bot_question' => 'contpaqi_usuarios']);
                return response()->json(['status' => 'ok']);
            }
            
            if (str_contains($text, 'demo') || str_contains($text, '2') || str_contains($text, 'demostr')) {
                $this->sendMessage($from, "🎯 ¡Excelente! La demostración te permitirá ver el sistema en acción.\n\nPara preparar la demo personalizada, necesito tu nombre y empresa. ¿Me los compartes?");
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'nombre']);
                return response()->json(['status' => 'ok']);
            }
        }
        
        // Respuestas sobre precios
        if (str_contains($text, 'precio') || str_contains($text, 'costo') || str_contains($text, 'cuanto')) {
            $this->sendMessage($from, "💰 Los precios de CONTPAQi varían según:\n• Módulo específico\n• Número de usuarios\n• Modalidad (compra o renta mensual)\n\n¿Qué módulo te interesa?\n\n📊 Contabilidad\n👥 Nóminas\n🏪 Comercial\n🏦 Bancos\n🎯 Suite completa");
            $chat->update(['last_bot_question' => 'contpaqi_modulo']);
            return response()->json(['status' => 'ok']);
        }
        
        // Preguntas sobre módulos o funciones
        if (str_contains($text, 'modulo') || str_contains($text, 'funcion') || str_contains($text, 'caracteristica') || str_contains($text, 'hace')) {
            $this->sendMessage($from, "📊 *Módulos CONTPAQi disponibles:*\n\n1️⃣ *Contabilidad* - Control fiscal y financiero\n2️⃣ *Nóminas* - Gestión de capital humano\n3️⃣ *Comercial* - Facturación e inventarios\n4️⃣ *Bancos* - Conciliación bancaria\n5️⃣ *Producción* - Control de manufactura\n\n¿Sobre cuál te gustaría saber más? (Escribe el número o nombre)");
            $chat->update(['last_bot_question' => 'contpaqi_modulo']);
            return response()->json(['status' => 'ok']);
        }
        
        // Respuestas positivas generales
        if ($this->isPositiveResponse($text) && empty($lastQuestion)) {
            $this->sendMessage($from, "¡Perfecto! 🎯\n\n¿Qué módulo de CONTPAQi te interesa?\n\n📊 Contabilidad\n👥 Nóminas\n🏪 Comercial  \n🏦 Bancos\n🎯 Suite completa\n\nEscribe el nombre del módulo.");
            $chat->update(['last_bot_question' => 'contpaqi_modulo']);
            return response()->json(['status' => 'ok']);
        }
        
        return null;
    }

    private function handleNubeContext($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        
        // Si preguntamos "¿Te gustaría conocer nuestros planes?"
        if ($lastQuestion === 'nube_planes' && $this->isPositiveResponse($text)) {
            $this->sendMessage($from, "☁️ *Planes de Escritorios Virtuales:*\n\n💼 *Plan Básico* - 1 usuario\n• Perfecto para emprendedores\n• 50GB almacenamiento\n• Respaldo diario\n\n🏢 *Plan Empresarial* - 3-10 usuarios\n• Para empresas en crecimiento\n• 200GB almacenamiento\n• Servidor dedicado\n\n🏭 *Plan Corporativo* - +10 usuarios\n• Solución empresarial completa\n• Almacenamiento ilimitado\n• Soporte prioritario 24/7\n\nTodos incluyen actualización y mantenimiento. ¿Cuántos usuarios necesitas?");
            $chat->update(['last_bot_question' => 'nube_usuarios']);
            return response()->json(['status' => 'ok']);
        }
        
        // Si respondió cuántos usuarios necesita
        if ($lastQuestion === 'nube_usuarios') {
            // Extraer número de la respuesta
            preg_match('/\d+/', $text, $matches);
            $usuarios = $matches[0] ?? null;
            
            if ($usuarios) {
                $plan = $usuarios == 1 ? 'Básico' : ($usuarios <= 10 ? 'Empresarial' : 'Corporativo');
                $metadata = json_decode($chat->metadata ?? '{}', true);
                $metadata['plan_interes'] = $plan;
                $metadata['usuarios'] = $usuarios;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $this->sendMessage($from, "Perfecto, el *Plan $plan* es ideal para ti con $usuarios usuario(s). 👍\n\n¿Te gustaría:\n\n1️⃣ Ver precios específicos\n2️⃣ Agendar una demostración\n3️⃣ Recibir cotización formal\n\nEscribe el número de tu preferencia.");
                $chat->update(['last_bot_question' => 'nube_siguiente_paso']);
                return response()->json(['status' => 'ok']);
            } else {
                $this->sendMessage($from, "Para darte la mejor recomendación, ¿cuántos usuarios trabajarán con el sistema? (Solo escribe el número)");
                return response()->json(['status' => 'ok']);
            }
        }
        
        // Siguiente paso después de elegir plan
        if ($lastQuestion === 'nube_siguiente_paso') {
            if (str_contains($text, '1') || str_contains($text, 'precio')) {
                $metadata = json_decode($chat->metadata ?? '{}', true);
                $usuarios = $metadata['usuarios'] ?? 1;
                $precioBase = $usuarios * 800; // Ejemplo de cálculo
                
                $this->sendMessage($from, "💰 *Inversión mensual aproximada:*\n\nPara $usuarios usuario(s): *$" . number_format($precioBase, 2) . " MXN/mes*\n\nIncluye:\n✅ Todo el software necesario\n✅ Respaldos automáticos\n✅ Soporte técnico\n✅ Actualizaciones\n\n_Precio puede variar según configuración específica_\n\n¿Te gustaría que un asesor te prepare una cotización formal? Escribe *'Sí'* o *'Cotización'*");
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => null]);
                return response()->json(['status' => 'ok']);
            }
            
            if (str_contains($text, '2') || str_contains($text, 'demo')) {
                $this->sendMessage($from, "🎯 ¡Excelente! Una demostración te permitirá ver el sistema en acción.\n\n¿Qué día y horario te viene mejor?\n\nEjemplo: 'Mañana a las 10am' o 'Viernes 3pm'");
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'agendar_demo']);
                return response()->json(['status' => 'ok']);
            }
            
            if (str_contains($text, '3') || str_contains($text, 'cotiz')) {
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => null]);
                return $this->handleQuoteFlow($chat, $from, 'iniciar');
            }
        }
        
        // Si preguntamos por demostración
        if ($lastQuestion === 'nube_demo' && $this->isPositiveResponse($text)) {
            $this->sendMessage($from, "🎯 Perfecto, prepararemos una demostración personalizada.\n\n¿Qué día y horario te viene mejor? (Ejemplo: 'Mañana 10am' o 'Viernes 3pm')");
            $chat->update(['last_bot_question' => 'agendar_demo']);
            return response()->json(['status' => 'ok']);
        }
        
        // Preguntas sobre precios
        if (str_contains($text, 'precio') || str_contains($text, 'costo') || str_contains($text, 'cuanto')) {
            $this->sendMessage($from, "💰 El precio depende del número de usuarios que necesites.\n\n¿Cuántas personas trabajarán con el sistema?");
            $chat->update(['last_bot_question' => 'nube_usuarios']);
            return response()->json(['status' => 'ok']);
        }
        
        // Preguntas sobre ventajas
        if (str_contains($text, 'ventaja') || str_contains($text, 'beneficio') || str_contains($text, 'porque')) {
            $this->sendMessage($from, "🌟 *Beneficios de la Nube:*\n\n✅ Acceso desde cualquier lugar (casa, oficina, viaje)\n✅ Sin inversión en servidores físicos ($50,000+ de ahorro)\n✅ Respaldos automáticos diarios (nunca pierdas información)\n✅ Escalable según tu crecimiento (agrega usuarios fácilmente)\n✅ Eliminación de costos de mantenimiento\n✅ Máxima seguridad de información\n\n¿Te gustaría conocer nuestros planes?");
            $chat->update(['last_bot_question' => 'nube_planes']);
            return response()->json(['status' => 'ok']);
        }
        
        // Respuestas positivas generales en contexto NUBE
        if ($this->isPositiveResponse($text) && empty($lastQuestion)) {
            $this->sendMessage($from, "☁️ *Planes de Escritorios Virtuales:*\n\n💼 *Plan Básico* - 1 usuario\n🏢 *Plan Empresarial* - 3-10 usuarios\n🏭 *Plan Corporativo* - +10 usuarios\n\nTodos con respaldo automático y soporte 24/7.\n\n¿Cuántos usuarios necesitas?");
            $chat->update(['last_bot_question' => 'nube_usuarios']);
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
        // Detección específica de CONTPAQi
        if (str_contains($text, 'contpaqi') || str_contains($text, 'conpaq') || 
            (str_contains($text, 'sistema') && str_contains($text, 'administrativo'))) {
            return 'CONTPAQI';
        }
        
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
            'CONTPAQI' => "Entiendo que te interesa *CONTPAQi*. 🎯\n\nSomos Socios Máster Nivel Oro con 30 años implementando soluciones administrativas.\n\n¿Qué módulo te interesa?\n\n1️⃣ Contabilidad\n2️⃣ Nóminas\n3️⃣ Comercial\n4️⃣ Bancos\n5️⃣ Suite completa\n\nEscribe el número o nombre del módulo.",
            'NUBE' => "¡Excelente elección! ☁️\n\nNuestros *Escritorios Virtuales* transformarán tu forma de trabajar. ¿Quieres conocer los beneficios, planes o ver una demo?",
            'REDISEÑO' => "Perfecto, hablemos de *Rediseño Empresarial*. 🛡️\n\nNo solo implementamos software, reestructuramos tu operación completa. ¿Te interesa saber cómo funciona el proceso?",
            'CAPACITACION' => "¡Invertir en tu equipo es la mejor decisión! 🎓\n\n¿Buscas cursos de CONTPAQi, Excel, Fiscales o certificaciones STPS?",
            'SOPORTE' => "Entiendo que necesitas *soporte técnico*. 🛠️\n\nPara ayudarte mejor, ¿tu sistema está en servidor físico o en la nube?",
        ];
        
        $message = $transitions[$newContext] ?? "Entiendo tu interés. ¿Cómo puedo ayudarte específicamente?";
        
        // Establecer next_question para CONTPAQi
        if ($newContext === 'CONTPAQI') {
            $chat->update(['last_bot_question' => 'contpaqi_modulo']);
        }
        
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
    
    private function isPositiveResponse($text)
    {
        $positives = [
            'si', 'sí', 'see', 'si por favor', 'claro', 'ok', 'okay', 
            'vale', 'dale', 'perfecto', 'excelente', 'por supuesto',
            'me interesa', 'quiero', 'necesito', 'adelante', 'confirmo',
            '👍', '✅', '1', 'si por supuesto'
        ];
        
        foreach ($positives as $positive) {
            if (str_contains($text, $positive) || $text === $positive) {
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
        // Guardar la pregunta siguiente si existe
        if (isset($catalogResponse['next_question'])) {
            $chat->update(['last_bot_question' => $catalogResponse['next_question']]);
        }
        
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

    private function findResponseInCatalog(string $input, $currentContext = null): ?array
    {
        $catalog = [
            // SALUDOS Y BIENVENIDA
            [
                'keys' => ['hola', 'inicio', 'buenos', 'buenas', 'menu', 'empezar'],
                'type' => 'greeting',
                'response' => '',
                'context' => 'START',
                'skip_in_contexts' => [], // Nunca skipear saludos
            ],
            
            // INFORMACIÓN GENERAL
            [
                'keys' => ['quienes son', 'que hacen', 'sobre ustedes', 'empresa'],
                'type' => 'text',
                'response' => "Somos *Tecnología Empresarial*, consultores especializados con 30 años de experiencia, liderados por la L.C.P. Verónica De León.\n\n🎯 *Nuestra misión:* Blindar tu empresa y garantizar tu cumplimiento fiscal mediante:\n\n🚀 Tecnología de vanguardia\n📊 Automatización de procesos\n🎓 Capacitación especializada\n\n¿Te gustaría conocer nuestros servicios específicos?",
                'context' => 'INFO',
                'skip_in_contexts' => [],
            ],
            
            // NUBE Y ESCRITORIOS VIRTUALES
            [
                'keys' => ['nube', 'escritorio', 'virtual', 'servidor', 'hosting', 'cloud'],
                'type' => 'text',
                'response' => "☁️ *Escritorios Virtuales en la Nube*\n\n¡Lleva tu oficina a cualquier lugar! Olvídate de:\n\n❌ Servidores físicos costosos\n❌ Fallas de luz que detienen tu operación\n❌ Mantenimientos complejos\n❌ Pérdida de información\n\n✅ Acceso 24/7 desde cualquier dispositivo\n✅ Respaldos automáticos diarios\n✅ Máxima seguridad\n✅ Soporte técnico incluido\n\n¿Te gustaría conocer nuestros planes?",
                'context' => 'NUBE',
                'next_question' => 'nube_planes',
                'skip_in_contexts' => ['NUBE'], // No repetir si ya estamos en NUBE
            ],
            
            // REDISEÑO Y BLINDAJE
            [
                'keys' => ['rediseño', 'rediseno', 'blindaje', 'fiscal', 'materialidad', 'automatizacion'],
                'type' => 'text',
                'response' => "🛡️ *Rediseño Empresarial 360°*\n\nNo solo implementamos software, transformamos tu empresa para que esté blindada ante el SAT.\n\n🎯 Garantizamos:\n• *Materialidad* de operaciones\n• *Trazabilidad* completa\n• *Razón de negocio* justificada\n\nTransformamos tu administración en un sistema sólido, automatizado y cumplidor.\n\n¿Te gustaría un diagnóstico sin costo?",
                'context' => 'REDISEÑO',
                'skip_in_contexts' => ['REDISEÑO'],
            ],
            
            // CAPACITACIÓN
            [
                'keys' => ['capacitacion', 'curso', 'taller', 'entrenamiento', 'aprender'],
                'type' => 'text',
                'response' => "🎓 *Capacitación Empresarial Especializada*\n\nEl software no comete errores, las personas sí. Por eso capacitamos a tu equipo para alcanzar su máximo nivel de eficiencia.\n\n📚 Cursos disponibles:\n• CONTPAQi (todos los módulos)\n• Excel Empresarial\n• Fiscales y tributarios\n• Administración\n\n🏆 Certificados con validez STPS\n\n¿Qué curso necesita tu equipo?",
                'context' => 'CAPACITACION',
                'skip_in_contexts' => ['CAPACITACION'],
            ],
            
            // SOPORTE TÉCNICO
            [
                'keys' => ['soporte', 'ayuda', 'tecnico', 'falla', 'problema', 'error'],
                'type' => 'text',
                'response' => "🛠️ *Soporte Técnico Especializado*\n\nEntendemos que tu operación no puede detenerse.\n\n¿Qué necesitas?\n1️⃣ Reportar nueva falla\n2️⃣ Consultar ticket existente\n3️⃣ Preguntas frecuentes\n\nEscribe el número o describe tu problema directamente.",
                'context' => 'SOPORTE',
                'skip_in_contexts' => ['SOPORTE'],
            ],
            
            // PRECIOS Y COTIZACIONES
            [
                'keys' => ['precio', 'costo', 'cuanto', 'cotizacion', 'cotización'],
                'type' => 'text',
                'response' => "💰 Cada empresa es única y merece una solución personalizada.\n\nPara brindarte un precio justo necesitamos conocer:\n• Tamaño de tu empresa\n• Servicios específicos que requieres\n• Número de usuarios\n\n¿Te gustaría que un asesor comercial prepare tu cotización personalizada? Escribe *'Sí'* o *'Cotización'*",
                'context' => 'QUOTE',
                'skip_in_contexts' => ['CONTPAQI', 'NUBE', 'REDISEÑO'], // No usar si ya estamos en contexto específico
            ],
            
            // MÓDULOS ESPECÍFICOS - SOLO SI NO ESTAMOS EN CONTEXTO CONTPAQI
            [
                'keys' => ['contabilidad', 'contable'],
                'type' => 'text',
                'response' => "📊 *CONTPAQi Contabilidad*\n\nEl sistema líder en México para control fiscal y financiero.\n\n✅ Contabilidad electrónica\n✅ Pólizas automáticas\n✅ Estados financieros en tiempo real\n✅ Cumplimiento SAT garantizado\n✅ Integración bancaria\n\n¿Necesitas implementación, actualización o capacitación?",
                'context' => 'CONTPAQI',
                'skip_in_contexts' => ['CONTPAQI'], // CRÍTICO: No usar si ya estamos en CONTPAQI
            ],
            
            [
                'keys' => ['nomina', 'nominas', 'empleados', 'rrhh'],
                'type' => 'text',
                'response' => "👥 *CONTPAQi Nóminas*\n\nGestiona tu capital humano sin errores.\n\n✅ Cálculo automático de nómina\n✅ Timbrado CFDI\n✅ IMSS e Infonavit\n✅ Finiquitos y liquidaciones\n✅ Reportes ejecutivos\n\n¿Cuántos empleados tiene tu empresa?",
                'context' => 'CONTPAQI',
                'skip_in_contexts' => ['CONTPAQI'],
            ],
            
            [
                'keys' => ['comercial', 'facturacion', 'inventario', 'ventas'],
                'type' => 'text',
                'response' => "🏪 *CONTPAQi Comercial*\n\nControla tu operación comercial completa.\n\n✅ Facturación electrónica 4.0\n✅ Control de inventarios\n✅ Cuentas por cobrar/pagar\n✅ Punto de venta\n✅ Múltiples almacenes\n\n¿Manejas inventarios o solo servicios?",
                'context' => 'CONTPAQI',
                'skip_in_contexts' => ['CONTPAQI'], // CRÍTICO: No usar si ya estamos en CONTPAQI
            ],
            
            [
                'keys' => ['bancos', 'tesoreria', 'conciliacion'],
                'type' => 'text',
                'response' => "🏦 *CONTPAQi Bancos*\n\nConecta tus bancos con tu contabilidad automáticamente.\n\n✅ Conciliación bancaria automática\n✅ Flujo de efectivo en tiempo real\n✅ Pagos electrónicos\n✅ Proyecciones financieras\n\nElimina la talacha manual y ten control total. 💸",
                'context' => 'CONTPAQI',
                'skip_in_contexts' => ['CONTPAQI'],
            ],
            
            // SECTORES
            [
                'keys' => ['petrolero', 'energia', 'gas', 'petroleo'],
                'type' => 'text',
                'response' => "🛢️ Tenemos amplia experiencia en el sector *Petrolero y Energético*.\n\nSabemos manejar:\n• Altos volúmenes de operación\n• Requisitos fiscales específicos\n• Normativas del sector\n• Trazabilidad completa\n\n¿Qué tipo de operación realizas?",
                'context' => 'REDISEÑO',
                'skip_in_contexts' => [],
            ],
            
            [
                'keys' => ['construccion', 'obra', 'constructor'],
                'type' => 'text',
                'response' => "🏗️ Especializados en el sector *Construcción*.\n\n✅ Control de obras y proyectos\n✅ Presupuestos vs real\n✅ Subcontratistas\n✅ Materiales y mano de obra\n✅ Deducción correcta de gastos\n\nIntegramos todo con tu contabilidad para evitar desvíos.",
                'context' => 'REDISEÑO',
                'skip_in_contexts' => [],
            ],
            
            // CONTACTO Y CITAS
            [
                'keys' => ['cita', 'reunion', 'agendar', 'visita', 'demo'],
                'type' => 'text',
                'response' => "🗓️ *¡Perfecto! Agendemos una sesión.*\n\n¿Qué prefieres?\n1️⃣ Cita presencial en tu empresa\n2️⃣ Videollamada por Zoom\n3️⃣ Llamada telefónica\n\nEscribe el número de tu preferencia.",
                'context' => 'QUOTE',
                'skip_in_contexts' => [],
            ],
            
            [
                'keys' => ['telefono', 'llamar', 'celular', 'contacto'],
                'type' => 'text',
                'response' => "📞 *Contáctanos:*\n\nTeléfono: [Tu número]\nHorario: Lunes a Viernes 9:00 AM - 6:00 PM\n\n¿Prefieres que te llamemos nosotros? Escribe *'Sí'* y tu nombre completo.",
                'context' => 'QUOTE',
                'skip_in_contexts' => [],
            ],
            
            [
                'keys' => ['ubicacion', 'direccion', 'donde', 'oficina'],
                'type' => 'text',
                'response' => "📍 *Nuestra ubicación:*\n\n[Tu dirección completa]\n\nSi requieres una visita presencial o consultoría en sitio, escribe *'Cita'* para coordinar.",
                'context' => 'INFO',
                'skip_in_contexts' => [],
            ],
            
            // URGENCIAS Y ALERTAS
            [
                'keys' => ['urgente', 'rapido', 'inmediato', 'ya'],
                'type' => 'text',
                'response' => "⚡ Entiendo la urgencia.\n\n¿Es un problema técnico o comercial?\n\n• Si es *técnico* → Escribe 'Soporte'\n• Si es *comercial* → Escribe 'Asesor'\n\nUn ejecutivo te atenderá de inmediato.",
                'context' => 'SUPPORT',
                'skip_in_contexts' => [],
            ],
            
            [
                'keys' => ['sat', 'auditoria', 'revision', 'fiscalizacion'],
                'type' => 'text',
                'response' => "🚨 *Alerta SAT*\n\nSi estás bajo revisión fiscal:\n\n1. No esperes - actúa ahora\n2. Necesitas evidencia digital\n3. Materialidad de operaciones\n\nNuestro servicio de blindaje preventivo puede ayudarte.\n\nEscribe *'Urgente'* para atención inmediata.",
                'context' => 'REDISEÑO',
                'skip_in_contexts' => [],
            ],
            
            // DESPEDIDAS
            [
                'keys' => ['gracias', 'adios', 'bye', 'hasta luego', 'nos vemos'],
                'type' => 'text',
                'response' => "¡Gracias a ti! 🙏\n\nEstamos aquí para blindar tu operación 24/7.\n\nSi necesitas algo más, solo escríbeme. ¡Hasta pronto! 🚀",
                'context' => 'START',
                'skip_in_contexts' => [],
            ],
            
            // MULTIMEDIA
            [
                'keys' => ['foto', 'imagen', 'ver producto'],
                'type' => 'image',
                'url' => 'https://botwp.tecnologiaempresarial.mx/images/XPLUS.png',
                'caption' => '📸 Nuestras soluciones empresariales',
                'context' => 'INFO',
                'skip_in_contexts' => [],
            ],
            
            [
                'keys' => ['video', 'demostracion', 'demo visual'],
                'type' => 'video',
                'url' => 'https://botwp.tecnologiaempresarial.mx/videos/XPLUS.mp4',
                'caption' => '🎥 Mira cómo transformamos empresas',
                'context' => 'INFO',
                'skip_in_contexts' => [],
            ],
            
            [
                'keys' => ['catalogo', 'pdf', 'documento', 'brochure'],
                'type' => 'document',
                'url' => 'https://botwp.tecnologiaempresarial.mx/docs/XPLUS.pdf',
                'filename' => 'Catalogo_Tecnologia_Empresarial_2026.pdf',
                'context' => 'INFO',
                'skip_in_contexts' => [],
            ],
        ];

        // Buscar coincidencias en el catálogo
        foreach ($catalog as $item) {
            // Si estamos en un contexto que debe skipear esta entrada, continuar
            if (isset($item['skip_in_contexts']) && 
                in_array($currentContext, $item['skip_in_contexts'])) {
                continue;
            }
            
            foreach ($item['keys'] as $keyword) {
                if (str_contains($input, $keyword)) {
                    return $item;
                }
            }
        }

        // Si no encuentra nada, retorna tipo fallback
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