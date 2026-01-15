<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tecnología Empresarial · Panel WhatsApp</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:#f0f2f5;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .sidebar {
            background:#0b3c49;
            color:white;
            min-height:100vh;
        }
        .sidebar a {
            color:#dceff5;
            text-decoration:none;
            display:block;
            padding:12px 16px;
            border-radius:6px;
        }
        .sidebar a:hover {
            background:#0f4d5c;
        }
        .brand {
            font-weight:700;
            font-size:18px;
            padding:16px;
            border-bottom:1px solid rgba(255,255,255,.1);
        }
        .content {
            padding:20px;
        }
    </style>

    @yield('styles')
</head>
<body>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar">
            <div class="brand">
                🛡 Tecnología Empresarial
            </div>
            <a href="{{ route('agent.chats.index') }}">
                <i class="bi bi-chat-dots"></i> Chats
            </a>
            <a href="#">
                <i class="bi bi-graph-up"></i> Métricas
            </a>
            <a href="#">
                <i class="bi bi-gear"></i> Configuración
            </a>
        </div>

        <!-- Contenido -->
        <div class="col-md-10 content">
            @yield('content')
        </div>
    </div>
</div>

@yield('scripts')
</body>
</html>
