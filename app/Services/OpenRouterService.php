<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    private $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key');
    }

    public function generateContextualResponse(array $conversationHistory, string $currentMessage, string $context = 'INITIAL')
    {
        try {
            $systemPrompt = $this->buildSystemPrompt($context);
            
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt]
            ];
            
            foreach (array_slice($conversationHistory, -5) as $msg) {
                $messages[] = [
                    'role' => $msg['sender'] === 'user' ? 'user' : 'assistant',
                    'content' => $msg['message']
                ];
            }
            
            $messages[] = ['role' => 'user', 'content' => $currentMessage];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'HTTP-Referer' => url('/'),
            ])->timeout(30)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => 'meta-llama/llama-3.1-8b-instruct:free', // Modelo gratuito
                'messages' => $messages,
                'max_tokens' => 150,
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                $text = $result['choices'][0]['message']['content'] ?? '';
                return $this->cleanResponse($text);
            }

            Log::error('❌ Error en OpenRouter API', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('❌ Excepción en OpenRouterService', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function buildSystemPrompt(string $context): string
    {
        return "Eres un asistente virtual de Tecnología Empresarial en México. " .
               "Ofreces CONTPAQi, servicios en la nube, rediseño fiscal, capacitación y soporte. " .
               "Responde en español de México, máximo 3 líneas, amigable y profesional.";
    }

    private function cleanResponse(string $response): string
    {
        $response = trim($response);
        if (strlen($response) > 500) {
            $response = substr($response, 0, 497) . '...';
        }
        return $response ?: null;
    }

    public function classifyIntent(string $message): ?string
    {
        // Mismo código que HuggingFaceService
        $message = strtolower($message);
        $keywords = [
            'CONTPAQI' => ['contpaqi', 'sistema', 'contabilidad', 'nomina'],
            'NUBE' => ['nube', 'cloud', 'virtual', 'remoto'],
            'REDISEÑO' => ['rediseño', 'fiscal', 'sat', 'auditoria'],
            'CAPACITACION' => ['capacitacion', 'curso', 'taller'],
            'SOPORTE' => ['soporte', 'ayuda', 'error', 'falla'],
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

    public function extractKeyInfo(string $message): array
    {
        // Mismo código que HuggingFaceService
        $info = [];
        if (preg_match('/(\d+)\s*(usuario|empleado)/i', $message, $matches)) {
            $info['num_usuarios'] = (int)$matches[1];
        }
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $message, $matches)) {
            $info['email'] = $matches[0];
        }
        return array_filter($info);
    }
}