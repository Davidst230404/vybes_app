# VYBES Admin CMS — Security & Authorization Audit

Baseline: **PRD VYBES v1.2.4 (06 Oktober 2026)**  
Scope: **RBAC, Authorization Gates, IDOR, Mass Assignment, Input Sanitization, Rate Limiting, and Immutability**

---

## 1. Authentication & Authorization Matrix

| Access Attempt | Request Type | Expected Status | Enforcement Mechanism |
|---|---|---|---|
| No Bearer Token | Any `/api/admin/*` | `401 Unauthorized` | Laravel Sanctum middleware (`auth:sanctum`) |
| Invalid / Expired Token | Any `/api/admin/*` | `401 Unauthorized` | PersonalAccessToken expiration / token hash mismatch |
| Customer Token | Any `/api/admin/*` | `403 Forbidden` | `EnsureUserIsAdmin` middleware (`hasRole('admin') \|\| hasPermission('admin.manage')`) |
| Merchant Token | Any `/api/admin/*` | `403 Forbidden` | `EnsureUserIsAdmin` middleware |
| Organizer Token | Any `/api/admin/*` | `403 Forbidden` | `EnsureUserIsAdmin` middleware |
| Admin Token | Any `/api/admin/*` | `200 OK` / `201 Created` | Authorized access |

---

## 2. Insecure Direct Object References (IDOR) & Scope Audit

### 2.1 Route Model Binding Hardening
Every parameterized route under `/api/admin` enforces numeric identifier validation using regex pattern constraints (`whereNumber`):
- `/users/{user}` ➔ `whereNumber('user')`
- `/approvals/merchants/{merchant}` ➔ `whereNumber('merchant')`
- `/approvals/organizers/{organizer}` ➔ `whereNumber('organizer')`
- `/categories/{category}` ➔ `whereNumber('category')`
- `/bookings/{booking}` ➔ `whereNumber('booking')`
- `/refunds/{refund}` ➔ `whereNumber('refund')`
- `/venues/{venue}` ➔ `whereNumber('venue')`

Non-numeric malicious inputs (e.g., path traversal attempts or SQL injection payloads) are rejected immediately by Laravel router with `404 Not Found`.

### 2.2 Global Supervision Scope
- Non-admin users are restricted to their own models via tenancy/ownership scopes (e.g. `CustomerBookingController`, `OrganizerEventController`).
- Admin endpoints purposefully operate as platform-wide supervision across all merchants, venues, users, and bookings, strictly gated by the `admin` middleware.

---

## 3. Mass Assignment & Parameter Tampering Prevention

### 3.1 Strict Form Request Isolation
Controllers never pass raw `$request->all()` into Eloquent model mutators:
- `UserController@update` accepts only `$request->validated()` from `UpdateUserRequest` (`name`, `email`, `role_id`). FormRequest excludes `password`, `created_at`, `email_verified_at`, and unvalidated fields.
- `CategoryController@store` & `update` use `StoreCategoryRequest` and `UpdateCategoryRequest` (`name`, `description`, `icon`, `is_active`, `sort_order`).
- `RefundController@store` uses `CreateRefundRequest` (`payment_id`, `amount`, `reason`).
- `PlatformSettingController@update` uses `UpdatePlatformSettingsRequest` with bounded integers.

---

## 4. Audit Log Immutability & Sensitive Credential Sanitization

### 4.1 Immutability Enforcement
1. **API Level:** The `/api/admin/audit` route provides only `GET` (read-only). Any `POST`, `PUT`, `PATCH`, or `DELETE` attempt returns `405 Method Not Allowed` or `404 Not Found`.
2. **Model Level:** `AuditLog::booted()` defines event listeners:
   - `static::updating()` ➔ throws `\LogicException('AuditLog records are append-only and cannot be updated.')`.
   - `static::deleting()` ➔ throws `\LogicException('AuditLog records are immutable and cannot be deleted.')`.
   Verified by automated unit and feature tests.

### 4.2 Credential Redaction
`AuditLogger::sanitize()` automatically recurses through all payload keys and replaces sensitive values with `'[REDACTED]'`:
- `password`
- `password_confirmation`
- `token`
- `access_token`
- `bearer_token`
- `secret`
- `x-callback-token`
- `api_key`
- `credit_card`
- `cvv`

---

## 5. Rate Limiting & Abuse Protection

### 5.1 Named Rate Limiters (PRD Section 5.1 & SEC-H04)
Admin routes are bound to the `throttle:api-user` rate limiter:
- Limit: 60 requests per minute per authenticated user ID (with fallback to client IP).
- Prevents script burst abuse and denial-of-service on analytical aggregation endpoints like `/api/admin/dashboard`.

---

## 6. SQL Injection & Query Safety
1. **Parameter Binding:** All queries utilize Eloquent ORM parameterized queries (`where()`, `whereIn()`, `lockForUpdate()`).
2. **Search Parameters:** Where wildcard matching is used (e.g. `VenueController` and `UserController`), inputs are passed as bound parameters (`where('name', 'ilike', $search)` and `where('booking_code', 'like', $code)`). Raw SQL string concatenation is not used anywhere in the admin stack.
