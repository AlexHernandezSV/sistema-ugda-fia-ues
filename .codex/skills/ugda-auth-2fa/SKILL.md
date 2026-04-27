---
name: ugda-auth-2fa
description: Maintain and troubleshoot the Sistema UGDA FIA UES authentication flow with email-based 2FA. Use when Codex needs to modify login, `/login-auth`, `/login-verify`, sessions, redirects, Mailpit email delivery, auth validation, or related Vue login behavior.
---

# UGDA Auth 2FA

## Flow

1. `POST /login-auth` validates email/password, checks active user status, generates the 2FA code, and sends it by email.
2. `POST /login-verify` validates the code, starts the Laravel session, and responds with a redirect to `/dashboard`.
3. The local mailbox is Mailpit at `http://127.0.0.1:8025`.

## Files To Inspect First

- `app/Http/Controllers/AuthController.php`
- `routes/web.php`
- Login-related Vue view or component
- Mail configuration in `.env`, Docker config, or Laravel mail settings when delivery fails

## Rules

- Keep user-facing auth messages in Spanish.
- Do not weaken password, active-user, session, or 2FA checks to make local testing easier.
- Avoid changing route names or response shapes unless the Vue caller is updated at the same time.
- Keep 2FA codes short-lived and single-purpose if the current implementation supports that.
- Never use or reintroduce `users.name`; identify users with the current split-name fields and `email`.

## Local Debug Checklist

- Confirm the app responds at `http://127.0.0.1:8000/up`.
- Confirm Mailpit responds at `http://127.0.0.1:8025`.
- Use seeded credentials only for local work: `admin-ugda@yopmail.com` / `password`.
- If email does not arrive, inspect container logs before editing auth logic.
