<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contrasena temporal</title>
</head>
<body style="font-family: Arial, sans-serif; color: #26384f; background: #f4f7fb; padding: 24px;">
    <div style="max-width: 620px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #dbe6f2;">
        <div style="background: #1560bd; color: #ffffff; padding: 22px 26px;">
            <h2 style="margin: 0; font-size: 22px;">Contrasena temporal UGDA</h2>
            <p style="margin: 8px 0 0; opacity: .9;">Sistema de Gestion Documental</p>
        </div>
        <div style="padding: 26px;">
            <p>Hola <strong>{{ $data['name'] }}</strong>,</p>
            <p>Un administrador restablecio tu contrasena de acceso. Usa la siguiente contrasena temporal para ingresar al sistema:</p>

            <div style="background: #eef5ff; border-left: 4px solid #1560bd; padding: 16px; margin: 20px 0;">
                <p style="margin: 4px 0;"><strong>Correo:</strong> {{ $data['email'] }}</p>
                <p style="margin: 4px 0;"><strong>Contrasena temporal:</strong> {{ $data['password'] }}</p>
            </div>

            <p>Al iniciar sesion el sistema solicitara que cambies esta contrasena antes de continuar.</p>

            @if(!empty($data['expires_at']))
                <p style="font-size: 13px; color: #667085;">Vence: {{ $data['expires_at'] }}</p>
            @endif

            <p style="font-size: 12px; color: #7a8797; margin-top: 24px;">Si no esperabas este cambio, contacta a la administracion UGDA.</p>
        </div>
    </div>
</body>
</html>
