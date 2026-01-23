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
        .chat-layout {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* Sidebar */
        .chat-sidebar {
            width: 320px;
            background: #fff;
            border-right: 1px solid #ddd;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        /* Chat principal */
        .chat-main {
            flex: 1;
            overflow: hidden;
        }

        /* Botón menú */
        .menu-toggle {
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1050;
            background: #0d6efd;
            color: #fff;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
        }

        /* Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 1040;
        }

        /* MODO MÓVIL */
        @media (max-width: 768px) {
            .chat-layout {
                flex-direction: column;
            }

            .chat-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                transform: translateX(-100%);
                z-index: 1050;
            }

            .chat-sidebar.open {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .chat-main {
                width: 100%;
            }
        }
    </style>

    @yield('styles')
</head>

<body>
    <div class="chat-layout">

        <!-- Botón menú solo en móvil -->
        <button class="menu-toggle d-md-none" id="openMenu">
            ☰
        </button>

        <!-- Panel lateral -->
        <aside class="chat-sidebar" id="chatSidebar">
            @include('panelChats') {{-- tu panel --}}
        </aside>

        <!-- Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Contenido principal (chat) -->
        <main class="chat-main">
            @yield('content')
        </main>

    </div>

    @yield('scripts')
</body>

</html>
