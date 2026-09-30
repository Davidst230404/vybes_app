# Vybes Backend

Vybes backend is the core API layer for a digital booking and event-ticketing platform. Built with Laravel, it powers merchant bookings, organizer event operations, secure payment flows, QR-based check-in, and refund handling for ticket and booking transactions.

## Overview

This project was developed over approximately three months, with feature work and integration milestones designed to reach production readiness by the end of the year. The backend focuses on three main business flows:

- booking and availability management
- event ticket purchasing and validation
- payment processing, refunds, and late recovery logic

## Core Features

- resource availability checking
- booking hold and reservation flow
- event ticket order creation and confirmation
- role-based access for users, merchants, and organizers
- QR-code based check-in for tickets
- Xendit payment session and webhook integration
- refund management and payment recovery workflow

## Tech Stack

- PHP 8.3
- Laravel 13
- Laravel Sanctum
- PostgreSQL 17
- Redis 7
- Docker Compose
- Xendit API

## Project Structure

```text
backend/
├── app/
│   ├── Console/
│   ├── Http/Controllers/Api/
│   ├── Models/
│   ├── Services/
│   └── Providers/
├── config/
├── database/
├── public/
├── routes/
├── storage/
├── tests/
├── composer.json
├── artisan
├── phpunit.xml
└── .env.example
```

## Main Business Modules

### Authentication
- register
- login
- current user info
- logout

### Booking
- create booking hold
- view bookings
- digital ticket retrieval
- payment session creation

### Event Ticket Orders
- create temporary ticket order
- view order detail
- cancel order
- create payment session

### Organizer
- list events
- create event
- manage ticket types

### Payment & Xendit Webhooks
- payment session creation
- callback validation from Xendit
- capture and failure handling
- refund processing
- late payment recovery scenario

### Check-In
- validate booking or event QR ticket
- process attendance based on valid ticket state

## Local Setup

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

For the local infrastructure services:

```bash
docker compose up -d
```

## Environment

This project is designed to work with:

- PostgreSQL on port 5433
- Redis on port 6379

## Timeline

The product was built throughout a focused three-month window, with development moving through:

1. foundation and system architecture
2. booking and ticketing features
3. payment and Xendit integration
4. refund/recovery flow and validation
5. stabilization before end-of-year release preparation

## Notes

This backend is the engine behind the Vybes ecosystem and represents the core product logic for internal business operations, customer transactions, and event-entry verification.
