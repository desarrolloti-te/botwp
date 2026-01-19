<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

//models
use App\Models\Chat;
use App\Models\Message;


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

        $chat = Chat::firstOrCreate(
            ['user_number' => $from],
            ['status' => 'open']
        );

        $isHumanRequest = in_array($text, ['asesor', 'humano', 'agente']);

        Message::create([
            'chat_id' => $chat->id,
            'message' => $text,
            'type' => 'user',
            'requires_human' => $isHumanRequest
        ]);

        if ($isHumanRequest) {
            $this->sendMessage($from,
                "👨‍💻 Un asesor humano fue notificado.\nEn breve te atenderemos."
            );
            return response()->json(['status' => 'ok']);
        }

        if($text === '/agente' && in_array($from, config('services.whatsapp.agent_numbers'))) {
            // Obtener todos los mensajes pendientes
            $pending = \App\Models\Message::where('requires_human', true)
                ->where('handled', false)
                ->with('chat')
                ->get();

            $response = "📋 Consultas pendientes:\n\n";

            foreach($pending as $msg){
                $response .= "ID: {$msg->id}\n";
                $response .= "Usuario: {$msg->chat->user_number}\n";
                $response .= "Mensaje: {$msg->message}\n\n";
            }

            $this->sendMessage($from, $response ?: "No hay consultas pendientes.");

            return response()->json(['status'=>'ok']);
        }

        if (
            str_starts_with($text, '/responder') &&
            in_array($from, config('services.whatsapp.agent_numbers'))
        ) {

            preg_match('/^\/responder (\d+) (.+)$/s', $text, $matches);

            if (count($matches) !== 3) {
                $this->sendMessage($from, "❌ Usa: /responder <ID> <mensaje>");
                return response()->json(['status'=>'ok']);
            }

            [, $msgId, $replyText] = $matches;

            $msg = Message::with('chat')->find($msgId);

            if (!$msg) {
                $this->sendMessage($from, "❌ Mensaje no encontrado.");
                return response()->json(['status'=>'ok']);
            }

            // Guardar respuesta del agente
            Message::create([
                'chat_id' => $msg->chat->id,
                'message' => $replyText,
                'type' => 'agent',
                'handled' => true
            ]);

            // Marcar mensaje original como atendido
            $msg->update(['handled' => true]);

            // Enviar mensaje al usuario
            $this->sendMessage($msg->chat->user_number, $replyText);

            $this->sendMessage($from,
                "✅ Respuesta enviada al usuario {$msg->chat->user_number}"
            );

            return response()->json(['status'=>'ok']);
        }


        // match ($text) {
        //     'hola' => $this->sendMessage($from, '¡Hola! 👋 ¿En qué puedo ayudarte?'),
        //     'info' => $this->sendMessage($from, 'Somos una empresa que ofrece servicios.'),
        //     default => $this->sendMessage($from, 'No entendí tu mensaje 😅. Escribe *hola* o *info*.'),
        // };

        $item = $this->findResponseInCatalog($text);

        switch ($item['type']) {
            case 'text':
                $this->sendMessage($from, $item['response']);
                break;
            case 'image':
                $this->sendImage($from, $item['url'], $item['caption'] ?? null);
                break;
            case 'video':
                $this->sendVideo($from, $item['url'], $item['caption'] ?? null);
                break;
            case 'document':
                $this->sendDocument($from, $item['url'], $item['filename'] ?? null);
                break;
            default:
                $this->sendMessage($from, "👋 No entendí tu mensaje.");
        }

        return response()->json(['status' => 'ok']);
    }

    private function sendMessage(string $to, string $message): void
    {
        //  $chat = Chat::where('user_number', $to)->first();

        // if ($chat) {
        //     Message::create([
        //         'chat_id' => $chat->id,
        //         'message' => $message,
        //         'type' => 'bot',
        //         'handled' => true
        //     ]);
        // }
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

    private function findResponseInCatalog(string $input): array
    {
        
        $catalog = [
            // GREETING
            [
                'keys' => ['hola', 'inicio', 'buenos', 'buenas', 'menu', 'empezar'],
                'type' => 'text',
                'response' => "¡Hola! 👋 Bienvenido a *Tecnología Empresarial*.\nSomos Arquitectos de Evidencia Operativa.\n\n¿En qué podemos ayudarte hoy?\n1️⃣ *Rediseño 360°* (Blindaje Fiscal)\n2️⃣ *CONTPAQi* (Nube y Licencias)\n3️⃣ *Capacitación* (Cursos STPS)\n4️⃣ *Soporte Técnico*\n\n_Escribe el número o el tema que te interese._"
            ],
            [
                'keys' => ['gracias', 'adios', 'bye', 'hasta luego'],
                'type' => 'text',
                'response' => "¡Gracias a ti! Estamos para blindar tu operación. Si necesitas algo más, aquí seguiremos. 🛡️"
            ],
            [
                'keys' => ['ubicacion', 'direccion', 'donde estan', 'oficina'],
                'type' => 'text',
                'response' => "📍 Nos encontramos listos para atenderte. Si requieres una visita presencial o consultoría en sitio, por favor escribe *'Cita'* para coordinar con un asesor."
            ],

            // --- GRUPO 2: REDISEÑO Y BLINDAJE ---
            [
                'keys' => ['rediseño', 'rediseno', 'blindaje', 'reingenieria'],
                'type' => 'text',
                'response' => "🛡️ Nuestro *Rediseño 360°* no es solo software. Reestructuramos tus procesos administrativos para garantizar la *Materialidad* y *Razón de Negocios* que exige el SAT.\n\n¿Te gustaría agendar un diagnóstico de vulnerabilidad?"
            ],
            [
                'keys' => ['reforma', '2026', 'fiscal', 'sat', 'hacienda'],
                'type' => 'text',
                'response' => "⚠️ *Alerta 2026:* La fiscalización será inteligente. Lo que no está documentado digitalmente, no existe.\nTe ayudamos a generar la evidencia operativa necesaria para evitar multas. Escribe *'Diagnóstico'* para empezar."
            ],
            [
                'keys' => ['materialidad', 'razon de negocio', 'evidencia'],
                'type' => 'text',
                'response' => "La *Materialidad* es la clave para deducir impuestos hoy. Nosotros alineamos tu operación (compras, ventas, inventarios) para que cada movimiento genere su evidencia automática. ¿Quieres saber cómo?"
            ],
            [
                'keys' => ['auditoria', 'revision', 'multa', 'miedo', 'sancion'],
                'type' => 'text',
                'response' => "No esperes a la notificación. 🛑 Nuestro servicio preventivo detecta inconsistencias antes que la autoridad. Actuamos como un escudo fiscal mediante tecnología y procesos."
            ],

            // --- GRUPO 3: CONTPAQI Y NUBE ---
            [
                'keys' => ['contpaqi', 'sistema', 'programa', 'software'],
                'type' => 'text',
                'response' => "Somos *Socios Máster* con 30 años de experiencia. 🏅 Implementamos, configuramos y damos soporte a toda la suite CONTPAQi.\n¿Buscas una licencia nueva o renovar?"
            ],
            [
                'keys' => ['nube', 'escritorio', 'virtual', 'remoto', 'vdi'],
                'type' => 'text',
                'response' => "☁️ *¡Lleva tu oficina a cualquier lugar!* Con nuestros Escritorios Virtuales olvídate de servidores físicos, fallas de luz y mantenimientos. Tu información segura y respaldada diariamente. ¿Te interesa ver los paquetes?"
            ],
            [
                'keys' => ['contabilidad', 'contable'],
                'type' => 'text',
                'response' => "*CONTPAQi Contabilidad* es el líder fiscal. Nosotros no solo lo instalamos, te enseñamos a usarlo para generar reportes financieros reales, no solo para cumplir. 📊"
            ],
            [
                'keys' => ['nominas', 'nomina', 'empleados'],
                'type' => 'text',
                'response' => "Gestiona tu capital humano sin errores. *CONTPAQi Nóminas* cumple con todas las leyes laborales vigentes. ¿Necesitas ayuda con timbrado o cálculo?"
            ],
            [
                'keys' => ['comercial', 'facturacion', 'factura', 'inventario'],
                'type' => 'text',
                'response' => "Controla inventarios, cuentas por cobrar y facturación al día con *CONTPAQi Comercial*. Ideal para integrar tu operación administrativa. 📦"
            ],
            [
                'keys' => ['bancos', 'tesoreria', 'flujo'],
                'type' => 'text',
                'response' => "Conecta tus bancos con tu contabilidad automáticamente. Evita la talacha manual y ten tu flujo de efectivo al día con *CONTPAQi Bancos*. 💸"
            ],

            // --- GRUPO 4: CAPACITACIÓN ---
            [
                'keys' => ['capacitacion', 'curso', 'aprender', 'enseñar', 'taller'],
                'type' => 'text',
                'response' => "🎓 El software no comete errores, las personas sí. Ofrecemos capacitación para convertir a tu equipo en expertos operativos. ¿Buscas cursos para Contabilidad, Nóminas o Administración?"
            ],
            [
                'keys' => ['stps', 'certificado', 'constancia', 'diploma'],
                'type' => 'text',
                'response' => "Nuestros cursos tienen valor curricular y registro ante la *STPS*. Capacitación formal para profesionalizar a tu empresa y cumplir con la normativa laboral."
            ],

            // --- GRUPO 5: SOPORTE TÉCNICO ---
            [
                'keys' => ['soporte', 'ayuda', 'tecnico', 'fallando', 'apoyo'],
                'type' => 'text',
                'response' => "🛠️ Entendemos la urgencia. Para soporte técnico inmediato, por favor describe tu problema o envía una foto del error. Un ingeniero te atenderá en breve."
            ],
            [
                'keys' => ['error', 'no abre', 'lento', 'mensaje'],
                'type' => 'text',
                'response' => "Detectamos que tienes un problema técnico. ¿Es en servidor físico o en la Nube? Escribe *'Físico'* o *'Nube'* para orientarte mejor."
            ],
            [
                'keys' => ['actualizacion', 'version', 'actualizar'],
                'type' => 'text',
                'response' => "Mantenerse actualizado es obligatorio por el SAT. ¿Deseas cotizar la actualización a la última versión de tu sistema?"
            ],
            [
                'keys' => ['migracion', 'cambio', 'mover'],
                'type' => 'text',
                'response' => "¿Quieres mover tu información a un nuevo servidor o a la Nube? Somos expertos en migraciones sin pérdida de datos. 💾"
            ],

            // --- GRUPO 6: VENTAS Y CIERRE ---
            [
                'keys' => ['precio', 'costo', 'cuanto cuesta', 'cotizacion', 'valor'],
                'type' => 'text',
                'response' => "Cada empresa es única. Para darte un precio justo, necesitamos saber el número de usuarios y el tipo de servicio.\n\n¿Te gustaría hablar con un asesor comercial ahora?"
            ],
            [
                'keys' => ['comprar', 'adquirir', 'contratar', 'quiero'],
                'type' => 'text',
                'response' => "¡Excelente decisión! 🎉 Estás a un paso de blindar tu empresa. Por favor compártenos tu *Nombre* y *Correo* para enviarte la propuesta formal."
            ],
            [
                'keys' => ['cita', 'reunion', 'agendar', 'visita'],
                'type' => 'text',
                'response' => "🗓️ Claro, agendemos una sesión para analizar tus necesidades. ¿Prefieres cita presencial o videollamada?"
            ],
            [
                'keys' => ['diagnostico', 'analisis', 'evaluacion'],
                'type' => 'text',
                'response' => "Nuestro *Diagnóstico Operativo* revela tus riesgos fiscales actuales. Es el primer paso hacia el Rediseño. Escribe *'Si'* para coordinarlo."
            ],

            // --- GRUPO 7: SECTORES ---
            [
                'keys' => ['petrolero', 'energia', 'gas', 'petroleo'],
                'type' => 'text',
                'response' => "Tenemos amplia experiencia en el sector *Petrolero*. Sabemos manejar la complejidad de tus volúmenes de operación y requisitos fiscales específicos. 🛢️"
            ],
            [
                'keys' => ['construccion', 'obra', 'constructor'],
                'type' => 'text',
                'response' => "El sector *Construcción* requiere controles de obra precisos. Te ayudamos a integrar tus presupuestos con tu contabilidad para evitar desvíos. 🏗️"
            ],
            [
                'keys' => ['administrativo', 'servicios', 'despacho'],
                'type' => 'text',
                'response' => "Optimizamos empresas de *Servicios* para que la facturación y cobranza sean automáticas. Recupera tu tiempo y enfócate en tus clientes. ⏱️"
            ],

            // --- GRUPO 8: INFO EMPRESA ---
            [
                'keys' => ['quien eres', 'que hacen', 'nosotros', 'empresa'],
                'type' => 'text',
                'response' => "Somos *Tecnología Empresarial*. No somos simples distribuidores; somos consultores con 30 años de experiencia liderados por la L.C.P. Verónica De León. Organizamos tu negocio."
            ],
            [
                'keys' => ['veronica', 'dueña', 'fundadora', 'lcp'],
                'type' => 'text',
                'response' => "La *L.C.P. Verónica De León* es nuestra socia fundadora, especialista fiscal y creadora de metodologías de cálculo automático. Estás en manos expertas."
            ],
            [
                'keys' => ['telefono', 'llamar', 'celular', 'numero'],
                'type' => 'text',
                'response' => "📞 Puedes llamarnos al número 99. Nuestro horario es de 9:00 AM a 6:00 PM. ¿Prefieres que te llamemos nosotros?"
            ],

            [
                'keys' => ['foto', 'imagen', 'producto'],
                'type' => 'image',
                'url' => 'https://botwp.tecnologiaempresarial.mx/images/XPLUS.png',
                'caption' => '📸 Nuestro producto destacado'
            ],
            [
                'keys' => ['video', 'demostracion', 'demo'],
                'type' => 'video',
                'url' => 'https://botwp.tecnologiaempresarial.mx/videos/XPLUS.mp4',
                'caption' => '🎥 Mira cómo funciona nuestro servicio'
            ],
            [
                'keys' => ['catalogo', 'pdf', 'documento'],
                'type' => 'document',
                'url' => 'https://botwp.tecnologiaempresarial.mx/docs/XPLUS.pdf',
                'filename' => 'Catalogo2026.pdf'
            ],
            // AGREGAR AQUÍ EL RESTO DE LOS 30 MENSAJES DEL CATÁLOGO ARRIBA...
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
        return [
            'type' => 'text',
            'response' => "👋 No estoy seguro de cómo responder a eso, pero quiero ayudarte.\n\nPrueba escribiendo:\n- *'Rediseño'* para blindaje fiscal.\n- *'Nube'* para escritorios virtuales.\n- *'Asesor'* para hablar con un humano."
        ];
    }

    private function sendDocument(string $to, string $docUrl, string $filename = null): void
    {

        // $chat = Chat::where('user_number', $to)->first();

        // if ($chat) {
        //     Message::create([
        //         'chat_id' => $chat->id,
        //         'message' => $filename ?? '[Documento enviado]',
        //         'type' => 'bot',
        //         'handled' => true
        //     ]);
        // }
        
        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'document',
                'document' => [
                    'link' => $docUrl,
                    'filename' => $filename,
                ],
            ]);
    }
    private function sendVideo(string $to, string $videoUrl, string $caption = null): void
    {
        //  $chat = Chat::where('user_number', $to)->first();

        // if ($chat) {
        //     Message::create([
        //         'chat_id' => $chat->id,
        //         'message' => $caption ?? '[Video enviado]',
        //         'type' => 'bot',
        //         'handled' => true
        //     ]);
        // }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'video',
                'video' => [
                    'link' => $videoUrl,
                    'caption' => $caption,
                ],
            ]);
    }
    private function sendImage(string $to, string $imageUrl, string $caption = null): void
    {
        //  $chat = Chat::where('user_number', $to)->first();

        // if ($chat) {
        //     Message::create([
        //         'chat_id' => $chat->id,
        //         'message' => $caption ?? '[Imagen enviada]',
        //         'type' => 'bot',
        //         'handled' => true
        //     ]);
        // }

        Http::withToken(config('services.whatsapp.token'))
            ->post(config('services.whatsapp.url') . '/' . config('services.whatsapp.phone_id') . '/messages', [
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
