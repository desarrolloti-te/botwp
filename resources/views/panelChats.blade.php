@extends('layouts.app')

@section('styles')
<style>
    .chat-card {
        cursor: pointer;
        transition: .2s;
        border-left: 4px solid transparent;
    }
    .chat-card:hover {
        background: #f8f9fa;
        transform: translateX(5px);
    }
    /* Indicadores de estado visuales */
    .status-active { border-left-color: #198754; } /* Verde */
    .status-expired { border-left-color: #6c757d; opacity: 0.8; } /* Gris */
    .status-human { border-left-color: #dc3545; background-color: #fff5f5; } /* Rojo */
    .badge-status { font-size: 0.75rem; padding: 4px 8px; border-radius: 12px; }
    .bg-human { background-color: #dc3545; color: white; }
    .chat-preview { font-size: 13px; color: #6c757d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
@endsection

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>💬 Panel de Conversaciones</h4>
        <button class="btn btn-primary btn-sm" onclick="loadChats()">🔄 Actualizar</button>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h6 class="m-0">Bandeja de Entrada</h6>
        </div>
        <div class="card-body p-0">
            <div id="chats-container" class="list-group list-group-flush">
                <div class="text-center p-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
function renderChats(chats) {
    let html = '';

    if (chats.length === 0) {
        document.getElementById('chats-container').innerHTML = '<div class="p-4 text-center text-muted">No hay conversaciones.</div>';
        return;
    }

    chats.forEach(chat => {
        let statusBadge = '';
        let rowClass = '';

        if (chat.status === 'human_required') {
            statusBadge = '<span class="badge bg-danger">🙋‍♂️ Requiere Humano</span>';
            rowClass = 'status-human';
        } else if (chat.status === 'active') {
            statusBadge = '<span class="badge bg-success">🟢 Activo</span>';
            rowClass = 'status-active';
        } else {
            statusBadge = '<span class="badge bg-secondary">⛔ Caducado</span>';
            rowClass = 'status-expired';
        }

        html += `
            <a href="/agent/chats/${chat.id}" class="list-group-item list-group-item-action chat-card ${rowClass} p-3">
                <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                    <h6 class="mb-0 fw-bold">📞 ${chat.user_number}</h6>
                    <small class="text-muted">${chat.time}</small>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <p class="mb-0 chat-preview text-truncate" style="max-width: 60%;">
                        ${chat.last_message}
                    </p>
                    <div>${statusBadge}</div>
                </div>
            </a>
        `;
    });

    document.getElementById('chats-container').innerHTML = html;
}

function loadChats() {
    fetch("{{ route('agent.chats.data') }}")
        .then(res => res.json())
        .then(data => {
            renderChats(data);
        })
        .catch(err => console.error(err));
}

loadChats();
setInterval(loadChats, 10000);
</script>
@endsection