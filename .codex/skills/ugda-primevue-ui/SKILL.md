---
name: ugda-primevue-ui
description: Design and implement PrimeVue administrative screens for Sistema UGDA FIA UES. Use when Codex needs to create or refine Vue 3 UI with PrimeVue, PrimeIcons, PrimeFlex, forms, tables, dialogs, filters, toasts, validation states, responsive layouts, or Spanish business copy.
---

# UGDA PrimeVue UI

## UI Direction

Build quiet administrative screens for repeated work: dense, organized, scannable, and consistent with the current app. Do not turn CRUD screens into marketing pages.

## Components

- Use PrimeVue components already present in the repo before introducing a new pattern.
- Use `DataTable` for lists, with clear columns, loading, empty state, and action buttons.
- Use dialogs or routed forms according to the current module pattern.
- Use PrimeIcons in action buttons when an icon exists.
- Use toasts or inline messages for success/error feedback in Spanish.
- Use dropdowns, toggles, checkboxes, and inputs that match the data type.

## Copy And Naming

- Keep visible UI text in Spanish.
- Use business-oriented route/view names such as `Usuarios.vue`, `Unidades.vue`, `Solicitudes.vue`, and `NuevaSolicitud.vue`.
- Use concise labels: `Guardar`, `Cancelar`, `Editar`, `Eliminar`, `Activo`, `Unidad`, `Rol`.
- Avoid explanatory text that describes how the UI works unless it is genuinely needed for the workflow.

## Layout Rules

- Keep cards restrained and avoid nesting cards inside cards.
- Ensure button text fits on mobile and desktop.
- Preserve stable table/form dimensions so loading states and validation errors do not shift the layout awkwardly.
- Match the existing palette and spacing instead of introducing a one-note color theme.

## Verification

After meaningful UI changes, run the frontend build or open the local app through Vite/Laravel and inspect the affected viewport. Check desktop and narrow widths when layout changed.
