<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    // Definir contextos principales con sus palabras clave de activación
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
                \Log::info('📩 Valor de context', $chat->context);


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
            sleep(1);
        }

        // 2. Detectar comandos de reset o menú principal
        if ($this->isResetCommand($text)) {
            $chat->update(['context' => 'START', 'last_bot_question' => null, 'metadata' => null]);
            return $this->handleInitialGreeting($chat, $from);
        }

        // 3. Detectar si es un saludo inicial
        if ($this->isGreeting($text) && $chat->context === 'INITIAL') {
            return $this->handleInitialGreeting($chat, $from);
        }

        // 4. PRIORIDAD MÁXIMA: Si estamos en un contexto específico, NUNCA salir
        $currentContext = $chat->context ?? 'INITIAL';
        
        if (in_array($currentContext, ['CONTPAQI', 'NUBE', 'REDISEÑO', 'CAPACITACION', 'SOPORTE'])) {
            return $this->handleContextualFlow($chat, $from, $text, $currentContext);
        }

        // 5. Si estamos en flujo de cotización
        if (in_array($currentContext, ['QUOTE', 'QUOTE_WAITING_EMAIL'])) {
            return $this->handleQuoteFlow($chat, $from, $text);
        }

        // 6. Detectar nuevo contexto para iniciar flujo
        $detectedContext = $this->detectContextFromMessage($text);
        
        if ($detectedContext !== null) {
            $chat->update(['context' => $detectedContext, 'last_bot_question' => null]);
            return $this->initializeContextFlow($chat, $from, $text, $detectedContext);
        }

        // 7. Respuestas del catálogo general (solo si no hay contexto activo)
        if (in_array($currentContext, ['INITIAL', 'START', 'INFO'])) {
            $catalogResponse = $this->findGeneralResponse($text);
            
            if ($catalogResponse !== null) {
                if (isset($catalogResponse['context'])) {
                    $chat->update(['context' => $catalogResponse['context']]);
                }
                return $this->sendCatalogResponse($from, $catalogResponse, $chat);
            }
        }

        // 8. Respuesta por defecto
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

        $chat->update(['context' => 'START']);
        
        $message = $greeting . "Estamos encantados de acompañarte en este *2026* para que tu negocio no solo crezca, sino que esté totalmente blindado y a la vanguardia. 🚀\n\n¿Cómo podemos apoyarte hoy?\n\n1️⃣ *Conocer Tecnología Empresarial*\n2️⃣ *Explorar servicios* (CONTPAQi, Rediseño, Capacitación)\n3️⃣ *Soporte Técnico*\n4️⃣ *Hablar con un ejecutivo*\n\n_También puedes escribirme directamente lo que necesitas y procesaré tu solicitud. 😊_";
        
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
        // NUNCA permitir que se salga del contexto a menos que sea comando de reset
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

    // ============================================
    // FLUJO CONTPAQI - COMPLETAMENTE AISLADO
    // ============================================
    
    private function startContPaqiFlow($chat, $from, $text)
    {
        $intro = "📊 *¡Excelente elección! CONTPAQi*\n\n";
        $intro .= "Somos *Socios Máster Nivel Oro* con 30 años implementando soluciones administrativas.\n\n";
        
        // Detectar si mencionó un módulo específico en el mensaje inicial
        if (str_contains($text, 'contab')) {
            $chat->update(['last_bot_question' => 'contpaqi_detalle_contabilidad']);
            $metadata = ['modulo_interes' => 'Contabilidad'];
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, $intro . "📊 *CONTPAQi Contabilidad* - Excelente elección.\n\nPerfecto para:\n✅ Control fiscal total\n✅ Contabilidad electrónica SAT\n✅ Estados financieros automáticos\n✅ Pólizas automatizadas\n\n¿Necesitas implementación nueva, actualización o migración desde otro sistema?");
        } elseif (str_contains($text, 'nomin')) {
            $chat->update(['last_bot_question' => 'contpaqi_detalle_nominas']);
            $metadata = ['modulo_interes' => 'Nóminas'];
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, $intro . "👥 *CONTPAQi Nóminas* - La mejor decisión.\n\nIdeal para:\n✅ Cálculo automático de nómina\n✅ Timbrado CFDI 4.0\n✅ IMSS, Infonavit, ISR\n✅ Finiquitos y liquidaciones\n\n¿Cuántos empleados tienes actualmente?");
        } elseif (str_contains($text, 'comerc') || str_contains($text, 'factur') || str_contains($text, 'inventario')) {
            $chat->update(['last_bot_question' => 'contpaqi_detalle_comercial']);
            $metadata = ['modulo_interes' => 'Comercial'];
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, $intro . "🏪 *CONTPAQi Comercial* - buenasasasasas elección.\n\nTe permite:\n✅ Facturación electrónica 4.0\n✅ Control total de inventarios\n✅ Cuentas por cobrar/pagar\n✅ Múltiples almacenes\n\n¿Manejas inventarios, servicios o ambos?");
        } elseif (str_contains($text, 'banco') || str_contains($text, 'tesorer')) {
            $chat->update(['last_bot_question' => 'contpaqi_detalle_bancos']);
            $metadata = ['modulo_interes' => 'Bancos'];
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, $intro . "🏦 *CONTPAQi Bancos* - Excelente.\n\nBeneficios:\n✅ Conciliación bancaria automática\n✅ Flujo de efectivo en tiempo real\n✅ Control de cheques\n✅ Pagos electrónicos\n\n¿Con cuántos bancos trabajas?");
        } else {
            // Pregunta general sobre qué módulo le interesa
            $chat->update(['last_bot_question' => 'contpaqi_modulo']);
            $this->sendMessage($from, $intro . "¿Qué módulo te interesa?\n\n📊 *Contabilidad*\n👥 *Nóminas*\n🏪 *Comercial*\n🏦 *Bancos*\n🎯 *Suite completa*\n\nEscribe el nombre del módulo.");
        }
        
        return response()->json(['status' => 'ok']);
    }

    private function handleContPaqiFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        $metadata = json_decode($chat->metadata ?? '{}', true);
        
        // ============================================
        // PASO 1: Selección de módulo
        // ============================================
        \Log::info('📩 Valor de last_bot_question', $lastQuestion);

        if ($lastQuestion === 'contpaqi_modulo') {
            $modulo = '';
            
            if (str_contains($text, 'contab') || str_contains($text, '1')) {
                $modulo = 'Contabilidad';
                $nextQuestion = 'contpaqi_detalle_contabilidad';
                $message = "📊 *CONTPAQi Contabilidad* - Excelente elección.\n\nPerfecto para:\n✅ Control fiscal total\n✅ Contabilidad electrónica SAT\n✅ Estados financieros automáticos\n✅ Pólizas automatizadas\n\n¿Necesitas implementación nueva, actualización o migración desde otro sistema?";
            } elseif (str_contains($text, 'nomin') || str_contains($text, '2')) {
                $modulo = 'Nóminas';
                $nextQuestion = 'contpaqi_detalle_nominas';
                $message = "👥 *CONTPAQi Nóminas* - La mejor decisión.\n\nIdeal para:\n✅ Cálculo automático de nómina\n✅ Timbrado CFDI 4.0\n✅ IMSS, Infonavit, ISR\n✅ Finiquitos y liquidaciones\n\n¿Cuántos empleados tienes actualmente?";
            } elseif (str_contains($text, 'comerc') || str_contains($text, 'factur') || str_contains($text, 'inventario') || str_contains($text, '3')) {
                $modulo = 'Comercial';
                $nextQuestion = 'contpaqi_detalle_comercial';
                $message = "🏪 *CONTPAQi Comercial* - Perfecta elección.\n\nTe permite:\n✅ Facturación electrónica 4.0\n✅ Control total de inventarios\n✅ Cuentas por cobrar/pagar\n✅ Múltiples almacenes\n\n¿Manejas inventarios, servicios o ambos?";
            } elseif (str_contains($text, 'banco') || str_contains($text, 'tesorer') || str_contains($text, '4')) {
                $modulo = 'Bancos';
                $nextQuestion = 'contpaqi_detalle_bancos';
                $message = "🏦 *CONTPAQi Bancos* - Excelente.\n\nBeneficios:\n✅ Conciliación bancaria automática\n✅ Flujo de efectivo en tiempo real\n✅ Control de cheques\n✅ Pagos electrónicos\n\n¿Con cuántos bancos trabajas?";
            } elseif (str_contains($text, 'todo') || str_contains($text, 'suite') || str_contains($text, 'completa') || str_contains($text, '5')) {
                $modulo = 'Suite Completa';
                $nextQuestion = 'contpaqi_usuarios';
                $message = "🎯 *Suite Completa CONTPAQi* - La solución integral.\n\nIncluye:\n✅ Contabilidad\n✅ Nóminas\n✅ Comercial\n✅ Bancos\n\nTodo integrado automáticamente. ¿Cuántos usuarios necesitas?";
            } else {
                $this->sendMessage($from, "No estoy seguro de entender. Por favor elige uno de estos módulos:\n\n📊 Contabilidad\n👥 Nóminas\n🏪 Comercial\n🏦 Bancos\n🎯 Suite completa");
                return response()->json(['status' => 'ok']);
            }
            
            $metadata['modulo_interes'] = $modulo;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => $nextQuestion]);
            $this->sendMessage($from, $message);
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 2: Detalles de Contabilidad
        // ============================================
        if ($lastQuestion === 'contpaqi_detalle_contabilidad') {
            if (str_contains($text, 'nueva') || str_contains($text, 'implementa') || str_contains($text, '1')) {
                $metadata['tipo_implementacion'] = 'Nueva';
                $this->sendMessage($from, "Perfecto, implementación nueva. 🚀\n\nPara dimensionar correctamente:\n\n¿Cuántos usuarios necesitas que trabajen simultáneamente con el sistema?");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_usuarios']);
            } elseif (str_contains($text, 'actualiza') || str_contains($text, '2')) {
                $metadata['tipo_implementacion'] = 'Actualización';
                $this->sendMessage($from, "Entendido, actualización de versión. 🔄\n\n¿Qué versión de CONTPAQi tienes actualmente? (Ejemplo: 2020, 2022, etc.)");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_version_actual']);
            } elseif (str_contains($text, 'migraci') || str_contains($text, '3')) {
                $metadata['tipo_implementacion'] = 'Migración';
                $this->sendMessage($from, "Excelente decisión migrar a CONTPAQi. 📦\n\n¿De qué sistema vienes? (Ejemplo: Aspel, SAP, Excel, otro)");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_sistema_anterior']);
            } else {
                $this->sendMessage($from, "¿Qué tipo de implementación necesitas?\n\n1️⃣ Nueva implementación\n2️⃣ Actualización de versión\n3️⃣ Migración desde otro sistema");
            }
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 3: Detalles de Nóminas - Número de empleados
        // ============================================
        if ($lastQuestion === 'contpaqi_detalle_nominas') {
            preg_match('/\d+/', $text, $matches);
            $empleados = $matches[0] ?? null;
            
            if ($empleados) {
                $metadata['num_empleados'] = $empleados;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $recomendacion = $empleados <= 3 ? "💼 *Versión Start* - Ideal para tu equipo pequeño" : 
                    ($empleados <= 20 ? "🏢 *Versión Empresarial* - Perfecta para tu tamaño" : 
                    "🏭 *Versión Empresarial Plus* - Para operaciones grandes");
                
                $this->sendMessage($from, "Perfecto, con $empleados empleados te recomiendo:\n\n$recomendacion\n\n¿Necesitas también el módulo de timbrado de nómina? (Sí/No)");
                $chat->update(['last_bot_question' => 'contpaqi_timbrado']);
            } else {
                $this->sendMessage($from, "Para recomendarte la versión correcta, ¿cuántos empleados tienes? (Solo el número)");
            }
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 4: Detalles de Comercial - Tipo de negocio
        // ============================================
        if ($lastQuestion === 'contpaqi_detalle_comercial') {
            if (str_contains($text, 'inventario') || str_contains($text, 'producto')) {
                $metadata['tipo_negocio'] = 'Inventarios';
                $this->sendMessage($from, "Perfecto para control de inventarios. 📦\n\n¿Cuántos productos/SKUs manejas aproximadamente?\n\na) Menos de 100\nb) 100-500\nc) Más de 500");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_inventario_size']);
            } elseif (str_contains($text, 'servicio')) {
                $metadata['tipo_negocio'] = 'Servicios';
                $this->sendMessage($from, "Ideal para empresas de servicios. 💼\n\nLa versión para servicios es más simple y económica.\n\n¿Cuántos usuarios necesitas?");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_usuarios']);
            } elseif (str_contains($text, 'ambos') || str_contains($text, 'hibrido')) {
                $metadata['tipo_negocio'] = 'Híbrido';
                $this->sendMessage($from, "Entiendo, negocio híbrido. Necesitas la versión completa.\n\n¿Aproximadamente cuántos productos manejas en inventario?");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_inventario_size']);
            } else {
                $this->sendMessage($from, "Por favor especifica:\n\n📦 *Inventarios* - Productos físicos\n💼 *Servicios* - Solo servicios\n🔄 *Ambos* - Híbrido");
            }
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 5: Detalles de Bancos
        // ============================================
        if ($lastQuestion === 'contpaqi_detalle_bancos') {
            preg_match('/\d+/', $text, $matches);
            $bancos = $matches[0] ?? null;
            
            if ($bancos) {
                $metadata['num_bancos'] = $bancos;
                $this->sendMessage($from, "Perfecto, con $bancos banco(s). 🏦\n\nCONTPAQi Bancos te permitirá conciliar automáticamente con todos ellos.\n\n¿Necesitas integración con el módulo de Contabilidad? (Sí/No)");
                $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_integracion_contabilidad']);
            } else {
                $this->sendMessage($from, "¿Con cuántos bancos trabajas? (Solo el número)");
            }
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 6: Número de usuarios (común para varios flujos)
        // ============================================
        if ($lastQuestion === 'contpaqi_usuarios') {
            preg_match('/\d+/', $text, $matches);
            $usuarios = $matches[0] ?? null;
            
            if ($usuarios) {
                $metadata['num_usuarios'] = $usuarios;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $this->sendMessage($from, "Excelente, con $usuarios usuario(s). 👥\n\n¿Prefieres:\n\n1️⃣ *Licencia perpetua* (compra única)\n2️⃣ *Renta mensual* (pago recurrente)\n\nEscribe el número de tu preferencia.");
                $chat->update(['last_bot_question' => 'contpaqi_modalidad']);
            } else {
                $this->sendMessage($from, "¿Cuántos usuarios trabajarán con el sistema? (Solo el número)");
            }
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 7: Modalidad de compra
        // ============================================
        if ($lastQuestion === 'contpaqi_modalidad') {
            if (str_contains($text, '1') || str_contains($text, 'perpetua') || str_contains($text, 'compra')) {
                $metadata['modalidad'] = 'Perpetua';
                $mensaje = "💎 *Licencia Perpetua* - Excelente elección.\n\nVentajas:\n✅ Inversión única\n✅ Software de por vida\n✅ Sin pagos recurrentes\n\nIncluye 1 año de soporte técnico.\n\n¿Te gustaría:\n\n1️⃣ Ver cotización formal\n2️⃣ Agendar demostración\n3️⃣ Hablar con un asesor";
            } else {
                $metadata['modalidad'] = 'Renta';
                $mensaje = "💳 *Renta Mensual* - Decisión inteligente.\n\nVentajas:\n✅ Sin inversión inicial alta\n✅ Actualizaciones incluidas\n✅ Soporte técnico 24/7\n✅ Escalable según tu crecimiento\n\n¿Te gustaría:\n\n1️⃣ Ver cotización formal\n2️⃣ Agendar demostración\n3️⃣ Hablar con un asesor";
            }
            
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'contpaqi_siguiente_paso']);
            $this->sendMessage($from, $mensaje);
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // PASO 8: Siguiente paso (cierre)
        // ============================================
        if ($lastQuestion === 'contpaqi_siguiente_paso') {
            if (str_contains($text, '1') || str_contains($text, 'cotiz')) {
                $this->sendMessage($from, "📧 Perfecto, prepararé tu cotización personalizada.\n\nPara enviártela, necesito:\n\n1️⃣ Tu nombre completo\n2️⃣ Nombre de tu empresa\n3️⃣ Email\n\n¿Cuál es tu nombre?");
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'nombre']);
            } elseif (str_contains($text, '2') || str_contains($text, 'demo')) {
                $this->sendMessage($from, "🎯 ¡Excelente! La demostración te permitirará ver el sistema en acción.\n\n¿Qué día y horario te viene mejor?\n\nEjemplo: 'Mañana 10am' o 'Viernes 3pm'");
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'agendar_demo']);
            } elseif (str_contains($text, '3') || str_contains($text, 'asesor')) {
                $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
                $this->sendMessage($from, "👨‍💼 Perfecto, te conecto con un asesor comercial especializado.\n\nEn breve te atenderá personalmente.");
                $this->notifyAgent("🎯 Cliente interesado en CONTPAQi {$metadata['modulo_interes']}\n📱 $from\n📋 Datos: " . json_encode($metadata));
            }
            return response()->json(['status' => 'ok']);
        }
        
        // ============================================
        // Manejo de preguntas dentro del contexto CONTPAQI
        // ============================================
        
        // Preguntas sobre precios (siempre dentro de CONTPAQI)
        if (str_contains($text, 'precio') || str_contains($text, 'costo') || str_contains($text, 'cuanto')) {
            $this->sendMessage($from, "💰 El precio de CONTPAQi depende de varios factores:\n\n• Módulo específico\n• Número de usuarios\n• Modalidad (compra o renta)\n\nActualmente estamos viendo: *{$metadata['modulo_interes']}*\n\n¿Quieres continuar configurando tu solución o prefieres una cotización inmediata?");
            return response()->json(['status' => 'ok']);
        }
        
        // Preguntas sobre funcionalidades
        if (str_contains($text, 'funcion') || str_contains($text, 'caracteristica') || str_contains($text, 'que hace') || str_contains($text, 'sirve')) {
            $funciones = [
                'Contabilidad' => "📊 *Funciones de CONTPAQi Contabilidad:*\n\n✅ Pólizas automáticas\n✅ Estados financieros en tiempo real\n✅ Contabilidad electrónica SAT\n✅ Multi-empresa y multi-moneda\n✅ Presupuestos vs real\n✅ Centro de costos\n\n¿Hay alguna función específica que te interese conocer más a fondo?",
                'Nóminas' => "👥 *Funciones de CONTPAQi Nóminas:*\n\n✅ Cálculo automático de nómina\n✅ CFDI de nómina 4.0\n✅ Cálculo de IMSS, Infonavit, ISR\n✅ Incidencias (faltas, vacaciones, etc.)\n✅ Finiquitos y liquidaciones\n✅ PTU automática\n\n¿Tienes alguna pregunta sobre alguna función?",
                'Comercial' => "🏪 *Funciones de CONTPAQi Comercial:*\n\n✅ Factura electrónica 4.0\n✅ Control de inventarios\n✅ Punto de venta\n✅ Pedidos y cotizaciones\n✅ Cuentas por cobrar/pagar\n✅ Análisis de ventas\n\n¿Qué función te gustaría conocer mejor?",
                'Bancos' => "🏦 *Funciones de CONTPAQi Bancos:*\n\n✅ Conciliación bancaria automática\n✅ Importación de movimientos\n✅ Proyección de flujo\n✅ Cheques y traspasos\n✅ Pagos electrónicos\n✅ Control de inversiones\n\n¿Hay algo específico que necesites?"
            ];
            
            $modulo = $metadata['modulo_interes'] ?? 'Contabilidad';
            $this->sendMessage($from, $funciones[$modulo] ?? "Estamos hablando de CONTPAQi. ¿Qué aspecto específico te interesa?");
            return response()->json(['status' => 'ok']);
        }
        
        // Si no entendemos en el contexto
        $this->sendMessage($from, "🤔 No estoy seguro de entender tu pregunta sobre *{$metadata['modulo_interes']}*.\n\n¿Podrías ser más específico? También puedes:\n\n• Escribir *'precio'* para cotización\n• Escribir *'funciones'* para ver características\n• Escribir *'menú'* para volver al inicio");
        
        return response()->json(['status' => 'ok']);
    }
    
    // ============================================
    // FLUJO NUBE - COMPLETAMENTE AISLADO
    // ============================================
    
    private function startNubeFlow($chat, $from, $text)
    {
        $this->sendMessage($from, "☁️ *¡Excelente! Escritorios Virtuales en la Nube*\n\n¡Lleva tu oficina a cualquier lugar!\n\nOlvídate de:\n❌ Servidores físicos costosos\n❌ Fallas de luz que detienen tu operación\n❌ Mantenimientos complejos\n❌ Pérdida de información\n\n✅ Acceso 24/7 desde cualquier dispositivo\n✅ Respaldos automáticos diarios\n✅ Máxima seguridad\n✅ Soporte técnico incluido\n\n¿Cuántos usuarios necesitas que trabajen en la nube?");
        
        $chat->update(['last_bot_question' => 'nube_usuarios']);
        return response()->json(['status' => 'ok']);
    }

    private function handleNubeFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        $metadata = json_decode($chat->metadata ?? '{}', true);
        
        if ($lastQuestion === 'nube_usuarios') {
            preg_match('/\d+/', $text, $matches);
            $usuarios = $matches[0] ?? null;
            
            if ($usuarios) {
                $plan = $usuarios == 1 ? 'Básico' : ($usuarios <= 10 ? 'Empresarial' : 'Corporativo');
                $metadata['plan_interes'] = $plan;
                $metadata['usuarios'] = $usuarios;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $descripcion = [
                    'Básico' => "💼 *Plan Básico* - Perfecto para emprendedores\n• 1 usuario\n• 50GB almacenamiento\n• Respaldo diario\n• Ideal para inicio",
                    'Empresarial' => "🏢 *Plan Empresarial* - Para empresas en crecimiento\n• Hasta 10 usuarios\n• 200GB almacenamiento\n• Servidor dedicado\n• Soporte prioritario",
                    'Corporativo' => "🏭 *Plan Corporativo* - Solución empresarial completa\n• +10 usuarios\n• Almacenamiento ilimitado\n• Infraestructura dedicada\n• Soporte 24/7"
                ];
                
                $this->sendMessage($from, $descripcion[$plan] . "\n\n¿Qué software necesitas en la nube?\n\n1️⃣ CONTPAQi\n2️⃣ Office 365\n3️⃣ Otros sistemas\n4️⃣ Todos los anteriores");
                $chat->update(['last_bot_question' => 'nube_software']);
            } else {
                $this->sendMessage($from, "¿Cuántos usuarios trabajarán en la nube? (Solo el número)");
            }
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'nube_software') {
            $software = '';
            if (str_contains($text, '1') || str_contains($text, 'contpaqi')) $software = 'CONTPAQi';
            elseif (str_contains($text, '2') || str_contains($text, 'office')) $software = 'Office 365';
            elseif (str_contains($text, '3') || str_contains($text, 'otros')) $software = 'Sistemas personalizados';
            elseif (str_contains($text, '4') || str_contains($text, 'todos')) $software = 'Suite completa';
            
            if ($software) {
                $metadata['software'] = $software;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $usuarios = $metadata['usuarios'] ?? 1;
                $precioBase = $usuarios * 800;
                
                $this->sendMessage($from, "Perfecto, *$software* en la nube. ☁️\n\n💰 *Inversión mensual estimada:*\n\nPara {$usuarios} usuario(s): *$" . number_format($precioBase, 2) . " MXN/mes*\n\nIncluye:\n✅ Software completo\n✅ Respaldos automáticos\n✅ Soporte técnico\n✅ Actualizaciones\n\n¿Te gustaría:\n\n1️⃣ Cotización formal\n2️⃣ Demostración en vivo\n3️⃣ Hablar con un asesor");
                $chat->update(['last_bot_question' => 'nube_siguiente_paso']);
            } else {
                $this->sendMessage($from, "Por favor elige una opción:\n\n1️⃣ CONTPAQi\n2️⃣ Office 365\n3️⃣ Otros sistemas\n4️⃣ Todos");
            }
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'nube_siguiente_paso') {
            if (str_contains($text, '1') || str_contains($text, 'cotiz')) {
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'nombre']);
                $this->sendMessage($from, "📧 Perfecto, prepararé tu cotización.\n\n¿Cuál es tu nombre completo?");
            } elseif (str_contains($text, '2') || str_contains($text, 'demo')) {
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'agendar_demo']);
                $this->sendMessage($from, "🎯 ¡Excelente! ¿Qué día y horario te viene mejor?\n\nEjemplo: 'Mañana 10am'");
            } elseif (str_contains($text, '3') || str_contains($text, 'asesor')) {
                $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
                $this->sendMessage($from, "👨‍💼 Te conecto con un asesor especializado.");
                $this->notifyAgent("☁️ Cliente interesado en Nube\n📱 $from\n📋 " . json_encode($metadata));
            }
            return response()->json(['status' => 'ok']);
        }
        
        // Preguntas dentro del contexto NUBE
        if (str_contains($text, 'ventaja') || str_contains($text, 'beneficio')) {
            $this->sendMessage($from, "🌟 *Beneficios de la Nube:*\n\n✅ Trabajo remoto total\n✅ Cero inversión en hardware ($50K+ ahorro)\n✅ Nunca pierdas información\n✅ Crece según necesites\n✅ Seguridad bancaria\n\n¿Tienes otra pregunta sobre la nube?");
            return response()->json(['status' => 'ok']);
        }
        
        $this->sendMessage($from, "🤔 ¿Podrías ser más específico sobre la nube?\n\nTambién puedes escribir *'menú'* para volver al inicio.");
        return response()->json(['status' => 'ok']);
    }
    
    // ============================================
    // FLUJO REDISEÑO - COMPLETAMENTE AISLADO
    // ============================================
    
    private function startRedisenoFlow($chat, $from, $text)
    {
        $this->sendMessage($from, "🛡️ *¡Excelente! Rediseño Empresarial 360°*\n\nNo solo implementamos software, transformamos tu empresa para que esté blindada ante el SAT.\n\n🎯 Garantizamos:\n✅ *Materialidad* de operaciones\n✅ *Trazabilidad* completa\n✅ *Razón de negocio* justificada\n✅ Automatización total\n\n¿Cuál es tu principal preocupación?\n\n1️⃣ Auditoría SAT en curso\n2️⃣ Prevenir fiscalización\n3️⃣ Mejorar procesos\n4️⃣ Cumplimiento fiscal");
        
        $chat->update(['last_bot_question' => 'rediseno_necesidad']);
        return response()->json(['status' => 'ok']);
    }

    private function handleRedisenoFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        $metadata = json_decode($chat->metadata ?? '{}', true);
        
        if ($lastQuestion === 'rediseno_necesidad') {
            $necesidad = '';
            if (str_contains($text, '1') || str_contains($text, 'auditoria') || str_contains($text, 'sat')) {
                $necesidad = 'Auditoría SAT';
                $mensaje = "🚨 *Situación urgente - Auditoría SAT*\n\nNecesitas blindaje inmediato:\n\n1️⃣ Revisión de materialidad\n2️⃣ Evidencia digital robusta\n3️⃣ Justificación de operaciones\n\n⏱️ Podemos iniciar en 24-48 horas.\n\n¿En qué sector operas?";
                $nextQ = 'rediseno_sector';
            } elseif (str_contains($text, '2') || str_contains($text, 'prevenir')) {
                $necesidad = 'Prevención';
                $mensaje = "🛡️ *Prevención Inteligente*\n\nLa mejor estrategia es estar preparado antes de la fiscalización.\n\n✅ Diagnóstico preventivo\n✅ Blindaje fiscal\n✅ Procesos documentados\n\n¿Qué tamaño tiene tu empresa?\n\na) Pequeña (1-10 empleados)\nb) Mediana (11-50)\nc) Grande (+50)";
                $nextQ = 'rediseno_tamano';
            } elseif (str_contains($text, '3') || str_contains($text, 'procesos') || str_contains($text, 'mejorar')) {
                $necesidad = 'Mejora de procesos';
                $mensaje = "🔄 *Optimización de Procesos*\n\nTransformación operativa completa:\n\n✅ Mapeo de procesos actuales\n✅ Identificación de cuellos de botella\n✅ Automatización inteligente\n✅ Capacitación del equipo\n\n¿Qué área necesita más atención?\n\n1️⃣ Contabilidad/Finanzas\n2️⃣ Operaciones\n3️⃣ Ventas\n4️⃣ Todas";
                $nextQ = 'rediseno_area';
            } else {
                $necesidad = 'Cumplimiento fiscal';
                $mensaje = "📋 *Cumplimiento Fiscal Total*\n\nAseguramos que tu operación cumpla 100% con el SAT:\n\n✅ CFDI 4.0\n✅ Contabilidad electrónica\n✅ Complementos de pago\n✅ DIOT y declaraciones\n\n¿Qué régimen fiscal tienes?\n\na) Persona Física\nb) Persona Moral\nc) RESICO";
                $nextQ = 'rediseno_regimen';
            }
            
            $metadata['necesidad'] = $necesidad;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => $nextQ]);
            $this->sendMessage($from, $mensaje);
            return response()->json(['status' => 'ok']);
        }
        
        // Continuación del flujo según la rama
        if ($lastQuestion === 'rediseno_sector' || $lastQuestion === 'rediseno_tamano' || 
            $lastQuestion === 'rediseno_area' || $lastQuestion === 'rediseno_regimen') {
            
            $metadata['detalle'] = $text;
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, "Perfecto, entiendo tu situación. 👍\n\nEl siguiente paso es realizar un *diagnóstico sin costo* donde:\n\n✅ Analizamos tu operación actual\n✅ Identificamos vulnerabilidades\n✅ Diseñamos tu solución personalizada\n\n¿Te gustaría agendar el diagnóstico?\n\n1️⃣ Sí, agendemos\n2️⃣ Primero quiero más información\n3️⃣ Hablar con un asesor");
            $chat->update(['last_bot_question' => 'rediseno_diagnostico']);
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'rediseno_diagnostico') {
            if (str_contains($text, '1') || str_contains($text, 'si') || str_contains($text, 'agendar')) {
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'agendar_demo']);
                $this->sendMessage($from, "🗓️ Perfecto, agendemos tu diagnóstico.\n\n¿Qué día y horario te viene mejor?\n\nEjemplo: 'Mañana 10am' o 'Viernes 2pm'");
            } elseif (str_contains($text, '2') || str_contains($text, 'informacion')) {
                $this->sendMessage($from, "📋 *Proceso de Rediseño 360°:*\n\n1️⃣ *Diagnóstico* (2-3 días)\n2️⃣ *Diseño de solución* (1 semana)\n3️⃣ *Implementación* (2-4 semanas)\n4️⃣ *Capacitación* (continua)\n5️⃣ *Acompañamiento* (permanente)\n\nInversión desde $25,000 MXN según alcance.\n\n¿Tienes alguna pregunta específica?");
            } elseif (str_contains($text, '3') || str_contains($text, 'asesor')) {
                $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
                $this->sendMessage($from, "👨‍💼 Te conecto con un consultor especializado.");
                $this->notifyAgent("🛡️ Cliente interesado en Rediseño\n📱 $from\n📋 " . json_encode($metadata));
            }
            return response()->json(['status' => 'ok']);
        }
        
        $this->sendMessage($from, "¿Podrías ser más específico sobre el rediseño?\n\nEscribe *'menú'* para volver al inicio.");
        return response()->json(['status' => 'ok']);
    }
    
    // ============================================
    // FLUJO CAPACITACIÓN - COMPLETAMENTE AISLADO
    // ============================================
    
    private function startCapacitacionFlow($chat, $from, $text)
    {
        $this->sendMessage($from, "🎓 *¡Excelente! Capacitación Empresarial*\n\nEl software no comete errores, las personas sí. Por eso capacitamos a tu equipo para alcanzar su máximo nivel de eficiencia.\n\n📚 ¿Qué curso necesita tu equipo?\n\n1️⃣ CONTPAQi (Contabilidad, Nóminas, Comercial)\n2️⃣ Excel Empresarial\n3️⃣ Cursos Fiscales\n4️⃣ Administración\n\n🏆 Todos con certificación STPS");
        
        $chat->update(['last_bot_question' => 'capacitacion_tipo']);
        return response()->json(['status' => 'ok']);
    }

    private function handleCapacitacionFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        $metadata = json_decode($chat->metadata ?? '{}', true);
        
        if ($lastQuestion === 'capacitacion_tipo') {
            $tipo = '';
            if (str_contains($text, '1') || str_contains($text, 'contpaqi')) {
                $tipo = 'CONTPAQi';
                $mensaje = "📊 *Capacitación en CONTPAQi*\n\n¿Qué módulo específico?\n\n1️⃣ Contabilidad\n2️⃣ Nóminas\n3️⃣ Comercial\n4️⃣ Bancos\n5️⃣ Todos los módulos";
                $nextQ = 'capacitacion_modulo_contpaqi';
            } elseif (str_contains($text, '2') || str_contains($text, 'excel')) {
                $tipo = 'Excel';
                $mensaje = "📈 *Capacitación en Excel Empresarial*\n\n¿Qué nivel?\n\n1️⃣ Básico\n2️⃣ Intermedio\n3️⃣ Avanzado (Macros, Power Query)\n4️⃣ Curso completo (todos los niveles)";
                $nextQ = 'capacitacion_nivel_excel';
            } elseif (str_contains($text, '3') || str_contains($text, 'fiscal')) {
                $tipo = 'Fiscal';
                $mensaje = "📋 *Capacitación Fiscal*\n\nTemas disponibles:\n\n1️⃣ CFDI 4.0 y complementos\n2️⃣ Contabilidad electrónica\n3️⃣ Declaraciones anuales\n4️⃣ Taller fiscal integral";
                $nextQ = 'capacitacion_tema_fiscal';
            } else {
                $tipo = 'Administración';
                $mensaje = "💼 *Capacitación Administrativa*\n\n1️⃣ Gestión financiera\n2️⃣ Costos y presupuestos\n3️⃣ Administración de proyectos\n4️⃣ Liderazgo empresarial";
                $nextQ = 'capacitacion_tema_admin';
            }
            
            $metadata['tipo_capacitacion'] = $tipo;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => $nextQ]);
            $this->sendMessage($from, $mensaje);
            return response()->json(['status' => 'ok']);
        }
        
        if (in_array($lastQuestion, ['capacitacion_modulo_contpaqi', 'capacitacion_nivel_excel', 
                                     'capacitacion_tema_fiscal', 'capacitacion_tema_admin'])) {
            $metadata['detalle_curso'] = $text;
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, "Perfecto. 👍\n\n¿Cuántas personas tomarán el curso?\n\n(El precio varía según el número de participantes)");
            $chat->update(['last_bot_question' => 'capacitacion_participantes']);
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'capacitacion_participantes') {
            preg_match('/\d+/', $text, $matches);
            $participantes = $matches[0] ?? null;
            
            if ($participantes) {
                $metadata['participantes'] = $participantes;
                $chat->update(['metadata' => json_encode($metadata)]);
                
                $this->sendMessage($from, "Excelente, para $participantes persona(s). 👥\n\n¿Qué modalidad prefieres?\n\n1️⃣ *Presencial* (en tus instalaciones o las nuestras)\n2️⃣ *Virtual* (sesiones en vivo por Zoom)\n3️⃣ *Híbrida* (combinación)");
                $chat->update(['last_bot_question' => 'capacitacion_modalidad']);
            } else {
                $this->sendMessage($from, "¿Cuántas personas tomarán el curso? (Solo el número)");
            }
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'capacitacion_modalidad') {
            $modalidad = '';
            if (str_contains($text, '1') || str_contains($text, 'presencial')) $modalidad = 'Presencial';
            elseif (str_contains($text, '2') || str_contains($text, 'virtual')) $modalidad = 'Virtual';
            else $modalidad = 'Híbrida';
            
            $metadata['modalidad'] = $modalidad;
            $participantes = $metadata['participantes'] ?? 1;
            $precioBase = $participantes * 1500;
            
            $chat->update(['metadata' => json_encode($metadata)]);
            
            $this->sendMessage($from, "Perfecto, modalidad *$modalidad*. 🎯\n\n💰 *Inversión aproximada:*\n\nPara $participantes persona(s): *$" . number_format($precioBase, 2) . " MXN*\n\nIncluye:\n✅ Material didáctico\n✅ Certificado STPS\n✅ Acceso a grabaciones\n✅ Soporte post-curso\n\n¿Te gustaría:\n\n1️⃣ Cotización formal\n2️⃣ Agendar el curso\n3️⃣ Hablar con un asesor");
            $chat->update(['last_bot_question' => 'capacitacion_siguiente_paso']);
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'capacitacion_siguiente_paso') {
            if (str_contains($text, '1') || str_contains($text, 'cotiz')) {
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'nombre']);
                $this->sendMessage($from, "📧 Prepararé tu cotización.\n\n¿Cuál es tu nombre completo?");
            } elseif (str_contains($text, '2') || str_contains($text, 'agendar')) {
                $chat->update(['context' => 'QUOTE', 'last_bot_question' => 'agendar_demo']);
                $this->sendMessage($from, "🗓️ ¿Qué fechas te vienen mejor para el curso?\n\nEjemplo: 'Próxima semana' o 'Marzo 15-16'");
            } elseif (str_contains($text, '3') || str_contains($text, 'asesor')) {
                $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
                $this->sendMessage($from, "👨‍💼 Te conecto con un asesor de capacitación.");
                $this->notifyAgent("🎓 Cliente interesado en Capacitación\n📱 $from\n📋 " . json_encode($metadata));
            }
            return response()->json(['status' => 'ok']);
        }
        
        $this->sendMessage($from, "¿Tienes alguna pregunta específica sobre la capacitación?\n\nEscribe *'menú'* para volver al inicio.");
        return response()->json(['status' => 'ok']);
    }
    
    // ============================================
    // FLUJO SOPORTE - COMPLETAMENTE AISLADO
    // ============================================
    
    private function startSoporteFlow($chat, $from, $text)
    {
        $this->sendMessage($from, "🛠️ *Soporte Técnico Especializado*\n\nEntendemos que tu operación no puede detenerse.\n\n¿Qué necesitas?\n\n1️⃣ Reportar nueva falla\n2️⃣ Consultar ticket existente\n3️⃣ Preguntas frecuentes\n4️⃣ Soporte urgente");
        
        $chat->update(['last_bot_question' => 'soporte_opcion']);
        return response()->json(['status' => 'ok']);
    }

    private function handleSoporteFlow($chat, $from, $text)
    {
        $lastQuestion = $chat->last_bot_question ?? '';
        
        if ($lastQuestion === 'soporte_opcion') {
            if (str_contains($text, '1') || str_contains($text, 'reportar') || str_contains($text, 'falla')) {
                $this->sendMessage($from, "🔧 *Reporte de Falla*\n\nPor favor describe tu problema:\n\n• ¿Qué sistema está fallando?\n• ¿Qué mensaje de error recibes?\n• ¿Cuándo comenzó el problema?\n\nPuedes adjuntar capturas de pantalla si es posible.");
                $chat->update(['last_bot_question' => 'soporte_describe_error']);
            } elseif (str_contains($text, '2') || str_contains($text, 'ticket') || str_contains($text, 'consultar')) {
                $this->sendMessage($from, "🎫 Introduce tu número de ticket:\n\nEjemplo: TKT-2026-001");
                $chat->update(['last_bot_question' => 'soporte_check_ticket']);
            } elseif (str_contains($text, '3') || str_contains($text, 'preguntas') || str_contains($text, 'faq')) {
                $this->sendMessage($from, "❓ *Preguntas Frecuentes*\n\n1️⃣ Mi sistema está lento\n2️⃣ No puedo acceder\n3️⃣ Error de timbrado\n4️⃣ Problemas de conexión\n5️⃣ Error en cálculos\n\n¿Cuál es tu situación?");
                $chat->update(['last_bot_question' => 'soporte_faq']);
            } elseif (str_contains($text, '4') || str_contains($text, 'urgente')) {
                $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
                $this->sendMessage($from, "🚨 *Soporte Urgente Activado*\n\nConectando con técnico especializado...\n\nTiempo de respuesta: 5-10 minutos.");
                $this->notifyAgent("🚨 SOPORTE URGENTE\n📱 $from\n⏰ " . now()->format('H:i'));
            }
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'soporte_describe_error') {
            $ticketNumber = 'TKT-' . now()->format('Y') . '-' . rand(1000, 9999);
            
            $this->sendMessage($from, "✅ *Ticket creado exitosamente*\n\n🎫 Número: *$ticketNumber*\n⏱️ Prioridad: Normal\n📧 Recibirás actualizaciones por WhatsApp\n\nUn técnico revisará tu caso en los próximos 30 minutos.\n\n¿Necesitas algo más?");
            
            $this->notifyAgent("🔧 Nuevo ticket de soporte\n🎫 $ticketNumber\n📱 $from\n💬 $text");
            
            $chat->update(['context' => 'START', 'last_bot_question' => null]);
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'soporte_check_ticket') {
            // Aquí podrías integrar con tu sistema de tickets real
            $this->sendMessage($from, "🎫 Buscando ticket...\n\n_Función en desarrollo. Por favor contacta a un agente para consultar el estatus._\n\nEscribe *'asesor'* para conectar con soporte.");
            $chat->update(['last_bot_question' => null]);
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'soporte_faq') {
            $faqs = [
                '1' => "🐌 *Sistema lento*\n\nSoluciones rápidas:\n✅ Cierra programas innecesarios\n✅ Revisa tu conexión a internet\n✅ Limpia archivos temporales\n✅ Reinicia el sistema\n\n¿Se solucionó? (Sí/No)",
                '2' => "🔒 *No puedo acceder*\n\nVerifica:\n✅ Usuario y contraseña correctos\n✅ Licencia activa\n✅ Conexión al servidor\n\n¿Necesitas resetear tu contraseña? (Sí/No)",
                '3' => "📄 *Error de timbrado*\n\nCausas comunes:\n❌ Certificado vencido\n❌ Saldo insuficiente de timbres\n❌ Datos incorrectos en CFDI\n\n¿Qué error específico muestra?",
                '4' => "🌐 *Problemas de conexión*\n\n¿Estás en la nube?\n✅ Revisa tu internet\n✅ Verifica VPN si aplica\n\n¿Servidor local?\n✅ Ping al servidor\n✅ Revisa firewall\n\n¿Cuál es tu caso?",
                '5' => "🔢 *Error en cálculos*\n\nPasos:\n1️⃣ Verifica configuración\n2️⃣ Revisa catálogos SAT\n3️⃣ Actualiza tablas\n\n¿En qué módulo ocurre?"
            ];
            
            $faq = $faqs[$text] ?? $faqs['1'];
            $this->sendMessage($from, $faq);
            $chat->update(['last_bot_question' => 'soporte_faq_followup']);
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'soporte_faq_followup') {
            if ($this->isPositiveResponse($text)) {
                $this->sendMessage($from, "🎉 ¡Excelente! Me alegra que se haya solucionado.\n\n¿Hay algo más en lo que pueda ayudarte?");
                $chat->update(['context' => 'START', 'last_bot_question' => null]);
            } else {
                $this->sendMessage($from, "Entiendo, el problema persiste. 🤔\n\nVoy a conectarte con un técnico especializado para que te ayude personalmente.");
                $chat->update(['context' => 'HUMAN_SUPPORT', 'status' => 'waiting_agent']);
                $this->notifyAgent("🔧 Cliente necesita soporte técnico\n📱 $from\n💬 FAQ no resolvió el problema");
            }
            return response()->json(['status' => 'ok']);
        }
        
        $this->sendMessage($from, "¿Necesitas ayuda con algo más de soporte técnico?\n\nEscribe *'menú'* para opciones principales.");
        return response()->json(['status' => 'ok']);
    }

    // ============================================
    // FLUJO DE COTIZACIÓN (TRANSVERSAL)
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
            $this->sendMessage($from, "Excelente. 3️⃣ ¿A qué *correo electrónico* te envío la propuesta?");
            return response()->json(['status' => 'ok']);
        }

        if ($lastQuestion === 'email') {
            if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $metadata['email'] = $text;
                
                $this->sendMessage($from, "✅ ¡Perfecto! Hemos recibido tu solicitud.\n\n📋 *Resumen:*\nNombre: {$metadata['nombre']}\nEmpresa: {$metadata['empresa']}\nEmail: $text\n\n📧 Un asesor comercial te enviará la cotización personalizada en las próximas 2 horas.\n\n¿Hay algo más en lo que pueda ayudarte?");
                
                $this->notifyAgent("🎯 Nueva solicitud de cotización\n👤 {$metadata['nombre']}\n🏢 {$metadata['empresa']}\n📧 $text\n📱 $from\n📋 Detalles: " . json_encode($metadata));
                
                $chat->update(['context' => 'START', 'last_bot_question' => null, 'metadata' => json_encode($metadata)]);
            } else {
                $this->sendMessage($from, "❌ El correo no parece válido. Por favor, verifica e inténtalo de nuevo.\n\nEjemplo: nombre@empresa.com");
            }
            
            return response()->json(['status' => 'ok']);
        }
        
        if ($lastQuestion === 'agendar_demo') {
            $metadata['fecha_preferida'] = $text;
            $chat->update(['metadata' => json_encode($metadata), 'last_bot_question' => 'nombre']);
            $this->sendMessage($from, "Perfecto, anotado: *$text* 📅\n\nPara confirmar la cita, necesito tu nombre completo:");
            return response()->json(['status' => 'ok']);
        }

        return response()->json(['status' => 'ok']);
    }

    // ============================================
    // RESPUESTAS GENERALES (SOLO FUERA DE CONTEXTOS)
    // ============================================
    
    private function findGeneralResponse($text)
    {
        $generalCatalog = [
            [
                'keys' => ['quienes son', 'que hacen', 'sobre ustedes', 'empresa', 'conocer'],
                'response' => "Somos *Tecnología Empresarial*, consultores especializados con 30 años de experiencia, liderados por la L.C.P. Verónica De León.\n\n🎯 *Nuestra misión:* Blindar tu empresa y garantizar tu cumplimiento fiscal mediante:\n\n🚀 Tecnología de vanguardia\n📊 Automatización de procesos\n🎓 Capacitación especializada\n\n¿Te gustaría conocer nuestros servicios específicos?",
                'context' => 'INFO',
            ],
            [
                'keys' => ['servicios', 'que ofrecen', 'productos'],
                'response' => "🚀 *Nuestros Servicios:*\n\n📊 *CONTPAQi* - Sistemas administrativos\n☁️ *Nube* - Escritorios virtuales\n🛡️ *Rediseño* - Blindaje fiscal\n🎓 *Capacitación* - Cursos especializados\n🛠️ *Soporte* - Asistencia técnica\n\n¿Cuál te interesa conocer?",
                'context' => 'START',
            ],
            [
                'keys' => ['precio', 'costo', 'cuanto', 'cotizacion'],
                'response' => "💰 Cada empresa es única y merece una solución personalizada.\n\nPara brindarte un precio justo necesitamos conocer:\n• Tamaño de tu empresa\n• Servicios específicos que requieres\n• Número de usuarios\n\n¿Qué servicio te interesa? (CONTPAQi, Nube, Rediseño, Capacitación)",
                'context' => 'START',
            ],
            [
                'keys' => ['ubicacion', 'direccion', 'donde', 'oficina'],
                'response' => "📍 *Nuestra ubicación:*\n\n[Tu dirección completa aquí]\n\nSi requieres una visita presencial o consultoría en sitio, escribe *'Cita'* para coordinar.",
                'context' => 'INFO',
            ],
            [
                'keys' => ['contacto', 'telefono', 'llamar'],
                'response' => "📞 *Contáctanos:*\n\nTeléfono: [Tu número]\nHorario: Lunes a Viernes 9:00 AM - 6:00 PM\n\n¿Prefieres que te llamemos nosotros? Escribe *'Sí'* y tu nombre completo.",
                'context' => 'INFO',
            ],
            [
                'keys' => ['gracias', 'adios', 'bye', 'hasta luego'],
                'response' => "¡Gracias a ti! 🙏\n\nEstamos aquí para blindar tu operación 24/7.\n\nSi necesitas algo más, solo escríbeme. ¡Hasta pronto! 🚀",
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
    // MANEJO DE MENSAJES DESCONOCIDOS
    // ============================================
    
    private function handleUnknownMessage($chat, $from, $text)
    {
        $this->sendMessage($from, "🤔 Disculpa, no estoy seguro de entender.\n\nPuedes escribir:\n\n• *'CONTPAQi'* - Sistemas administrativos\n• *'Nube'* - Escritorios virtuales\n• *'Rediseño'* - Blindaje fiscal\n• *'Capacitación'* - Cursos\n• *'Soporte'* - Ayuda técnica\n• *'Asesor'* - Hablar con ejecutivo\n• *'Menú'* - Ver opciones principales");
        
        return response()->json(['status' => 'ok']);
    }

    // ============================================
    // COMANDOS DE AGENTES
    // ============================================
    
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

    // ============================================
    // UTILIDADES
    // ============================================
    
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