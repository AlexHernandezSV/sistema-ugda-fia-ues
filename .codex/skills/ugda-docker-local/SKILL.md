---
name: ugda-docker-local
description: Operate and troubleshoot the local Docker environment for Sistema UGDA FIA UES. Use when Codex needs to start, stop, reset, inspect, migrate, seed, or diagnose the Laravel app, Vite server, PostgreSQL 16, or Mailpit services defined in docker-compose.yml.
---

# UGDA Docker Local

## Services

- App: `http://127.0.0.1:8000`
- Health check: `http://127.0.0.1:8000/up`
- Vite: `http://127.0.0.1:5173`
- Mailpit: `http://127.0.0.1:8025`
- PostgreSQL: container service from `docker-compose.yml`

## Common Commands

Run from the project root:

```powershell
docker compose up -d
docker compose ps
docker compose logs app
docker compose logs vite
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan test
```

Use a full reset only when the task calls for destroying local data:

```powershell
docker compose down -v
docker compose up -d
```

## Startup Behavior

- `docker/start-app.sh` runs `composer install`, migrations with seeders, and `storage:link`.
- `docker/start-vite.sh` runs `npm install` and starts Vite on port `5173`.
- If ports `8000`, `5173`, `5432`, `1025`, or `8025` are busy, identify the conflicting process/container before changing project config.

## Troubleshooting Order

1. Check `docker compose ps`.
2. Check the specific service logs.
3. Confirm `.env` values match Docker services.
4. Confirm migrations and seeders do not reference removed columns such as `users.name`.
5. Run the narrowest artisan/npm command that verifies the fix.
