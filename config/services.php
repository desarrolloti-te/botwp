<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'huggingface' => [
        'api_key' => env('HUGGINGFACE_API_KEY'),
        // Modelos recomendados (ordenados por calidad):
        // 'model' => 'meta-llama/Llama-3.2-3B-Instruct', // Mejor para conversaciones naturales
        // 'model' => 'mistralai/Mistral-7B-Instruct-v0.3', // Alternativa excelente
        // 'model' => 'microsoft/Phi-3-mini-4k-instruct', // Más rápido, buena calidad
        'model' => env('HUGGINGFACE_MODEL', 'meta-llama/Llama-3.2-3B-Instruct'),
    ],
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_id' => env('WHATSAPP_PHONE_ID'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v24.0'),
        'agent_numbers' => [
        '5219933837315',//NUMERO DE TECNOLOGIA EMPRESARIAL
        '5219617116072',//NUMERO DE PRUEBA (ELIMINAR EN PRODUC)
        ],
    ],

];
