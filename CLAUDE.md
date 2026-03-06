# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A personal pages platform (similar to Linktree) where users create public profile pages with links, events, and social following. Built as a decoupled SPA + PHP REST API.

## Architecture

### Two independent services:

**`/frontend`** - React 18 SPA (Vite + TailwindCSS)
- Dev server on port 3000, proxies `/api` to port 8000
- Auth state managed via `AuthContext` in `App.jsx`, stored in `localStorage`
- API base URL hardcoded in `App.jsx`: `const API_URL = 'http://localhost:8000/api'`

**`/api`** - PHP 7.4+ REST API (no framework)
- Each endpoint is a standalone `.php` file that handles HTTP methods with `if/elseif` blocks
- `Database.php` — singleton PDO connection (MySQL, utf8mb4)
- `JWT.php` — custom HS256 JWT implementation; use `JWT::getUserFromToken()` to authenticate requests
- `config.php` — all constants (DB, JWT secret, VAPID keys, OAuth credentials); created from `config.example.php`

### API endpoint structure:
```
api/
  auth/        — login.php, register.php, google-login.php, apple-login.php, callbacks
  pages/       — index.php (list/create), detail.php (get/update/delete), follow.php, following.php, feed-events.php
  groups/      — index.php (list/create), detail.php (get/update/delete/reorder)
  links/       — index.php (list/create), detail.php (get/update/delete/reorder)
  users/       — location.php (get/update user geolocation)
  notifications/ — subscribe.php (VAPID push), index.php (list), process-daily.php (cron)
  upload/      — image.php (handles profile/background/link images → stored in api/uploads/)
```

### Data model (pages):
Pages have `groups` (type: `links` | `events`), and groups contain `links`. Event-type links have extra fields: `event_date`, `event_time`, `event_address`, `event_latitude`, `event_longitude`, `event_maps_url`.

### Frontend structure:
```
src/
  App.jsx              — Router, AuthContext provider
  pages/
    PageEditor.jsx     — Full CRUD editor for a page's groups and links
    PublicPage.jsx     — Public-facing page rendered by slug (/:slug route)
    Feed.jsx           — Events feed from followed pages
    Pages.jsx          — Browse/search all public pages
    MyPages.jsx        — User's own pages list
  components/
    templates/         — MinimalTemplate, ModernTemplate, CardsTemplate, CondensedTemplate
    Navigation.jsx, NotificationBell.jsx, FollowButton.jsx, EventsMap.jsx, etc.
  utils/
    apiHandler.js      — createAuthenticatedFetch + 401 auto-logout helper
    analytics.js       — Google Analytics (gtag) event wrappers
    pushNotifications.js — Web Push / Service Worker registration helpers
  hooks/
    usePageTracking.js — Fires GA pageview on route change
```

## Commands

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
composer install     # Install PHP dependencies (creates config.php from config.example.php)
php -S localhost:8000  # Dev server
php generate-vapid-keys.php  # Generate VAPID keys for push notifications
```

## Key Conventions

- Every API PHP file starts with `require_once '../config.php'`, `require_once '../Database.php'`, `require_once '../JWT.php'`
- Protected endpoints call `JWT::getUserFromToken()` and return 401 if null
- All API responses are JSON; `config.php` sets CORS and `Content-Type: application/json` headers globally
- Frontend uses `createAuthenticatedFetch` from `utils/apiHandler.js` which auto-handles 401s
- Page slugs are validated against a reserved list in `api/pages/index.php`
- Uploaded images go to `api/uploads/` (served via `UPLOAD_URL` constant)
- Push notifications use VAPID keys configured in `config.php`; service worker is at `frontend/public/sw.js`
- Google Analytics ID can be set via `VITE_GA_MEASUREMENT_ID` env var
