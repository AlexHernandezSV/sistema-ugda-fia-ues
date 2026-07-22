<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Código de acceso seguro - Sistema UGDA</title>
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
                    UGDA - VERIFICACIÓN DE ACCESO
                </div>

                <h2 style="color: #1E3A8A; margin: 0 0 20px 0; font-size: 22px; font-weight: 700;">Código de acceso seguro</h2>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 28px;">
                    Hemos recibido una solicitud para iniciar sesión en tu cuenta. Utiliza el siguiente código de verificación para completar el acceso:
                </p>

                <!-- Caja del Código con selección rápida -->
                <div style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 32px 24px; margin-bottom: 32px; text-align: center;">
                    <span style="color: #64748B; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 12px;">Tu código de verificación es</span>
                    
                    <!-- El código tiene un fondo gris sutil y la propiedad user-select: all -->
                    <div style="font-size: 42px; font-weight: 700; color: #2F66B6; letter-spacing: 8px; margin: 0; display: inline-block; background-color: #E2E8F0; padding: 10px 24px; border-radius: 8px; -webkit-user-select: all; user-select: all; cursor: pointer;">
                        {{ $code }}
                    </div>
                    
                    <!-- Instrucción para el usuario -->
                    <p style="margin: 12px 0 0 0; color: #94A3B8; font-size: 12px;">
                        (Toca o haz clic en el código para seleccionarlo fácilmente)
                    </p>
                </div>

                <!-- Caja de Recomendaciones -->
                <div style="background-color: #F8FAFC; border-left: 4px solid #2F66B6; border-radius: 4px 8px 8px 4px; padding: 18px 20px; margin-bottom: 32px;">
                    <h3 style="color: #1E3A8A; font-size: 15px; margin: 0 0 10px 0;">Recomendaciones de seguridad</h3>
                    <ul style="margin: 0; padding-left: 20px; color: #475569; font-size: 14px; line-height: 1.6;">
                        <li style="margin-bottom: 6px;">Este código expirará en <strong>10 minutos</strong>.</li>
                        <li style="margin-bottom: 6px;">No compartas este mensaje ni el código con otras personas.</li>
                        <li>Si no intentaste iniciar sesión, ignora este correo.</li>
                    </ul>
                </div>

            </td>
        </tr>

        <!-- Pie de página (Footer) -->
        <tr>
            <td style="background-color: #F8FAFC; padding: 28px; text-align: center; border-top: 1px solid #E2E8F0;">
                <p style="margin: 0; color: #64748B; font-size: 12px; line-height: 1.5;">
                    Este es un mensaje automático del sistema UGDA. No es necesario responder a este correo.
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