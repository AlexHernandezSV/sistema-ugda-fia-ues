<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credenciales de Acceso - Sistema UGDA</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F3F7FA; margin: 0; padding: 40px 20px;">

    <!-- Contenedor Principal -->
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 24px rgba(0,0,0,0.04);">
        
        <!-- Encabezado Institucional -->
        <tr>
            <td style="background-color: #2F66B6; padding: 24px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 18px; font-weight: 600; letter-spacing: 0.5px;">Sistema de Gestión Documental</h1>
                <p style="color: #D1E3F8; margin: 4px 0 0 0; font-size: 13px;">Universidad de El Salvador</p>
            </td>
        </tr>

        <!-- Cuerpo del Correo -->
        <tr>
            <td style="padding: 40px 32px;">
                
                <!-- Etiqueta (Badge) similar a la UI -->
                <div style="display: inline-block; background-color: #2F66B6; color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-bottom: 20px; letter-spacing: 0.3px;">
                    UGDA - ACCESO AL SISTEMA
                </div>

                <h2 style="color: #1E3A8A; margin: 0 0 20px 0; font-size: 22px; font-weight: 700;">Bienvenido al Sistema</h2>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 20px;">
                    Hola <strong>{{ $data['name'] }}</strong>,
                </p>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 28px;">
                    Se ha creado tu cuenta exitosamente. A continuación, encontrarás tus credenciales para ingresar a la plataforma de gestión de transferencias documentales:
                </p>

                <!-- Caja de Credenciales -->
                <div style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 28px;">
                    <p style="margin: 0 0 12px 0; color: #334155; font-size: 15px;">
                        <span style="color: #64748B; font-size: 13px; display: block; margin-bottom: 4px;">Usuario o Correo Electrónico</span>
                        <strong style="color: #1E3A8A;">{{ $data['email'] }}</strong>
                    </p>
                    <p style="margin: 0; color: #334155; font-size: 15px;">
                        <span style="color: #64748B; font-size: 13px; display: block; margin-bottom: 4px;">Contraseña Temporal</span>
                        <strong style="color: #1E3A8A;">{{ $data['password'] }}</strong>
                    </p>
                </div>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 32px;">
                    Por favor, ingresa al sistema y cambia tu contraseña lo antes posible para garantizar la seguridad de tu cuenta y de los procesos institucionales.
                </p>

                <!-- Botón de Acción -->
                <div style="text-align: center; margin-bottom: 10px;">
                    <a href="{{ url('/login') }}" style="background-color: #2F66B6; color: #ffffff; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; display: inline-block; transition: background-color 0.3s;">
                        Ingresar al Sistema
                    </a>
                </div>
            </td>
        </tr>

        <!-- Pie de página (Footer) -->
        <tr>
            <td style="background-color: #F8FAFC; padding: 28px; text-align: center; border-top: 1px solid #E2E8F0;">
                <p style="margin: 0; color: #64748B; font-size: 12px; line-height: 1.5;">
                    Si no solicitaste esta cuenta o crees que es un error, por favor ignora este correo.
                </p>
                <div style="margin: 20px 0; height: 1px; background-color: #E2E8F0;"></div>
                <p style="margin: 0; color: #94A3B8; font-size: 11px; line-height: 1.6;">
                    © 2026 Universidad de El Salvador<br>
                    Facultad de Ingeniería y Arquitectura<br>
                    Todos los derechos reservados
                </p>
            </td>
        </tr>
    </table>

</body>
</html>