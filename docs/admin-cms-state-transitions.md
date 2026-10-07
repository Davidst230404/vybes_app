# VYBES Admin CMS — Domain State Transitions Audit

Baseline: **PRD VYBES v1.2.4 (06 Oktober 2026)**  
Scope: **Domain State Machines, Transition Rules, Atomicity, and Idempotency**

---

## 1. State Machine Inventory

### 1.1 Merchant Approval
- **Table / Field:** `merchants.status` (`VARCHAR(20)`)
- **Allowed States:** `pending`, `approved`, `rejected`
- **Valid Transitions:**
  - `pending` ➔ `approved` (Admin approval triggers ability to publish venues)
  - `pending` ➔ `rejected` (Admin rejects incomplete application)
  - `rejected` ➔ `approved` (Admin re-evaluates revised application)
  - `approved` ➔ `rejected` (Admin revokes operator status)
- **Invalid Transitions:** Arbitrary string statuses (e.g., `active`, `suspended`, `banned` — rejected by `ApprovalRequest` via `in:approved,rejected,pending`).
- **Trigger Actor:** Platform Administrator (`EnsureUserIsAdmin`).
- **Atomicity & Idempotency:**
  - Repeated request with the same status returns early with HTTP 200 without creating redundant audit log entries.
  - Updates occur on single row with indexed `status`.

---

### 1.2 Organizer Approval
- **Table / Field:** `organizers.status` (`VARCHAR(20)`)
- **Allowed States:** `pending`, `approved`, `rejected`
- **Valid Transitions:**
  - `pending` ➔ `approved` (Admin approval triggers event creation & publication boundary)
  - `pending` ➔ `rejected`
  - `rejected` ➔ `approved`
  - `approved` ➔ `rejected`
- **Invalid Transitions:** Arbitrary string values.
- **Trigger Actor:** Platform Administrator.
- **Atomicity & Idempotency:** Idempotent early return; audited on actual transition.

---

### 1.3 Venue Publication
- **Table / Field:** `venues.status` (`VARCHAR(20)`)
- **Allowed States:** `draft`, `published`, `inactive`
- **Valid Transitions:**
  - `draft` ➔ `published` (Allowed only if merchant is `approved`)
  - `published` ➔ `inactive`
  - `inactive` ➔ `published`
- **Admin Boundary:** Admin has **read-only supervision** (`GET /api/admin/venues`, `GET /api/admin/venues/{venue}`). Publication lifecycle belongs to the merchant owner. Admin does not arbitrary force-override venue status without merchant coordination.

---

### 1.4 Booking Lifecycle (PRD Section 6.1 & 6.4)
- **Table / Field:** `bookings.status` (`VARCHAR(20)`)
- **Allowed States:** `held`, `confirmed`, `expired`, `cancelled`
- **Valid Transitions:**
  - `held` ➔ `confirmed` (Triggered via `XenditWebhookController` upon payment capture)
  - `held` ➔ `expired` (Triggered via `ExpireBookingHolds` console command when `hold_expires_at <= now()`)
  - `held` ➔ `cancelled` (Triggered by customer before hold expiration)
- **Admin Boundary:** Admin has **read-only supervision** (`GET /api/admin/bookings`, `GET /api/admin/bookings/{booking}`). Admin does not manually mutate booking state directly; status is strictly driven by transactions and provider webhooks.

---

### 1.5 Payment Lifecycle
- **Table / Field:** `payments.status` (`VARCHAR(20)`)
- **Allowed States:** `pending`, `paid`, `failed`, `expired`
- **Valid Transitions:**
  - `pending` ➔ `paid` (Triggered by verified Xendit webhook `payment.capture`)
  - `pending` ➔ `failed` / `expired`
- **PRD Invariant (BR-015):** Status payment tidak pernah berubah menjadi `refunded`. Pembayaran tetap berstatus `paid` secara historis dan akuntansi, sementara status pengembalian dana dicatat secara independen di entitas `payment_refunds`.

---

### 1.6 Payment Refund Lifecycle (PRD BR-010 & BR-015)
- **Table / Field:** `payment_refunds.status` (`VARCHAR(20)`)
- **Allowed States:** `pending`, `succeeded`, `failed`
- **Valid Transitions:**
  - `pending` ➔ `succeeded` (Triggered by Xendit webhook `refund.succeeded`)
  - `pending` ➔ `failed` (Triggered by Xendit webhook `refund.failed`)
- **Trigger Actor:**
  1. System automatic refund on late payment / event cancellation.
  2. Platform Administrator via `POST /api/admin/refunds`.
- **Concurrency & Locking:**
  - `PaymentRefundService` utilizes `lockForUpdate()` on the `payments` record to verify `status === 'paid'` and checks for existing `pending` or `succeeded` refunds before creating a new refund row.
  - Xendit external API call occurs strictly **after** the database transaction commits (PRD BR-010).

---

### 1.7 Event Ticket Order Lifecycle (PRD Section 6.2, 6.3, 6.4)
- **Table / Field:** `event_ticket_orders.status` (`VARCHAR(20)`)
- **Allowed States:** `held`, `confirmed`, `expired`, `cancelled`
- **Valid Transitions:**
  - `held` ➔ `confirmed` (Reserved quota converts to sold quota)
  - `held` ➔ `expired` (Reserved quota released back to available)
  - `held` ➔ `cancelled`
- **Late Payment Recovery (PRD Section 6.3):**
  - If `payment.capture` arrives for an `expired` order, `LatePaymentRecoveryService` attempts re-acquisition of quota with row-level locking.
  - If quota is unavailable, order remains expired and full automated refund is scheduled.

---

### 1.8 Category Taxonomy
- **Table / Field:** `categories.is_active` (`BOOLEAN`)
- **Lifecycle Operations:**
  - Create: slug auto-generated and unique.
  - Update: slug updated if name changes.
  - Delete: Guarded by foreign relationship (`venues()->exists()`). If venues are attached, returns HTTP 422 preventing orphaned venues.

---

### 1.9 User Role Assignment
- **Table / Field:** `users.role_id` (`BIGINT`, references `roles.id`)
- **Cardinality:** 1 User = 1 Role.
- **Allowed Mutations:** Admin can update `role_id` via `PATCH /api/admin/users/{user}`.
- **Validation:** Enforced via `Rule::exists('roles', 'id')`. Cannot assign invalid or non-existent role IDs.
- **User Account Status:** **TIDAK ADA KOLOM STATUS PADA TABEL USERS**. (Lihat Analisis User Suspension Gap).
