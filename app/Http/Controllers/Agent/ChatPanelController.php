<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use Illuminate\Http\Request;

class ChatPanelController extends Controller
{
     public function index()
    {
        $limit24 = now()->subHours(24);

        return response()->json([

            'activos_24h' => Chat::whereHas('messages', function ($q) use ($limit24) {
                $q->where('type', 'user')
                  ->where('created_at', '>=', $limit24);
            })
            ->with(['messages' => function ($q) {
                $q->latest()->limit(5);
            }])
            ->get(),

            'caducados' => Chat::whereHas('messages', function ($q) use ($limit24) {
                $q->where('type', 'user')
                  ->where('created_at', '<', $limit24);
            })
            ->with(['messages' => function ($q) {
                $q->latest()->limit(5);
            }])
            ->get(),

            'requieren_humano' => Message::where('requires_human', true)
                ->where('handled', false)
                ->with('chat')
                ->latest()
                ->get(),
        ]);
    }
}
