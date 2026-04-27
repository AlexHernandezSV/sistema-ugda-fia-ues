---
name: ugda-laravel-vue
description: Full-stack workflow for the Sistema UGDA FIA UES Laravel 12 + Vue 3 + Vite SPA. Use when Codex needs to implement or review changes that cross backend routes/controllers/models and frontend Vue views/router, especially features in dashboard, unidades, usuarios, solicitudes, or shared SPA behavior.
---

# UGDA Laravel Vue

## Workflow

1. Start from the current branch, not assumptions from other UGDA branches.
2. Read the relevant backend route in `routes/web.php`, the Vue route in `resources/js/router.js`, and the current view or controller before editing.
3. Keep internal endpoints as JSON under `/api/...` in `web.php`; keep auth routes outside the `/api` prefix.
4. Preserve the SPA shape: Vue 3 views in `resources/js/components/views`, Vite assets, PrimeVue UI, and Spanish visible text.
5. Prefer small, direct Laravel controllers with explicit validation and clear JSON responses.
6. Verify affected paths with the narrowest useful command: `php artisan test`, targeted `npm`/Vite checks, or container commands if Docker is the active runtime.

## Project Rules

- Use English for technical entities, tables, columns, models, and relationships.
- Use Spanish for labels, titles, messages, toasts, validation text, and business flows.
- Use PascalCase for Vue views and Laravel models.
- Use snake_case for database tables and columns.
- Use camelCase for methods and relationships.
- Never reintroduce `name` on `users`; use `first_name`, `second_name`, `first_last_name`, `second_last_name`, `carnet`, `email`, `role`, `unit_id`, and `is_active`.

## Key Files

- Backend routes: `routes/web.php`
- Frontend router: `resources/js/router.js`
- Auth: `app/Http/Controllers/AuthController.php`
- Users: `app/Http/Controllers/UserController.php`
- Units: `app/Http/Controllers/UnitController.php`
- Views: `resources/js/components/views`

## Completion Checklist

- Backend and frontend routes agree.
- User-facing text is in Spanish.
- JSON responses match how the Vue code consumes them.
- Seeders/factories still avoid `users.name`.
- Docker or local verification command has been run when behavior changed.
