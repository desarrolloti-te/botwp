<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tecnología Empresarial · Panel WhatsApp</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:#f0f2f5;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        
        /* Sidebar de Escritorio (Desktop) */
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
            margin-bottom: 5px;
        }
        .sidebar a:hover {
            background:#0f4d5c;
        }
        .brand {
            font-weight:700;
            font-size:18px;
            padding:16px;
            border-bottom:1px solid rgba(255,255,255,.1);
            margin-bottom: 20px;
        }

        /* Estilos específicos para Móvil */
        .mobile-header {
            background: #0b3c49;
            color: white;
            padding: 10px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        /* Reutilizamos estilos del sidebar para el menú offcanvas móvil */
        .offcanvas-body {
            background: #0b3c49; 
        }
        .offcanvas-header {
            background: #09323d;
            color: white;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .content {
            padding:20px;
        }
        
        /* Ajuste para que el contenido no quede pegado en móvil */
        @media (max-width: 768px) {
            .content {
                padding: 10px;
            }
        }
    </style>

    @yield('styles')
</head>
<body>

<div class="mobile-header d-md-none sticky-top shadow-sm">
    <span class="fw-bold"><i class="bi bi-shield-check"></i> Tec. Empresarial</span>
    <button class="btn btn-outline-light btn-sm border-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu">
        <i class="bi bi-list fs-4"></i>
    </button>
</div>

<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title fw-bold" id="mobileMenuLabel">Menú</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body sidebar p-3">
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
</div>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-2 sidebar d-none d-md-block">
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

        <div class="col-md-10 content col-12">
            @yield('content')
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

@yield('scripts')
</body>
</html>