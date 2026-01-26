<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - UGDA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-custom bg-blue-50 m-0 p-0">
    <div id="app">
        <dashboard-view :user="{{ Auth::user() }}"></dashboard-view>
    </div>
</body>
</html>