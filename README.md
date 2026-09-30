# VYBES Backend

<p align="center">
  <img src="https://readme-typing-svg.demolab.com?font=JetBrains+Mono&size=18&duration=2800&pause=900&color=D95F39&center=true&vCenter=true&width=760&lines=Booking+%7C+Event+Ticketing+%7C+Payment;Laravel+%2B+PostgreSQL+%2B+Redis+%2B+Docker;Secure+QR+Check-in+%7C+Xendit+Webhook+%7C+Refund;Built+as+the+transaction+engine+of+VYBES" alt="VYBES animation">
</p>

<p align="center">
  <strong>Core API & Transaction Engine untuk Platform Booking dan Event Ticketing</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white">
  <img src="https://img.shields.io/badge/PostgreSQL-17-336791?style=for-the-badge&logo=postgresql&logoColor=white">
  <img src="https://img.shields.io/badge/Redis-7-DC382D?style=for-the-badge&logo=redis&logoColor=white">
  <img src="https://img.shields.io/badge/Docker-Compose-2496ED?style=for-the-badge&logo=docker&logoColor=white">
  <img src="https://img.shields.io/badge/Xendit-Payment-6C2CFF?style=for-the-badge">
</p>

---

## Tentang VYBES

**VYBES Backend** merupakan core API layer dari **VYBES**, platform digital untuk discovery, booking, event ticketing, pembayaran, dan validasi akses.

Backend menangani business logic utama yang menghubungkan aplikasi customer, merchant, organizer, serta kebutuhan operasional platform.

Fokus utama backend:

- Booking & availability
- Event ticketing & quota management
- Payment & Xendit integration
- Refund & late-payment recovery
- Signed QR ticket & check-in
- Authentication dan role-based access

> **Status:** Active Development — Real Product Project

---

## Ringkasan Project

VYBES dikembangkan dalam focused development window sekitar tiga bulan dengan milestone yang bergerak dari foundation hingga payment, ticketing, recovery, dan stabilization.

Tahapan utama:

1. Foundation dan system architecture
2. Authentication dan role-based access
3. Booking dan resource availability
4. Event ticket order dan quota management
5. Payment session dan Xendit integration
6. QR ticket generation dan check-in
7. Refund dan late-payment recovery
8. QA, stabilization, dan persiapan release

Target pengembangan diarahkan menuju **production readiness untuk release akhir tahun**, dengan scope dan prioritas mengikuti keputusan product/project management.

---

## Arsitektur

VYBES menggunakan pendekatan **modular monolith** pada tahap pengembangan saat ini.

```text
                         ┌──────────────────────┐
                         │     VYBES Mobile     │
                         │    React Native      │
                         └──────────┬───────────┘
                                    │ HTTPS / REST API
                                    ▼
                         ┌──────────────────────┐
                         │      Nginx/API       │
                         └──────────┬───────────┘
                                    │
                         ┌──────────▼───────────┐
                         │    Laravel 13 API    │
                         │                      │
                         │ Auth / Booking       │
                         │ Ticket / Payment     │
                         │ Check-in / Refund    │
                         └──────┬─────────┬─────┘
                                │         │
                    ┌───────────▼───┐ ┌──▼────────────┐
                    │ PostgreSQL 17 │ │   Redis 7     │
                    │ Source of Truth│ │ Cache / Queue │
                    └───────────────┘ └───────────────┘
                                │
                         ┌──────▼───────┐
                         │    Xendit    │
                         │ Payment API  │
                         │ + Webhooks   │
                         └──────────────┘
```

### Prinsip Arsitektur

- PostgreSQL menjadi source of truth untuk transaksi dan inventory.
- Redis menjadi infrastructure pendukung untuk cache/queue.
- Critical inventory operation menggunakan database transaction dan row locking.
- Xendit menjadi payment provider.
- External refund request dilakukan setelah critical database transaction selesai.

---

## Fitur Utama

### Authentication

- Register
- Login
- Current authenticated user
- Logout
- Laravel Sanctum token authentication
- Role-based access

### Booking

- Resource availability checking
- Booking hold
- Reservation flow
- Booking detail
- Digital ticket retrieval
- Payment session
- Hold expiration
- Concurrency protection

### Event Ticketing

- Event ticket order
- Temporary ticket hold
- Ticket quota management
- Ticket confirmation
- Individual ticket issuance
- Unique ticket code
- Signed QR payload
- Order cancellation
- Ticket order payment session

### Payment

- Payment session
- Payment record
- Xendit payment request
- Payment status handling
- Xendit webhook validation
- Payment capture
- Payment failure
- Idempotent webhook processing

### Refund & Recovery

- Payment refund record
- Xendit refund request
- Refund status tracking
- `refund.succeeded`
- `refund.failed`
- Late payment recovery
- Inventory reacquisition
- Automatic refund ketika inventory tidak tersedia

### QR Check-in

- QR ticket validation
- Signed QR verification
- Ticket state validation
- Duplicate check-in prevention
- Event/booking access verification

---

## Role & Business Context

| Role | Tanggung Jawab Utama |
|---|---|
| Customer | Discovery, booking, pembelian tiket, pembayaran, dan akses tiket |
| Merchant | Pengelolaan venue/resource dan operasional booking |
| Organizer | Pengelolaan event, ticket type, quota, participant, dan check-in |
| Admin | Approval, moderation, monitoring, konfigurasi, dan operasional platform |

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.3 |
| Framework | Laravel 13 |
| Authentication | Laravel Sanctum |
| Database | PostgreSQL 17 |
| Cache / Infrastructure | Redis 7 |
| Containerization | Docker Compose |
| Payment | Xendit API |
| API Style | REST API |
| Version Control | Git / GitHub |
| API Testing | Postman |
| Mobile Client | React Native |

---

## Struktur Project

```text
backend/
├── app/
│   ├── Console/
│   ├── Http/
│   │   └── Controllers/
│   │       └── Api/
│   ├── Models/
│   ├── Services/
│   └── Providers/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   └── seeders/
├── public/
├── resources/
├── routes/
│   └── api.php
├── storage/
├── tests/
├── artisan
├── composer.json
├── phpunit.xml
└── .env.example
```

---

## Main Service Layer

Business logic penting dipisahkan ke service layer agar controller tetap fokus pada HTTP request/response.

```text
app/Services/
├── EventTicketPurchaseService
├── EventTicketService
├── PaymentSessionService
├── PaymentService
├── PaymentRefundService
├── LatePaymentRecoveryService
├── XenditService
└── CheckInService
```

---

## Alur Event Ticket

```text
Customer
   │
   ▼
Create Ticket Hold
   │
   ├── Validate event
   ├── Validate sales window
   ├── Lock ticket type
   ├── Check quota
   └── reserved += quantity
   │
   ▼
Payment Session
   │
   ▼
Xendit Payment
   │
   ▼
Xendit Webhook
   │
   ├── Payment Capture
   │
   ▼
Confirm Order
   │
   ├── reserved -= quantity
   ├── sold += quantity
   └── Generate Individual Tickets
   │
   ▼
Signed QR Ticket
   │
   ▼
Check-in
```

---

## Late Payment Recovery

Ketika payment berhasil diterima setelah ticket order expired, backend mencoba memperoleh kembali inventory.

```text
PAYMENT CAPTURE
      │
      ▼
Order = EXPIRED?
   /          \
 NO            YES
 │              │
 ▼              ▼
Normal     Lock Ticket Type
Flow             │
                 ▼
          Check Available Quota
             /          \
           YES           NO
            │             │
            ▼             ▼
      Re-acquire       Request Refund
       Inventory         to Xendit
            │             │
            ▼             ▼
        CONFIRMED     REFUND PENDING
            │             │
            ▼             ▼
      Generate Ticket  Refund Webhook
```

Database transaction digunakan untuk keputusan inventory. Komunikasi refund dengan Xendit dilakukan setelah transaksi database selesai.

---

## Security & Consistency

- Authentication menggunakan Laravel Sanctum.
- Authorization berdasarkan role/permission.
- Input validation pada API.
- PostgreSQL transaction untuk operasi kritis.
- Row locking pada inventory/resource.
- Signed QR payload untuk ticket verification.
- Webhook token validation untuk callback Xendit.
- Idempotency pada payment/webhook flow.
- Pencegahan duplicate ticket/check-in.
- External payment/refund request tidak dilakukan di tengah critical DB transaction.

---

## Local Setup

### 1. Clone Repository

```bash
git clone <repository-url>
cd vybes/backend
```

### 2. Install Dependency

```bash
composer install
```

### 3. Environment

```bash
Setup Sendiri
```

### 4. Jalankan Infrastructure

```bash
docker compose up -d
docker compose ps
```

### 5. Migration

```bash
php artisan migrate
```

Untuk seed:

```bash
php artisan db:seed
```

### 6. Jalankan Laravel

```bash
php artisan serve
```

## API Endpoint Utama

```text
POST   /api/auth/register
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me

GET    /api/bookings
POST   /api/bookings
GET    /api/bookings/{booking}
GET    /api/bookings/{booking}/ticket

POST   /api/bookings/{booking}/payment-session

POST   /api/events/{event}/ticket-orders
GET    /api/event-ticket-orders/{order}
POST   /api/event-ticket-orders/{order}/cancel
POST   /api/event-ticket-orders/{order}/payment-session

POST   /api/check-in

POST   /api/webhooks/xendit
```

---

## Development Workflow

```text
Requirement
    ↓
PRD / Technical Specification
    ↓
Database / API Design
    ↓
Implementation
    ↓
Local Testing
    ↓
Integration Testing
    ↓
Code Review
    ↓
QA / UAT
    ↓
Staging
    ↓
Production
```

Perubahan requirement harus dicatat pada PRD dan/atau technical documentation agar Backend, Mobile, Admin/CMS, QA, dan Project Management tetap menggunakan baseline yang sama.

---

## Testing

Area testing:

- Unit Test
- Feature Test
- Integration Test
- API Test
- Black-box Test
- Payment webhook test
- Refund test
- Concurrency test
- QR validation test
- Check-in test
- UAT

Area validasi utama:

```text
Booking
├── availability
├── hold
├── payment
├── confirmation
└── expiration

Event Ticket
├── quota
├── reserved
├── sold
├── ticket issuance
└── check-in

Payment
├── capture
├── failure
├── webhook retry
├── refund
└── late payment recovery
```

---

## Project Timeline

| Tahap | Fokus |
|---|---|
| Foundation | Architecture, Laravel, PostgreSQL, Docker, authentication |
| Booking | Availability, resource, hold, reservation |
| Event Ticketing | Event, ticket type, quota, order, ticket issuance |
| Payment | Payment session dan Xendit integration |
| Security | Webhook validation, signed QR, authorization |
| Recovery | Late payment recovery dan refund |
| Stabilization | Inventory consistency, idempotency, testing |
| Release Preparation | QA, UAT, deployment, production readiness |

---

## Current Development Focus

Area yang masih menjadi bagian dari development dan hardening:

- Admin operational features
- Merchant operational features
- Organizer participant/check-in flow
- Review dan moderation
- Notification
- Cancellation policy
- Audit log
- Idempotency hardening
- Refund end-to-end testing
- Inventory reconciliation
- Concurrency/load testing
- Security testing
- Mobile API integration
- Production deployment

Status fitur dapat berubah mengikuti hasil development dan keputusan Project Manager.

---

## Project Documentation

```text
docs/
├── PRD/
├── API/
├── Architecture/
├── Database/
├── QA/
└── Deployment/
```

PRD digunakan sebagai baseline requirement dan diperbarui melalui versioning apabila terjadi perubahan scope, business rule, atau requirement.

---

## Engineering Principles

> **Requirement → Business Rule → Transaction → Validation → Test → Release**

- Database adalah source of truth untuk transaksi dan inventory.
- Business logic penting dipisahkan dari controller.
- Critical inventory operation harus transactional.
- External payment provider diperlakukan sebagai external dependency.
- Webhook harus aman dan idempotent.
- QR ticket harus dapat diverifikasi secara aman.
- Requirement dan implementasi harus traceable.
- Perubahan scope harus terdokumentasi.

---

## Repository Status

```text
Project       : VYBES
Component     : Backend
Framework     : Laravel 13
Database      : PostgreSQL 17
Cache         : Redis 7
Payment       : Xendit
Auth          : Laravel Sanctum
Container     : Docker Compose
API           : REST
Status        : Active Development
```

---

<p align="center">
  <img src="https://readme-typing-svg.demolab.com?font=JetBrains+Mono&size=16&duration=3000&pause=1000&color=2F6B4F&center=true&vCenter=true&width=650&lines=VYBES+Backend;Booking+%7C+Events+%7C+Tickets+%7C+Payments+%7C+Check-in" alt="VYBES footer animation">
</p>

<p align="center">
  <strong>Built for the VYBES ecosystem.</strong>
</p>
