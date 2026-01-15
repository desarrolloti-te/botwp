@extends('layouts.app')

@section('styles')
<style>
.chat-box {
    height:70vh;
    overflow-y:auto;
    background:#e5ddd5;
    padding:20px;
}
.msg {
    max-width:70%;
    padding:10px 14px;
    border-radius:8px;
    margin-bottom:10px;
    clear:both;
}
.msg.user {
    background:white;
    float:left;
}
.msg.agent {
    background:#dcf8c6;
    float:right;
}
.msg small {
    display:block;
    text-align:right;
    font-size:11px;
    opacity:.6;
}
</style>
@endsection

@section('content')
<a href="{{ route('agent.chats.index') }}" class="btn btn-sm btn-secondary mb-2">
    ← Volver
</a>

<div class="card">
    <div class="card-header">
        📞 {{ $chat->user_number }}
    </div>

    <div class="chat-box">
        @foreach($messages as $msg)
            <div class="msg {{ $msg->type }}">
                {{ $msg->message }}
                <small>{{ $msg->created_at->format('H:i') }}</small>
            </div>
        @endforeach
    </div>

    @if($canReply)
        <form method="POST" action="{{ route('agent.chats.send', $chat) }}">
            @csrf
            <div class="card-footer d-flex">
                <input type="text" name="message" class="form-control me-2" placeholder="Escribe un mensaje...">
                <button class="btn btn-success">Enviar</button>
            </div>
        </form>
    @else
        <div class="card-footer text-center text-danger">
            ⛔ Ventana de 24 horas cerrada (solo lectura)
        </div>
    @endif
</div>
@endsection
