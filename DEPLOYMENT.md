# Deployment Guide

## Railway (push-to-deploy, recommended)

This repo is a monorepo (`backend/`, `frontend/`). Each app has its own `Dockerfile` and `railway.json` so Railway builds them as separate services with no extra buildpack config needed.

1. In Railway: **New Project → Deploy from GitHub repo** → select this repo.
2. **Add a Postgres database** (Railway plugin, one click) to the project.
3. **Add the backend service**: New Service → same repo → set **Root Directory** to `backend`. Railway will detect `backend/Dockerfile` automatically.
4. **Add the frontend service**: New Service → same repo → set **Root Directory** to `frontend`. Railway will detect `frontend/Dockerfile` automatically.
5. Set backend service variables (Settings → Variables):
   - `APP_KEY` — generate locally with `php artisan key:generate --show` and paste the value.
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` = the backend service's Railway domain (e.g. `https://ongkoleyt-backend.up.railway.app`).
   - `DB_CONNECTION=pgsql`, `DB_HOST=${{Postgres.PGHOST}}`, `DB_PORT=${{Postgres.PGPORT}}`, `DB_DATABASE=${{Postgres.PGDATABASE}}`, `DB_USERNAME=${{Postgres.PGUSER}}`, `DB_PASSWORD=${{Postgres.PGPASSWORD}}` (reference the Postgres plugin's *private* variables — never `DATABASE_PUBLIC_URL`, which routes through the public proxy and incurs egress fees).
   - `SANCTUM_STATEFUL_DOMAINS` — **leave unset/empty.** Auth is token-based (bearer), not cookie/session, so no stateful domains are needed. Setting it makes Sanctum enforce CSRF on the token login and it fails with **419**.
   - `SESSION_SECURE_COOKIE=true` (Railway domains are HTTPS by default).
   - `CORS_ALLOWED_ORIGINS` = the frontend's full URL, e.g. `https://ongkoleyt-frontend.up.railway.app`.
   - Railway injects `PORT` automatically — the Dockerfile's `CMD` already binds to it.
6. Set frontend service variables:
   - `VITE_API_BASE_URL` = the backend service's Railway domain, e.g. `https://ongkoleyt-backend.up.railway.app`. This must be set **before the build** — Vite bakes env vars in at build time, so redeploy after changing it.
7. On every push to the deployed branch, both services rebuild and redeploy automatically. The backend's `CMD` runs `php artisan migrate --force` on every boot, so schema changes ship automatically — no manual SSH step needed.
8. First deploy only: since there's no admin seeder (by design — see the post-deploy checklist below), open the backend service's Railway shell (or `railway run ...`) and create the first admin with the built-in command:
   ```bash
   php artisan admin:create --name="Admin" --email="admin@ongkoleyt.example" --password="choose-a-real-password"
   ```
   (Re-run with `--force` to reset an existing admin's password.)

## Backend (Laravel API) — manual VPS alternative

1. Provision a server/hosting plan with PHP 8.2+, MySQL 8, and Composer (a small VPS such as a 1-2GB DigitalOcean/Vultr droplet, or shared hosting with SSH access, comfortably fits the proposal's ₱1,500/mo hosting fee).
2. Clone the repo, `cd backend`, `composer install --no-dev --optimize-autoloader`.
3. Copy `.env.production.example` to `.env`, fill in DB credentials and `APP_URL`.
4. `php artisan key:generate --force`.
5. `php artisan migrate --force`.
6. `php artisan db:seed --force` (only for initial go-live with the demo roster — replace with the client's real employee list before production use).
7. Point the web server's document root at `backend/public`, or run behind Nginx + PHP-FPM with a standard Laravel server block.
8. Set up HTTPS (Let's Encrypt via certbot) — required for Sanctum's `SESSION_SECURE_COOKIE=true`.
9. Set up a cron entry for Laravel's scheduler (not used yet, but standard practice): `* * * * * php /path/to/backend/artisan schedule:run >> /dev/null 2>&1`.

## Frontend (React SPA) — manual VPS alternative

1. `cd frontend`, `npm install`, `npm run build` — outputs static files to `frontend/dist`.
2. Deploy `frontend/dist` to any static host (same server via Nginx, or a separate static host) at the domain listed in `SANCTUM_STATEFUL_DOMAINS`.
3. Ensure the frontend's origin is HTTPS and matches `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` exactly, or the Sanctum cookie session will silently fail.

## Post-deploy checklist (mirrors the Handover Checklist in `Ongkoleyt-Client-Handover.docx`)

- [ ] Replace seeded demo employees with the real roster and real PINs (never ship the `1234` demo PIN to production).
- [ ] Create the real admin login(s) via `php artisan tinker` or a one-off seeder, then delete/rotate any demo admin credentials.
- [ ] Confirm daily basic rate, overtime multiplier, night differential multiplier, and 13th month settings with the client via the Settings tab before go-live.
- [ ] Walk admin staff at each branch through the kiosk + admin flows.
- [ ] Set a calendar reminder for the 14-day complimentary adjustment window mentioned in the handover doc.
