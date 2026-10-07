# VYBES Security Hardening — 06 Oktober 2026

## 1. Scope

Security hardening dilakukan terhadap source repository VYBES dengan fokus pada:

- authentication
- authorization dan IDOR protection
- organizer approval boundary
- state-changing endpoints
- webhook input validation
- rate limiting
- inventory/publication boundaries
- Sanctum token lifetime
- sensitive error exposure
- regression verification

Hardening ini dilakukan tanpa mengubah boundary yang sebelumnya sudah ditetapkan untuk payment/refund.

---

## 2. Security Findings & Remediation

| ID | Severity | Finding | Remediation | Status |
|---|---|---|---|---|
| SEC-H01 | HIGH | Endpoint organizer tidak selalu memastikan role organizer dan status approval sebelum operasi terproteksi. | Tambahkan validasi role `organizer`, organizer profile, dan `status=approved` pada event, ticket type, participant, dan check-in path. | Fixed |
| SEC-H02 | HIGH | Organizer dapat mencoba mengirim `status=published/cancelled/completed` melalui create/update event dan melewati controlled state transition. | `status` tidak lagi diterima sebagai input create/update; event baru selalu dibuat sebagai `draft`. | Fixed |
| SEC-H03 | HIGH | Detail exception/provider error dapat bocor ke response ketika refund gagal. | Exception dicatat melalui server-side logging dan client menerima pesan generik. `PaymentRefundService` tidak diubah. | Fixed |
| SEC-H04 | HIGH | Endpoint authenticated API belum memiliki rate limiting yang terdefinisi secara eksplisit. | Named rate limiter ditambahkan untuk login, register, authenticated API, dan check-in. | Fixed |
| SEC-H05 | HIGH | Webhook menerima payload malformed yang dapat mencapai business handler. | Payload wajib memiliki `event` string dan `data` array; event yang tidak didukung diabaikan tanpa business processing. | Fixed |
| SEC-H06 | MEDIUM | Availability/booking dapat berisiko menggunakan inventory yang inactive, venue yang unpublished, atau merchant yang belum approved. | Availability dan booking memvalidasi resource active, venue published, dan merchant approved sebelum transaksi dilanjutkan. | Fixed |
| SEC-H07 | MEDIUM | Personal access token tidak mempunyai expiration eksplisit pada konfigurasi sebelumnya. | Sanctum token expiration dibuat configurable dengan default `43200` menit / 30 hari. | Fixed |
| SEC-H08 | PASS | Booking/order/payment customer sudah memiliki ownership check terhadap authenticated user. | Tidak direfactor; existing protection dipertahankan. | Verified |
| SEC-H09 | PASS | Organizer participant/event authorization sudah memiliki ownership check berbasis event organizer. | Approval validation ditambahkan tanpa menghapus ownership protection. | Verified |
| SEC-H10 | PASS | Event QR menggunakan HMAC-SHA256 dan `hash_equals`; check-in menggunakan row lock untuk mencegah duplicate processing. | Existing implementation dipertahankan. | Verified |
| SEC-H11 | PASS | Repository tidak menyimpan `.env` production atau credential Xendit aktual; environment secret tetap berada di local environment. | Tidak diubah. `.env.example` tetap menjadi template dan tidak digunakan untuk secret aktual. | Verified |

---

## 3. Modified Security Components

Komponen yang diperkeras pada pass ini:

```text
app/Providers/AppServiceProvider.php
app/Services/AvailabilityService.php
app/Services/BookingService.php
app/Services/CheckInService.php
app/Http/Controllers/Api/OrganizerEventController.php
app/Http/Controllers/Api/OrganizerParticipantController.php
app/Http/Controllers/Api/OrganizerTicketTypeController.php
app/Http/Controllers/Api/XenditWebhookController.php
config/sanctum.php
routes/api.php
tests/Feature/SecurityHardeningTest.php