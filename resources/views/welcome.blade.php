<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sistema UGDA</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/primeicons/7.0.0/primeicons.min.css" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="m-0 font-family-sans" style="background-color: #f0f6ff;">
    <div id="app"></div>
</body>
</html>
