# VYBES Admin CMS — Request Flow & Sequence Traces

**Document Reference:** Architecture Subsystem Specification (Phase 6.5)  
**Parent Document:** [System Architecture](admin-cms-system-architecture.md)

---

## 1. Overview

This document specifies the end-to-end lifecycle of HTTP requests originating from the VYBES Admin CMS client through the Laravel backend, including gateway validation, middleware gates, business services, database persistence, and audit logging.

---

## 2. Diagram 3 — Comprehensive Admin Request Flow

The following diagram illustrates the complete processing sequence from user navigation to API response, including authentication failure (401), authorization failure (403), validation rejection (422), and rate limiting (429).

```mermaid
sequenceDiagram
    autonumber
    actor AdminUser as Platform Admin (Browser)
    participant VueRouter as Vue Router 4 (Guards)
    participant Axios as Axios Client (api.js)
    participant LaravelGW as Laravel HTTP Kernel
    participant Sanctum as auth:sanctum Middleware
    participant AdminMW as EnsureUserIsAdmin Middleware
    participant Throttle as throttle:api-user Middleware
    participant FormReq as Form Request Validation
    participant Ctrl as Admin Controller
    participant Service as Application / Domain Service
    participant Audit as AuditLogger Service
    participant DB as PostgreSQL 17 Database

    AdminUser->>VueRouter: Navigate to /admin/{resource}
    
    alt Client Not Authenticated (No Token in Storage)
        VueRouter-->>AdminUser: Redirect to /auth/login
    else Client Has Token
        VueRouter->>VueRouter: Check meta.requiresAuth & authStore.isAdmin
        VueRouter->>Axios: Trigger API Call (e.g. GET /api/admin/{resource})
        Axios->>LaravelGW: HTTP Request (Headers: Bearer <token>, Accept: application/json)
        
        LaravelGW->>Sanctum: Authenticate Bearer Token
        alt Token Missing, Invalid, or Expired
            Sanctum-->>Axios: HTTP 401 Unauthorized {"message": "Unauthenticated."}
            Axios->>Axios: Clear localStorage token
            Axios-->>AdminUser: Redirect to /auth/session-expired
        else Token Authenticated
            Sanctum->>AdminMW: Forward Authenticated User Model
            AdminMW->>AdminMW: Verify: user.hasRole('admin') || user.hasPermission('admin.manage')
            alt User is Customer, Merchant, or Organizer
                AdminMW-->>Axios: HTTP 403 Forbidden {"message": "You do not have permission..."}
                Axios-->>AdminUser: Redirect to /admin/access-denied
            else User is Verified Admin
                AdminMW->>Throttle: Forward Request
                alt Exceeds 60 Requests / Minute
                    Throttle-->>Axios: HTTP 429 Too Many Requests {"message": "Too Many Attempts."}
                    Axios-->>AdminUser: Display Rate Limit Notification
                else Rate Limit OK
                    Throttle->>FormReq: Validate Incoming Payload & Types
                    alt Validation Fails
                        FormReq-->>Axios: HTTP 422 Unprocessable Content {"message": "...", "errors": {...}}
                        Axios-->>AdminUser: Display Field Validation Errors
                    else Validation Passes
                        FormReq->>Ctrl: Invoke Controller Action with Validated Data
                        
                        alt Read-Only Request (e.g. Index / Show / Dashboard)
                            Ctrl->>DB: Execute Query (with Eager Loading / Pagination)
                            DB-->>Ctrl: Eloquent Collection / Model
                            Ctrl-->>Axios: HTTP 200 OK (API Resource JSON)
                        else State-Mutating Request (e.g. Create / Update / Delete)
                            opt Business Service Invoked
                                Ctrl->>Service: Execute Domain Operation
                            end
                            
                            critical Database Mutation
                                Ctrl->>DB: Begin Transaction / Update / Insert
                                DB-->>Ctrl: Persisted Model Instance
                            end
                            
                            Ctrl->>Audit: AuditLogger::log(actor, action, entity, metadata)
                            Audit->>Audit: Sanitize Payload (Mask secrets to [REDACTED])
                            Audit->>DB: INSERT INTO audit_logs
                            
                            Ctrl-->>Axios: HTTP 200 OK / 201 Created (Transformed Resource JSON)
                        end
                        
                        Axios-->>AdminUser: Render View with Fresh State
                    end
                end
            end
        end
    end
```

---

## 3. End-to-End Execution Traces

### 3.1 Trace A: Query Request — Platform Bookings Supervision
- **Route:** `GET /api/admin/bookings`
- **Controller Action:** [BookingController@index](file:///D:/Projects/vybes/backend/app/Http/Controllers/Api/Admin/BookingController.php)
1. **HTTP Parameters:** Evaluates query parameters: `booking_code`, `status`, `venue_id`, and `per_page`.
2. **Bounds Enforcement:** Limits `per_page` to `min(max((int)$perPage, 1), 100)`.
3. **Query Construction:** Instantiates `Booking::with(['user', 'venue'])` to eliminate N+1 query overhead.
4. **Ordering & Pagination:** Applies `latest('id')->paginate($perPage)`.
5. **Transformation:** Wraps collection in `BookingResource::collection()`.
6. **Side Effects:** None; no database mutations or audit logs generated.

---

### 3.2 Trace B: Mutation Request — Operator Approval State Transition
- **Route:** `POST /api/admin/approvals/merchants/{merchant}`
- **Controller Action:** [ApprovalController@updateMerchantStatus](file:///D:/Projects/vybes/backend/app/Http/Controllers/Api/Admin/ApprovalController.php)
1. **Route Model Binding:** Automatically resolves `Merchant` instance by ID via `whereNumber('merchant')`.
2. **Form Request Validation:** [ApprovalRequest.php](file:///D:/Projects/vybes/backend/app/Http/Requests/Admin/ApprovalRequest.php) validates `status in:approved,rejected,pending`.
3. **Idempotency Gate:** Checks if `$merchant->status === $validated['status']`. If identical, returns early with HTTP 200: `"Merchant status is already {status}"` without duplicate mutation or audit spam.
4. **Model Mutation:** Calls `$merchant->update(['status' => $validated['status']])`.
5. **Audit Logging:** Calls [AuditLogger.php](file:///D:/Projects/vybes/backend/app/Services/AuditLogger.php) to insert an immutable entry:
   - `action`: `merchant.approval`
   - `entity_type`: `merchant`
   - `entity_id`: `$merchant->id`
   - `metadata`: `['business_name' => ..., 'old_status' => ..., 'new_status' => ...]`
6. **Response:** Returns HTTP 200 with loaded `user` relationship.

---

### 3.3 Trace C: Financial Mutation — Manual Refund Trigger
- **Route:** `POST /api/admin/refunds`
- **Controller Action:** [RefundController@store](file:///D:/Projects/vybes/backend/app/Http/Controllers/Api/Admin/RefundController.php)
1. **Form Request Validation:** [CreateRefundRequest.php](file:///D:/Projects/vybes/backend/app/Http/Requests/Admin/CreateRefundRequest.php) validates `payment_id` (`exists:payments,id`), `amount` (numeric, min: 1), and `reason` (string, max: 100).
2. **Payment Existence:** Retrieves `Payment::findOrFail($paymentId)`.
3. **Service Delegation:** Calls `PaymentRefundService::createRefund()`.
4. **Transaction Boundary (PRD BR-010):**
   - Inside DB transaction: Executes `Payment::whereKey($id)->lockForUpdate()`, validates `$payment->status === 'paid'`, ensures refund amount does not exceed payment amount, verifies no duplicate active/succeeded refund exists, and inserts `payment_refunds` record with status `pending`.
   - Transaction COMMITS before any external network communication.
5. **External Provider Call:** `PaymentRefundService` dispatches outbound HTTP POST to Xendit Refunds API.
6. **Graceful Exception Boundary:** If `PaymentRefundService` throws a `\RuntimeException` (e.g. payment not paid, provider mismatch), `RefundController` catches it and returns HTTP 422 with the exact message, preventing 500 server crashes.
7. **Audit Record:** Emits `refund.create` audit record with sanitized financial details.
8. **Asynchronous Resolution:** Final refund state (`succeeded` or `failed`) is updated when Xendit sends an inbound webhook to `/api/webhooks/xendit`.

---

### 3.4 Trace D: Settings Mutation — Hold Duration Configuration
- **Route:** `PATCH /api/admin/settings`
- **Controller Action:** [PlatformSettingController@update](file:///D:/Projects/vybes/backend/app/Http/Controllers/Api/Admin/PlatformSettingController.php)
1. **Validation:** [UpdatePlatformSettingsRequest.php](file:///D:/Projects/vybes/backend/app/Http/Requests/Admin/UpdatePlatformSettingsRequest.php) enforces integer between 1 and 1,440 minutes.
2. **Persistence:** `PlatformSetting::set('booking_hold_duration_minutes', $val, 'integer')`.
3. **Audit Record:** Records `platform_setting.update` capturing old value and new value.
4. **Downstream Propagation:** Immediately active for subsequent calls in `BookingService`, `EventTicketPurchaseService`, and `PaymentSessionService`.
