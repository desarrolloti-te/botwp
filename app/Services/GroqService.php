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
        $this->apiKey = config('services.groq.api_key'); // Nuevo config
        $this->model = config('services.groq.model', 'llama-3.1-8b-instant');
        $this->apiUrl = "https://api.groq.com/openai/v1/chat/completions";
    }

    /**
     * Genera respuesta contextual usando historial de conversación
     */
    public function generateContextualResponse(array $conversationHistory, string $currentMessage, string $context = 'INITIAL')
    {
        try {
            $systemPrompt = $this->buildSystemPrompt($context);
            $conversationText = $this->buildConversationText($conversationHistory, $currentMessage);

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $conversationText]
            ];

            Log::info('🤖 Enviando prompt a Groq', [
                'model' => $this->model,
                'prompt_length' => strlen($conversationText),
                'context' => $context
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => 120,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $generatedText = $result['choices'][0]['message']['content'] ?? null;
                $cleanResponse = $this->cleanResponse($generatedText);

                Log::info('✅ Respuesta IA generada', [
                    'response' => $cleanResponse
                ]);

                return $cleanResponse;
            }

            Log::error('❌ Error en Groq API', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('❌ Excepción en GroqService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function buildSystemPrompt(string $context): string
    {
        $basePrompt = "Eres un asistente virtual profesional de Tecnología Empresarial, empresa mexicana especializada en:\n" .
                     "- CONTPAQi (Contabilidad, Nóminas, Comercial, Bancos)\n" .
                     "- Escritorios virtuales en la nube\n" .
                     "- Rediseño empresarial y blindaje fiscal\n" .
                     "- Capacitación empresarial certificada STPS\n" .
                     "- Soporte técnico especializado\n\n" .
                     "IMPORTANTE:\n" .
                     "- Respuestas CORTAS (máx 3-4 líneas)\n" .
                     "- Español de México, amigable y profesional\n" .
                     "- Usa emojis moderadamente (1-2 por mensaje)\n" .
                     "- Si no sabes algo, sugiere hablar con un asesor\n" .
                     "- Dirige sutilmente hacia agendar citas o cotizaciones\n" .
                     "- NUNCA inventes precios o datos técnicos\n\n";

        $contextPrompts = [
            'CONTPAQI' => "CONTEXTO: Usuario interesado en CONTPAQi. Enfócate en beneficios, módulos y casos de uso.",
            'NUBE' => "CONTEXTO: Usuario pregunta sobre escritorios virtuales en la nube.",
            'REDISEÑO' => "CONTEXTO: Usuario interesado en rediseño empresarial y blindaje fiscal.",
            'CAPACITACION' => "CONTEXTO: Usuario pregunta sobre capacitación. Menciona cursos, certificación y modalidades.",
            'SOPORTE' => "CONTEXTO: Usuario necesita soporte técnico. Sé empático y práctico.",
            'INITIAL' => "CONTEXTO: Primera interacción. Usuario conociendo servicios. Sé acogedor.",
        ];

        return $basePrompt . ($contextPrompts[$context] ?? $contextPrompts['INITIAL']);
    }

    private function buildConversationText(array $history, string $currentMessage): string
    {
        $text = "Historial de conversación:\n";
        $recentHistory = array_slice($history, -6);

        foreach ($recentHistory as $msg) {
            $sender = $msg['sender'] === 'user' ? 'Cliente' : 'Asistente';
            $text .= "{$sender}: {$msg['message']}\n";
        }

        $text .= "Cliente: {$currentMessage}\n";
        return $text;
    }

    private function cleanResponse(?string $response): ?string
    {
        if (!$response) return null;
        $response = trim($response);
        $response = preg_replace('/^(Asistente:|Cliente:|Bot:|Respuesta:)\s*/i', '', $response);
        $response = preg_replace('/\n{3,}/', "\n\n", $response);
        if (strlen($response) > 600) $response = substr($response, 0, 597) . '...';
        if (strlen($response) < 10) return null;
        if (!preg_match('/[.!?]$/', $response)) $response .= '.';
        return $response;
    }
}
