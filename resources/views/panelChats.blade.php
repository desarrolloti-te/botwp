@extends('layouts.app')

@section('styles')
<style>
.chat-card {
    cursor:pointer;
    transition:.15s;
}
.chat-card:hover {
    background:#f1f5f9;
}
.chat-user {
    font-weight:600;
}
.chat-preview {
    font-size:13px;
    color:#6c757d;
}
.chat-time {
    font-size:11px;
    color:#999;
}
</style>
@endsection

@section('content')
<h4 class="mb-3">📲 Panel de Chats WhatsApp</h4>

<div class="row">
    <div class="col-md-4">
        <h6>🟢 Activos (24h)</h6>
        <div id="activos"></div>
    </div>

    <div class="col-md-4">
        <h6>👨‍💻 Requieren Humano</h6>
        <div id="humanos"></div>
    </div>

    <div class="col-md-4">
        <h6>⏰ Caducados</h6>
        <div id="caducados"></div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function renderChats(container, chats, highlight = false) {
    let html = '';

    chats.forEach(chat => {
        let msg = chat.messages[0]?.message ?? 'Sin mensajes';
        let time = chat.messages[0]?.created_at ?? '';
        let url = `/agent/chats/${chat.id}`;

        html += `
            <a href="${url}" class="text-decoration-none text-dark">
                <div class="card mb-2 chat-card ${highlight ? 'border-danger' : ''}">
                    <div class="card-body p-2">
                        <div class="d-flex justify-content-between">
                            <span class="chat-user">${chat.user_number}</span>
                            <span class="chat-time">${time.substring(11,16)}</span>
                        </div>
                        <div class="chat-preview">${msg}</div>
                    </div>
                </div>
            </a>
        `;
    });

    document.getElementById(container).innerHTML =
        html || '<small class="text-muted">Sin registros</small>';
}

function loadChats() {
    fetch("{{ route('agent.chats.data') }}")
        .then(res => res.json())
        .then(data => {
            renderChats('activos', data.activos_24h);
            renderChats('caducados', data.caducados);
            renderChats('humanos', data.requieren_humano, true);
        });
}

// primera carga
loadChats();

// polling cada 5 segundos
setInterval(loadChats, 5000);
</script>
@endsection
