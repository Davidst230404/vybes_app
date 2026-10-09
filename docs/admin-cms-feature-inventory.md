# VYBES Admin CMS — Feature Inventory

**Baseline:** PRD VYBES v1.2.4 (06 October 2026)  
**Phase:** Phase 7.1 — VYBES Admin CMS Integration Verification & Gap Closure  
**Generated Date:** 2026-10-09  

---

## 1. Executive Summary

This document presents a comprehensive, evidence-based inventory of all 12 Admin CMS functional modules in the VYBES platform. Each module has been audited against the physical Laravel backend code, the PostgreSQL schema, the Vue 3 frontend services and views, and the automated test suite.

### Module Status Summary

| # | Module | Status | Primary Endpoints | Test Coverage |
|---|---|---|---|---|
| 1 | **Dashboard** | `IMPLEMENTED` | `GET /api/admin/dashboard` | Automated Feature Test |
| 2 | **Users** | `PARTIALLY IMPLEMENTED` | `GET, PATCH /api/admin/users/*` | Automated Feature Test (Suspension Blocked) |
| 3 | **Approvals** | `IMPLEMENTED` | `GET, POST /api/admin/approvals/*` | Automated Feature Test |
| 4 | **Categories** | `IMPLEMENTED` | `GET, POST, PUT, DELETE /api/admin/categories/*` | Automated Feature Test |
| 5 | **Moderation** | `DEFERRED BY PRD` | N/A (PRD Sec 4.2) | Component Build Check |
| 6 | **Bookings** | `IMPLEMENTED` | `GET /api/admin/bookings/*` | Automated Feature Test |
| 7 | **Refunds** | `IMPLEMENTED` | `GET, POST /api/admin/refunds/*` | Automated Feature Test |
| 8 | **Reviews** | `BLOCKED BY CONTRACT` | N/A (PRD FR-016 Planned) | Component Build Check |
| 9 | **Venues** | `IMPLEMENTED` | `GET /api/admin/venues/*` | Automated Feature Test |
| 10 | **Platform Settings** | `IMPLEMENTED` | `GET, PATCH /api/admin/settings` | Automated Feature Test |
| 11 | **Audit Log** | `IMPLEMENTED` | `GET /api/admin/audit` | Automated Feature Test |
| 12 | **Reports** | `DEFERRED BY PRD` | N/A (PRD Sec 4.2) | Component Build Check |

---

## 2. Detailed Module Inventories

### Module 1: Dashboard
- **Current UI Route:** `/dashboard` (`admin/src/views/DashboardView.vue`)
- **Frontend Service:** `admin/src/services/dashboard.service.js` (`getMetrics()`)
- **Backend Endpoint:** `GET /api/admin/dashboard`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\DashboardController@index`
- **Application / Domain Service:** Real Eloquent aggregate queries (no fake metrics):
  - Users: `User::count()`
  - Bookings: `Booking::count()`, `Booking::where('status', 'confirmed')->count()`
  - Venues: `Venue::where('status', 'published')->count()`
  - Events: `Event::where('status', 'published')->count()`
  - Revenue: `Payment::where('status', 'confirmed')->sum('amount')`
  - Refunds: `PaymentRefund::where('status', 'completed')->sum('amount')`
  - Approvals Breakdown: Pending counts for `merchants` and `organizers`
- **Database Tables and Models:** `users` (`User`), `bookings` (`Booking`), `venues` (`Venue`), `events` (`Event`), `payments` (`Payment`), `payment_refunds` (`PaymentRefund`), `merchants` (`Merchant`), `organizers` (`Organizer`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware (`admin` role or `admin.manage` permission)
- **Request Validation:** None required (read-only aggregate request)
- **Response Contract:**
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
- **Loading / Error / Empty States:** Full coverage: `LoadingSkeleton.vue`, `ErrorState.vue`, zero-safe arithmetic display.
- **Automated Tests:** `test_admin_can_access_dashboard_with_all_metrics` in `backend/tests/Feature/Admin/AdminApiTest.php`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 2: Users
- **Current UI Route:** `/users` (`UsersView.vue`), `/users/:id` (`UserDetailView.vue`)
- **Frontend Service:** `admin/src/services/user.service.js` (`getUsers()`, `getUser(id)`, `updateUser(id, payload)`)
- **Backend Endpoints:**
  - `GET /api/admin/users`
  - `GET /api/admin/users/{user}`
  - `PATCH /api/admin/users/{user}`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\UserController@index`, `@show`, `@update`
- **Application / Domain Service:** Direct Eloquent with eager loading `role`, mutation guarded by `AuditLogger`
- **Database Tables and Models:** `users` (`User`), `roles` (`Role`), `audit_logs` (`AuditLog`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** `UpdateUserRequest` (`name: string|max:255`, `role_id: exists:roles,id`)
- **Response Contract:** Paginated User Resource collection with embedded role object, single User Resource detail.
- **Loading / Error / Empty States:** Full coverage: `TablePagination.vue`, `LoadingSkeleton.vue`, `ErrorState.vue`, `EmptyState.vue`.
- **Automated Tests:**
  - `test_admin_can_list_users`
  - `test_admin_can_view_user_detail`
  - `test_admin_can_update_user_role`
  - `test_admin_user_role_update_is_audited`
- **Missing Dependencies / Gaps:** 
  - User suspension & status transition (`BLOCKED BY CONTRACT` / GAP-01). The `users` table lacks a `status` column, and token invalidation on suspension is not defined in PRD v1.2.4.
- **Current Status:** `PARTIALLY IMPLEMENTED`

---

### Module 3: Approvals (Merchants & Organizers)
- **Current UI Route:** `/approvals` (`admin/src/views/ApprovalsView.vue`)
- **Frontend Service:** `admin/src/services/approval.service.js` (`getApprovals()`, `updateMerchantStatus()`, `updateOrganizerStatus()`)
- **Backend Endpoints:**
  - `GET /api/admin/approvals`
  - `POST /api/admin/approvals/merchants/{merchant}`
  - `POST /api/admin/approvals/organizers/{organizer}`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\ApprovalController@index`, `@updateMerchantStatus`, `@updateOrganizerStatus`
- **Application / Domain Service:** State transition machine enforcing `pending` -> `approved` | `rejected`, guarded by `AuditLogger`
- **Database Tables and Models:** `merchants` (`Merchant`), `organizers` (`Organizer`), `users` (`User`), `audit_logs` (`AuditLog`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** `UpdateApprovalStatusRequest` (`status: required|in:approved,rejected`)
- **Response Contract:**
  - `GET /api/admin/approvals`:
    ```json
    {
      "data": {
        "merchants": [MerchantResource],
        "organizers": [OrganizerResource]
      }
    }
    ```
  - `POST /api/admin/approvals/merchants/{id}`: `{ "data": MerchantResource }`
  - `POST /api/admin/approvals/organizers/{id}`: `{ "data": OrganizerResource }`
- **Loading / Error / Empty States:** Tabbed interface (`merchants`, `organizers`), `LoadingSkeleton.vue`, `EmptyState.vue`, `ErrorState.vue`, `ConfirmModal.vue`.
- **Automated Tests:**
  - `test_admin_can_list_pending_approvals`
  - `test_admin_can_approve_merchant`
  - `test_admin_can_approve_organizer`
  - `test_admin_approvals_index_returns_merchant_and_organizer_resources`
  - `test_admin_approval_mutations_are_audited`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 4: Categories
- **Current UI Route:** `/categories` (`admin/src/views/CategoriesView.vue`)
- **Frontend Service:** `admin/src/services/category.service.js` (`getCategories()`, `getCategory()`, `createCategory()`, `updateCategory()`, `deleteCategory()`)
- **Backend Endpoints:**
  - `GET /api/admin/categories`
  - `POST /api/admin/categories`
  - `GET /api/admin/categories/{category}`
  - `PUT /api/admin/categories/{category}`
  - `DELETE /api/admin/categories/{category}`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\CategoryController@index, store, show, update, destroy`
- **Application / Domain Service:** Eloquent CRUD with auto-slug generation, deletion protection against active venue references, and `AuditLogger` integration.
- **Database Tables and Models:** `categories` (`Category`), `venues` (`Venue`), `audit_logs` (`AuditLog`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** `StoreCategoryRequest`, `UpdateCategoryRequest` (`name: required|string|max:100`, `description: nullable|string`, `icon: nullable|string`, `sort_order: integer|min:0`, `is_active: boolean`)
- **Response Contract:** Paginated `CategoryResource` collection, single `CategoryResource` object.
- **Loading / Error / Empty States:** Full coverage: Create/Edit modal, Delete confirmation modal, `LoadingSkeleton.vue`, `EmptyState.vue`, `ErrorState.vue`.
- **Automated Tests:**
  - `test_admin_can_crud_categories`
  - `test_category_deletion_prevented_if_has_venues`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 5: Moderation
- **Current UI Route:** `/moderation` (`admin/src/views/ModerationView.vue`)
- **Frontend Service:** None (direct placeholder state)
- **Backend Endpoints:** None
- **Controller / Action:** None
- **Application / Domain Service:** None
- **Database Tables and Models:** N/A
- **Authentication & Authorization:** Route-level admin guard (`authStore.user`)
- **Request Validation:** None
- **Response Contract:** None
- **Loading / Error / Empty States:** Explicit PRD deferred notice view (`ModerationView.vue`) referencing PRD v1.2.4 Section 4.2.
- **Automated Tests:** Production build compilation verified
- **Missing Dependencies / Gaps:** Explicitly deferred by PRD v1.2.4 Section 4.2: *"Seluruh pelaporan admin lanjutan dan alur moderasi ditunda."*
- **Current Status:** `DEFERRED BY PRD`

---

### Module 6: Bookings & Inventory Supervision
- **Current UI Route:** `/bookings` (`admin/src/views/BookingsView.vue`)
- **Frontend Service:** `admin/src/services/booking.service.js` (`getBookings()`, `getBooking(id)`)
- **Backend Endpoints:**
  - `GET /api/admin/bookings`
  - `GET /api/admin/bookings/{booking}`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\BookingController@index, show`
- **Application / Domain Service:** `BookingService`, `AvailabilityService`, `PlatformSetting` (for dynamic hold duration). Command `bookings:expire-holds` delegates to `BookingService::expireHolds()`.
- **Database Tables and Models:** `bookings` (`Booking`), `booking_items` (`BookingItem`), `resources` (`Resource`), `venues` (`Venue`), `users` (`User`), `payments` (`Payment`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** Query parameter filters (`status`, `venue_id`, `booking_code`, `page`, `per_page`)
- **Response Contract:** Paginated Booking collection with embedded relationships (user, venue, resource, payments, items).
- **Loading / Error / Empty States:** Full coverage: Filter toolbar, `LoadingSkeleton.vue`, `EmptyState.vue`, `ErrorState.vue`, detail slide-over modal.
- **Automated Tests:**
  - `test_admin_can_list_and_view_bookings`
  - `test_booking_service_and_console_command_expire_holds`
  - `test_booking_service_uses_dynamic_platform_setting_hold_duration`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 7: Refunds & Payment Integration
- **Current UI Route:** `/refunds` (`admin/src/views/RefundsView.vue`)
- **Frontend Service:** `admin/src/services/refund.service.js` (`getRefunds()`, `getRefund(id)`, `initiateRefund(payload)`)
- **Backend Endpoints:**
  - `GET /api/admin/refunds`
  - `GET /api/admin/refunds/{refund}`
  - `POST /api/admin/refunds`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\RefundController@index, show, store`
- **Application / Domain Service:** `PaymentRefundService`, Xendit gateway integration decoupled from transactions (PRD BR-010), `AuditLogger`
- **Database Tables and Models:** `payment_refunds` (`PaymentRefund`), `payments` (`Payment`), `bookings` (`Booking`), `event_ticket_orders` (`EventTicketOrder`), `audit_logs` (`AuditLog`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** `InitiateRefundRequest` (`payment_id: required|exists:payments,id`, `amount: required|numeric|min:1`, `reason: required|string|max:500`)
- **Response Contract:** Paginated refund list, single refund detail with payment and booking relations, refund initiation payload.
- **Loading / Error / Empty States:** Full coverage: `TablePagination.vue`, `LoadingSkeleton.vue`, `EmptyState.vue`, `ErrorState.vue`, initiate refund modal with form validation errors.
- **Automated Tests:**
  - `test_admin_can_list_and_view_refunds`
  - `test_admin_can_initiate_manual_refund_and_is_audited`
  - `test_refund_validation_prevents_duplicate_or_invalid_amounts`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 8: Reviews
- **Current UI Route:** `/reviews` (`admin/src/views/ReviewsView.vue`)
- **Frontend Service:** None (placeholder view)
- **Backend Endpoints:** None
- **Controller / Action:** None
- **Application / Domain Service:** None
- **Database Tables and Models:** None (no `reviews` table)
- **Authentication & Authorization:** Route-level admin guard
- **Request Validation:** None
- **Response Contract:** None
- **Loading / Error / Empty States:** Explicit placeholder notice referencing PRD v1.2.4 FR-016 (Planned status).
- **Automated Tests:** Production build compilation verified
- **Missing Dependencies / Gaps:** 
  - Schema not defined in database migrations.
  - Domain lifecycle contracts (rating range, polymorphic target, verified purchase rule, moderation states) not established.
- **Current Status:** `BLOCKED BY CONTRACT`

---

### Module 9: Venues
- **Current UI Route:** `/venues` (`admin/src/views/VenuesView.vue`)
- **Frontend Service:** `admin/src/services/venue.service.js` (`getVenues()`, `getVenue(id)`)
- **Backend Endpoints:**
  - `GET /api/admin/venues`
  - `GET /api/admin/venues/{venue}`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\VenueController@index, show`
- **Application / Domain Service:** Read-only supervision over venue inventory, merchants, categories, and resources.
- **Database Tables and Models:** `venues` (`Venue`), `merchants` (`Merchant`), `categories` (`Category`), `resources` (`Resource`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** Filters (`search`, `status`, `merchant_id`, `category_id`, `page`, `per_page`)
- **Response Contract:** Paginated venue list with category, merchant, and resources; single venue detail.
- **Loading / Error / Empty States:** Full coverage: `TablePagination.vue`, `LoadingSkeleton.vue`, `EmptyState.vue`, `ErrorState.vue`, detail modal.
- **Automated Tests:** `test_admin_can_list_and_view_venues` in `AdminApiTest.php`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 10: Platform Settings
- **Current UI Route:** `/settings` (`admin/src/views/PlatformSettingsView.vue`)
- **Frontend Service:** `admin/src/services/settings.service.js` (`getSettings()`, `updateSettings(payload)`)
- **Backend Endpoints:**
  - `GET /api/admin/settings`
  - `PATCH /api/admin/settings`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\PlatformSettingController@index, update`
- **Application / Domain Service:** `PlatformSetting` model, `AuditLogger` for mutations, consumed dynamically by `BookingService` and `PaymentSessionService`.
- **Database Tables and Models:** `platform_settings` (`PlatformSetting`), `audit_logs` (`AuditLog`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** `UpdatePlatformSettingsRequest` (`booking_hold_duration_minutes: integer|min:1|max:1440`)
- **Response Contract:** Array of setting objects (`id`, `key`, `value`, `type`, `description`, `updated_at`).
- **Loading / Error / Empty States:** Full coverage: Form loading skeleton, feedback alerts, optimistic state rollback on error.
- **Automated Tests:**
  - `test_admin_can_read_and_update_platform_settings`
  - `test_settings_update_is_audited`
  - `test_booking_service_uses_dynamic_platform_setting_hold_duration`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 11: Audit Log
- **Current UI Route:** `/audit-log` (`admin/src/views/AuditLogView.vue`)
- **Frontend Service:** `admin/src/services/audit.service.js` (`getAuditLogs()`)
- **Backend Endpoint:** `GET /api/admin/audit`
- **Controller / Action:** `App\Http\Controllers\Api\Admin\AuditLogController@index`
- **Application / Domain Service:** `AuditLogger` helper, append-only security table.
- **Database Tables and Models:** `audit_logs` (`AuditLog`), `users` (`User`)
- **Authentication & Authorization:** `auth:sanctum`, `admin` middleware
- **Request Validation:** Query filters (`actor_id`, `action`, `entity_type`, `from_date`, `to_date`, `page`, `per_page`)
- **Response Contract:** Paginated audit log records containing actor profile, action type, entity type, entity ID, before/after JSON diffs, client IP, and User-Agent.
- **Loading / Error / Empty States:** Full coverage: `TablePagination.vue`, `LoadingSkeleton.vue`, `EmptyState.vue`, `ErrorState.vue`, detail diff viewer drawer.
- **Automated Tests:**
  - `test_admin_can_read_audit_logs_with_filtering`
  - `test_admin_cannot_mutate_audit_logs`
- **Missing Dependencies:** None
- **Current Status:** `IMPLEMENTED`

---

### Module 12: Reports
- **Current UI Route:** `/reports` (`admin/src/views/ReportsView.vue`)
- **Frontend Service:** None (direct placeholder state)
- **Backend Endpoints:** None
- **Controller / Action:** None
- **Application / Domain Service:** None
- **Database Tables and Models:** N/A
- **Authentication & Authorization:** Route-level admin guard
- **Request Validation:** None
- **Response Contract:** None
- **Loading / Error / Empty States:** Explicit PRD deferred notice view (`ReportsView.vue`) referencing PRD v1.2.4 Section 4.2.
- **Automated Tests:** Production build compilation verified
- **Missing Dependencies / Gaps:** Explicitly deferred by PRD v1.2.4 Section 4.2: *"Analitik/BI tingkat produksi penuh dan seluruh pelaporan admin lanjutan ditunda."*
- **Current Status:** `DEFERRED BY PRD`
