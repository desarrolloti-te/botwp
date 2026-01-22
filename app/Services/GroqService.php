<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqService
{
    private $apiKey;
    private $model;
    private $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key');
        $this->model = config('services.groq.model', 'llama-3.1-70b-versatile'); // Usamos un modelo más capaz para razonamiento
        $this->apiUrl = "https://api.groq.com/openai/v1/chat/completions";
    }

    public function generateContextualResponse(array $conversationHistory, string $currentMessage)
    {
        try {
            $systemPrompt = $this->getCompanyKnowledgeBase();
            $conversationText = $this->buildConversationText($conversationHistory, $currentMessage);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $conversationText]
            ];

            Log::info('🤖 Consultando a Groq...', ['message' => $currentMessage]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.6, // Creatividad moderada pero precisa
                'max_tokens' => 250,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $result['choices'][0]['message']['content'] ?? null;
            }

            Log::error('❌ Error Groq API', ['body' => $response->body()]);
            return null;

        } catch (\Exception $e) {
            Log::error('❌ Excepción GroqService', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildConversationText(array $history, string $currentMessage): string
    {
        // Formateamos el historial para que la IA entienda el contexto reciente
        $text = "HISTORIAL DE CONVERSACIÓN RECIENTE:\n";
        $recentHistory = array_slice($history, -5); // Últimos 5 mensajes para contexto

        foreach ($recentHistory as $msg) {
            $role = $msg['sender'] === 'user' ? 'Cliente' : 'Asistente';
            $text .= "{$role}: {$msg['message']}\n";
        }

        $text .= "\nMENSAJE ACTUAL DEL CLIENTE: {$currentMessage}\n";
        $text .= "\nINSTRUCCIÓN: Responde al cliente basándote en el SYSTEM PROMPT. Si detectas que quiere cotizar o hablar con un humano, indícalo sutilmente pero responde su duda primero.";
        
        return $text;
    }

    /**
     * Aquí cargamos el contenido del documento DOCX procesado
     */
    private function getCompanyKnowledgeBase(): string
    {
        return <<<EOT
ERES "TECNOBOT", EL CONSULTOR EXPERTO DE LA EMPRESA "TECNOLOGÍA EMPRESARIAL".
Tu objetivo no es solo responder, sino "alinear el propósito del cliente con soluciones tecnológicas" y generar valor.

INFORMACIÓN CLAVE DE LA EMPRESA (Tus conocimientos):
1. **Identidad**: Somos una consultora que digitaliza, automatiza y fortalece la administración de las PyMEs.
   - Tagline: "Digitalizamos, integramos y fortalecemos tu administración."
   - Ubicación: Villahermosa, Tabasco (atendemos sureste de México).
   - Experiencia: 30 años, Socios Máster Nivel Oro de CONTPAQi.

2. **Servicios Principales**:
   - **CONTPAQi**: Implementación, actualización y soporte de Contabilidad, Nóminas, Comercial y Bancos.
   - **Escritorios Virtuales (Nube)**: Solución estratégica para trabajo remoto seguro, servidores virtuales y respaldos automáticos.
   - **Rediseño Empresarial 360°**: No es solo software. Es consultoría, reingeniería de procesos y blindaje fiscal (materialidad, razón de negocios). Ideal para empresas que temen auditorías o quieren crecer.
   - **Capacitación**: Cursos certificados STPS (Excel, Fiscal, CONTPAQi).
   - **Soporte**: Atención rápida vía WhatsApp y Escritorio Remoto.

3. **Tono de Voz**:
   - Profesional pero cercano (Empático).
   - Consultivo: No solo vendes, educas. Explicas el "por qué" (ej. el riesgo fiscal de no tener procesos).
   - Seguro: Transmites autoridad técnica y confianza.

4. **Público Objetivo**: Dueños de PyMEs, Contadores y Administradores que valoran el orden y temen al SAT/multas. Buscan "paz mental" y eficiencia.

DIRECTRICES DE RESPUESTA:
- **Respuestas Concisas**: Máximo 3-4 oraciones por párrafo. Usa emojis profesionales (🚀, 📊, ✅) moderadamente.
- **Venta Consultiva**: Si preguntan precio, explica brevemente el valor antes de dar un rango o sugerir una cotización personalizada.
- **Rediseño Empresarial**: Si el cliente menciona "desorden", "auditoría" o "problemas administrativos", sugiere el servicio de Rediseño Empresarial como solución integral.
- **Llamadas a la Acción (CTA)**: Al final de tus respuestas, invita a dar el siguiente paso (ej. "¿Te gustaría ver una demo?", "¿Quieres que te envíe una propuesta formal?").

INSTRUCCIÓN ESPECIAL DE CONTROL:
Si el cliente dice explícitamente "quiero cotizar", "necesito precio exacto", "hablar con asesor" o "agendar cita", responde amablemente y agrega al final de tu mensaje la etiqueta oculta: [ACTION_REQUIRED].
EOT;
    }
}