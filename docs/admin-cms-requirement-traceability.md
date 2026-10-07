# VYBES Admin CMS Requirement Traceability

Baseline Source of Truth: **VYBES Product Requirements Document (PRD) v1.2.4 (06 Oktober 2026)**  
Scope: **Phase 6 — Admin CMS PRD Alignment & Backend Contract Foundation**

Status Legend:
- `IMPLEMENTED`: Backend schema, business logic, endpoints, security middleware, and frontend integration fully exist and verified by tests.
- `PARTIAL`: Foundation exists (e.g., schema or operational view), but lifecycle or contract coverage is incomplete.
- `PLANNED`: Feature is defined in PRD scope/architecture, but domain schema or contract has not yet been introduced.
- `DEFERRED`: Explicitly postponed by PRD governance (e.g., Section 4.2 Out of Scope / Postponed).
- `BLOCKED BY CONTRACT`: Implementation cannot proceed because authoritative database schema, lifecycle machine, or security boundaries have not been specified.
- `NOT APPLICABLE`: Not an Admin CMS direct capability.

---

## 1. Requirement Traceability Matrix

| PRD ID | Requirement | Backend Support | API | Frontend | Status | Gap |
|---|---|---|---|---|---|---|
| **FR-002** | RBAC: Otorisasi operasi terproteksi sesuai role dan permission user | Table `roles`, `permissions`, `role_permissions`, `users.role_id`, helper `User::hasPermission()`, middleware `admin` | `PATCH /api/admin/users/{user}` | `UsersView.vue`, `UserDetailView.vue`, `useAuthStore` | **PARTIAL** | Model RBAC adalah 1 user = 1 role (`users.role_id`). Admin dapat mengubah role user, namun permission granular admin belum dimutasi per role secara dinamis via API. |
| **FR-003** | Venue & Resource: Mengelola venue, resource, kategori, dan status publikasi | Table `venues`, `resources`, `categories`, `availabilities`. Relasi merchant_id, category_id, base_price | `GET /api/admin/categories` (CRUD), `GET /api/admin/venues`, `GET /api/admin/venues/{venue}` | `CategoriesView.vue`, `VenuesView.vue` | **IMPLEMENTED** | Admin dapat melakukan CRUD kategori secara penuh dan supervisi venue global (filter status, merchant, kategori). Pengubahan status venue operasional masih terikat kepemilikan merchant. |
| **FR-011** | Refund: Menyimpan status refund dan memproses hasil webhook refund Xendit | Table `payment_refunds`, `PaymentRefund` model, `PaymentRefundService`, Xendit webhook handler | `GET /api/admin/refunds`, `GET /api/admin/refunds/{refund}`, `POST /api/admin/refunds` | `RefundsView.vue` | **IMPLEMENTED** | Siklus refund terpisah dari payment (BR-015). Admin dapat melihat riwayat refund, memfilter status, dan menginisiasi refund manual via Xendit API terproteksi. |
| **FR-015** | Operasional Admin: Persetujuan, moderasi, pemantauan booking/pembayaran, audit/pelaporan | Modul Admin (approvals, bookings, refunds, dashboard, settings, audit_logs) | 22 endpoint di bawah `/api/admin/*` | Full Admin CMS Layout, Dashboard, Users, Approvals, Categories, Bookings, Refunds, Venues, Settings, Audit | **PARTIAL** | Persetujuan merchant/organizer, kategori, supervisi booking/refund, platform settings, dan audit log telah memiliki kontrak nyata (`IMPLEMENTED`). Moderasi konten lanjutan dan pelaporan BI kompleks berstatus `DEFERRED` per PRD Section 4.2. |
| **FR-016** | Ulasan (Reviews): Customer dapat mengirim ulasan setelah booking/event selesai | Belum ada schema `reviews` di migrasi PostgreSQL | Tidak ada API (tidak mengarang endpoint) | `ReviewsView.vue` (explicit placeholder state) | **BLOCKED BY CONTRACT** / **PLANNED** | PRD menetapkan status FR-016 sebagai "Direncanakan". Tidak ada schema tabel, relationship, unique constraint booking/event, atau moderation flag di backend. |
| **BR-003** | Booking Hold Duration: Durasi default 15 menit dan harus dapat dikonfigurasi oleh administrasi platform | Table `platform_settings`, key `booking_hold_duration_minutes` (integer, default 15). Runtime consumer di `BookingService` & `EventTicketPurchaseService` | `GET /api/admin/settings`, `PATCH /api/admin/settings` | `PlatformSettingsView.vue` | **IMPLEMENTED** | Durasi hold tersimpan di database PostgreSQL, tervalidasi (1-1440 menit), ter-audit otomatis, dan dikonsumsi secara real-time saat hold booking atau order tiket dibuat. |
| **BR-013** | Publikasi Inventori: Operator tidak dapat mempublikasikan inventori sebelum persetujuan selesai | Column `approval_status` pada `merchants` dan `organizers`. Guard di availability dan order hold | `GET /api/admin/approvals`, `POST /api/admin/approvals/merchants/{id}`, `POST /api/admin/approvals/organizers/{id}` | `ApprovalsView.vue` | **IMPLEMENTED** | Admin dapat menyetujui (`approved`) atau menolak (`rejected`) pendaftaran merchant & organizer. Inventori merchant/event pending tidak dapat dipublikasikan. |
| **BR-015** | Pemisahan Status Pembayaran & Refund: Status refund terpisah dari status payment | Table `payments` (`status: paid`) terpisah dari `payment_refunds` (`status: pending/succeeded/failed`) | `GET /api/admin/refunds`, `GET /api/admin/bookings/{id}` | `RefundsView.vue`, `BookingsView.vue` | **IMPLEMENTED** | Konsisten pada database, model, resource, dan UI admin. Tidak ada mutasi status payment paid menjadi arbitrary string refund. |

---

## 2. Additional Traceability Mapping

| Area | PRD Baseline | Status Backend | Admin API | Status UI | Traceability Status |
|---|---|---|---|---|---|
| **Dashboard Metrics** | Operational monitoring (FR-015) | Real aggregate query (users, bookings, venues, events, revenue, refunds, approvals) | `GET /api/admin/dashboard` | `DashboardView.vue` | **IMPLEMENTED** |
| **User Role Assignment** | FR-002, Kamus Entitas `users.role_id` | Role switching with DB transaction | `PATCH /api/admin/users/{user}` | `UsersView.vue` | **IMPLEMENTED** |
| **User Suspension** | Persona Administrator (Section 5.2): "menangguhkan user" | Tidak ada kolom `status` pada tabel `users` | Tidak ada endpoint suspend | Modal notice di UI | **BLOCKED BY CONTRACT** (Lihat Dokumen Gap A) |
| **Audit Logging** | PRD Section 15.3, 15.4 Kamus Entitas `audit_logs` | Table `audit_logs`, `AuditLogger` service append-only with redaction | `GET /api/admin/audit` | `AuditLogView.vue` | **IMPLEMENTED** |
| **Content Moderation** | PRD FR-015, Section 4.2 "Seluruh alur moderasi ditunda" | Model Venue & Event memiliki publication status (`draft`, `published`, `cancelled`) | Tidak ada moderation queue endpoint | `ModerationView.vue` (explicit placeholder) | **DEFERRED** |
| **Advanced Reporting / BI** | PRD Section 4.2 "Analitik/BI tingkat produksi penuh ditunda" | Operational aggregations only | `GET /api/admin/dashboard` | `ReportsView.vue` (explicit placeholder) | **DEFERRED** |
| **Banner / Platform Media** | PRD Section 5.2 "banner/pengaturan" | Tidak ada tabel `banners` di migrasi PostgreSQL | Tidak ada API banner | Notice di `PlatformSettingsView.vue` | **PLANNED** |

---

## 3. Kesimpulan Verifikasi
1. Seluruh area yang berstatus **IMPLEMENTED** didukung oleh migrasi PostgreSQL riil, Model Eloquent teruji, Endpoint REST terproteksi middleware (`auth:sanctum`, `admin`, `throttle:api-user`), dan UI Vue yang terintegrasi tanpa mock data.
2. Modul **Moderation**, **Reviews**, dan **Reports** dipertahankan sebagai placeholder state terhormat sesuai batasan PRD v1.2.4 Section 4.2, tanpa mengarang tabel atau endpoint buatan.
