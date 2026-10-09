# VYBES Admin CMS API Contract & PRD Gap Analysis

Baseline: **PRD VYBES v1.2.4 (06 Oktober 2026)**  
Environment Base URL: `/api/admin`  
Middleware Stack: `auth:sanctum`, `admin` (`Role: admin` / `Permission: admin.manage`), `throttle:api-user` (60 req/min)

---

## 1. Verified Admin API Endpoints

### 1.1 Dashboard Metrics
- **METHOD:** `GET`
- **PATH:** `/api/admin/dashboard`
- **AUTHORIZATION:** `admin`
- **QUERY PARAMETERS:** None
- **REQUEST:** None
- **RESPONSE (200 OK):**
  ```json
  {
    "data": {
      "metrics": {
        "total_users": 150,
        "total_bookings": 320,
        "confirmed_bookings": 280,
        "total_venues": 45,
        "total_events": 12,
        "total_revenue": 145000000,
        "total_refunds": 3500000,
        "pending_approvals": 4
      },
      "pending_breakdown": {
        "merchants": 3,
        "organizers": 1
      }
    }
  }
  ```
- **ERRORS:** `401 Unauthorized`, `403 Forbidden`
- **PAGINATION:** None (Aggregate Summary)
- **SIDE EFFECTS:** None (Read-only)
- **AUDIT REQUIREMENT:** None

---

### 1.2 User Management
#### `GET /api/admin/users`
- **METHOD:** `GET`
- **PATH:** `/api/admin/users`
- **AUTHORIZATION:** `admin`
- **QUERY PARAMETERS:**
  - `page` (integer, optional)
  - `per_page` (integer, optional, default: 15, max: 100)
  - `search` (string, optional, searches `name` and `email`)
  - `role_id` (integer, optional)
- **RESPONSE (200 OK):**
  ```json
  {
    "data": [
      {
        "id": 1,
        "name": "David Stanley",
        "email": "david@vybes.local",
        "role_id": 1,
        "role": { "id": 1, "name": "admin", "display_name": "Administrator" },
        "created_at": "2026-10-01T08:00:00.000000Z",
        "updated_at": "2026-10-01T08:00:00.000000Z"
      }
    ],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "from": 1, "last_page": 10, "per_page": 15, "to": 15, "total": 150 }
  }
  ```
- **ERRORS:** `401 Unauthorized`, `403 Forbidden`
- **PAGINATION:** Standard Laravel Paginator

#### `GET /api/admin/users/{user}`
- **METHOD:** `GET`
- **PATH:** `/api/admin/users/{user}`
- **AUTHORIZATION:** `admin`
- **RESPONSE (200 OK):** User details including role and timestamps.
- **ERRORS:** `401`, `403`, `404 Not Found`

#### `PATCH /api/admin/users/{user}`
- **METHOD:** `PATCH`
- **PATH:** `/api/admin/users/{user}`
- **AUTHORIZATION:** `admin`
- **REQUEST (JSON):**
  ```json
  {
    "name": "Jane Doe",
    "role_id": 2
  }
  ```
- **RESPONSE (200 OK):** Updated user object.
- **ERRORS:** `401`, `403`, `404`, `422 Validation Error`
- **SIDE EFFECTS:** Updates user row in database.
- **AUDIT REQUIREMENT:** Logged as `user.role_update` in `audit_logs` table with actor and before/after metadata.

---

### 1.3 Approvals (Merchants & Organizers)
#### `GET /api/admin/approvals`
- **METHOD:** `GET`
- **PATH:** `/api/admin/approvals`
- **AUTHORIZATION:** `admin`
- **RESPONSE (200 OK):**
  ```json
  {
    "data": {
      "merchants": [
        {
          "id": 1,
          "user_id": 5,
          "business_name": "Champion Sports Arena",
          "phone": "08123456789",
          "status": "pending",
          "created_at": "2026-10-01T10:00:00.000000Z",
          "updated_at": "2026-10-01T10:00:00.000000Z",
          "user": {
            "id": 5,
            "name": "Budi Hartono",
            "email": "budi@arena.com"
          }
        }
      ],
      "organizers": [
        {
          "id": 1,
          "user_id": 8,
          "organization_name": "Soundfest Asia",
          "phone": "08198765432",
          "status": "pending",
          "created_at": "2026-10-01T11:00:00.000000Z",
          "updated_at": "2026-10-01T11:00:00.000000Z",
          "user": {
            "id": 8,
            "name": "Sarah Connor",
            "email": "sarah@soundfest.com"
          }
        }
      ]
    }
  }
  ```
- **ERRORS:** `401 Unauthorized`, `403 Forbidden`

#### `POST /api/admin/approvals/merchants/{merchant}`
- **METHOD:** `POST`
- **PATH:** `/api/admin/approvals/merchants/{merchant}`
- **AUTHORIZATION:** `admin`
- **REQUEST (JSON):**
  ```json
  {
    "status": "approved" // or "rejected"
  }
  ```
- **RESPONSE (200 OK):**
  ```json
  {
    "data": {
      "id": 1,
      "user_id": 5,
      "business_name": "Champion Sports Arena",
      "phone": "08123456789",
      "status": "approved",
      "created_at": "2026-10-01T10:00:00.000000Z",
      "updated_at": "2026-10-09T03:30:00.000000Z",
      "user": {
        "id": 5,
        "name": "Budi Hartono",
        "email": "budi@arena.com"
      }
    }
  }
  ```
- **SIDE EFFECTS:** Updates `merchants.approval_status`. Enables inventory publication boundary (PRD BR-013).
- **AUDIT REQUIREMENT:** Logged as `merchant.status_update` with before/after state.

#### `POST /api/admin/approvals/organizers/{organizer}`
- **METHOD:** `POST`
- **PATH:** `/api/admin/approvals/organizers/{organizer}`
- **AUTHORIZATION:** `admin`
- **REQUEST (JSON):**
  ```json
  {
    "status": "approved" // or "rejected"
  }
  ```
- **RESPONSE (200 OK):**
  ```json
  {
    "data": {
      "id": 1,
      "user_id": 8,
      "organization_name": "Soundfest Asia",
      "phone": "08198765432",
      "status": "approved",
      "created_at": "2026-10-01T11:00:00.000000Z",
      "updated_at": "2026-10-09T03:30:00.000000Z",
      "user": {
        "id": 8,
        "name": "Sarah Connor",
        "email": "sarah@soundfest.com"
      }
    }
  }
  ```
- **SIDE EFFECTS:** Updates `organizers.approval_status`. Enables event publication boundary.
- **AUDIT REQUIREMENT:** Logged as `organizer.status_update` with before/after state.

---

### 1.4 Category Management
- **Endpoints:**
  - `GET /api/admin/categories` (paginated list)
  - `POST /api/admin/categories` (create category with `name`, `description`, `icon`, `sort_order`, `status`)
  - `GET /api/admin/categories/{category}` (detail)
  - `PUT /api/admin/categories/{category}` (update)
  - `DELETE /api/admin/categories/{category}` (delete if no venues/events attached)
- **AUTHORIZATION:** `admin`
- **SIDE EFFECTS:** DB mutation on `categories` table.
- **AUDIT REQUIREMENT:** Logged as `category.create`, `category.update`, and `category.delete`.

---

### 1.5 Global Bookings Supervision
- **Endpoints:**
  - `GET /api/admin/bookings` (filter by `status`, `venue_id`, `booking_code`, paginated)
  - `GET /api/admin/bookings/{booking}` (includes user, venue, resource, booking_items, payments)
- **AUTHORIZATION:** `admin`
- **SIDE EFFECTS:** Read-only supervision.

---

### 1.6 Refunds Management
- **Endpoints:**
  - `GET /api/admin/refunds` (filter by `status`, `payment_id`, paginated)
  - `GET /api/admin/refunds/{refund}` (detail with payment & transaction info)
  - `POST /api/admin/refunds` (trigger manual refund: `payment_id`, `amount`, `reason`)
- **AUTHORIZATION:** `admin`
- **SIDE EFFECTS:** Calls `PaymentRefundService` outside database transaction (PRD BR-010); records pending refund row.
- **AUDIT REQUIREMENT:** Logged as `refund.initiate` with actor and sanitized payload.

---

### 1.7 Global Venues Supervision
- **Endpoints:**
  - `GET /api/admin/venues` (filter by `status`, `merchant_id`, `category_id`, paginated)
  - `GET /api/admin/venues/{venue}` (detail with merchant, category, and resources)
- **AUTHORIZATION:** `admin`
- **SIDE EFFECTS:** Read-only supervision.

---

### 1.8 Platform Settings (PRD BR-003)
#### `GET /api/admin/settings`
- **METHOD:** `GET`
- **PATH:** `/api/admin/settings`
- **AUTHORIZATION:** `admin`
- **RESPONSE (200 OK):**
  ```json
  {
    "data": [
      {
        "id": 1,
        "key": "booking_hold_duration_minutes",
        "value": 15,
        "type": "integer",
        "description": "Default hold duration for resource booking and ticket reservation in minutes (PRD BR-003).",
        "updated_at": "2026-10-01T10:00:00.000000Z"
      }
    ]
  }
  ```
- **ERRORS:** `401 Unauthorized`, `403 Forbidden`

#### `PATCH /api/admin/settings`
- **METHOD:** `PATCH`
- **PATH:** `/api/admin/settings`
- **AUTHORIZATION:** `admin`
- **REQUEST (JSON):**
  ```json
  {
    "booking_hold_duration_minutes": 20
  }
  ```
- **VALIDATION:** `booking_hold_duration_minutes: integer|min:1|max:1440`
- **RESPONSE (200 OK):**
  ```json
  {
    "message": "Platform settings updated successfully.",
    "data": [ ... ]
  }
  ```
- **RUNTIME CONSUMER:** Injected into `BookingService::createHold()` and `EventTicketPurchaseService::createHeldOrder()` at hold creation.
- **AUDIT REQUIREMENT:** Logged as `platform_setting.update` with old and new values.

---

### 1.9 Audit Log (PRD Section 15.3, 15.4)
#### `GET /api/admin/audit`
- **METHOD:** `GET`
- **PATH:** `/api/admin/audit`
- **AUTHORIZATION:** `admin`
- **QUERY PARAMETERS:**
  - `page` (integer)
  - `per_page` (integer, max 100)
  - `actor_id` (integer)
  - `action` (string)
  - `entity_type` (string: `category`, `merchant`, `organizer`, `user`, `refund`, `platform_setting`)
  - `from_date` (ISO date)
  - `to_date` (ISO date)
- **RESPONSE (200 OK):**
  ```json
  {
    "data": [
      {
        "id": 1,
        "actor_id": 1,
        "actor": {
          "id": 1,
          "name": "David Stanley",
          "email": "david@vybes.local"
        },
        "action": "category.create",
        "entity_type": "category",
        "entity_id": "4",
        "metadata": { "name": "Badminton Arena" },
        "before": null,
        "after": { "name": "Badminton Arena" },
        "ip_address": "127.0.0.1",
        "user_agent": "Mozilla/5.0 ...",
        "created_at": "2026-10-08T03:15:00.000000Z"
      }
    ],
    "meta": { "current_page": 1, "total": 45, "per_page": 20 }
  }
  ```
- **CONTRACT CHARACTERISTICS:** Append-only database table. Read-only API (mutations return 405 Method Not Allowed). Sensitive credentials (`password`, `token`, `secret`, `x-callback-token`) are strictly sanitized to `[REDACTED]`.

---

## 2. ADMIN USER STATUS GAP ANALYSIS

### 2.1 PRD Requirement
PRD Section 5.2 (Hak Akses Inti Admin) dan FR-015 menyatakan bahwa Administrator bertanggung jawab untuk:
- "Menyetujui/menangguhkan user dan operator"

### 2.2 Existing Schema & State Machine
1. Tabel `users`:
   - `id`, `name`, `email`, `password`, `role_id`, `created_at`, `updated_at`.
   - **TIDAK ADA kolom `status`** (misalnya `active`, `suspended`, `banned`).
2. Tabel `merchants` dan `organizers`:
   - Memiliki kolom `approval_status` (`pending`, `approved`, `rejected`).
   - Operator dapat ditangguhkan/ditolak pada level profil bisnis, namun akun `users` mereka tetap ada.

### 2.3 Impact Analysis (Why NOT inventing arbitrary status)
1. **Impact on Authentication:**
   Jika `status: suspended` ditambahkan tanpa state machine terpadu:
   - Sanctum token revocation tidak otomatis terjadi saat status berubah. User yang sedang memiliki token tetap dapat melakukan request API.
   - Login guard (`AuthController@login`) saat ini hanya memeriksa kredensial email & password tanpa filter status.
2. **Impact on Authorization & Relations:**
   - Seorang customer yang di-suspend masih memiliki booking atau tiket aktif. Apakah tiketnya otomatis dibatalkan dan direfund? PRD belum menetapkan aturan bisnis pembatalan massal akibat penangguhan akun user.
3. **Recommendation for Product Owner:**
   Status harus dirancang sebagai state machine formal:
   - Column: `users.status` ENUM (`active`, `suspended`, `deactivated`) dengan migration terarah.
   - Event listener: `UserSuspended` -> mencabut seluruh Sanctum personal access token (`$user->tokens()->delete()`).
   - Guard: `auth:sanctum` middleware memeriksa apakah `$user->status === 'active'`.
   - Frontend: Mengganti modal peringatan dengan tombol aksi "Suspend User" yang terotorisasi penuh.

---

## 3. MODERATION REQUIREMENT MAPPING

PRD Section 4.2 menyatakan: *"Seluruh pelaporan admin lanjutan dan alur moderasi ditunda."*

### Requirement Mapping Table

| MODERATION TARGET | Existing Entity | Existing Status | Existing Transitions | Admin Permission | Existing Endpoint | Missing Contract |
|---|---|---|---|---|---|---|
| **Venue Content** | `venues` | `draft`, `published`, `inactive` | `draft` -> `published` -> `inactive` | `venue.manage`, `admin.manage` | `GET /api/admin/venues/{id}` | Endpoint admin untuk override publication status venue belum dibuat; diatur oleh merchant pemilik venue. |
| **Event Content** | `events` | `draft`, `published`, `cancelled` | `draft` -> `published`, `published` -> `cancelled` (with automated refund) | `event.manage`, `admin.manage` | `GET /api/admin/dashboard` | Alur flagging/penolakan konten event oleh platform admin belum didefinisikan di PRD. |
| **Reviews Moderation** | `reviews` | None | N/A | Undefined | None | Entitas `reviews` belum ada di skema database. |

**Keputusan Desain:** Modul `ModerationView.vue` dipertahankan sebagai placeholder view resmi tanpa mock data.

---

## 4. REVIEW CONTRACT GAP (FR-016)

PRD FR-016: *"Customer dapat mengirim ulasan setelah booking/event yang memenuhi syarat selesai."*  
Status PRD: **Direncanakan (Planned)**.

### Gap Specification:
1. **Missing Schema:**
   - Tidak ada tabel `reviews`.
   - Diperlukan tabel `reviews` dengan foreign keys: `user_id`, polymorphic target `reviewable_type` (`App\Models\Venue` atau `App\Models\Event`), `rating` (1-5), `comment` (text), `status` (`pending`, `published`, `hidden`).
   - Diperlukan unique constraint: `(user_id, booking_id)` atau `(user_id, ticket_id)` agar satu tiket/booking hanya dapat diulas sekali.
2. **Missing Lifecycle & API:**
   - Customer endpoint: `POST /api/reviews` (hanya setelah booking berstatus `confirmed` dan jadwal selesai, atau tiket berstatus `checked_in`).
   - Admin moderation endpoint: `PATCH /api/admin/reviews/{review}` (`hide` / `publish`).
3. **Frontend Status:** `ReviewsView.vue` berstatus explicit placeholder state sesuai arahan prompt.

---

## 5. REPORTS REQUIREMENT MATRIX

PRD Section 4.2 secara eksplisit menunda: *"Analitik/BI tingkat produksi penuh dan seluruh pelaporan admin lanjutan."*

### Operational Monitoring vs Advanced BI Matrix

| REPORT | SOURCE DATA | AGGREGATION | TIME RANGE | FILTER | EXPORT FORMAT | AUTHORIZATION | STATUS |
|---|---|---|---|---|---|---|---|
| **Executive Summary Metrics** | `users`, `bookings`, `venues`, `events`, `payments`, `payment_refunds` | `COUNT()`, `SUM(amount)` | All-time | None | JSON API | `admin.manage` | **IMPLEMENTED** (Dashboard API) |
| **Merchant Revenue Settlement** | `bookings`, `merchants`, `payments` | Group by merchant, sum revenue net of refunds | Monthly / Custom | Merchant, Category | CSV / XLSX | `admin.manage` | **DEFERRED** (PRD Section 4.2) |
| **Organizer Ticket Sales Report** | `event_ticket_orders`, `events`, `event_tickets` | Sum sold, reserved, quota variance | Per-event | Organizer, Event | CSV | `admin.manage` | **DEFERRED** (PRD Section 4.2) |
| **Tax & Financial Compliance BI** | Financial ledger | Net transaction fee | Quarterly | Tax rate | PDF | `admin.manage` | **DEFERRED** |

**Keputusan Desain:** Modul `ReportsView.vue` dipertahankan sebagai placeholder view resmi dengan penanda PRD trace.
