@extends('layouts.app')

@section('styles')
<style>
    .chat-container {
        height: 75vh;
        display: flex;
        flex-direction: column;
    }
    
    .chat-box {
        flex: 1;
        overflow-y: auto;
        background-color: #e5ddd5;
        background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); /* Fondo tipo WhatsApp opcional */
        padding: 20px;
        scroll-behavior: smooth; /* Suavizar scroll manual */
    }

    .msg {
        max-width: 75%;
        padding: 10px 15px;
        border-radius: 12px;
        margin-bottom: 8px;
        position: relative;
        font-size: 15px;
        line-height: 1.4;
        box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        clear: both;
    }

    .msg small {
        display: block;
        text-align: right;
        font-size: 10px;
        margin-top: 4px;
        opacity: 0.7;
    }

    /* ESTILOS DE MENSAJES */
    
    /* 1. Usuario (Blanco - Izquierda) */
    .msg.user {
        background: #ffffff;
        float: left;
        border-top-left-radius: 0;
    }

    /* 2. Bot (Azul Claro - Izquierda) - TU PETICIÓN */
    .msg.bot {
        background: #dbf1ff; /* Azulito */
        color: #004085;
        float: left;
        border-top-left-radius: 0;
        border: 1px solid #b8daff;
    }

    /* 3. Agente Humano (Verde - Derecha) */
    .msg.agent {
        background: #d9fdd3; /* Verde WhatsApp */
        float: right;
        border-top-right-radius: 0;
    }

    .clearfix::after {
        content: "";
        clear: both;
        display: table;
    }
</style>
@endsection

@section('content')
<div class="container py-3">
    <div class="card shadow">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
            <div class="d-flex align-items-center">
                <a href="{{ route('agent.chats.index') }}" class="btn btn-light btn-sm me-3 rounded-circle">
                    <i class="fs-5">←</i>
                </a>
                <div>
                    <h5 class="mb-0">📞 {{ $chat->user_number }}</h5>
                    <small class="text-muted">
                        @if($canReply) 
                            <span class="text-success">● Disponible para responder</span>
                        @else 
                            <span class="text-danger">● Ventana 24h cerrada</span>
                        @endif
                    </small>
                </div>
            </div>
        </div>

        <div class="chat-box" id="chatBox">
            @foreach($messages as $msg)
                <div class="clearfix">
                    <div class="msg {{ $msg->type }}">
                        @if($msg->type == 'bot')
                            <strong style="font-size:11px; display:block; margin-bottom:3px;">🤖 Bot</strong>
                        @endif
                        
                        {!! nl2br(e($msg->message)) !!}
                        <small>{{ $msg->created_at->format('H:i') }}</small>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card-footer bg-light py-3">
            @if($canReply)
                <form method="POST" action="{{ route('agent.chats.send', $chat) }}" id="chatForm">
                    @csrf
                    <div class="input-group">
                        <input type="text" name="message" class="form-control border-0 p-3 shadow-sm" placeholder="Escribe un mensaje..." required autocomplete="off">
                        <button class="btn btn-success px-4 shadow-sm">
                            ➤ Enviar
                        </button>
                    </div>
                </form>
            @else
                <div class="alert alert-warning mb-0 text-center">
                    ⏳ <strong>Sesión Caducada:</strong> Han pasado más de 24 horas desde el último mensaje del usuario. Solo puedes responder usando Plantillas (Templates).
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const chatBox = document.getElementById('chatBox');
        
        // 1. SCROLL AUTOMÁTICO AL FONDO
        function scrollToBottom() {
            chatBox.scrollTop = chatBox.scrollHeight;
        }
        
        // Ejecutar al cargar
        scrollToBottom();

        // Opcional: Auto focus en el input
        const input = document.querySelector('input[name="message"]');
        if(input) input.focus();
    });
</script>
@endsection