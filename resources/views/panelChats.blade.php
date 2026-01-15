@extends('layouts.app')

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
        html += `
            <div class="card mb-2 ${highlight ? 'border-danger' : ''}">
            <a href="{{ route('agent.chats.show', $chat) }}" class="text-decoration-none">
                <div class="card-body p-2">
                    <strong>${chat.user_number}</strong><br>
                    <small class="text-muted">${msg}</small>
                </div>
            </div>
        `;
    });

    document.getElementById(container).innerHTML = html || '<small class="text-muted">Sin registros</small>';
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
