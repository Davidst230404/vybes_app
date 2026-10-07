# VYBES Admin CMS — Full API Inventory

Baseline: **PRD VYBES v1.2.4 (06 Oktober 2026)**  
Environment: `Laravel 13 REST API`  
Base Prefix: `/api/admin`  
Global Middleware Stack:
- `auth:sanctum`: Personal access token authentication.
- `admin`: Ensures user possesses role `admin` or permission `admin.manage`.
- `throttle:api-user`: Named rate limiter at 60 requests per minute per authenticated user (fallback to IP).

---

## Complete Route Inventory Table (22 Routes)

| # | Method | URI | Controller & Action | Request Validation | Response Resource | DB Entities | Tx | Audit Log | Rate Limit |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `GET` | `/api/admin/dashboard` | `DashboardController@index` | None | JSON Data Array | `User`, `Booking`, `Venue`, `Event`, `Payment`, `PaymentRefund`, `Merchant`, `Organizer` | No | None (Read-only) | 60/min |
| 2 | `GET` | `/api/admin/users` | `UserController@index` | Query params (optional) | `UserResource::collection` | `User`, `Role` | No | None (Read-only) | 60/min |
| 3 | `GET` | `/api/admin/users/{user}` | `UserController@show` | Route model binding (`whereNumber`) | `UserResource` | `User`, `Role`, `Merchant`, `Organizer` | No | None (Read-only) | 60/min |
| 4 | `PATCH` | `/api/admin/users/{user}` | `UserController@update` | `UpdateUserRequest` | `UserResource` | `User`, `Role` | No | `user.update` | 60/min |
| 5 | `GET` | `/api/admin/approvals` | `ApprovalController@index` | Query `status` | JSON (`merchants`, `organizers`) | `Merchant`, `Organizer`, `User` | No | None (Read-only) | 60/min |
| 6 | `POST` | `/api/admin/approvals/merchants/{merchant}` | `ApprovalController@updateMerchantStatus` | `ApprovalRequest` | JSON (`Merchant`) | `Merchant`, `User` | No | `merchant.approval` | 60/min |
| 7 | `POST` | `/api/admin/approvals/organizers/{organizer}` | `ApprovalController@updateOrganizerStatus` | `ApprovalRequest` | JSON (`Organizer`) | `Organizer`, `User` | No | `organizer.approval` | 60/min |
| 8 | `GET` | `/api/admin/categories` | `CategoryController@index` | None | `CategoryResource::collection` | `Category`, `Venue` (count) | No | None (Read-only) | 60/min |
| 9 | `POST` | `/api/admin/categories` | `CategoryController@store` | `StoreCategoryRequest` | `CategoryResource` | `Category` | No | `category.create` | 60/min |
| 10 | `GET` | `/api/admin/categories/{category}` | `CategoryController@show` | Route model binding (`whereNumber`) | `CategoryResource` | `Category`, `Venue` (count) | No | None (Read-only) | 60/min |
| 11 | `PUT` | `/api/admin/categories/{category}` | `CategoryController@update` | `UpdateCategoryRequest` | `CategoryResource` | `Category` | No | `category.update` | 60/min |
| 12 | `DELETE` | `/api/admin/categories/{category}` | `CategoryController@destroy` | Route model binding (`whereNumber`) | JSON Message | `Category`, `Venue` | No | `category.delete` | 60/min |
| 13 | `GET` | `/api/admin/bookings` | `BookingController@index` | Query params (optional) | `BookingResource::collection` | `Booking`, `User`, `Venue` | No | None (Read-only) | 60/min |
| 14 | `GET` | `/api/admin/bookings/{booking}` | `BookingController@show` | Route model binding (`whereNumber`) | `BookingResource` | `Booking`, `User`, `Venue`, `BookingItem`, `Resource`, `Payment` | No | None (Read-only) | 60/min |
| 15 | `GET` | `/api/admin/refunds` | `RefundController@index` | Query `status`, `per_page` | `RefundResource::collection` | `PaymentRefund`, `Payment` | No | None (Read-only) | 60/min |
| 16 | `GET` | `/api/admin/refunds/{refund}` | `RefundController@show` | Route model binding (`whereNumber`) | `RefundResource` | `PaymentRefund`, `Payment` | No | None (Read-only) | 60/min |
| 17 | `POST` | `/api/admin/refunds` | `RefundController@store` | `CreateRefundRequest` | `RefundResource` | `Payment`, `PaymentRefund` | Yes (in service) | `refund.create` | 60/min |
| 18 | `GET` | `/api/admin/venues` | `VenueController@index` | Query params (optional) | `VenueResource::collection` | `Venue`, `Merchant`, `Category` | No | None (Read-only) | 60/min |
| 19 | `GET` | `/api/admin/venues/{venue}` | `VenueController@show` | Route model binding (`whereNumber`) | `VenueResource` | `Venue`, `Merchant`, `Category`, `Resource` | No | None (Read-only) | 60/min |
| 20 | `GET` | `/api/admin/settings` | `PlatformSettingController@index` | None | `PlatformSettingResource::collection` | `PlatformSetting` | No | None (Read-only) | 60/min |
| 21 | `PATCH` | `/api/admin/settings` | `PlatformSettingController@update` | `UpdatePlatformSettingsRequest` | `PlatformSettingResource::collection` | `PlatformSetting` | No | `platform_setting.update` | 60/min |
| 22 | `GET` | `/api/admin/audit` | `AuditLogController@index` | Query params (optional) | `AuditLogResource::collection` | `AuditLog`, `User` | No | None (Read-only) | 60/min |

---

## Detailed Endpoint Breakdown

### 1. Dashboard Metrics
- **Route:** `GET /api/admin/dashboard`
- **Controller Action:** `App\Http\Controllers\Api\Admin\DashboardController@index`
- **Authorization:** `EnsureUserIsAdmin` (`hasRole('admin') || hasPermission('admin.manage')`).
- **Domain Logic:** Executes real database aggregate counts and financial sums:
  - Total users, bookings, confirmed bookings, venues, events.
  - Total paid revenue (`Payment::where('status', 'paid')->sum('amount')`).
  - Total succeeded refunds (`PaymentRefund::where('status', 'succeeded')->sum('amount')`).
  - Pending merchant and organizer approval counts.
- **Side Effects:** None.
- **Audit Logging:** None.

### 2. User Management
- **List:** `GET /api/admin/users` — Paginated list (default 15, max 100). Filterable by `search` (name/email) and `role_id`. Eager loads `role`.
- **Detail:** `GET /api/admin/users/{user}` — Single user detail with `role.permissions`, `merchant`, `organizer`.
- **Update:** `PATCH /api/admin/users/{user}`
  - FormRequest: `UpdateUserRequest` validates `name` (string, max 100), `email` (email, max 255, unique except self), `role_id` (exists:roles,id).
  - Security: Prevents mass assignment or status injection (`password` and arbitrary fields are unvalidated and excluded).
  - Audit: Logs `user.update` with actor, entity ID, updated fields, old role ID, new role ID.

### 3. Approvals (Merchants & Organizers)
- **List:** `GET /api/admin/approvals` — Retrieves pending merchants and organizers with `user` relation.
- **Update Merchant:** `POST /api/admin/approvals/merchants/{merchant}`
  - FormRequest: `ApprovalRequest` validates `status in:approved,rejected,pending`.
  - Idempotency: Returns early if status is already set to requested value without creating duplicate audit log entries.
  - Audit: Logs `merchant.approval` with old and new status.
- **Update Organizer:** `POST /api/admin/approvals/organizers/{organizer}`
  - FormRequest: `ApprovalRequest` validates `status in:approved,rejected,pending`.
  - Idempotency: Returns early if status is already set to requested value.
  - Audit: Logs `organizer.approval` with old and new status.

### 4. Category Management
- **List:** `GET /api/admin/categories` — Eager loads `venues_count`, sorted by `sort_order`.
- **Create:** `POST /api/admin/categories` — `StoreCategoryRequest` validates `name`, `description`, `icon`, `sort_order`, `is_active`. Generates unique slug via `Str::slug`. Audited as `category.create`.
- **Detail:** `GET /api/admin/categories/{category}` — Includes `venues_count`.
- **Update:** `PUT /api/admin/categories/{category}` — `UpdateCategoryRequest` validates parameters; regenerate slug if name changed. Audited as `category.update`.
- **Delete:** `DELETE /api/admin/categories/{category}` — Deletion blocked with HTTP 422 if venues are assigned. Audited as `category.delete`.

### 5. Bookings Supervision
- **List:** `GET /api/admin/bookings` — Paginated list with filters `booking_code`, `status`, `venue_id`. Eager loads `user`, `venue`.
- **Detail:** `GET /api/admin/bookings/{booking}` — Deep detail eager loads `user`, `venue`, `items.resource`, `payment`.

### 6. Refunds Management
- **List:** `GET /api/admin/refunds` — Paginated list with `status` filter. Eager loads `payment`.
- **Detail:** `GET /api/admin/refunds/{refund}` — Eager loads `payment`.
- **Store:** `POST /api/admin/refunds`
  - FormRequest: `CreateRefundRequest` validates `payment_id` (exists:payments,id), `amount` (numeric, min 1), `reason` (string, max 100).
  - Business Service: Delegates to `PaymentRefundService::createRefund()`.
  - Transaction Boundary: Local row locking and pending refund insertion inside DB transaction; external Xendit refund HTTP call made outside DB transaction (PRD BR-010).
  - Exception Contract: Gracefully catches `RuntimeException` and returns HTTP 422.
  - Audit: Logs `refund.create` with sanitized metadata.

### 7. Venues Supervision
- **List:** `GET /api/admin/venues` — Paginated list with `search`, `status`, `category_id`. Eager loads `merchant`, `category`.
- **Detail:** `GET /api/admin/venues/{venue}` — Eager loads `merchant`, `category`, `resources`.

### 8. Platform Settings
- **List:** `GET /api/admin/settings` — Returns typed settings list.
- **Update:** `PATCH /api/admin/settings` — `UpdatePlatformSettingsRequest` validates `booking_hold_duration_minutes` (integer, min: 1, max: 1440).
  - Downstream Consumers: Consumed by `BookingService`, `EventTicketPurchaseService`, and `PaymentSessionService`.
  - Audit: Logs `platform_setting.update` with old and new values.

### 9. Audit Logs
- **List:** `GET /api/admin/audit` — Paginated list with filters `actor_id`, `action`, `entity_type`, `from_date`, `to_date`.
- **Immutability:** Application-level guards (`static::updating` and `static::deleting` throw `LogicException`). API is strictly read-only.
- **Sanitization:** Credential fields (`password`, `token`, `secret`, `api_key`, `x-callback-token`) are redacted to `[REDACTED]`.
