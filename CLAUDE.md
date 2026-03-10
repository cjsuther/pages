# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A personal pages platform (similar to Linktree) where users create public profile pages with links, events, and social following. Built as a decoupled SPA + PHP REST API.

## Architecture

### Two independent services:

**`/frontend`** - React 18 SPA (Vite + TailwindCSS)
- Dev server on port 3000, proxies `/api` to port 8000
- Auth state managed via `AuthContext` in `App.jsx`, stored in `localStorage`
- API base URL hardcoded in `App.jsx`: `const API_URL = 'http://localhost:8000/api'` (production URL commented out below it)

**`/api`** - PHP 7.4+ REST API (no framework)
- Each endpoint is a standalone `.php` file that handles HTTP methods with `if/elseif` blocks on `$_SERVER['REQUEST_METHOD']`
- Request bodies are read via `json_decode(file_get_contents('php://input'), true)`
- `Database.php` — PDO connection (MySQL, utf8mb4); instantiate with `new Database()` then call `->connect()`
- `JWT.php` — custom HS256 JWT implementation; use `JWT::getUserFromToken()` to authenticate requests (reads `Authorization: Bearer` header)
- `config.php` — all constants (DB, JWT secret, VAPID keys, OAuth credentials) + global CORS/JSON headers; created from `config.example.php`

### Data model:
- `users` → `pages` (one-to-many via `user_id`)
- `pages` → `link_groups` (one-to-many via `page_id`; groups have `type`: `links` | `events` and `position` for ordering)
- `link_groups` → `links` (one-to-many via `group_id`; links have `position` for ordering)
- Event-type links have extra fields: `event_date`, `event_time`, `event_address`, `event_latitude`, `event_longitude`, `event_maps_url`
- Pages have color customization fields (`primary_color`, `secondary_color`, `background_color`, `text_color`) and a `template` field
- Social features: `page_follows` table tracks user→page follows; `notifications` table for push notifications

### Database:
- Base schema: `database.sql`
- Migrations are standalone SQL files at project root: `migration_*.sql` (applied manually, no migration tool)

## Commands

### Quick Start (both services)
```bash
./dev.sh    # Starts API on :8000 and frontend on :3000, shows combined logs
```

### Frontend
```bash
cd frontend
npm install          # Install dependencies
npm run dev          # Dev server (port 3000)
npm run build        # Production build
npm run preview      # Preview production build
```

### API
```bash
cd api
composer install     # Install PHP dependencies
cp config.example.php config.php  # Then edit with real credentials
php -S localhost:8000  # Dev server
```

### No test suite or linter is configured for either service.

## Key Conventions

- Every API PHP file starts with `require_once '../config.php'`, `require_once '../Database.php'`, `require_once '../JWT.php'`
- Protected endpoints call `JWT::getUserFromToken()` and return 401 JSON if null
- All API responses are JSON; `config.php` sets CORS and `Content-Type: application/json` headers globally
- Frontend uses `createAuthenticatedFetch` from `utils/apiHandler.js` which auto-handles 401s (logs out user)
- Page slugs are validated against a reserved list in `api/pages/index.php` (line ~53)
- Uploaded images go to `api/uploads/` (served via `UPLOAD_URL` constant)
- Push notifications use VAPID keys configured in `config.php`; service worker is at `frontend/public/sw.js`
- Google Analytics ID can be set via `VITE_GA_MEASUREMENT_ID` env var
- Page templates are in `frontend/src/components/templates/` (MinimalTemplate, ModernTemplate, CardsTemplate, CondensedTemplate)
