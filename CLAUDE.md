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

### Production (AWS EC2 t3.micro, us-east-2)

- SSH alias: `sintelcft` → `ubuntu@ip-172-31-8-109`
- App root: `/var/www/sintelcft`
- DB port: 3306 (production) vs 3307 (local)
