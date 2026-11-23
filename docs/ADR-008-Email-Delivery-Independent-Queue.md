# ADR-008: Email Delivery as Independent Channel Queue

**Status:** ACCEPTED / LOCKED  
**Date:** 2026-01-11  
**Phase:** Post-Phase 8 (Delivery Architecture)  
**Scope:** Messaging / Async Delivery  
**Deciders:** Architecture Board  
**Supersedes:** None  
**Related:**  
- ADR-007 Notification Module Scope & History Coupling  
- ADR-009 Telegram Delivery as Independent Channel Queue  
- Phase 8 — Notification Intent  
- Phase 9 — Delivery Execution  

---

## 1️⃣ Context

The system requires reliable, secure, and extensible **asynchronous email delivery**
without coupling email transport logic to the Notification domain.

Email delivery was intentionally designed with:
- encrypted payloads
- async queue processing
- retry and failure semantics
- transport isolation (SMTP)

To preserve architectural clarity and prevent future channel entanglement,
Email delivery must remain **independent from Notification orchestration logic**.

---

## 2️⃣ Problem Statement

Should Email delivery be:

- **Option A:** Embedded directly inside the Notification Delivery system  
- **Option B:** Implemented as a standalone, channel-owned delivery subsystem  

---

## 3️⃣ Decision

**We choose Option B.**

> **Email delivery SHALL be implemented as an independent, channel-owned delivery system with its own encrypted queue, worker, and transport layer.**

The Notification system MAY enqueue Email delivery jobs,
but Email delivery is **not owned by** the Notification system.

---

## 4️⃣ Architectural Decision

### ✅ Email is a Delivery Channel, not a Notification System

- Email handles **message transport only**
- Notifications handle **intent, preference, and history**
- The integration point is **enqueue-only**

```

Notification → EmailQueueWriter → email_queue → EmailQueueWorker → SMTP

```

---

### ✅ Independent Queue Contract

A dedicated database table **`email_queue`** exists with:

- Encrypted recipient address
- Encrypted rendered payload
- Template binding (template_key, language)
- Priority, scheduling, and retry semantics
- Independent delivery lifecycle

The schema is considered **LOCKED** and authoritative.

---

### ✅ Strict Responsibility Boundaries

| Layer                 | Responsibility                   |
|-----------------------|----------------------------------|
| Notification Intent   | Why to notify                    |
| Routing / Preferences | Which channels                   |
| Email Queue           | Delivery execution               |
| Email Worker          | Retry, backoff, SMTP failures    |
| Email Transport       | SMTP / Mail provider interaction |

---

## 5️⃣ Explicitly Forbidden

The following are **NOT allowed**:

- ❌ Email worker reading from `notification_delivery_queue`
- ❌ Notification system sending emails directly
- ❌ Shared workers between Email and other channels
- ❌ Email logic performing routing or preference resolution
- ❌ Email system storing notification history or severity

Any of the above constitutes a **hard architectural violation**.

---

## 6️⃣ Consequences

### 🟢 Positive
- Clear separation of concerns
- Predictable email delivery behavior
- Safer retry and failure isolation
- Clean extension path for additional channels
- No channel cross-contamination

### 🔴 Costs
- Dedicated queue table
- Dedicated worker process
- Intentional duplication of delivery patterns

---

## 7️⃣ Compliance & Invariants

- Encryption model MUST use AES-GCM with key versioning
- No plaintext email addresses at rest
- Delivery failures MUST be persisted as state
- No exceptions for expected delivery errors
- No coupling to Notification internals

---

## 8️⃣ Implementation Order (MANDATORY)

1. **Database schema (`email_queue`) — LOCK**
2. ADR-008 accepted and locked (this document)
3. Email module contracts
4. Queue writer
5. Worker
6. Transport adapter

Any deviation from this order requires a **new ADR**.

---

## 9️⃣ Final Verdict

> **Email delivery is an independent delivery subsystem.  
> Notifications enqueue — Email delivers.  
> No shared ownership. No shared queues.**

**ADR-008 is hereby ACCEPTED and LOCKED.**

---
