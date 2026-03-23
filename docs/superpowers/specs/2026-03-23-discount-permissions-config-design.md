# Discount Permissions Configuration — Design Spec

> **Date:** 2026-03-23
> **Priority:** Critical — blocks coffee shop go-live
> **Scope:** Backend (apps/api), Web (apps/web), POS Desktop (apps/pos)

---

## 1. Fix Defaults + Super Admin Bypass

### Migration (existing data fix)
- Set `can_discount = true` for users with `admin` or `super_admin` role
- Set `max_discount_percent = 100` for all terminals where it is currently `0.00`
- Change `pos_terminals.max_discount_percent` column default from `0.00` to `100.00`

### Super admin bypass in verify-pin
- In `PosAuthController`, when returning operator data from `verifyPin`/`setupPin`/`pinData`, if the user has `super_admin` or `admin` role: override `can_discount = true` and `max_discount_percent = 100.00`
- This is a safety net — admin discount capability should never depend on a DB column that defaults to false

### UserFactory
- Default `can_discount` to `true` so test users work out of the box

---

## 2. Backend API Extensions (Hexagonal Architecture)

### User Module (Identity)

**Domain:** No changes to `User.php` model (fields already exist in fillable + casts)

**Application layer:**
- Extend `UserData` DTO — add `can_discount: bool` and `max_discount_percent: ?float`

**Presentation layer:**
- Extend `UpdateUserRequest` — add validation rules:
  - `can_discount` — `sometimes|boolean`
  - `max_discount_percent` — `sometimes|nullable|numeric|min:0|max:100`
- Extend `UserController.update()` — include `can_discount` and `max_discount_percent` in `$fieldsToUpdate`

### POS Module (Terminal)

**Presentation layer:**
- Extend `UpdateTerminalRequest` — add validation:
  - `max_discount_percent` — `sometimes|numeric|min:0|max:100`
  - `allow_line_discounts` — `sometimes|boolean`
  - `allow_transaction_discounts` — `sometimes|boolean`
- Extend `TerminalResource` — serialize all three discount fields

---

## 3. Web Frontend — User Edit + Terminal Settings

### User Edit Modal (UsersPage)
- New "Edit" action on each user row → opens modal
- Fields: name, email, phone, role, **POS Discount section** (toggle + percentage input)
- Uses `PATCH /users/{id}` with the extended fields
- i18n keys for labels in en/fr

### Terminal Discount Settings
- Add discount config fields to existing terminal management (if a terminal edit exists) or add a "POS Terminal Settings" section in the settings area
- Fields: max_discount_percent input, allow_line_discounts toggle, allow_transaction_discounts toggle
- Uses `PATCH /pos/terminals/{id}` with the extended fields

### Frontend Types
- Add `can_discount` and `max_discount_percent` to the web `User` interface

---

## 4. POS Desktop — Manager PIN Override

When a cashier tries to apply a discount but `!canDiscount` or value exceeds `maxDiscountPct`:

1. Instead of error toast, show inline PIN prompt in the discount modal
2. Manager enters their PIN on the numpad (already rendered)
3. Frontend calls `POST /pos/auth/verify-pin` with the manager's PIN
4. Check if the PIN owner has `can_discount = true` and sufficient `max_discount_percent`
5. If approved: apply the discount, store `discount_authorized_by` on the receipt
6. If denied: show inline error "Manager does not have sufficient discount permission"

Both the DiscountModal and LineDiscountModal need this flow.

---

## 5. Out of Scope

- Named/predefined discount definitions
- Discount rules repeater UI
- Role-based discount limits (currently per-user)
