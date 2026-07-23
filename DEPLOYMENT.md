# Deployment Guide

## Backend (Laravel API)

1. Provision a server/hosting plan with PHP 8.2+, MySQL 8, and Composer (a small VPS such as a 1-2GB DigitalOcean/Vultr droplet, or shared hosting with SSH access, comfortably fits the proposal's ₱1,500/mo hosting fee).
2. Clone the repo, `cd backend`, `composer install --no-dev --optimize-autoloader`.
3. Copy `.env.production.example` to `.env`, fill in DB credentials and `APP_URL`.
4. `php artisan key:generate --force`.
5. `php artisan migrate --force`.
6. `php artisan db:seed --force` (only for initial go-live with the demo roster — replace with the client's real employee list before production use).
7. Point the web server's document root at `backend/public`, or run behind Nginx + PHP-FPM with a standard Laravel server block.
8. Set up HTTPS (Let's Encrypt via certbot) — required for Sanctum's `SESSION_SECURE_COOKIE=true`.
9. Set up a cron entry for Laravel's scheduler (not used yet, but standard practice): `* * * * * php /path/to/backend/artisan schedule:run >> /dev/null 2>&1`.

## Frontend (React SPA)

1. `cd frontend`, `npm install`, `npm run build` — outputs static files to `frontend/dist`.
2. Deploy `frontend/dist` to any static host (same server via Nginx, or a separate static host) at the domain listed in `SANCTUM_STATEFUL_DOMAINS`.
3. Ensure the frontend's origin is HTTPS and matches `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` exactly, or the Sanctum cookie session will silently fail.

## Post-deploy checklist (mirrors the Handover Checklist in `Ongkoleyt-Client-Handover.docx`)

- [ ] Replace seeded demo employees with the real roster and real PINs (never ship the `1234` demo PIN to production).
- [ ] Create the real admin login(s) via `php artisan tinker` or a one-off seeder, then delete/rotate any demo admin credentials.
- [ ] Confirm daily basic rate, overtime multiplier, night differential multiplier, and 13th month settings with the client via the Settings tab before go-live.
- [ ] Walk admin staff at each branch through the kiosk + admin flows.
- [ ] Set a calendar reminder for the 14-day complimentary adjustment window mentioned in the handover doc.
