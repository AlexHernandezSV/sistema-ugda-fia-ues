<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitud pendiente de autorización - Sistema UGDA</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F3F7FA; margin: 0; padding: 40px 20px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 24px rgba(0,0,0,0.04);">
        <tr>
            <td style="background-color: #2F66B6; padding: 24px; text-align: center;">
                <h1 style="color: #ffffff; margin: 0; font-size: 18px; font-weight: 600; letter-spacing: 0.5px;">Sistema de Gestión Documental</h1>
                <p style="color: #D1E3F8; margin: 4px 0 0 0; font-size: 13px;">Universidad de El Salvador</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 40px 32px;">
                <div style="display: inline-block; background-color: #D97706; color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-bottom: 20px; letter-spacing: 0.3px;">
                    UGDA - AUTORIZACIÓN PENDIENTE
                </div>

                <h2 style="color: #1E3A8A; margin: 0 0 20px 0; font-size: 22px; font-weight: 700;">Solicitud pendiente de autorización</h2>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 20px;">
                    Hola <strong>{{ $recipientName }}</strong>,
                </p>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 28px;">
                    La unidad <strong>{{ $unitName }}</strong> ha enviado una solicitud de <strong>{{ $requestType }}</strong> que está pendiente de su autorización.
                </p>

                <div style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; margin-bottom: 28px;">
                    <p style="margin: 0; color: #334155; font-size: 15px;">
                        <span style="color: #64748B; font-size: 13px; display: block; margin-bottom: 4px;">Solicitud</span>
                        <strong style="color: #1E3A8A;">{{ ucfirst($requestType) }} #{{ $requestNumber }}</strong>
                    </p>
                </div>

                <p style="color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 32px;">
                    Ingrese al sistema para revisar el detalle y autorizar o denegar la solicitud.
                </p>

                <div style="text-align: center; margin-bottom: 10px;">
                    <a href="{{ url($actionUrl) }}" style="background-color: #2F66B6; color: #ffffff; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px; display: inline-block;">
                        Revisar solicitud
                    </a>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background-color: #F8FAFC; padding: 28px; text-align: center; border-top: 1px solid #E2E8F0;">
                <p style="margin: 0; color: #64748B; font-size: 12px; line-height: 1.5;">
                    Este es un mensaje automático del sistema UGDA. No es necesario responder a este correo.
                </p>
                <div style="margin: 20px 0; height: 1px; background-color: #E2E8F0;"></div>
                <p style="margin: 0; color: #94A3B8; font-size: 11px; line-height: 1.6;">
                    © 2025 Universidad de El Salvador<br>
                    Facultad de Ingeniería y Arquitectura<br>
                    Todos los derechos reservados
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
