<!DOCTYPE html>
<html>
<head>
    <title>Credenciales de Acceso</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333;">
    <div style="background-color: #f4f4f4; padding: 20px;">
        <div style="background-color: white; padding: 20px; border-radius: 8px; max-width: 600px; margin: auto;">
            <h2 style="color: #1560bd;">Bienvenido al Sistema UGDA</h2>
            <p>Hola <strong>{{ $data['name'] }}</strong>,</p>
            <p>Se ha creado tu cuenta exitosamente. A continuación, encontrarás tus credenciales para ingresar al sistema:</p>
            
            <div style="background-color: #eef2f7; padding: 15px; border-left: 4px solid #1560bd; margin: 20px 0;">
                <p style="margin: 5px 0;"><strong>Usuario/Correo:</strong> {{ $data['email'] }}</p>
                <p style="margin: 5px 0;"><strong>Contraseña Temporal:</strong> {{ $data['password'] }}</p>
            </div>

            <p>Por favor, ingresa al sistema y cambia tu contraseña lo antes posible por seguridad.</p>
            <br>
            <p style="font-size: 12px; color: #777;">Si no solicitaste esta cuenta, por favor ignora este correo.</p>
        </div>
    </div>
</body>
</html>