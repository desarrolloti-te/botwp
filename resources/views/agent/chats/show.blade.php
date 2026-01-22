@extends('layouts.app')

@section('styles')
<style>
    /* Estilo de la barra de desplazamiento (más estético, tipo App) */
    .chat-box::-webkit-scrollbar {
        width: 6px;
    }
    .chat-box::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.05);
    }
    .chat-box::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.2);
        border-radius: 3px;
    }

    /* Contenedor principal de la tarjeta */
    .chat-card {
        height: 85vh; /* Altura fija importante para que el scroll funcione */
        display: flex;
        flex-direction: column;
        border: none;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    /* Área de mensajes */
    .chat-box {
        flex: 1; /* Ocupa todo el espacio disponible */
        overflow-y: auto; /* Activa el scroll vertical */
        background-color: #e5ddd5;
        background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');
        padding: 20px;
        /* IMPORTANTE: Quitamos scroll-behavior: smooth para que el salto inicial sea instantáneo */
    }

    .msg {
        max-width: 75%;
        padding: 10px 15px;
        border-radius: 8px;
        margin-bottom: 8px;
        position: relative;
        font-size: 15px;
        line-height: 1.4;
        box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        word-wrap: break-word; /* Evita que textos largos rompan el diseño */
    }

    .msg small {
        display: block;
        text-align: right;
        font-size: 10px;
        margin-top: 4px;
        opacity: 0.7;
    }

    /* Colores de mensajes */
    .msg.user { background: #ffffff; float: left; border-top-left-radius: 0; }
    .msg.bot { background: #dbf1ff; color: #004085; float: left; border-top-left-radius: 0; }
    .msg.agent { background: #d9fdd3; float: right; border-top-right-radius: 0; }

    .clearfix::after { content: ""; clear: both; display: table; }
</style>
@endsection

@section('content')
<div class="container py-2"> <div class="card chat-card"> 
        
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-2 border-bottom">
            <div class="d-flex align-items-center">
                <a href="{{ route('agent.chats.index') }}" class="btn btn-light btn-sm me-3 rounded-circle" title="Volver">
                    <i class="bi bi-arrow-left">←</i>
                </a>
                <div>
                    <h6 class="mb-0 fw-bold">📞 {{ $chat->user_number }}</h6>
                    <small style="font-size: 12px;">
                        @if($canReply) 
                            <span class="text-success">● En línea (Ventana abierta)</span>
                        @else 
                            <span class="text-danger">● Ventana cerrada (24h)</span>
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
                            <strong style="font-size:11px; display:block; margin-bottom:3px; color:#0056b3;">🤖 Bot</strong>
                        @endif
                        
                        {!! nl2br(e($msg->message)) !!}
                        <small>{{ $msg->created_at->setTimezone('America/Mexico_City')->format('H:i') }}</small>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card-footer bg-light py-3 border-top">
            @if($canReply)
                <form method="POST" action="{{ route('agent.chats.send', $chat) }}" id="chatForm">
                    @csrf
                    <div class="input-group">
                        <input type="text" name="message" class="form-control border-0 p-3 shadow-sm" placeholder="Escribe un mensaje..." required autocomplete="off" autofocus>
                        <button class="btn btn-success px-4 shadow-sm">
                            Enviar ➤
                        </button>
                    </div>
                </form>
            @else
                <div class="alert alert-secondary mb-0 text-center py-2" style="font-size: 14px;">
                    🔒 <strong>Sesión finalizada:</strong> Pasaron 24h desde el último mensaje del cliente.
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
        
        // FUNCIÓN CLAVE: Salto instantáneo al final
        function jumpToBottom() {
            if(chatBox) {
                // scrollTop = scrollHeight fuerza al navegador a ir al píxel más bajo
                chatBox.scrollTop = chatBox.scrollHeight;
            }
        }
        
        // 1. Ejecutar inmediatamente al cargar el DOM
        jumpToBottom();

        // 2. Ejecutar de nuevo una fracción de segundo después por si cargan imágenes/fuentes
        // Esto asegura que si algo empuja el contenido, el scroll se corrija
        setTimeout(jumpToBottom, 100);

        // Auto-focus al input
        const input = document.querySelector('input[name="message"]');
        if(input) input.focus();
    });
</script>
@endsection