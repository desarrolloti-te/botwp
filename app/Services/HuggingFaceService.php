<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HuggingFaceService
{
    private $apiKey;
    private $model;
    private $apiUrl;

    public function __construct()
    {
        $this->apiKey = config('services.huggingface.api_key');
        $this->model = config('services.huggingface.model');
        // Nueva URL de Hugging Face Router
        $this->apiUrl = "https://router.huggingface.co/hf-inference/models/{$this->model}";
    }

    /**
     * Genera una respuesta contextual usando el historial de conversación
     * 
     * @param array $conversationHistory Historial de mensajes
     * @param string $currentMessage Mensaje actual del usuario
     * @param string $context Contexto actual (CONTPAQI, NUBE, etc.)
     * @return string|null Respuesta generada o null si falla
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
                'prompt_length' => strlen($fullPrompt),
                'context' => $context
            ]);
$apiKey = config('services.huggingface.api_key');
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->timeout(60)->post('https://router.huggingface.co/models/mistralai/gpt2', [
                'inputs' => 'Hola, ¿qué servicios ofrecen?',
                'parameters' => ['max_new_tokens' => 100],
                'options' => ['wait_for_model' => true]
            ]);

    
            echo "nGPT-2 Status: " . $response->status() . "\n";
            if ($response->successful()) {
                print_r($response->json());
            } else {
                echo $response->body() . "\n";
            }

            // if ($response->successful()) {
            //     $result = $response->json();
            //     $generatedText = $result[0]['generated_text'] ?? '';
                
            //     // Limpiar y formatear la respuesta
            //     $cleanResponse = $this->cleanResponse($generatedText);
                
            //     Log::info('✅ Respuesta IA generada', [
            //         'response' => $cleanResponse
            //     ]);

            //     return $cleanResponse;
            // }

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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Construye el prompt del sistema según el contexto
     */
    private function buildSystemPrompt(string $context): string
    {
        $basePrompt = "Eres un asistente virtual profesional de Tecnología Empresarial, empresa mexicana especializada en:\n" .
                     "- CONTPAQi (sistemas administrativos: Contabilidad, Nóminas, Comercial, Bancos)\n" .
                     "- Escritorios virtuales en la nube (acceso remoto 24/7)\n" .
                     "- Rediseño empresarial y blindaje fiscal (protección ante SAT)\n" .
                     "- Capacitación empresarial certificada STPS\n" .
                     "- Soporte técnico especializado\n\n" .
                     "IMPORTANTE:\n" .
                     "- Respuestas CORTAS (máximo 3-4 líneas)\n" .
                     "- Usa español de México, tono amigable y profesional\n" .
                     "- Usa emojis moderadamente (1-2 por mensaje)\n" .
                     "- Si no sabes algo específico, sugiere hablar con un asesor\n" .
                     "- Dirige sutilmente hacia agendar citas o cotizaciones\n" .
                     "- NUNCA inventes precios o datos técnicos\n\n";

        $contextPrompts = [
            'CONTPAQI' => "CONTEXTO: El usuario está interesado en CONTPAQi. Enfócate en beneficios, módulos disponibles y casos de uso. Pregunta qué módulo específico necesita.",
            'NUBE' => "CONTEXTO: El usuario pregunta sobre escritorios virtuales en la nube. Destaca: acceso remoto, seguridad, respaldos automáticos, ahorro en hardware.",
            'REDISEÑO' => "CONTEXTO: El usuario está interesado en rediseño empresarial y blindaje fiscal. Habla de materialidad, trazabilidad, prevención de auditorías SAT.",
            'CAPACITACION' => "CONTEXTO: El usuario pregunta sobre capacitación. Menciona cursos disponibles, certificación STPS, modalidades (presencial/virtual).",
            'SOPORTE' => "CONTEXTO: El usuario necesita soporte técnico. Sé empático, práctico y ofrece soluciones rápidas o conectar con un técnico.",
            'INITIAL' => "CONTEXTO: Primera interacción. El usuario está conociendo nuestros servicios. Sé acogedor y explora qué necesita.",
        ];

        return $basePrompt . ($contextPrompts[$context] ?? $contextPrompts['INITIAL']);
    }

    /**
     * Construye el texto de conversación para el modelo
     */
    private function buildConversationText(array $history, string $currentMessage): string
    {
        $text = "Historial de conversación:\n";
        
        // Incluir los últimos 6 mensajes para contexto
        $recentHistory = array_slice($history, -6);
        
        foreach ($recentHistory as $msg) {
            $sender = $msg['sender'] === 'user' ? 'Cliente' : 'Asistente';
            $message = $msg['message'] ?? '';
            $text .= "{$sender}: {$message}\n";
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
        
        // Remover prefijos comunes que el modelo a veces añade
        $response = preg_replace('/^(Asistente:|Cliente:|Bot:|Respuesta:)\s*/i', '', $response);
        
        // Remover saltos de línea excesivos
        $response = preg_replace('/\n{3,}/', "\n\n", $response);
        
        // Limitar longitud máxima (WhatsApp tiene límites)
        if (strlen($response) > 600) {
            $response = substr($response, 0, 597) . '...';
        }
        
        // Si la respuesta está vacía o es muy corta, devolver null
        if (strlen($response) < 10) {
            return null;
        }
        
        // Asegurar que termine con puntuación
        if (!preg_match('/[.!?]$/', $response)) {
            $response .= '.';
        }
        
        return $response;
    }

    /**
     * Clasifica la intención del usuario usando keywords
     * Útil para routing antes de usar el modelo
     * 
     * @param string $message Mensaje del usuario
     * @return string|null Contexto detectado o null
     */
    public function classifyIntent(string $message): ?string
    {
        $message = strtolower($message);
        
        $keywords = [
            'CONTPAQI' => ['contpaqi', 'conpaq', 'sistema', 'administrativo', 'contabilidad', 'nomina', 'facturacion', 'comercial', 'inventario', 'erp'],
            'NUBE' => ['nube', 'cloud', 'escritorio', 'virtual', 'remoto', 'servidor', 'hosting'],
            'REDISEÑO' => ['rediseño', 'rediseno', 'blindaje', 'fiscal', 'sat', 'auditoria', 'materialidad', 'cumplimiento'],
            'CAPACITACION' => ['capacitacion', 'capacitación', 'curso', 'taller', 'aprender', 'certificacion', 'stps', 'entrenar'],
            'SOPORTE' => ['soporte', 'ayuda', 'problema', 'error', 'falla', 'ticket', 'urgente', 'técnico', 'tecnico'],
            'PRECIO' => ['precio', 'costo', 'cuanto', 'cotizacion', 'cotización', 'presupuesto', 'inversión'],
            'INFO' => ['quienes', 'empresa', 'servicio', 'ofrecen', 'ustedes', 'información'],
        ];

        foreach ($keywords as $intent => $words) {
            foreach ($words as $word) {
                if (str_contains($message, $word)) {
                    Log::info("🎯 Intent detectado: {$intent} por keyword: {$word}");
                    return $intent;
                }
            }
        }

        return null;
    }

    /**
     * Extrae información clave del mensaje del usuario
     * Útil para autocompletar formularios
     * 
     * @param string $message Mensaje del usuario
     * @return array Información extraída
     */
    public function extractKeyInfo(string $message): array
    {
        $info = [];

        // Extraer números (usuarios/empleados)
        if (preg_match('/(\d+)\s*(usuario|empleado|persona|trabajador)/i', $message, $matches)) {
            $num = (int)$matches[1];
            $tipo = strtolower($matches[2]);
            
            if (str_contains($tipo, 'usuario')) {
                $info['num_usuarios'] = $num;
            } elseif (str_contains($tipo, 'empleado') || str_contains($tipo, 'trabajador')) {
                $info['num_empleados'] = $num;
            } else {
                $info['num_personas'] = $num;
            }
        }

        // Extraer email
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $message, $matches)) {
            $info['email'] = strtolower($matches[0]);
        }

        // Extraer teléfono (10 dígitos)
        if (preg_match('/\b(\d{10})\b/', $message, $matches)) {
            $info['telefono'] = $matches[1];
        }

        // Extraer nombre de empresa (palabras con mayúsculas seguidas)
        if (preg_match('/\b([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+)+)\b/', $message, $matches)) {
            $info['empresa'] = $matches[1];
        }

        // Detectar números simples (para preguntas como "¿cuántos usuarios?")
        if (preg_match('/\b(\d{1,3})\b/', $message, $matches) && !isset($info['num_usuarios']) && !isset($info['telefono'])) {
            $info['numero'] = (int)$matches[1];
        }

        return array_filter($info);
    }

    /**
     * Genera un resumen de la conversación
     * Útil para reportes de agentes
     */
    public function summarizeConversation(array $conversationHistory): string
    {
        if (empty($conversationHistory)) {
            return "Sin conversación previa.";
        }

        $messages = array_slice($conversationHistory, -10);
        $text = "Resumen de conversación:\n\n";
        
        foreach ($messages as $msg) {
            $sender = $msg['sender'] === 'user' ? '👤 Cliente' : '🤖 Bot';
            $text .= "{$sender}: {$msg['message']}\n";
        }

        return $text;
    }

    /**
     * Verifica si el modelo está disponible
     */
    public function checkModelStatus(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->timeout(10)->get($this->apiUrl);

            if ($response->successful()) {
                return [
                    'status' => 'ready',
                    'message' => 'Modelo disponible'
                ];
            }

            if ($response->status() === 503) {
                $error = $response->json();
                return [
                    'status' => 'loading',
                    'message' => 'Modelo cargando...',
                    'estimated_time' => $error['estimated_time'] ?? null
                ];
            }

            return [
                'status' => 'error',
                'message' => 'Error de conexión'
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}