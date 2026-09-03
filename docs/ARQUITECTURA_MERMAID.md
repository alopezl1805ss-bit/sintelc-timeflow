# Sintelc FlowTime — Esquema de arquitectura (Mermaid)

Generado el 2026-09-01 a partir del código en `main`.
Cinco vistas: global, modelo de datos, flujo de asistencia, aprovisionamiento de
usuarios al dispositivo y cierre de turnos olvidados.

---

## 1. Vista global — de la ponchada a Factorial

```mermaid
flowchart TB
    subgraph CAMPO["🏢 Campo — sedes del cliente"]
        ZK["Dispositivos ZKTeco<br/>huella · rostro · tarjeta"]
        MLA["Cámaras Provision / MLA<br/>integración en curso"]
    end

    subgraph HTTP["🌐 Capa HTTP — /iclock/* · sin auth · sin CSRF"]
        PING["GET/POST /iclock/ping"]
        GETREQ["GET /iclock/getrequest<br/>el equipo pregunta por comandos"]
        CDATA["POST /iclock/cdata?table=..."]
        DEVCMD["POST /iclock/devicecmd<br/>ACK de comando ejecutado"]
        REG["GET /iclock/registry"]
        PUSH["GET /iclock/push"]
    end

    subgraph CTRL["🎛️ IclockController — handlers por tabla"]
        H_ATT["handleAttlog<br/>fichajes en lote"]
        H_RT["handleRtlog<br/>fichaje en tiempo real"]
        H_USER["handleUserInfo<br/>USERINFO · user"]
        H_OP["handleOperlog<br/>OPERLOG"]
        H_BIO["handleBiodata<br/>BIODATA · clonado"]
    end

    subgraph SVC["⚙️ Servicios de dispositivo"]
        S_INFO["DeviceInfoService<br/>firmware · contadores"]
        S_INV["DeviceInventoryService<br/>snapshot del padrón"]
        S_VER["DeviceAssignmentVerificationService<br/>confirma altas/bajas"]
        S_MAT["DeviceAssignmentMaterializer<br/>PIN → identidad"]
        S_LIFE["DeviceCommandLifecycleService<br/>sent → acked"]
        S_BATCH["DeviceSyncBatchService<br/>crea lotes + comandos"]
        S_ONB["DeviceOnboardingService"]
        S_PROTO["DeviceProtocolResolver<br/>perfil push del equipo"]
        S_REC["DeviceReconciliationService"]
        S_REF["DeviceEmployeeRefreshService"]
        S_AGG["DeviceAggregateVerificationService"]
        S_OFF["EmployeeOffboardingService"]
    end

    subgraph DB["🗄️ MySQL"]
        T_LOG["attendance_logs<br/>pending → resolved → synced/failed"]
        T_SRC["biometric_sources"]
        T_SYNC["biometric_user_syncs<br/>mapa PIN ↔ empleado"]
        T_ASG["device_user_assignments"]
        T_CMD["device_commands"]
        T_BATCH["device_sync_batches / items"]
        T_INV["device_inventory_snapshots / users"]
        T_EMP["factorial_employees"]
        T_CONN["factorial_connections<br/>tokens OAuth cifrados"]
        T_CFG["client_attendance_configs"]
    end

    subgraph QUEUE["📮 Cola — driver database · 1 worker Supervisor"]
        J_SYNC["SyncAttendanceToFactorial<br/>tries=1"]
        J_CLOSE["CloseForgottenShiftJob<br/>reintentos + backoff"]
        J_CONN["SyncFactorialConnection"]
        J_CSV["ProcessCsvAutoMatch"]
    end

    FS["🔌 FactorialService<br/>un objeto por FactorialConnection"]
    FAPI["☁️ API Factorial HR"]

    subgraph CRON["⏰ Scheduler + CLI"]
        SCHED["cron → schedule:run<br/>attendance:close-forgotten-shifts · hourly"]
        CMD1["attendance:resolve-pending"]
        CMD2["factorial:sync-employees / sync-locations"]
        CMD3["biometric:push-users"]
        CMD4["factorial:list-open-shifts"]
        CMD5["attendance:import-attlog-dat"]
    end

    subgraph UI["🖥️ UI — Livewire Volt · auth + rol"]
        U_DASH["dashboard/attendance-stats<br/>dashboard/device-status"]
        U_DEV["devices/device-manager<br/>devices/device-onboarding"]
        U_EMP["employees/employee-sync-manager"]
        U_CLI["clients/client-manager · client-profile<br/>clients/attendance-records"]
        U_CONN["connections/connection-manager"]
    end

    OAUTH["FactorialAuthController<br/>/oauth/factorial/redirect · callback"]

    ZK --> PING & GETREQ & CDATA & DEVCMD & REG & PUSH
    MLA -.->|"pendiente de definir"| CDATA

    CDATA --> H_ATT & H_RT & H_USER & H_OP & H_BIO
    GETREQ --> S_INFO
    GETREQ -->|"saca el comando pendiente"| T_CMD
    GETREQ --> S_LIFE
    DEVCMD --> S_LIFE

    H_ATT --> T_LOG
    H_RT --> T_LOG
    H_USER --> S_INV
    H_OP --> S_INV
    H_BIO --> T_CMD

    S_INV --> T_INV
    S_INV --> S_VER
    S_INV --> S_MAT
    S_VER --> T_ASG
    S_VER --> T_BATCH
    S_MAT --> T_SYNC
    S_MAT --> T_ASG
    S_LIFE --> T_CMD
    S_BATCH --> T_BATCH & T_CMD & T_ASG
    S_BATCH --> S_PROTO
    S_ONB --> T_CMD
    S_ONB --> S_PROTO
    S_OFF --> S_BATCH
    S_REF --> S_BATCH
    S_INFO --> T_SRC

    H_ATT -->|"dispatch ordenado por occurred_at<br/>delay 2s entre jobs"| J_SYNC
    H_RT --> J_SYNC
    CMD1 --> J_SYNC
    J_CSV --> J_SYNC

    SCHED --> CLOSE_CMD["CloseForgottenShifts"]
    CLOSE_CMD --> FS
    CLOSE_CMD --> J_CLOSE

    J_SYNC --> FS
    J_CLOSE --> FS
    J_CONN --> FS
    CMD2 --> FS
    CMD4 --> FS
    CMD3 --> S_BATCH

    J_SYNC --> T_LOG
    J_CLOSE -->|"log sintético de cierre"| T_LOG
    J_CONN --> T_EMP
    FS <--> T_CONN
    FS <--> FAPI

    OAUTH --> T_CONN
    OAUTH --> FS

    U_DEV --> S_ONB & S_INV & S_REC & S_BATCH
    U_EMP --> S_OFF
    U_EMP --> J_CONN & J_SYNC
    U_CONN --> J_CONN
    U_CLI --> T_CFG
    U_DASH --> T_LOG & T_SRC
    U_CLI --> T_LOG

    T_CFG -.->|"check_type · checkin_only ·<br/>auto_close_forgotten_shifts"| H_ATT
    T_CFG -.-> CLOSE_CMD
    T_SYNC -.->|"resuelve employee_code →<br/>factorial_employee_id"| H_ATT

    classDef device fill:#dcecef,stroke:#3f8b99,color:#0e2a30
    classDef store fill:#f6ecd8,stroke:#c09a4e,color:#3a2f16
    classDef job fill:#e7e3f4,stroke:#8b7fc4,color:#241f3d
    classDef ext fill:#dff0e2,stroke:#5aa86e,color:#153020
    class ZK,MLA device
    class T_LOG,T_SRC,T_SYNC,T_ASG,T_CMD,T_BATCH,T_INV,T_EMP,T_CONN,T_CFG store
    class J_SYNC,J_CLOSE,J_CONN,J_CSV job
    class FAPI,FS ext
```

---

## 2. Modelo de datos

```mermaid
erDiagram
    USERS }o--o| CLIENTS : "pertenece · role admin|client"
    CLIENTS ||--o{ FACTORIAL_CONNECTIONS : "OAuth por empresa"
    CLIENTS ||--o| CLIENT_ATTENDANCE_CONFIGS : "1 config"
    CLIENTS ||--o{ BIOMETRIC_PROVIDERS : "agrupa equipos"
    CLIENTS ||--o{ BIOMETRIC_SOURCES : "equipos"
    CLIENTS ||--o{ FACTORIAL_LOCATIONS : "sedes"
    CLIENTS ||--o{ FACTORIAL_EMPLOYEES : "empleados"
    CLIENTS ||--o{ BIOMETRIC_USER_SYNCS : "identidades"
    CLIENTS ||--o{ ATTENDANCE_LOGS : "fichajes"
    CLIENTS ||--o{ DEVICE_SYNC_BATCHES : "lotes"
    CLIENTS ||--o{ DEVICE_USER_ASSIGNMENTS : "asignaciones"

    FACTORIAL_CONNECTIONS ||--o{ FACTORIAL_EMPLOYEES : "sincroniza"
    FACTORIAL_CONNECTIONS ||--o{ FACTORIAL_LOCATIONS : "sincroniza"
    FACTORIAL_CONNECTIONS ||--o{ BIOMETRIC_PROVIDERS : "conecta"

    BIOMETRIC_PROVIDERS ||--o{ BIOMETRIC_SOURCES : "contiene"
    BIOMETRIC_PROVIDERS ||--o{ BIOMETRIC_USER_SYNCS : "padrón"

    FACTORIAL_LOCATIONS ||--o{ BIOMETRIC_SOURCES : "ubica"

    BIOMETRIC_SOURCES ||--o{ ATTENDANCE_LOGS : "origina"
    BIOMETRIC_SOURCES ||--o{ DEVICE_COMMANDS : "cola de comandos"
    BIOMETRIC_SOURCES ||--o{ DEVICE_INVENTORY_SNAPSHOTS : "padrón leído"
    BIOMETRIC_SOURCES ||--o{ DEVICE_USER_ASSIGNMENTS : "quién está en el equipo"
    BIOMETRIC_SOURCES ||--o{ DEVICE_SYNC_ITEMS : "renglones de lote"
    BIOMETRIC_SOURCES }o--o| BIOMETRIC_SOURCES : "clone_target_id"

    FACTORIAL_EMPLOYEES ||--o{ BIOMETRIC_USER_SYNCS : "identidad biométrica"
    FACTORIAL_EMPLOYEES ||--o{ ATTENDANCE_LOGS : "dueño del fichaje"
    FACTORIAL_EMPLOYEES ||--o{ DEVICE_USER_ASSIGNMENTS : "asignado a equipos"

    BIOMETRIC_USER_SYNCS ||--o{ DEVICE_USER_ASSIGNMENTS : "materializa"

    DEVICE_SYNC_BATCHES ||--o{ DEVICE_SYNC_ITEMS : "items"
    DEVICE_SYNC_ITEMS ||--o{ DEVICE_COMMANDS : "genera"
    DEVICE_USER_ASSIGNMENTS ||--o{ DEVICE_SYNC_ITEMS : "objetivo"
    DEVICE_INVENTORY_SNAPSHOTS ||--o{ DEVICE_INVENTORY_USERS : "usuarios leídos"
    USERS ||--o{ DEVICE_SYNC_BATCHES : "created_by"

    CLIENTS {
        id bigint PK
        name string
        slug string
    }
    FACTORIAL_CONNECTIONS {
        id bigint PK
        client_id bigint FK
        factorial_company_id bigint "nullable"
        access_token text "cifrado"
        refresh_token text "cifrado"
        email string
    }
    CLIENT_ATTENDANCE_CONFIGS {
        client_id bigint FK
        checkin_only bool "el equipo solo registra entrada"
        auto_close_forgotten_shifts bool "forzado true si checkin_only"
        checkin_id int "código biométrico → check_in"
        checkout_id int
        has_breaks bool
        breakin_id int
        breakout_id int
    }
    BIOMETRIC_SOURCES {
        id bigint PK
        client_id bigint FK "nullable = sin asignar"
        biometric_provider_id bigint FK
        factorial_location_id bigint FK
        serial_number string UK
        status string "active|inactive|virtual"
        onboarding_status string
        push_protocol_profile string
        device_users json
        biodata_cache json
        clone_target_id bigint FK
        last_ping_at datetime
    }
    BIOMETRIC_USER_SYNCS {
        id bigint PK
        client_id bigint FK
        biometric_provider_id bigint FK
        factorial_employee_id bigint FK
        external_employee_code string "PIN del equipo"
        local_name string
        sync_status string
        status string "active|archived"
        archived_at datetime
    }
    DEVICE_USER_ASSIGNMENTS {
        id bigint PK
        biometric_source_id bigint FK
        biometric_user_sync_id bigint FK
        factorial_employee_id bigint FK
        status string "pending|confirmed|removed"
    }
    DEVICE_SYNC_BATCHES {
        id bigint PK
        uuid string
        type string "push|removal"
        origin string
        status string
        total_items int
        confirmed_items int
        failed_items int
        pending_items int
    }
    DEVICE_SYNC_ITEMS {
        id bigint PK
        device_sync_batch_id bigint FK
        biometric_source_id bigint FK
        device_user_assignment_id bigint FK
        status string
    }
    DEVICE_COMMANDS {
        id bigint PK
        biometric_source_id bigint FK
        device_sync_item_id bigint FK
        command_seq int
        payload text
        status string "pending|sent|acked|failed"
    }
    DEVICE_INVENTORY_SNAPSHOTS {
        id bigint PK
        biometric_source_id bigint FK
        origin string "device|manual"
        metadata json
    }
    DEVICE_INVENTORY_USERS {
        id bigint PK
        device_inventory_snapshot_id bigint FK
        biometric_source_id bigint FK
    }
    FACTORIAL_EMPLOYEES {
        id bigint PK
        factorial_connection_id bigint FK
        factorial_id bigint "id remoto"
        full_name string
        login_email string
    }
    FACTORIAL_LOCATIONS {
        id bigint PK
        factorial_connection_id bigint FK
        factorial_id bigint
    }
    ATTENDANCE_LOGS {
        id bigint PK
        client_id bigint FK
        biometric_source_id bigint FK
        factorial_employee_id bigint FK "null = pending"
        factorial_shift_id bigint
        shift_closed bool
        external_event_id string UK
        employee_code string "PIN crudo"
        check_type string "check_in|check_out|break_in|break_out"
        occurred_at datetime
        raw_payload json
        sync_status string "pending|resolved|synced|failed"
        sync_error text
        sync_note text
    }
```

---

## 3. Flujo de un fichaje hasta Factorial

```mermaid
sequenceDiagram
    autonumber
    participant D as Dispositivo ZKTeco
    participant C as IclockController
    participant DB as attendance_logs
    participant Q as Cola database
    participant J as SyncAttendanceToFactorial
    participant F as FactorialService
    participant API as API Factorial

    D->>C: POST /iclock/cdata?table=ATTLOG<br/>lote de registros, incluye buffer offline
    C->>C: splitRecords + cleanDeviceString
    C->>DB: consulta claves existentes → dedup
    C->>DB: insertOrIgnore en bloque

    alt PIN mapeado en biometric_user_syncs
        C->>DB: sync_status = resolved + factorial_employee_id
        C->>Q: dispatch ordenado por occurred_at<br/>delay acumulado de 2 s por job
    else PIN sin mapear
        C->>DB: sync_status = pending
        Note over DB: espera a attendance:resolve-pending<br/>o al siguiente USERINFO
    end
    C-->>D: OK

    Q->>J: procesa el job — tries = 1
    J->>J: check_type según ClientAttendanceConfig
    alt check_in
        J->>F: clockIn
    else check_out
        J->>F: clockOut
    end
    F->>API: POST attendance/shifts/clock_in|clock_out
    alt éxito
        API-->>F: 200
        J->>DB: sync_status = synced + factorial_shift_id
    else rechazo — p. ej. open_shift
        API-->>F: error
        J->>F: findOpenShift
        F->>API: GET shifts del día
        J->>F: updateShift — sobrescribe clock_in/clock_out
        Note over J,API: el biométrico manda sobre API/desktop/mobile,<br/>salvo que ya lleve el marcador<br/>"Editado por biométrico SFT" del mismo check_type
        alt fallback OK
            J->>DB: synced + sync_note
        else también falla
            J->>DB: failed + sync_error
        end
    end
```

---

## 4. Aprovisionamiento de usuarios en el equipo

```mermaid
flowchart TB
    START["UI: device-manager · device-onboarding<br/>employee-sync-manager<br/>o CLI biometric:push-users"]
    START --> BATCH["DeviceSyncBatchService::create<br/>o ::createRemoval"]
    BATCH --> PROTO["DeviceProtocolResolver::resolve<br/>elige perfil push del equipo"]
    BATCH --> ROWS["device_sync_batches + device_sync_items<br/>+ device_user_assignments status=pending"]
    ROWS --> CMDS["device_commands status=pending<br/>DATA UPDATE USERINFO · DELETE USERINFO"]

    CMDS --> POLL{"El equipo hace<br/>GET /iclock/getrequest"}
    POLL -->|"hay comando pendiente"| SEND["DeviceCommandLifecycleService::markSent<br/>respuesta C:seq:CMD"]
    POLL -->|"nada pendiente"| OK["responde OK"]
    SEND --> EXEC["El equipo ejecuta"]
    EXEC --> ACK["POST /iclock/devicecmd<br/>ID + Return"]
    ACK --> LIFE["markAcknowledged<br/>acked | failed"]
    LIFE --> ITEM["actualiza device_sync_item<br/>y contadores del batch"]

    EXEC --> REPORT["El equipo reenvía su padrón<br/>USERINFO · user · OPERLOG"]
    REPORT --> INV["DeviceInventoryService::capture<br/>device_inventory_snapshots + users"]
    INV --> VERIF["DeviceAssignmentVerificationService::verify<br/>¿aparece o desapareció el PIN?"]
    VERIF --> CONF["device_user_assignments → confirmed / removed<br/>cierra el batch"]
    INV --> MAT["DeviceAssignmentMaterializer::materialize<br/>crea/actualiza biometric_user_syncs por PIN"]
    MAT --> RESOLVE["desbloquea attendance_logs en pending"]

    AGG["DeviceAggregateVerificationService<br/>contrasta reported_user_count"] -.-> CONF
    OFF["EmployeeOffboardingService<br/>removeFromSources · archive · reactivate"] --> BATCH
    REC["DeviceReconciliationService::analyze<br/>equipo vs Factorial"] -.-> START
    REF["DeviceEmployeeRefreshService::refresh"] --> BATCH
```

---

## 5. Cierre de turnos olvidados — `attendance:close-forgotten-shifts`

```mermaid
flowchart TB
    CRON["cron * * * * * → schedule:run<br/>Schedule::command hourly"] --> CMD["CloseForgottenShifts"]
    CMD --> SEL{"Clientes con checkin_only<br/>o auto_close_forgotten_shifts"}
    SEL -->|"ninguno"| END1["fin"]
    SEL -->|"por cada conexión"| OPEN["FactorialService::getOpenShifts<br/>endpoint nativo, filtra por employee_ids<br/>⚠ nunca volver al filtrado cliente<br/>clock_out === null de findOpenShift"]
    OPEN --> EST["FactorialService::getEstimatedTimes<br/>expected_minutes + source por empleado/día"]

    EST --> Q1{"expected_minutes > 0"}
    Q1 -->|"sí"| CH["reason = con_horario<br/>cierre = clock_in + expected_minutes<br/>sin el margen"]
    Q1 -->|"no"| Q2{"source == work_schedule"}
    Q2 -->|"sí"| DESC["reason = descanso_confirmado<br/>día de descanso real<br/>cierre = clock_in + 8 h<br/>SIEMPRE marca revisión"]
    Q2 -->|"no · shift_management, etc."| SIN["reason = sin_horario<br/>ambiguo: turno no cargado<br/>cierre = clock_in + 8 h<br/>revisión según checkin_only"]

    CH --> THR{"now >= hora de cierre + 4 h"}
    DESC --> THR
    SIN --> THR
    THR -->|"todavía no"| WAIT["espera a la siguiente hora"]
    THR -->|"sí"| DISP["dispatch CloseForgottenShiftJob<br/>con reintentos + backoff"]

    DISP --> RECHK{"getOpenShifts otra vez<br/>¿sigue abierto?"}
    RECHK -->|"ya cerrado por salida real"| NOOP["no-op · idempotente"]
    RECHK -->|"sigue abierto"| UPD["updateShift<br/>clock_out = hora calculada<br/>observations += No se registró salida"]

    UPD --> SYN["AttendanceLog sintético<br/>check_type=check_out · sync_status=synced<br/>sync_note = NOTE_PREFIX + motivo"]
    SYN --> VSRC["biometric_sources sintético<br/>serial AUTOCLOSE-{client_id} · status=virtual<br/>⚠ toda query de equipos debe excluir virtual"]
    SYN --> UIREC["visible en clients/{id}/records<br/>ámbar+negrita si requiere revisión, gris si es rutina"]
    UPD --> LOGW["Log::warning REQUIERE REVISIÓN"]

    GAP["🚧 Pendiente: edit_timesheet_requests<br/>no se conoce el enum request_type"] -.-> LOGW
    NO["❌ toggle_clock: implementado y revertido dos veces<br/>corrompió turnos/horas en producción"] -.-> CMD
```
