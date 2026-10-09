# VYBES Admin CMS — Architecture Gap Analysis

**Document Reference:** Architecture Subsystem Specification (Phase 6.5)  
**Parent Document:** [System Architecture](admin-cms-system-architecture.md)

---

## 1. Overview

This document identifies, analyzes, and categorizes architectural gaps, technical debts, missing domain entities, and operational inconsistencies discovered during the source code inspection of the VYBES platform and its Admin CMS subsystem.

---

## 2. Gap Classification Matrix

| Gap ID | Severity | Category | Description | Product Decision Required? |
|---|---|---|---|:---:|
| **GAP-01** | **HIGH** | Domain / Lifecycle | User Account Suspension State Machine Absence | **YES** |
| **GAP-02** | **HIGH** | Domain / Persistence | Reviews Domain Missing from PostgreSQL Schema (FR-016) | **YES** |
| **GAP-03** | **HIGH** | DevOps / Deployment | Absence of `Dockerfile` in Backend Root (CI Build Risk) | **NO** |
| **GAP-04** | **MEDIUM** | Architecture / Service | Inconsistent Hold Expiry Service Boundary Encapsulation | **NO** |
| **GAP-05** | **MEDIUM** | API Consistency | Untransformed Model DTOs in Operator Approvals API | **NO** |
| **GAP-06** | **MEDIUM** | Infrastructure | Dormant Redis Cache & Queue Utilization | **NO** |
| **GAP-07** | **LOW** | Nomenclature | Schema Nomenclature Drift (PRD Conceptual vs DB Physical) | **NO** |
| **GAP-08** | **LOW** | Domain / Content | Banner & Platform Media Domain Undefined | **YES** |

---

## 3. Detailed Gap Analysis & Recommendations

### GAP-01: User Account Suspension State Machine Absence
- **Severity:** `HIGH`
- **Category:** Domain / Lifecycle
- **Evidence:** Table `users` ([0001_01_01_000000_create_users_table.php](file:///D:/Projects/vybes/backend/database/migrations/0001_01_01_000000_create_users_table.php)) contains only `id`, `name`, `email`, `password`, `role_id`, timestamps. No `status` or `is_active` column exists.
- **Impact:** While PRD Section 5.2 states administrators can "menyetujui/menangguhkan user dan operator", suspending a customer account cannot be safely implemented. Adding an arbitrary string `status: suspended` would fail to answer:
  1. Does suspension revoke all active Sanctum tokens immediately?
  2. Does suspension cancel active resource bookings and ticket orders?
  3. Are payments for active bookings automatically refunded, or do they require manual operator dispute resolution?
- **Recommended Action:** Defer implementation until PM/Product Owner defines the suspension lifecycle machine. Mark as `BLOCKED BY CONTRACT` in requirement traceability.
- **Product Decision Required:** **YES**.

---

### GAP-02: Reviews Domain Missing from PostgreSQL Schema (FR-016)
- **Severity:** `HIGH`
- **Category:** Domain / Persistence
- **Evidence:** Zero migrations, models, or controllers exist for customer reviews or ratings across the repository.
- **Impact:** The Figma design exhibits a "Reviews" menu. In the absence of a database contract, any attempt to implement a Reviews table or moderation actions in the Admin CMS would require inventing unauthorized schema structures and endpoints.
- **Recommended Action:** Preserve [ReviewsView.vue](file:///D:/Projects/vybes/admin/src/views/reviews/ReviewsView.vue) as an explicit placeholder referencing PRD FR-016 status (*Direncanakan*). Do not create mock tables.
- **Product Decision Required:** **YES** (Review rating scale, moderation policy: pre-moderation vs post-moderation).

---

### GAP-03: Absence of `Dockerfile` in Backend Root
- **Severity:** `HIGH`
- **Category:** DevOps / Deployment
- **Evidence:** [.github/workflows/ci.yml](file:///D:/Projects/vybes/.github/workflows/ci.yml) lines 103–110 defines a `build-docker` job with `context: ./backend`. However, no `Dockerfile` exists in `backend/` or `docker/`.
- **Impact:** CI/CD pipeline triggers attempting Docker builds will fail on GitHub Actions.
- **Recommended Action:** Author a production-grade multi-stage `Dockerfile` (PHP 8.3 FPM, Opcache, PostgreSQL PDO, Redis extension) in `backend/` before production release.
- **Product Decision Required:** **NO** (Technical/DevOps engineering concern).

---

### GAP-04: Inconsistent Hold Expiry Service Boundary Encapsulation
- **Severity:** `MEDIUM`
- **Category:** Architecture / Service Encapsulation
- **Evidence:**
  - [ExpireEventTicketHolds.php](file:///D:/Projects/vybes/backend/app/Console/Commands/ExpireEventTicketHolds.php) delegates hold expiration to `EventTicketPurchaseService::expireHolds()`, which manages reserved inventory reconciliation inside database transactions.
  - [ExpireBookingHolds.php](file:///D:/Projects/vybes/backend/app/Console/Commands/ExpireBookingHolds.php) directly executes `Booking::query()->where(...)->update(['status' => 'expired'])` directly within the console command, bypassing `BookingService`.
- **Impact:** Violates clean architectural encapsulation. If booking expiration later requires domain events, notifications, or venue capacity recalculation, the logic would need duplication.
- **Recommended Action:** Move the booking expiration logic into a dedicated method `BookingService::expireHolds()` to mirror `EventTicketPurchaseService`.
- **Product Decision Required:** **NO**.

---

### GAP-05: Untransformed Model DTOs in Operator Approvals API
- **Severity:** `MEDIUM`
- **Category:** API Consistency
- **Evidence:** [ApprovalController.php](file:///D:/Projects/vybes/backend/app/Http/Controllers/Api/Admin/ApprovalController.php) returns direct Eloquent model instances in `response()->json(['data' => ['merchants' => $merchants, 'organizers' => $organizers]])`. All other admin controllers utilize dedicated API Resources (`UserResource`, `BookingResource`, `VenueResource`, etc.).
- **Impact:** Inconsistent API contract; internal model attributes could inadvertently leak to clients if model properties change.
- **Recommended Action:** Introduce `MerchantResource` and `OrganizerResource` in `App\Http\Resources\Admin\` to enforce contract uniformity across all admin responses.
- **Product Decision Required:** **NO**.

---

### GAP-06: Dormant Redis Cache & Queue Utilization
- **Severity:** `MEDIUM`
- **Category:** Infrastructure
- **Evidence:** [docker-compose.yml](file:///D:/Projects/vybes/docker-compose.yml) spins up `redis:7-alpine`. However, `backend/.env` and `ci.yml` configure `CACHE_STORE=file` or `array`, and `QUEUE_CONNECTION=sync`.
- **Impact:** Scheduled tasks and hold expirations execute synchronously. Under high operational loads, webhook ingestion and ticket issuance could block HTTP workers.
- **Recommended Action:** When entering production hardening, switch `QUEUE_CONNECTION=redis` and configure Laravel Horizon for background job workers.
- **Product Decision Required:** **NO**.

---

### GAP-07: Schema Nomenclature Drift
- **Severity:** `LOW`
- **Category:** Nomenclature / Documentation
- **Evidence:**
  - The PRD conceptual ERD refers to `availabilities`; the physical table created by migration is `schedules`.
  - The PRD conceptual ERD refers to `role_permissions`; the physical pivot table is `role_permission` (singular).
- **Impact:** Potential confusion when onboarding new engineers comparing the PRD to the codebase.
- **Recommended Action:** Document this mapping explicitly in the architecture documentation and update future PRD revisions to match physical schema reality.
- **Product Decision Required:** **NO**.

---

### GAP-08: Banner & Platform Media Domain Undefined
- **Severity:** `LOW`
- **Category:** Domain / Content Management
- **Evidence:** PRD Section 5.2 references "banner/pengaturan", but no banner table, model, or file upload pipeline exists in the backend.
- **Impact:** Admin settings cannot support banner upload or ordering until the domain is defined.
- **Recommended Action:** Keep banner management marked as `BLOCKED BY PRODUCT CONTRACT`. Maintain informational notice in `PlatformSettingsView.vue`.
- **Product Decision Required:** **YES**.
