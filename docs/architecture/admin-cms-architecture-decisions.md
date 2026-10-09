# VYBES Admin CMS — Architecture Decision Records (ADRs)

**Document Reference:** Architecture Subsystem Specification (Phase 6.5)  
**Parent Document:** [System Architecture](admin-cms-system-architecture.md)

---

## Index of Decisions

- [ADR-001: Modular Monolith Architecture Strategy](#adr-001-modular-monolith-architecture-strategy)
- [ADR-002: Dedicated Vue.js Single-Page Application for Admin CMS](#adr-002-dedicated-vuejs-single-page-application-for-admin-cms)
- [ADR-003: Headless Laravel REST API as Backend Boundary](#adr-003-headless-laravel-rest-api-as-backend-boundary)
- [ADR-004: PostgreSQL as Authoritative Transactional Persistence Layer](#adr-004-postgresql-as-authoritative-transactional-persistence-layer)
- [ADR-005: Laravel Sanctum Token Authentication for API Clients](#adr-005-laravel-sanctum-token-authentication-for-api-clients)
- [ADR-006: Dual-Factor RBAC Model for Administrative Authorization](#adr-006-dual-factor-rbac-model-for-administrative-authorization)
- [ADR-007: Centralized Service Ownership of Booking, Payment, and Refund Logic](#adr-007-centralized-service-ownership-of-booking-payment-and-refund-logic)
- [ADR-008: Immutable Append-Only Audit Logging Architecture](#adr-008-immutable-append-only-audit-logging-architecture)
- [ADR-009: Dynamic Platform Settings Database Persistence](#adr-009-dynamic-platform-settings-database-persistence)
- [ADR-010: Backend API Contract Authority Over Frontend Representations](#adr-010-backend-api-contract-authority-over-frontend-representations)
- [ADR-011: Deferral of Figma-to-Vue Implementation Until Backend Contract Freeze](#adr-011-deferral-of-figma-to-vue-implementation-until-backend-contract-freeze)

---

### ADR-001: Modular Monolith Architecture Strategy
- **Status:** `CONFIRMED`
- **Context:** The PRD (EDR-001, Section 14.3) defines VYBES as a multi-category booking platform with transactional dependencies across venues, tickets, payments, and refunds. Full microservices would introduce distributed transactions, dual-write complexities, network latency, and high DevOps maintenance overhead.
- **Decision:** Build VYBES as a modular monolith within a single Laravel application codebase. Shared database transactions and row-level locking maintain strict consistency.
- **Alternatives Considered:** Event-driven microservices architecture; separate payment microservice.
- **Consequences:** Lower operational overhead; simplified CI/CD; ACID guarantees for inventory. Modular boundaries must be kept clean to allow selective extraction later if justified.
- **Evidence:** PRD Section 14.3 (EDR-001); backend codebase organized into domain namespaces under `app/Services/` and `app/Models/`.

---

### ADR-002: Dedicated Vue.js Single-Page Application for Admin CMS
- **Status:** `CONFIRMED`
- **Context:** Platform operators require high-density data tables, real-time filtering, responsive modal inspectors, and fluid state management independent of mobile or customer web applications.
- **Decision:** Maintain the Admin CMS as a decoupled Vue 3 Single Page Application (SPA) located in the [admin/](file:///D:/Projects/vybes/admin) directory, built via Vite and communicating exclusively through REST APIs.
- **Alternatives Considered:** Laravel Blade + Livewire/Alpine; monolithic full-stack app.
- **Consequences:** Complete decoupling of client rendering from backend lifecycle; separate build pipelines; requires CORS configuration and token-based state hydration.
- **Evidence:** [admin/package.json](file:///D:/Projects/vybes/admin/package.json), [admin/src/main.js](file:///D:/Projects/vybes/admin/src/main.js), separate Git remote repository.

---

### ADR-003: Headless Laravel REST API as Backend Boundary
- **Status:** `CONFIRMED`
- **Context:** Both the React Native customer mobile app and the Vue Admin CMS consume platform capabilities. A unified API surface avoids duplicating business rules across server-rendered views.
- **Decision:** Expose backend services via JSON REST API endpoints protected by consistent HTTP status codes, Form Requests, and API Resources.
- **Alternatives Considered:** GraphQL; Inertia.js; gRPC.
- **Consequences:** Clear contract boundary; straightforward postman/automated testing; requires dedicated API documentation and contract versioning.
- **Evidence:** [routes/api.php](file:///D:/Projects/vybes/backend/routes/api.php) containing public auth routes, mobile booking routes, organizer routes, and `/api/admin/*` group.

---

### ADR-004: PostgreSQL as Authoritative Transactional Persistence Layer
- **Status:** `CONFIRMED`
- **Context:** Booking quotas, seat availability, and financial transactions require ACID transactions, strict foreign keys, and row locking (`FOR UPDATE`) to prevent double-booking and overselling.
- **Decision:** Designate PostgreSQL 17 as the sole transactional source of truth (EDR-002). Redis 7 is strictly non-authoritative (cache/transient acceleration).
- **Alternatives Considered:** MySQL; Redis-backed inventory ledger; MongoDB.
- **Consequences:** Strong consistency guarantees; reliable inventory invariants ($quota = sold + reserved + available$); requires PostgreSQL-compatible migration syntax.
- **Evidence:** PRD BR-001, EDR-002; [docker-compose.yml](file:///D:/Projects/vybes/docker-compose.yml) specifying `postgres:17`.

---

### ADR-005: Laravel Sanctum Token Authentication for API Clients
- **Status:** `CONFIRMED`
- **Context:** The Admin CMS is an SPA hosted on a separate port/domain from the API. State-less personal access tokens allow secure, revocable API access across web and mobile.
- **Decision:** Use Laravel Sanctum personal access tokens (`auth:sanctum`). Raw tokens are provided on login, hashed via SHA-256 before storage in `personal_access_tokens`, and transmitted via `Authorization: Bearer <token>`.
- **Alternatives Considered:** Stateful cookie sessions; JWT (tymon/jwt-auth); OAuth2 Passport.
- **Consequences:** Lightweight, native Laravel integration; tokens revocable per user; requires CSRF exemption for stateless Bearer tokens and explicit expiration configuration.
- **Evidence:** [2026_09_27_145624_create_personal_access_tokens_table.php](file:///D:/Projects/vybes/backend/database/migrations/2026_09_27_145624_create_personal_access_tokens_table.php); [admin/src/services/api.js](file:///D:/Projects/vybes/admin/src/services/api.js).

---

### ADR-006: Dual-Factor RBAC Model for Administrative Authorization
- **Status:** `CONFIRMED`
- **Context:** PRD FR-002 establishes single role per user (`users.role_id`). However, admin capabilities must support both role-level check and capability-level delegation (`admin.manage`).
- **Decision:** Authorize admin routes if `user.hasRole('admin') || user.hasPermission('admin.manage')`. Enforce this rule identically in backend middleware [EnsureUserIsAdmin.php](file:///D:/Projects/vybes/backend/app/Http/Middleware/EnsureUserIsAdmin.php) and frontend computed property `isAdmin` in [admin/src/stores/auth.js](file:///D:/Projects/vybes/admin/src/stores/auth.js).
- **Alternatives Considered:** Spatie Laravel-Permission package; granular permissions for every button.
- **Consequences:** Consistent access behavior; avoids premature explosion of unverified granular permissions; preserves single-role schema simplicity.
- **Evidence:** [EnsureUserIsAdmin.php](file:///D:/Projects/vybes/backend/app/Http/Middleware/EnsureUserIsAdmin.php), [admin/src/stores/auth.js](file:///D:/Projects/vybes/admin/src/stores/auth.js), [PermissionSeeder.php](file:///D:/Projects/vybes/backend/database/seeders/PermissionSeeder.php).

---

### ADR-007: Centralized Service Ownership of Booking, Payment, and Refund Logic
- **Status:** `CONFIRMED`
- **Context:** Multiple clients (customer app, webhooks, admin CMS) trigger financial and inventory state mutations. Replicating payment, refund, or hold logic in controllers would cause severe drift and bugs.
- **Decision:** Centralize business rules in domain service classes: `PaymentRefundService`, `BookingService`, `EventTicketPurchaseService`, `PaymentSessionService`, `LatePaymentRecoveryService`. Admin controllers delegate to these services.
- **Alternatives Considered:** Fat controllers; Eloquent model events; action classes.
- **Consequences:** Reusable, testable domain rules; strict enforcement of PRD BR-010 (decoupling Xendit HTTP calls from DB transactions).
- **Evidence:** [RefundController.php](file:///D:/Projects/vybes/backend/app/Http/Controllers/Api/Admin/RefundController.php) injecting `PaymentRefundService`.

---

### ADR-008: Immutable Append-Only Audit Logging Architecture
- **Status:** `CONFIRMED`
- **Context:** Compliance and platform security require auditing all administrative actions. Audit records must be protected from tampering or deletion by administrators.
- **Decision:** Implement a custom append-only `audit_logs` table and [AuditLogger.php](file:///D:/Projects/vybes/backend/app/Services/AuditLogger.php). The API endpoint `GET /api/admin/audit` is strictly read-only. Model-level hooks prevent updates and deletions. Sensitive keys are redacted.
- **Alternatives Considered:** Spatie Activitylog; database triggers; syslog.
- **Consequences:** Full operational audit trail with zero external package dependency; verified immutability; automatic credential sanitization.
- **Evidence:** [AuditLog.php](file:///D:/Projects/vybes/backend/app/Models/AuditLog.php), [AdminAuditLogTest.php](file:///D:/Projects/vybes/backend/tests/Feature/Admin/AdminAuditLogTest.php).

---

### ADR-009: Dynamic Platform Settings Database Persistence
- **Status:** `CONFIRMED`
- **Context:** PRD BR-003 requires that default hold duration (15 minutes) be configurable by platform administrators without requiring redeployment or `.env` modification.
- **Decision:** Implement a dedicated `platform_settings` table storing typed key-value pairs. Expose `GET` and `PATCH /api/admin/settings`. Downstream services (`BookingService`, `EventTicketPurchaseService`, `PaymentSessionService`) consume this dynamic configuration.
- **Alternatives Considered:** Hardcoded config file; Redis keys; environment variables.
- **Consequences:** Real-time platform adaptability; full audit trail on setting updates; requires fallback values when database record is absent.
- **Evidence:** [PlatformSetting.php](file:///D:/Projects/vybes/backend/app/Models/PlatformSetting.php), [PaymentSessionService.php](file:///D:/Projects/vybes/backend/app/Services/PaymentSessionService.php), [AdminSettingsTest.php](file:///D:/Projects/vybes/backend/tests/Feature/Admin/AdminSettingsTest.php).

---

### ADR-010: Backend API Contract Authority Over Frontend Representations
- **Status:** `CONFIRMED`
- **Context:** Figma designs frequently display decorative statistics, filters, or buttons that do not reflect backend database schemas or business rules.
- **Decision:** The Laravel backend REST API and PostgreSQL schema are the sole authoritative source of truth. The Vue frontend must not fabricate API endpoints, mock data, or database statuses to satisfy Figma designs. Modules lacking backend contracts remain explicit placeholders.
- **Alternatives Considered:** Mock frontend data with static arrays; synthesize temporary database tables.
- **Consequences:** Prevents phantom feature implementation; maintains 100% test integrity; clarifies product backlog gaps for stakeholders.
- **Evidence:** [ModerationView.vue](file:///D:/Projects/vybes/admin/src/views/moderation/ModerationView.vue), [ReviewsView.vue](file:///D:/Projects/vybes/admin/src/views/reviews/ReviewsView.vue), [ReportsView.vue](file:///D:/Projects/vybes/admin/src/views/reports/ReportsView.vue) displaying explicit PRD scope status.

---

### ADR-011: Deferral of Figma-to-Vue Implementation Until Backend Contract Freeze
- **Status:** `CONFIRMED`
- **Context:** Implementing intricate UI components against evolving backend contracts causes churn, broken tests, and frontend drift.
- **Decision:** Defer full Figma component implementation until the backend contracts under `/api/admin/*` are verified, tested, audited, and frozen in Phase 7.
- **Alternatives Considered:** Simultaneous UI and backend feature implementation.
- **Consequences:** Clean engineering sequencing; stable API foundation for frontend developers; prevents rework.
- **Evidence:** Phase 6 and Phase 6.5 project mandates; [docs/admin-cms-foundation-readiness.md](file:///D:/Projects/vybes/docs/admin-cms-foundation-readiness.md).
