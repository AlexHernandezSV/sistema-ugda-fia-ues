<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión - Tesis</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="m-0 font-family-sans surface-ground">
    
    <div id="app" class="flex flex-column min-h-screen">
        
        <div class="surface-overlay py-3 px-5 shadow-2 flex align-items-center justify-content-between relative z-2">
            <span class="text-900 font-medium text-xl">Mi Sistema UES</span>
            <p-button icon="pi pi-user" outlined aria-label="User" class="p-button-rounded"></p-button>
        </div>

        <div class="p-5 flex-grow-1 flex align-items-center justify-content-center">
            <div class="surface-card p-4 shadow-2 border-round w-full lg:w-6">
                <div class="text-center mb-5">
                    <div class="text-900 text-3xl font-medium mb-3">Bienvenido</div>
                    <span class="text-600 font-medium line-height-3">Sistema configurado con Laravel + PrimeVue + PrimeFlex</span>
                </div>

                <p-button label="Ingresar al Sistema" icon="pi pi-check" class="w-full"></p-button>
            </div>
        </div>

    </div>

</body>
</html>