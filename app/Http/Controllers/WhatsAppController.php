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

        // match ($text) {
        //     'hola' => $this->sendMessage($from, '¡Hola! 👋 ¿En qué puedo ayudarte?'),
        //     'info' => $this->sendMessage($from, 'Somos una empresa que ofrece servicios.'),
        //     default => $this->sendMessage($from, 'No entendí tu mensaje 😅. Escribe *hola* o *info*.'),
        // };

        $responseMessage = $this->findResponseInCatalog($text);

        $this->sendMessage($from, $responseMessage);

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

    private function findResponseInCatalog(string $input): string
    {
        // CATÁLOGO DE RESPUESTAS (Aquí pegas tus 30 mensajes)
        $catalog = [
            // GREETING
            ['keys' => ['hola', 'inicio', 'buenos', 'menu'], 'response' => "¡Hola! 👋 Bienvenido a *Tecnología Empresarial*.\nSomos Arquitectos de Evidencia Operativa.\n\n¿En qué podemos ayudarte?\n1️⃣ *Rediseño 360°* (Blindaje Fiscal)\n2️⃣ *CONTPAQi* (Nube y Licencias)\n3️⃣ *Capacitación* (Cursos)\n4️⃣ *Soporte Técnico*\n\n_Escribe el tema de tu interés._"],
            ['keys' => ['gracias', 'adios', 'bye'], 'response' => "¡Gracias a ti! Estamos para blindar tu operación. 🛡️"],
            
            // REDISEÑO & REFORMA (Prioridad Alta)
            ['keys' => ['rediseño', 'rediseno', 'blindaje'], 'response' => "🛡️ Nuestro *Rediseño 360°* estructura tus procesos para garantizar la *Materialidad* ante el SAT. ¿Te gustaría agendar un diagnóstico?"],
            ['keys' => ['reforma', '2026', 'fiscal', 'sat'], 'response' => "⚠️ *Alerta 2026:* La fiscalización será inteligente. Te ayudamos a generar la evidencia operativa para evitar multas. Escribe *'Diagnóstico'* para empezar."],
            ['keys' => ['materialidad', 'razon', 'evidencia'], 'response' => "La *Materialidad* es clave. Alineamos tu operación para que cada movimiento genere evidencia automática. ¿Quieres saber cómo?"],

            // CONTPAQi & NUBE
            ['keys' => ['nube', 'escritorio', 'virtual'], 'response' => "☁️ *¡Lleva tu oficina a cualquier lugar!* Olvídate de servidores físicos y fallas de luz. Tu info segura y respaldada. ¿Quieres ver paquetes?"],
            ['keys' => ['contpaqi', 'sistema', 'licencia'], 'response' => "Somos *Socios Máster* con 30 años de experiencia. 🏅 Implementamos y configuramos toda la suite. ¿Buscas licencia nueva o renovación?"],
            ['keys' => ['soporte', 'error', 'falla', 'ayuda'], 'response' => "🛠️ Entendemos la urgencia. Por favor describe tu problema técnico o envía foto del error. Un ingeniero te atenderá."],
            
            // CAPACITACIÓN
            ['keys' => ['curso', 'capacitacion', 'stps', 'aprender'], 'response' => "🎓 El software no comete errores, las personas sí. Ofrecemos capacitación certificada STPS. ¿Te interesa el catálogo?"],

            // VENTAS
            ['keys' => ['precio', 'costo', 'cotizacion', 'cuanto'], 'response' => "Cada empresa es única. Para darte un precio justo, necesitamos un diagnóstico rápido. ¿Te gustaría hablar con un asesor?"],
            ['keys' => ['humano', 'asesor', 'persona'], 'response' => "Entendido, transfiriendo con un especialista humano... 👨‍💻"],

            // AGREGAR AQUÍ EL RESTO DE LOS 30 MENSAJES DEL CATÁLOGO ARRIBA...
        ];

        // Recorremos el catálogo buscando coincidencias
        foreach ($catalog as $item) {
            foreach ($item['keys'] as $keyword) {
                // str_contains busca si la palabra clave está DENTRO del mensaje del usuario
                if (str_contains($input, $keyword)) {
                    return $item['response'];
                }
            }
        }

        // Respuesta por defecto (Default Fallback)
        return "👋 No estoy seguro de cómo responder a eso, pero quiero ayudarte.\n\nPrueba escribiendo:\n- *'Rediseño'* para blindaje fiscal.\n- *'Nube'* para escritorios virtuales.\n- *'Asesor'* para hablar con un humano.";
    }
}
