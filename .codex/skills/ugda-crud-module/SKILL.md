---
name: ugda-crud-module
description: Build, extend, or review CRUD modules for the Sistema UGDA FIA UES SPA. Use when Codex needs to add or modify administrative modules like unidades, usuarios, solicitudes, or new catalog/resources, including migrations, models, controllers, routes, Vue views, forms, tables, and validation messages.
---

# UGDA CRUD Module

## Backend Pattern

1. Add or update a singular PascalCase model under `app/Models`.
2. Use plural snake_case database tables and singular `_id` foreign keys.
3. Declare `fillable` explicitly for business data and `casts` for booleans/dates.
4. Keep one controller per main resource, named `<Resource>Controller`.
5. Put explicit validation in each controller method and return JSON for the SPA.
6. Register internal CRUD endpoints in `routes/web.php` under `/api/...`.

## Frontend Pattern

1. Add or update a PascalCase view in `resources/js/components/views`.
2. Register Spanish business routes in `resources/js/router.js`, for example `/unidades`, `/usuarios`, `/solicitudes`.
3. Use PrimeVue controls already used in the app: data tables, dialogs, inputs, dropdowns, buttons, toasts, and confirmation states.
4. Keep labels, empty states, validation messages, and action text in Spanish.
5. Preserve the existing administrative visual line instead of introducing a landing-page style.

## Users Special Case

The `users` table does not have `name`. Never add or depend on `name` in migrations, factories, seeders, requests, validation, forms, imports, display helpers, or tests.

Use these fields:

- `first_name`
- `second_name`
- `first_last_name`
- `second_last_name`
- `carnet`
- `email`
- `password`
- `role`
- `unit_id`
- `is_active`

## Verification

- For database changes, run migrations or `docker compose exec app php artisan migrate:fresh --seed` when a reset is intended.
- For controller changes, exercise the JSON endpoint or run targeted Laravel tests.
- For Vue changes, run the Vite/build command available in the repo or verify through the running Docker/Vite app.
