# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

Mojito is a ZPL label designer for thermal label printers (drag-and-drop UI): position text, barcode (with named data sources), and image elements on a label, preview the generated ZPL, and print via CUPS (`lp -o raw`) or Windows raw printing.

It is a standalone project (own git repo) but **deploys into** the `GreenEnergyServer` Laravel app as a station app — see "Deploy" below. `GreenEnergyServer` lives as a sibling directory (`../GreenEnergyServer`) when both repos are checked out side by side.

- **Frontend**: `src/` — Vue 3 + Vite, optional Electron shell (`electron/`)
- **Backend**: `server/` — plain PHP 8.2 (no framework), namespace `Mojito\Label\`, generates ZPL and shells out to print it

## Commands

```bash
# Install
npm install && cd server && composer install && cd ..

# Dev (frontend + PHP built-in server together, port 5174)
npm run dev:all
npm run dev          # frontend only (Vite)
npm run dev:server   # backend only: php -S localhost:8080 (server/)
npm run dev:electron # Electron shell, NODE_ENV=development

# Tests
npm test                    # Vitest, src/utils/**/*.test.js
npm run test:server         # PHPUnit, server/tests/Unit
npm run test:all            # both, with coverage

# Static analysis / style (backend)
npm run analyse:server      # PHPStan level 9 (no Larastan — this project doesn't use Laravel)
npm run pint:server:fix     # Laravel Pint code style, auto-fix

# Full validation
npm run validate            # frontend coverage + server pint/phpstan/phpunit
npm run validate:full       # + Infection (server) + Stryker (frontend) mutation testing, ~15 min

# Mutation testing individually
npm run mutation:server     # Infection (server/), needs XDEBUG_MODE=coverage
npm run mutation            # Stryker (frontend)

# Deploy into GreenEnergyServer
npm run build:renderer && npm run deploy:green-energy
# then on the server: composer dump-autoload -o
```

Run a single PHPUnit test: `cd server && vendor/bin/phpunit --filter=TestName tests/Unit/SomeTest.php`.
Run a single Vitest file: `npx vitest run src/utils/__tests__/templateStore.test.js`.

## Architecture

### Data model: template → data sources → elements

A label **template** (`{id, name, labelWidth, labelHeight, dpi, dataSources[], elements[]}`) is the single source of truth edited by the designer. `dataSources` are named values (`{name, label, defaultValue}`); `elements` (`text`, `barcode`, `image`) reference a data source by name rather than embedding a static value directly, except for `staticValue`/`imageData` which are element-local.

Most of the domain logic for manipulating this structure lives in `src/utils/templateStore.js` (pure functions, no Vue): creating elements, resolving an element's displayed value from `dataValues`/`dataSources`, auto-assigning a dedicated data source when an element is created (`ensureElementDataSource`), detecting/repairing shared or broken data source references (`findSharedDataSources`, `repairBrokenDataSourceReferences`), duplicating elements, and pruning unused data sources. Keeping this logic framework-free is deliberate — it's what gets 100% Vitest coverage and is the target of mutation testing.

`src/components/LabelDesigner.vue` is the orchestrating component (state, toolbar, save/load flows, API calls); `src/components/LabelCanvas.vue` renders the drag/select/position canvas. Canvas geometry helpers (hit-testing, selection rectangles, placement) are split into `src/utils/canvasDisplay.js`, `canvasSelection.js`, and `elementPlacement.js` for the same testability reason.

### Persistence: three independent save paths

A template can be saved three ways (`src/utils/layoutStorage.js`):
1. **File** (`.mojito.json`) — native save dialog via Electron IPC (`electron/main.js`, `Documenti/Mojito/layouts/`) when running desktop, or a browser download otherwise.
2. **Server** — `POST /api/templates`, persisted as `server/storage/templates/{id}.json` via `TemplateRepository`.
3. **Browser `localStorage`** — for quick reopen from the UI only, not portable.

`sanitizeTemplateForSave` strips runtime-only fields before any of the three paths write data.

### Backend: framework-free PHP, PSR-4 under `Mojito\Label\`

`server/public/index.php` is the single entrypoint — no router library. It builds a `LabelPrinterService` + `TemplateRepository`, hands the method/path/body to `ApiHandler::handle()`, which does its own routing via a `match(true)` over method+path pairs and returns `{status, payload}` for `index.php` to emit as JSON.

Key classes in `server/src/`:
- `ApiHandler` — HTTP routing/dispatch, one method per endpoint.
- `TemplateRepository` — reads/writes `storage/templates/{id}.json`.
- `ZplBuilder` — turns a template + values into a ZPL string (text, barcode, image commands).
- `ZplImageConverter` — converts raster images to ZPL `^GFA` ASCII-hex format.
- `LabelPrinterService` — orchestrates build + print, tracks last print method/output for diagnostics (`MOJITO_PRINTER_DEBUG` env var exposes raw print output over the API).
- `PrinterPlatform` — OS detection and printer discovery/printing, branches Linux (CUPS `lp -o raw`) vs Windows (raw spooling, with Laragon-specific temp-dir fallbacks — see recent `fix(print):` commits for the fragile edge cases here).
- `ShellCommandRunner` — thin wrapper around shell exec, isolated so it's mockable in tests.
- `TypeCaster` — defensive casting for decoded JSON request bodies (everything from `json_decode` is `mixed`).

When printing, the request either carries a `templateId` (server resolves it via `TemplateRepository`), an inline `template`, or a raw pre-built `zpl` string.

### Frontend/backend integration when embedded in GreenEnergyServer

`src/utils/runtime.js` resolves the API base URL as same-origin (`window.location.origin`) unless `VITE_API_BASE` is set — this lets the built assets work both standalone (`server/public` on :8080) and when served from GreenEnergyServer's Laravel routes (`/api/health`, `/api/print`, etc., see `Modules/BatteryModuleA/routes/` in GreenEnergyServer) without a build-time config split.

`scripts/deploy-green-energy.mjs` copies `dist/` → `GreenEnergyServer/public/stations/apps/mojito/`, `server/src/` → `GreenEnergyServer/lib/mojito-label/src/` (self-contained autoload, no dependency back on this repo), and `server/bin/print-raw.ps1` → `GreenEnergyServer/lib/mojito-label/bin/`. `GREEN_ENERGY_ROOT` / `GREEN_ENERGY_MOJITO_DEST` env vars override the default sibling-directory target.

## Quality gates

PHPStan level 9 and PHPUnit must pass before merging. Current mutation testing scores (Infection ~42%, Stryker ~62%) are below the 80% target configured in `infection.json5`/`stryker.config.json` — new/changed code should not regress these, but hitting 80% project-wide is still in progress per `README.md`.
