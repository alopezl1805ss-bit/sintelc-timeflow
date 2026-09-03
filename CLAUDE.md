# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What is this project

**Sintelc FlowTime** (`app.sintelcft.dev`) — middleware that receives biometric attendance punches from ZKTeco devices and syncs them to the Factorial HR API. Multi-tenant: one server handles multiple client companies, each with its own Factorial OAuth connection.

## Commands

```bash
# Local dev
php artisan serve
npm run dev

# Queue worker (must be running to process sync jobs)
php artisan queue:work --sleep=1 --tries=1

# After every deploy to production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Resolve attendance logs that arrived without a mapped employee
php artisan attendance:resolve-pending

# Close shifts left open past "horas asignadas + 4h margin" (checkin_only
# clients, or normal clients with auto_close_forgotten_shifts on). Runs
# hourly via the scheduler (routes/console.php) — see "Scheduler" below.
php artisan attendance:close-forgotten-shifts

# Sync Factorial employees/locations for a connection
php artisan factorial:sync-employees
php artisan factorial:sync-locations

# Push users from Factorial to a ZKTeco device
php artisan biometric:push-users

# Tests
php artisan test
php artisan test --filter=TestClassName
```

## Architecture

### Data flow

```
ZKTeco device → POST /iclock/cdata?table=ATTLOG
    → IclockController::handleAttlog()
        → AttendanceLog::insert() (bulk)
        → SyncAttendanceToFactorial::dispatch() per resolved log
            → FactorialService::clockIn() / clockOut()
            → fallback: FactorialService::updateShift() (overwrite)
```

### Key concepts

**AttendanceLog statuses:**
- `pending` — arrived but `factorial_employee_id` is null (employee not mapped yet)
- `resolved` — employee mapped, job dispatched but not yet processed
- `synced` — successfully pushed to Factorial
- `failed` — Factorial rejected it (see `sync_error`)

**Employee resolution:**
`employee_code` (PIN on device) → `BiometricUserSync.external_employee_code` → `factorial_employee_id`. If no mapping exists the log stays `pending` until `attendance:resolve-pending` runs or the device sends a new USERINFO batch.

**check_type mapping** is per-client via `ClientAttendanceConfig` — biometric status codes (0,1,2,3) map to `check_in`, `check_out`, `break_in`, `break_out`.

**Dispatch ordering** — buffered offline records must be dispatched oldest-first (`orderBy('occurred_at')`) with a 2-second delay between jobs so Factorial sees check_out before the next day's check_in.

### Factorial sync fallback

`SyncAttendanceToFactorial` tries `clockIn`/`clockOut` first (chosen per `check_type`). On failure it calls `findOpenShift()` and does `updateShift()` (overwrite), setting `clock_in`/`clock_out` explicitly per `check_type` — this always overwrites the open shift regardless of `in_source` (biometric outranks API/desktop/mobile), unless it was already edited by this same fallback for the same `check_type` (see the `Editado por biométrico SFT` marker in `observations`).

### Forgotten/checkin-only shift closing (`attendance:close-forgotten-shifts`)

`ClientAttendanceConfig.checkin_only` — client never sends `check_out` (device only registers entrada). `ClientAttendanceConfig.auto_close_forgotten_shifts` — enables the same closing mechanism for normal clients as a safety net when an employee forgets to clock out; forced `true` whenever `checkin_only` is `true`.

Mechanism (same for both cases — `checkin_only` only changes how the result is labeled, not the logic):
- Hourly, `CloseForgottenShifts` calls `FactorialService::getOpenShifts()` (native endpoint, filters server-side by `employee_ids` — do **not** reintroduce the old client-side `clock_out === null` filtering pattern from `findOpenShift()`) for every employee under a connection with the feature on.
- For each open shift, reads `FactorialService::getEstimatedTimes()` (`expected_minutes` per employee/date — this is Factorial's own planned-hours data, sourced from whatever schedule/contract the client already configured there; we deliberately don't ask the client to re-enter this in Sintelc FlowTime).
- If `expected_minutes > 0`: close time = `clock_in + expected_minutes` (recorded value, **without** the margin), `reason = 'con_horario'`. Threshold to act = close time `+ 4h`.
- If `expected_minutes == 0`, `getEstimatedTimes()`'s `source` field decides which of two cases this is (verified against the real API, aug 2026 — confirmed by comparing the same employee's `source` on a rest day vs. a workday):
  - `source == 'work_schedule'` → confirmed real rest day (this employee reliably shows `expected_minutes > 0` on workdays under the same `source`). An open shift here is a genuine anomaly (someone clocked in on their day off) regardless of `checkin_only` — `reason = 'descanso_confirmado'`, closes with the 8h default (we don't guess an actual worked duration) but **always** forces the review note/styling in `CloseForgottenShiftJob`, even for `checkin_only=true` clients where everything else is routine.
  - Any other `source` (e.g. `shift_management` — rotating-shift employees) → still ambiguous: `expected_minutes` reads 0 even on workdays when the shift just hasn't been loaded into Factorial yet, so it can't be told apart from a real day off under that scheme. Same fallback as before: 8h default, `reason = 'sin_horario'`, review driven by `checkin_only` as usual.
- Once `now >= threshold`, dispatches `CloseForgottenShiftJob` (retries + backoff, unlike `SyncAttendanceToFactorial`'s `tries=1` — losing this job silently reintroduces the `open_shift` block on the employee's next check-in). The job re-checks `getOpenShifts()` before acting (idempotent: no-ops if a real checkout already closed it) and writes the `No se registró salida` marker to `observations`.
- **`toggle_clock` was tried and rejected, don't retry it as-is:** per Factorial support (ticket with Gustavo Torres, aug 2026), `open_shift` on `clock_in` happens because "Unresolved Shifts" stops applying on 0-planned-hour days, and their official recommendation is `toggle_clock` in real time instead of explicit `clock_in`/`clock_out`. This was implemented twice (commits `74bbc6a`, `4124e52`, aug 6) with the correct `clock_time` payload field, but reverted (`3f6e47e`, aug 16) because **in production it generated incorrect shifts/hours** — worse than the original block. The `CloseForgottenShifts`/`source`-based approach above is the accepted mitigation instead; do not re-propose `toggle_clock` without first understanding why it corrupted shift data last time.
- **Known gap:** for `checkin_only=false` (an actual forgotten-checkout exception, not routine), the intent is to also file a Factorial `edit_timesheet_requests` so it surfaces in the client's native review flow — the exact `request_type` enum value for `POST attendance/edit_timesheet_requests` could not be determined (Factorial's docs site didn't yield the field-level schema, and blind-guessing the enum against a real client's API felt irresponsible). Until that's resolved, the signal lives in two places: a `Log::warning` tagged "REQUIERE REVISIÓN", and (since 2026-08-17) a visible note in the client's `Registros de asistencia` screen — see below.
- Each close also inserts a synthetic `AttendanceLog` (`check_type = check_out`, `sync_status = synced`, `sync_note` prefixed with `CloseForgottenShiftJob::NOTE_PREFIX`) so the closure shows up in `clients/{id}/records` with the note visible under the status badge (amber + bold if it needs review, gray if routine). It needs a `biometric_source_id` (FK, not nullable) even though no real device is involved — uses one synthetic `BiometricSource` per client (`status = 'virtual'`, name "Cierre automático", `firstOrCreate` keyed by `serial_number = "AUTOCLOSE-{client_id}"`). **Any new query that lists `BiometricSource` broadly must exclude `status = 'virtual'`** (device manager, dashboard device counts/recent-devices widget, and the client locations mapping already do this — `employee-sync-manager.blade.php` was audited and fixed on 2026-08-31 — its two unfiltered queries (the device selector and the `$totalSources` count) now exclude virtual too; note its other four `BiometricSource` queries filter by `status = 'active'`, which already excluded it. That count bug was not cosmetic: `$sourcesPerProvider` is keyed by `biometric_provider_id` (null on the virtual source) so the virtual device inflated `$totalSources` without ever adding to `$sourceCount`, making employees mapped to *every* real device render as "Varios" instead of "Todos").

### Multi-tenancy

- `Client` → has many `BiometricSource` devices + `FactorialConnection`
- `FactorialConnection` stores OAuth tokens (encrypted). `FactorialService` is instantiated with a connection.
- `BiometricProvider` groups devices for a client; `BiometricUserSync` maps device PINs to `FactorialEmployee.id`.

### Frontend

All UI is **Livewire Volt** (single-file components with `new class extends Component` in the PHP block). Located in `resources/views/livewire/`. No separate controller classes for UI.

Key components:
- `devices/device-manager` — ZKTeco device list, CSV user import, command queue
- `clients/client-profile` — inline-edit client info + embedded connection manager
- `employees/employee-sync-manager` — map device PINs to Factorial employees
- `dashboard/attendance-stats` — sync metrics with Chart.js donuts (cache 30–300s)
- `connections/connection-manager` — Factorial OAuth connections (embeddable with `:client-filter-id`)

### ZKTeco protocol endpoints (`/iclock/*`, no auth)

- `GET /iclock/getrequest` — device polls for commands; server sends `DATA QUERY USERINFO` etc.
- `POST /iclock/cdata?table=ATTLOG` — attendance punches
- `POST /iclock/cdata?table=USERINFO` — user list from device → stored in `biometric_sources.device_users`
- `POST /iclock/devicecmd` — device acknowledges command execution

### Queue & cache

- Queue driver: `database` (table `jobs`). One worker process via Supervisor.
- Cache driver: `database`. Nav badges cached 60s; dashboard stats 30–300s.
- Cache keys: `nav.unassigned_devices`, `nav.unresolved_employees`, `stats.*`

### Scheduler

Added 2026-08-17 — before this, `routes/console.php` had no scheduled tasks and nothing ran automatically. Requires a system cron entry (not part of the app, doesn't survive a fresh server the way `Schedule::` definitions in code do):

```
# crontab -u www-data -e
* * * * * cd /var/www/sintelcft && php artisan schedule:run >> /dev/null 2>&1
```

Currently scheduled: `attendance:close-forgotten-shifts` (`->hourly()`).

### Production (AWS EC2 t3.micro, us-east-2)

- SSH alias: `sintelcft` → `ubuntu@ip-172-31-8-109`
- App root: `/var/www/sintelcft`
- DB port: 3306 (production) vs 3307 (local)
