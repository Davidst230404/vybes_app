# Vybes

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel" alt="Laravel 13" />
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php" alt="PHP 8.3" />
  <img src="https://img.shields.io/badge/PostgreSQL-17-336791?style=for-the-badge&logo=postgresql" alt="PostgreSQL 17" />
  <img src="https://img.shields.io/badge/Redis-7-DC382D?style=for-the-badge&logo=redis" alt="Redis 7" />
</p>

Vybes adalah platform digital commerce dan ticketing yang menghubungkan merchant, organizer, dan customer dalam satu ekosistem booking dan event management. Project ini dibangun sebagai solusi untuk pengelolaan ketersediaan sumber daya, pemesanan tiket acara, pembayaran digital, serta verifikasi kehadiran melalui sistem check-in berbasis QR.

## Project Snapshot

- Product type: Booking & event ticketing platform
- Primary stack: Laravel + PHP + PostgreSQL + Redis + Docker
- Payment gateway: Xendit
- Use cases:
  - booking resource / venue / service
  - ticket order for event
  - payment session and QRIS-based checkout
  - QR check-in and validation
  - refund and late payment recovery

## Why This Project Exists

Vybes dibuat untuk menyederhanakan proses bisnis yang biasanya tersebar di beberapa kanal dan alat: mulai dari cek ketersediaan, pembuatan order, integrasi pembayaran, hingga validasi masuk di lokasi acara. Solusi ini dibuat agar proses operasional merchant dan organizer lebih cepat, lebih terukur, dan lebih aman.

## Key Features

### 1. Resource Booking Management
- cek ketersediaan resource
- hold / temporary reservation
- status pemesanan yang jelas
- relasi booking dengan item dan tiket

### 2. Event Ticketing
- pembuatan order tiket event
- pembatasan dan validasi kuota tiket
- pengelolaan tipe tiket
- pembayaran untuk order event

### 3. Payment Integration
- sesi pembayaran aman
- integrasi Xendit untuk pembayaran QRIS
- webhook handling untuk capture, failure, refund
- mekanisme refund dan recovery payment

### 4. Check-In System
- verifikasi kehadiran berbasis QR
- support untuk booking ticket dan event ticket
- proses check-in yang dapat dilakukan oleh merchant atau organizer

### 5. Role-Based Business Flow
- merchant
- organizer
- customer / end user
- pemisahan alur operasional antar role utama

## Architecture Overview

```text
Client App / Mobile / Web
        |
        v
Laravel API (backend)
        |
        +--> MySQL/PostgreSQL
        +--> Redis
        +--> Xendit Gateway
        +--> QR / Check-In validation
```

### Backend Modules
- Auth and user management
- Availability service
- Booking and payment service
- Event ticket order service
- Organizer management
- Check-in service
- Webhook processing for payment status notification

## Project Timeline

Project ini dikembangkan dalam kurun waktu sekitar 3 bulan, dimulai dari arsitektur awal hingga pengujian integrasi dan finalisasi fitur inti. Fokus pengembangannya mencakup:

- bulan 1: setup infrastructure, backend foundation, data model, auth
- bulan 2: booking flow, event ticket flow, payment session, webhook integration
- bulan 3: QR check-in, refund/recovery logic, stabilisasi, dan penyesuaian business flow

Dengan target penyelesaian dan readiness menuju akhir tahun, project ini dirancang agar siap untuk tahap pengujian lanjut, rollout, dan iterasi produk.

## Tech Stack

### Backend
- PHP 8.3
- Laravel 13
- Laravel Sanctum
- Eloquent ORM
- Artisan commands

### Data & Infra
- PostgreSQL 17
- Redis 7
- Docker Compose

### Integrations
- Xendit Payment API
- QR code generation
- webhook-based payment processing

## Local Development

### 1. Clone repository

```bash
git clone <repository-url>
cd vybes
```

### 2. Start infrastructure services

```bash
docker compose up -d
```

### 3. Setup backend

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

### 4. Optional frontend / client setup

```bash
cd frontend
npm install
npm run dev
```

> Sesuaikan dengan struktur project yang sedang Anda jalankan. Jika frontend berada di folder lain, sesuaikan command sesuai kebutuhan.

## Main API Modules

- Auth: register, login, user detail, logout
- Availability: check resource availability
- Booking: create and view booking
- Payment: session creation and payment flow
- Event ticket order: create, view, cancel order
- Organizer: manage events and ticket types
- Check-in: validate user entry using QR flow
- Webhooks: Xendit callback processing

## Current Status

Project ini berada dalam fase pengembangan fitur inti dan integrasi bisnis. Fokus saat ini adalah memperkuat alur transaksi, keamanan payment, serta pengalaman operasional merchant dan organizer.

## Roadmap

- improve transaction monitoring
- strengthen refund/recovery audit trail
- optimize organizer dashboard
- expand mobile client support
- enhance QA and staging readiness before production launch

## License

This project is currently under internal development and is not publicly released as a package or product license yet.

## Notes

README ini dibuat untuk memberikan gambaran proyek yang lebih profesional, mudah dibaca, dan siap digunakan sebagai dokumentasi awal untuk internal team, stakeholder, maupun penerus development.
