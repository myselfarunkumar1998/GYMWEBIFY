# GymOS: gym management platform

All-in-one gym software for Indian gyms. It is multi-tenant (SaaS), built with a React front end and a PHP 8 + MySQL back end, and runs on Bluehost shared hosting.

- **Admin web app** (`/admin`): dashboard, members, plans, billing & GST, check-ins & sessions, leads CRM, classes & PT, workout/diet builder, staff & payroll, store & inventory, WhatsApp & campaigns, AI insights, reports, branches, devices, settings. Also a front-desk kiosk with QR camera scanning (`/admin/punch`).
- **Owner app** (`/owner`): installable phone app (PWA) with live occupancy, punches, money, dues and reports.
- **Member app** (`/m/<gym>`): installable PWA in Tamil and English. It has:
  - WhatsApp OTP sign-in
  - a live session timer and a rotating QR card
  - visits, class/PT/court booking, workouts and diet, progress
  - challenges, the store, and renew & pay (Razorpay, UPI AutoPay)
  - referrals, waiver and Strava
- **Gym website** (`/g/<gym>`): plans, timetable and free-trial booking that creates a lead.
- **Platform console** (`/platform`): create, suspend and extend gym accounts.

## Layout

```
api/            PHP API (no Composer dependencies)
  index.php     router entry (/api/*)
  adms.php      eSSL/ZKTeco ADMS push endpoint (/iclock/*)
  cron.php      scheduled jobs (every 5 minutes)
  install.php   one-time web installer
  db/schema.sql database schema · db/seed-demo.php demo data (CLI)
  src/Core      config, DB, auth, middleware, validation, rate limiting, uploads
  src/Services  billing/GST, memberships, access control, attendance, messaging (WhatsApp/SMS/email/push),
                Razorpay, churn scoring, WhatsApp bot, ADMS, cron, webhooks
  src/Controllers  one per module
web/            React 18 + Vite app (admin, owner, member, public)
tests/api       HTTP integration + security tests (200 checks)
tests/e2e       Playwright browser journeys
deploy/         Apache rules, PHP ini, build script
```

## Run locally

```bash
mysql -u root -e "CREATE DATABASE gymapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

```bash
mysql -u root gymapp < api/db/schema.sql
```

Create `api/config.php` (see the keys written by `install.php`). Then seed the demo data and start the API:

```bash
php api/db/seed-demo.php
```

```bash
(cd web && npm install && npx vite build)
```

```bash
cd api && php -S localhost:8080 router-dev.php
```

Open http://localhost:8080/login and sign in as `owner@demo.gym` / `Demo@12345`. Other demo logins (same password):
- `manager@demo.gym`
- `desk@demo.gym`
- `trainer@demo.gym`

Member app: http://localhost:8080/m/demo with phone `9876543210`. In demo mode the code is shown on screen.

## Tests

Both suites expect a test server on port 8090 using the `gymapp_test` database.

```bash
cd api && GYM_DB=gymapp_test GYM_URL=http://localhost:8090 GYM_IP_HEADER=HTTP_X_TEST_IP php -S localhost:8090 router-dev.php
```

```bash
php tests/api/run.php
```

```bash
node tests/e2e/e2e.cjs
```

## Build for Bluehost

```bash
./deploy/build.sh
```

This produces `build/gymapp-bluehost.zip`. See [DEPLOY-BLUEHOST.md](DEPLOY-BLUEHOST.md).

## Security notes

- **Auth:**
  - Passwords are hashed with Argon2id (bcrypt fallback); accounts lock after 5 failed sign-ins.
  - Session tokens are random 256-bit values, stored only as SHA-256 hashes, and sent in HttpOnly SameSite cookies.
  - Staff and member sessions use separate cookies.
- **CSRF:** every state change needs a custom header that browsers cannot send cross-site, plus an Origin check.
- **Tenants and roles:**
  - Every query is scoped by `gym_id`.
  - Role permissions: owner, manager, front desk, trainer (trainers see only their own clients).
  - Optional single-branch limit per staff member.
- **Input:**
  - All SQL uses bound parameters.
  - Input is validated per field; mass assignment is blocked.
  - Spreadsheet exports neutralise formulas.
- **Uploads:**
  - Content is checked by its bytes, not its file name.
  - Files get random names and sit in a folder Apache denies.
  - They are served only to the owning gym.
- **Secrets and webhooks:**
  - Integration secrets are encrypted at rest (libsodium).
  - Incoming webhooks verify signatures (Razorpay, WhatsApp/Meta).
  - Outgoing webhooks are HMAC-signed and blocked from private networks.
- **Abuse limits:**
  - Rate limits on sign-in, OTP, password reset, trial booking and demo payment.
  - OTP is limited to 5 attempts and 10 minutes.
- **Headers:** CSP, HSTS, `X-Frame-Options`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`.
- **Demo mode** (on-screen OTP, simulated WhatsApp and payments) only ever applies to the gym marked as the demo.
- **Known limit:** eSSL/ZKTeco ADMS has no device authentication. Only registered serial numbers are accepted, but a serial number is not a secret. Keep devices on the gym network.
