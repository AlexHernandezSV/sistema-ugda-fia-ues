---
name: figma
description: Usa Figma MCP para implementar UI en este proyecto Laravel + Vue 3 + PrimeVue. Se dispara con URLs de Figma, IDs de nodos y tareas de diseno a codigo; tambien para soporte de configuracion del MCP.
---

# Figma MCP

Usa Figma MCP para implementar interfaces del sistema UGDA. El frontend real esta en Laravel + Vue 3 (Vite), con PrimeVue, PrimeFlex y Tailwind.

## Reglas de integracion con Figma MCP
Estas reglas estan adaptadas a esta base de codigo y deben seguirse en cada cambio guiado por Figma.

### Stack objetivo del proyecto
- Frontend: `resources/js` (Vue SPA montada en `#app` desde Blade), con enrutado en `resources/js/router.js`.
- Layout principal: `resources/js/components/layout/AdminLayout.vue` y `resources/js/components/layout/AppSidebar.vue`.
- Componentes globales registrados en `resources/js/app.js` (Button, InputText, DataTable, Column, Dialog, Dropdown, Toast, Card).
- Estilos base en `resources/css/app.css`, con `Instrument Sans`, PrimeFlex, directivas de Tailwind y estilos propios de PrimeVue.
- Axios ya esta configurado en `resources/js/bootstrap.js` y `resources/js/app.js` (interceptor para 419/401 redirige a `/login`).

### Flujo requerido (sin omitir)
1. Ejecuta primero `get_design_context` para traer el nodo exacto.
2. Si la salida esta truncada, ejecuta `get_metadata` y vuelve a pedir solo los nodos necesarios con `get_design_context`.
3. Ejecuta `get_screenshot` del mismo nodo o variante antes de maquetar.
4. Descarga assets desde MCP y solo despues implementa.
5. Convierte a Vue + PrimeVue/PrimeFlex (no React), respetando estructura de `resources/js`.
6. Verifica componentes/rutas existentes antes de agregar nuevos.
7. Valida look y comportamiento contra la captura antes de dar el trabajo completo.

### Reglas de implementacion
- Convierte la salida de Figma (normalmente React + Tailwind) a convencion Vue + PrimeVue del proyecto.
- Mantiene componentes en las carpetas existentes (`components`, `components/layout`, `components/views`) y usa componentes globales ya registrados antes de importar nuevos.
- Si el diseno trae utilidades Tailwind, traducelas a la mezcla usada aqui: PrimeFlex + utilidades Tailwind existentes + estilos globales.
- Conserva `Instrument Sans` como tipografia base y reutiliza estilos globales del `app.css` (incluyendo estilos de toast).
- Usa `primeicons` y componentes PrimeVue para iconos y UI; evita agregar nuevos paquetes de iconos.
- Respeta la navegacion del `router.js` y no romper las rutas base (`/`, `/login`, `/dashboard`, `/unidades`, `/usuarios`).
- Conserva la configuracion de axios (CSRF + withCredentials) y la logica de seguridad del interceptor 419/401.
- Mantiene textos en espanol cuando aplique al dominio del sistema.

### Manejo de recursos
- Usa el endpoint de assets de Figma para descargar imagenes o SVG.
- IMPORTANTE: si Figma devuelve una fuente `localhost`, usa esa ruta tal cual.
- IMPORTANTE: no crees placeholders cuando ya exista fuente valida.
- IMPORTANTE: no agregues paquetes nuevos de iconos; prioriza recursos de Figma o PrimeVue existente.

### Prompting por enlace
- El MCP es link-based: pasa siempre el link exacto del frame/capa/nodo objetivo.
- El MCP toma el ID del nodo del link; verifica que apunte al nodo o variante correcta.
