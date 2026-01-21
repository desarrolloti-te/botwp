<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HuggingFaceService
{
    private $apiKey;
    private $model;
    private $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('services.huggingface.api_key');
        $this->model = config('services.huggingface.model');
        $this->apiUrl = "https://api-inference.huggingface.co/models/{$this->model}";
    }

    /**
     * Genera una respuesta contextual usando el historial de conversación
     */
    public function generateContextualResponse(array $conversationHistory, string $currentMessage, string $context = 'INITIAL')
    {
        try {
            // Construir el prompt con contexto empresarial
            $systemPrompt = $this->buildSystemPrompt($context);
            $conversationText = $this->buildConversationText($conversationHistory, $currentMessage);
            
            $fullPrompt = "{$systemPrompt}\n\n{$conversationText}\n\nAsistente:";

            Log::info('🤖 Enviando prompt a Hugging Face', [
                'model' => $this->model,
                'prompt_length' => strlen($fullPrompt)
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->timeout(30)->post($this->apiUrl, [
                'inputs' => $fullPrompt,
                'parameters' => [
                    'max_new_tokens' => 250,
                    'temperature' => 0.7,
                    'top_p' => 0.9,
                    'do_sample' => true,
                    'return_full_text' => false,
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $generatedText = $result[0]['generated_text'] ?? '';
                
                // Limpiar y formatear la respuesta
                $cleanResponse = $this->cleanResponse($generatedText);
                
                Log::info('✅ Respuesta IA generada', [
                    'response' => $cleanResponse
                ]);

                return $cleanResponse;
            }

            // Si el modelo está cargando, esperar
            if ($response->status() === 503) {
                $error = $response->json();
                if (isset($error['estimated_time'])) {
                    Log::warning('⏳ Modelo cargando', ['tiempo_estimado' => $error['estimated_time']]);
                    return "Estoy procesando tu consulta, dame un momento por favor... ⏳";
                }
            }

            Log::error('❌ Error en Hugging Face API', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('❌ Excepción en HuggingFaceService', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Construye el prompt del sistema según el contexto
     */
    private function buildSystemPrompt(string $context): string
    {
        $basePrompt = "Eres un asistente virtual profesional de Tecnología Empresarial, empresa especializada en:\n" .
                     "- CONTPAQi (sistemas administrativos)\n" .
                     "- Escritorios virtuales en la nube\n" .
                     "- Rediseño empresarial y blindaje fiscal\n" .
                     "- Capacitación empresarial\n" .
                     "- Soporte técnico\n\n" .
                     "Diriges la conversación hacia nuestros servicios de forma natural y profesional.\n" .
                     "Respuestas CORTAS (máximo 3 líneas), amigables y en español de México.\n" .
                     "Si no sabes algo específico, sugiere hablar con un asesor especializado.\n\n";

        $contextPrompts = [
            'CONTPAQI' => "Contexto actual: El usuario está interesado en CONTPAQi. Mantén el enfoque en este tema.",
            'NUBE' => "Contexto actual: El usuario pregunta sobre escritorios virtuales en la nube. Enfócate en sus beneficios.",
            'REDISEÑO' => "Contexto actual: El usuario está interesado en rediseño empresarial y blindaje fiscal.",
            'CAPACITACION' => "Contexto actual: El usuario pregunta sobre capacitación empresarial.",
            'SOPORTE' => "Contexto actual: El usuario necesita soporte técnico. Sé empático y práctico.",
        ];

        return $basePrompt . ($contextPrompts[$context] ?? "El usuario está conociendo nuestros servicios.");
    }

    /**
     * Construye el texto de conversación para el modelo
     */
    private function buildConversationText(array $history, string $currentMessage): string
    {
        $text = "Conversación:\n";
        
        // Incluir los últimos 5 mensajes para contexto
        $recentHistory = array_slice($history, -5);
        
        foreach ($recentHistory as $msg) {
            $sender = $msg['sender'] === 'user' ? 'Cliente' : 'Asistente';
            $text .= "{$sender}: {$msg['message']}\n";
        }
        
        $text .= "Cliente: {$currentMessage}\n";
        
        return $text;
    }

    /**
     * Limpia y formatea la respuesta del modelo
     */
    private function cleanResponse(string $response): string
    {
        // Remover el prompt si el modelo lo incluye
        $response = trim($response);
        
        // Remover prefijos comunes
        $response = preg_replace('/^(Asistente:|Cliente:|Bot:)\s*/i', '', $response);
        
        // Limitar longitud máxima
        if (strlen($response) > 500) {
            $response = substr($response, 0, 497) . '...';
        }
        
        // Si la respuesta está vacía o es muy corta, devolver null
        if (strlen($response) < 10) {
            return null;
        }
        
        return $response;
    }

    /**
     * Clasifica la intención del usuario (útil para routing)
     */
    public function classifyIntent(string $message): ?string
    {
        $message = strtolower($message);
        
        $keywords = [
            'CONTPAQI' => ['contpaqi', 'sistema', 'contabilidad', 'nomina', 'facturacion', 'comercial', 'inventario'],
            'NUBE' => ['nube', 'cloud', 'escritorio virtual', 'remoto', 'servidor'],
            'REDISEÑO' => ['rediseño', 'blindaje', 'fiscal', 'sat', 'auditoria', 'materialidad'],
            'CAPACITACION' => ['capacitacion', 'curso', 'taller', 'aprender', 'certificacion'],
            'SOPORTE' => ['soporte', 'ayuda', 'problema', 'error', 'falla', 'ticket'],
            'PRECIO' => ['precio', 'costo', 'cuanto', 'cotizacion'],
            'INFO' => ['quienes', 'empresa', 'servicio', 'ofrecen'],
        ];

        foreach ($keywords as $intent => $words) {
            foreach ($words as $word) {
                if (str_contains($message, $word)) {
                    return $intent;
                }
            }
        }

        return null;
    }

    /**
     * Extrae información clave del mensaje del usuario
     */
    public function extractKeyInfo(string $message): array
    {
        $info = [
            'num_usuarios' => null,
            'num_empleados' => null,
            'email' => null,
            'phone' => null,
        ];

        // Extraer números (usuarios/empleados)
        if (preg_match('/(\d+)\s*(usuario|empleado|persona)/i', $message, $matches)) {
            $num = (int)$matches[1];
            if (str_contains(strtolower($matches[2]), 'usuario')) {
                $info['num_usuarios'] = $num;
            } else {
                $info['num_empleados'] = $num;
            }
        }

        // Extraer email
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $message, $matches)) {
            $info['email'] = $matches[0];
        }

        // Extraer teléfono
        if (preg_match('/\b\d{10}\b/', $message, $matches)) {
            $info['phone'] = $matches[0];
        }

        return array_filter($info);
    }
}