# Garage Scheduling, Capacity & Team Management

> **Status**: Planning
> **Related**: [ROADMAP.md](ROADMAP.md) — Otospex POS roadmap
> **Scope**: Bay-based scheduling calendar, light HR for technicians, capacity management

---

## Table of Contents

1. [How Garages Schedule Work](#1-how-garages-schedule-work)
2. [Scheduling Model: Bays, Not People](#2-scheduling-model-bays-not-people)
3. [Calendar UI Design](#3-calendar-ui-design)
4. [Capacity Management](#4-capacity-management)
5. [Team Management (Light HR)](#5-team-management-light-hr)
6. [Appointment Lifecycle](#6-appointment-lifecycle)
7. [Integration with Work Orders](#7-integration-with-work-orders)
8. [Customer-Facing Booking](#8-customer-facing-booking)
9. [Data Model](#9-data-model)
10. [Implementation Plan](#10-implementation-plan)

---

## 1. How Garages Schedule Work

### Industry Standard

Garages schedule by **physical bays** (lifts/ramps), not by technician. The bay is the hard constraint — you can't service more cars than you have bays. Technicians are the soft constraint — assigned after the bay slot is booked.

This is different from a hair salon (schedule by person) or a restaurant (schedule by table). A garage bay is a large, expensive, shared resource that defines throughput.

### Small vs Large Shops

| Shop Size | Bays | Scheduling Method | Calendar Need |
|-----------|------|-------------------|---------------|
| Tiny (1-2 bays) | 1-2 | Informal / whiteboard | Day view, simple |
| Small (3-5 bays) | 3-5 | Basic calendar software | Day + week view |
| Medium (6-10 bays) | 6-10 | Full scheduling with tech assignment | Day + week + capacity tracking |
| Large (10+ bays) | 10+ | Dispatch-based with specialization | Full suite + multi-bay types |

### What Real Systems Look Like

All major shop management systems (Tekmetric, Mitchell 1, Shop-Ware, AutoLeap, ROWriter) use the same pattern:

- **Day view** is the primary working screen
- **Columns = bays** (or technicians, configurable)
- **Rows = time slots** (30-minute grid)
- Appointments are **colored blocks** spanning their estimated duration
- **Drag-and-drop** to reschedule or reassign
- **Capacity bar** showing booked vs available hours

---

## 2. Scheduling Model: Bays, Not People

### Concept: "Spots" / Bays

The user configures their shop's physical capacity:

```
My Garage
├── Bay 1 — General (lift)
├── Bay 2 — General (lift)
├── Bay 3 — Quick Service (pit or flat)
└── Alignment Bay — Specialized (alignment rack)
```

Each bay is a column in the calendar. The shop owner defines:
- How many bays they have
- What type each bay is (determines what work can be scheduled there)
- Operating hours per bay (some bays may not operate on Saturdays)

### Bay Types

```
general          — Standard lift, handles most repairs
quick_service    — Oil changes, tire work, inspections (fast turnaround)
alignment        — Wheel alignment rack (specialized equipment)
heavy            — Heavy-duty lift for trucks, vans, SUVs
specialist       — Paint booth, body shop, diagnostic station
flat             — No lift, ground-level work only
```

### Technician Assignment is Secondary

After a bay slot is booked:
1. Service writer assigns a technician to the job
2. System can suggest based on: skill match, current workload, availability
3. One technician can work multiple bays (moving between jobs)
4. One bay can have multiple technicians (complex jobs)

This means the calendar shows **bay utilization**, not technician schedules. A separate "technician workload" view shows each tech's assigned jobs.

---

## 3. Calendar UI Design

### Day View (Primary)

```
┌─────────────────────────────────────────────────────────────────────┐
│  ◀ Mon 17 Mar 2026 ▶          [Day] [Week] [Month]    🔧 Settings │
├─────────┬──────────────┬──────────────┬──────────────┬─────────────┤
│  Time   │ Bay 1        │ Bay 2        │ Bay 3 (Quick)│ Alignment   │
│         │ General      │ General      │ Service      │ Specialist  │
├─────────┼──────────────┼──────────────┼──────────────┼─────────────┤
│  8:00   │ ┌──────────┐ │              │ ┌──────────┐ │             │
│         │ │🔵 Oil Chg │ │              │ │🟢 Tire   │ │             │
│  8:30   │ │ Ahmed B.  │ │              │ │ Rotation │ │             │
│         │ │ 123-TU-45 │ │ ┌──────────┐│ │ Samir K. │ │             │
│  9:00   │ └──────────┘ │ │🟠 Brake   ││ │ 789-TU-12│ │ ┌─────────┐│
│         │              │ │ Job       ││ └──────────┘ │ │🔵 Align  ││
│  9:30   │ ┌──────────┐ │ │ Nadia M.  ││              │ │ ment     ││
│         │ │🟡 Diag-  │ │ │ 456-TU-78 ││ ┌──────────┐│ │ Fathi L. ││
│ 10:00   │ │ nostic   │ │ │ (3 hrs)   ││ │🟢 Inspec ││ │ 321-TU-99││
│         │ │ Mehdi S.  │ │ │           ││ │ tion     ││ └─────────┘│
│ 10:30   │ │ (1.5 hrs) │ │ │           ││ │ Walk-in  ││             │
│         │ └──────────┘ │ └──────────┘│ └──────────┘│             │
│ 11:00   │              │              │              │             │
│         │    ░░░░░░░░  │    ░░░░░░░░  │ ┌──────────┐│             │
│ 11:30   │   (lunch)    │   (lunch)    │ │🟢 Oil Chg ││             │
│         │              │              │ │ Walk-in  ││             │
│ 12:00   │              │              │ └──────────┘│             │
│  ...    │              │              │              │             │
├─────────┴──────────────┴──────────────┴──────────────┴─────────────┤
│ CAPACITY: Bay 1: 5.5/8 hrs │ Bay 2: 6/8 hrs │ Bay 3: 4/8 │ Alg: 2/8│
│ TOTAL: 17.5 / 32 hrs (55%)  │  Unassigned: 2 appointments          │
└─────────────────────────────────────────────────────────────────────┘
```

### Appointment Block Content

Each block shows:
- **Color** — by status (default) or configurable (by type, by technician)
- **Service type** — icon + short name
- **Customer name** — or vehicle plate
- **Vehicle** — license plate
- **Duration** — visual height = estimated hours
- **Technician** — assigned tech name (if assigned)
- **Wait type** — icon: ⏳ waiter, 🚗 drop-off

### Color Coding (Default: By Status)

| Status | Color | Meaning |
|--------|-------|---------|
| Scheduled | 🔵 Blue | Confirmed appointment, not yet arrived |
| Checked In | 🟠 Orange | Customer arrived, vehicle at shop |
| In Progress | 🟡 Yellow | Technician working on it |
| Waiting Parts | 🔴 Red | Blocked — parts on order |
| Completed | 🟢 Green | Work done, awaiting pickup/payment |
| No Show | ⚫ Gray strikethrough | Customer didn't show up |

### Week View

Compressed: each day is a column, each bay is a row. Blocks show abbreviated info (customer + service type). Good for planning ahead and spotting gaps.

```
         │ Mon 17   │ Tue 18   │ Wed 19   │ Thu 20   │ Fri 21   │ Sat 22│
─────────┼──────────┼──────────┼──────────┼──────────┼──────────┼───────┤
Bay 1    │ ██ ██ ░░ │ ██ ██ ██ │ ░░ ██ ░░ │ ████████ │ ██ ██ ░░ │ ██ ░░ │
Bay 2    │ ████ ░░░ │ ██ ░░ ██ │ ████████ │ ██ ██ ░░ │ ░░░░░░░░ │ ░░░░░ │
Bay 3    │ ██████░░ │ ██████░░ │ ██████░░ │ ██████░░ │ ██████░░ │ ████░ │
Align    │ ██ ░░░░░ │ ░░░░░░░░ │ ██ ██ ░░ │ ░░░░░░░░ │ ██ ░░░░░ │ ░░░░░ │
─────────┼──────────┼──────────┼──────────┼──────────┼──────────┼───────┤
Capacity │ 72%      │ 81%      │ 63%      │ 59%      │ 44%      │ 50%   │
```

### Month View

High-level: shows appointment count per day with color-coded capacity (green/yellow/red).

### Interactions

- **Click empty slot** → Create appointment (pre-filled: bay, time)
- **Click appointment** → Open detail panel (customer, vehicle, services, technician, notes)
- **Drag block vertically** → Reschedule to different time (same bay)
- **Drag block horizontally** → Reassign to different bay
- **Resize block bottom edge** → Change estimated duration
- **Right-click** → Context menu (check in, cancel, convert to work order)

---

## 4. Capacity Management

### How Capacity Is Calculated

```
Daily capacity per bay = operating_hours (e.g., 8 hrs)
Daily shop capacity = sum of all bay hours

Booked capacity = sum of estimated_duration for all appointments
Available capacity = daily shop capacity - booked capacity
Utilization % = booked / total × 100
```

### Capacity Bar (Always Visible)

At the bottom of the calendar:
```
Bay 1: ████████░░░░ 5.5/8 hrs (69%)
Bay 2: ██████████░░ 7.0/8 hrs (88%)  ⚠️ Near capacity
Bay 3: ██████░░░░░░ 4.0/8 hrs (50%)
TOTAL: ████████░░░░ 16.5/24 hrs (69%)
```

### Overbooking Rules

- **Warning** at 90% capacity (yellow indicator)
- **Soft block** at 100% — shows warning but allows override
- **Hard block** configurable — prevent booking beyond X% (e.g., 110%)
- Walk-in buffer: shop can reserve X hours/day for walk-ins (subtracted from bookable capacity)

### Multi-Day Jobs

Jobs estimated at >1 day span across multiple calendar days:
- Bay is "occupied" for the duration
- Overnight hours (closed hours) shown as a faded connector between days
- The block label shows "Day 1/3", "Day 2/3", etc.

---

## 5. Team Management (Light HR)

### What This Is NOT

This is **not a full HR/payroll module**. It's a lightweight team roster for:
- Defining who works at the shop
- Their skill levels and certifications
- Their working hours and availability
- Their labor cost rate (for margin tracking)
- Assigning them to work orders

### Technician Profile

```
┌─────────────────────────────────────┐
│ 👤 Ahmed Ben Salah                   │
│ Role: Senior Technician              │
│ Skill Level: Senior                  │
│ Specialties: Engine, Electrical      │
│                                      │
│ Labor Cost Rate: 25.000 TND/hr      │
│ (Shop bills at: 50.000 TND/hr)      │
│                                      │
│ Certifications:                      │
│  ✅ General Mechanic — No expiry     │
│  ✅ Diagnostic Scanner — Exp 2027-06 │
│  ⚠️ AC Systems — Exp 2026-05 (soon!) │
│                                      │
│ Schedule: Mon-Fri 8:00-17:00         │
│           Sat 8:00-12:00             │
│                                      │
│ Time Off: Apr 14-18 (vacation)       │
└─────────────────────────────────────┘
```

### Skill Levels

```php
enum TechnicianSkillLevel: string {
    case Apprentice = 'apprentice';       // Learning, supervised
    case Junior = 'junior';               // Basic services unsupervised
    case General = 'general';             // Standard repairs
    case Senior = 'senior';              // Complex work, diagnostics
    case Master = 'master';              // Everything, mentors others
    case Specialist = 'specialist';      // Deep expertise in one area
}
```

### Specialties (Tags, Multiple Per Tech)

```php
enum TechnicianSpecialty: string {
    case Engine = 'engine';
    case Transmission = 'transmission';
    case Electrical = 'electrical';
    case Suspension = 'suspension';
    case Brakes = 'brakes';
    case AC = 'ac_climate';
    case Diagnostic = 'diagnostic';
    case Bodywork = 'bodywork';
    case Tires = 'tires';
    case Alignment = 'alignment';
    case Hybrid = 'hybrid_ev';
    case Diesel = 'diesel';
}
```

### Working Hours & Availability

Each technician has a weekly schedule:

```
Mon: 08:00 - 12:00, 13:00 - 17:00  (8 hrs, 1hr lunch)
Tue: 08:00 - 12:00, 13:00 - 17:00
Wed: 08:00 - 12:00, 13:00 - 17:00
Thu: 08:00 - 12:00, 13:00 - 17:00
Fri: 08:00 - 12:00, 13:00 - 17:00
Sat: 08:00 - 12:00                   (half day)
Sun: OFF
```

Plus time-off records (vacation, sick, training) that block availability on specific dates.

### Team List Page (Admin)

Simple CRUD table:
```
Name              │ Skill Level │ Specialties         │ Cost Rate  │ Status
──────────────────┼─────────────┼─────────────────────┼────────────┼────────
Ahmed Ben Salah   │ Senior      │ Engine, Electrical  │ 25.000/hr  │ Active
Mehdi Trabelsi    │ General     │ Brakes, Suspension  │ 18.000/hr  │ Active
Samir Khelifi     │ Junior      │ Tires, Oil Change   │ 12.000/hr  │ Active
Nadia Mansouri    │ Specialist  │ Diagnostic, AC      │ 30.000/hr  │ Active
```

---

## 6. Appointment Lifecycle

### Status Flow

```
              ┌───────────┐
  Created ──▶ │ Scheduled │
              └─────┬─────┘
                    │ customer arrives
              ┌─────▼─────┐
              │Checked In │ ──── customer no-show ──▶ No Show
              └─────┬─────┘
                    │ technician starts
              ┌─────▼─────┐
              │In Progress│ ──── parts needed ──▶ Waiting Parts ──▶ (back to In Progress)
              └─────┬─────┘
                    │ work done
              ┌─────▼─────┐
              │ Completed │
              └─────┬─────┘
                    │ customer picks up + pays
              ┌─────▼─────┐
              │  Closed   │
              └───────────┘
```

```php
enum AppointmentStatus: string {
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';       // Customer confirmed (SMS reply)
    case CheckedIn = 'checked_in';      // Vehicle arrived
    case InProgress = 'in_progress';    // Work started
    case WaitingParts = 'waiting_parts'; // Paused — parts on order
    case Completed = 'completed';       // Work done
    case Closed = 'closed';            // Paid and picked up
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
}
```

### Appointment Types

```php
enum AppointmentType: string {
    case QuickService = 'quick_service';         // 30-60 min
    case Inspection = 'inspection';              // 30-60 min
    case Diagnostic = 'diagnostic';              // 30-90 min
    case StandardRepair = 'standard_repair';     // 2-4 hrs
    case MajorRepair = 'major_repair';          // 4+ hrs, multi-day
    case ScheduledMaintenance = 'maintenance';   // 2-4 hrs
    case TireService = 'tire_service';          // 30-90 min
    case Bodywork = 'bodywork';                 // Multi-day
}
```

### Customer Wait Type

```php
enum CustomerWaitType: string {
    case Waiter = 'waiter';           // Stays at shop, expects <1hr
    case DropOff = 'drop_off';        // Leaves car, flexible pickup
    case PickupScheduled = 'pickup';  // Returning at specific time
}
```

---

## 7. Integration with Work Orders

### Appointment → Work Order Flow

```
1. APPOINTMENT created (customer + vehicle + services + bay + time)
      │
2. Customer ARRIVES → status: checked_in
      │
3. Click "Create Work Order" on the appointment
      │ → Pre-fills: customer, vehicle, mileage, services from appointment
      │ → Work order status: received
      │
4. Technician DIAGNOSES → adds findings, may add more services/parts
      │ → Work order status: quoted
      │ → Calendar block may resize if estimated hours changed
      │
5. Customer APPROVES quote
      │ → Work order status: approved → in_progress
      │ → Calendar block color changes to "in progress"
      │
6. Work COMPLETED
      │ → Work order status: completed
      │ → Calendar block turns green
      │
7. INVOICE generated → customer pays → appointment: closed
```

### Calendar Reflects Work Order Status

The appointment block color auto-updates based on the linked work order's status. This means the calendar is always an accurate view of what's happening in the shop.

### Multi-Day Jobs on Calendar

```
Mon 17              Tue 18              Wed 19
Bay 2               Bay 2               Bay 2
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│🟡 Engine Swap │───│🟡 Engine Swap │───│🟡 Engine Swap │
│   Day 1/3     │   │   Day 2/3     │   │   Day 3/3     │
│   Ahmed B.    │   │   Ahmed B.    │   │   Ahmed B.    │
│   456-TU-78   │   │               │   │               │
│   (all day)   │   │   (all day)   │   │   (until 14h) │
└───────────────┘   └───────────────┘   └───────────────┘
```

### Job Running Over Estimate

When a job takes longer than estimated:
1. Technician (or service writer) extends the duration on the work order
2. Calendar block auto-extends
3. If this conflicts with the next appointment in that bay:
   - System shows a **collision warning**
   - Suggests: reschedule the next appointment, or move current job to another bay
   - Service writer decides (drag-and-drop)

---

## 8. Customer-Facing Booking (Future — Via Storefront)

> This integrates with the online ordering infrastructure (see [../pos/STOREFRONT-WEBSITE.md](../pos/STOREFRONT-WEBSITE.md)).

### Online Booking Flow

```
Customer visits Otospex storefront → "Book a Service"
  → Select service type (from configured list)
  → Enter vehicle info (plate or make/model/year)
  → System shows available slots (real-time bay availability)
  → Customer picks date + time
  → Enters name + phone
  → Selects: waiter or drop-off
  → Confirmation (SMS + email)
  → Appointment appears on shop calendar as "Scheduled"
```

### Shop Configuration for Online Booking

- Which service types are bookable online (quick service yes, major repair probably not)
- Maximum advance booking window (e.g., 30 days)
- Minimum notice (e.g., 24 hours)
- Auto-confirm vs manual approval (pending until shop approves)
- Walk-in buffer (reserve X hrs/day unbookable online)

### Communication Touchpoints

| Event | Channel | Timing |
|-------|---------|--------|
| Booking confirmed | SMS + email | Immediately |
| Reminder | SMS | 24 hours before |
| Vehicle checked in | SMS | On check-in |
| Quote ready / approval needed | SMS with link | When quoted |
| Work complete / ready for pickup | SMS + email | On completion |
| Follow-up / feedback request | Email | 48 hours after |

---

## 9. Data Model

### New Tables

```sql
-- Shop bays (physical resources)
CREATE TABLE workshop_bays (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    location_id UUID NOT NULL,
    name VARCHAR(100) NOT NULL,          -- "Bay 1", "Alignment Rack"
    bay_type VARCHAR(30) NOT NULL,       -- general, quick_service, alignment, heavy, specialist
    position INT NOT NULL DEFAULT 0,     -- Display order in calendar
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    operating_hours JSONB,               -- Per-day hours: {"mon": {"open": "08:00", "close": "17:00"}, ...}
    notes TEXT,
    UNIQUE(tenant_id, company_id, location_id, name)
);

-- Technicians (extends users, light HR)
CREATE TABLE workshop_technicians (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    user_id UUID NOT NULL REFERENCES users(id),  -- Links to auth user
    skill_level VARCHAR(20) NOT NULL,    -- apprentice, junior, general, senior, master, specialist
    specialties JSONB DEFAULT '[]',      -- Array of specialty tags
    labor_cost_rate DECIMAL(12,3),       -- Internal cost per hour (for margin tracking)
    weekly_schedule JSONB,               -- Per-day working hours
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    notes TEXT,
    UNIQUE(tenant_id, company_id, user_id)
);

-- Technician certifications
CREATE TABLE workshop_technician_certifications (
    id UUID PRIMARY KEY,
    technician_id UUID NOT NULL REFERENCES workshop_technicians(id),
    certification_name VARCHAR(200) NOT NULL,
    issuing_body VARCHAR(200),
    issued_at DATE,
    expires_at DATE,                     -- NULL = no expiry
    certificate_number VARCHAR(100),
    notes TEXT
);

-- Technician time-off
CREATE TABLE workshop_technician_time_off (
    id UUID PRIMARY KEY,
    technician_id UUID NOT NULL REFERENCES workshop_technicians(id),
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    reason VARCHAR(50) NOT NULL,         -- vacation, sick, training, personal
    notes TEXT,
    approved BOOLEAN NOT NULL DEFAULT FALSE,
    approved_by UUID
);

-- Appointments (calendar blocks)
CREATE TABLE workshop_appointments (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    location_id UUID NOT NULL,
    bay_id UUID REFERENCES workshop_bays(id),
    technician_id UUID REFERENCES workshop_technicians(id),
    partner_id UUID REFERENCES partners(id),       -- Customer
    vehicle_id UUID REFERENCES vehicles(id),
    work_order_id UUID,                             -- FK, set when WO created from appointment

    appointment_number VARCHAR(30),                 -- APT-2026-00001
    appointment_type VARCHAR(30) NOT NULL,          -- quick_service, standard_repair, etc.
    customer_wait_type VARCHAR(20) NOT NULL DEFAULT 'drop_off',
    status VARCHAR(20) NOT NULL DEFAULT 'scheduled',

    -- Scheduling
    scheduled_start TIMESTAMPTZ NOT NULL,
    scheduled_end TIMESTAMPTZ NOT NULL,             -- start + estimated_duration
    estimated_duration_minutes INT NOT NULL,
    actual_start TIMESTAMPTZ,
    actual_end TIMESTAMPTZ,

    -- Customer info (denormalized for quick display)
    customer_name VARCHAR(200),
    customer_phone VARCHAR(30),
    vehicle_plate VARCHAR(30),
    vehicle_description VARCHAR(200),               -- "2019 Peugeot 308 1.6 HDi"

    -- Details
    services_summary TEXT,                          -- "Oil change, brake inspection"
    customer_notes TEXT,                            -- What customer reported
    internal_notes TEXT,                            -- Service writer notes
    color_label VARCHAR(20),                        -- Optional color override

    -- Source
    source VARCHAR(20) NOT NULL DEFAULT 'manual',   -- manual, online, phone
    online_booking_token VARCHAR(64),               -- For online booking tracking

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT valid_schedule CHECK (scheduled_end > scheduled_start)
);

CREATE INDEX idx_appointments_schedule ON workshop_appointments(bay_id, scheduled_start, scheduled_end);
CREATE INDEX idx_appointments_status ON workshop_appointments(company_id, status, scheduled_start);
CREATE INDEX idx_appointments_partner ON workshop_appointments(partner_id);
CREATE INDEX idx_appointments_vehicle ON workshop_appointments(vehicle_id);

-- Appointment-service link (what services are planned)
CREATE TABLE workshop_appointment_services (
    id UUID PRIMARY KEY,
    appointment_id UUID NOT NULL REFERENCES workshop_appointments(id) ON DELETE CASCADE,
    service_id UUID NOT NULL,                       -- FK to services table
    service_name VARCHAR(200) NOT NULL,             -- Snapshot
    estimated_duration_minutes INT NOT NULL,
    estimated_price DECIMAL(12,3)
);

-- Shop scheduling configuration
CREATE TABLE workshop_schedule_config (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    company_id UUID NOT NULL,
    location_id UUID NOT NULL,
    time_slot_minutes INT NOT NULL DEFAULT 30,      -- Calendar grid: 15 or 30
    default_appointment_duration_minutes INT NOT NULL DEFAULT 60,
    walk_in_buffer_hours DECIMAL(4,1) DEFAULT 0,    -- Hours reserved for walk-ins per day
    overbooking_threshold_percent INT DEFAULT 100,   -- 100 = no overbooking, 110 = 10% over
    online_booking_enabled BOOLEAN DEFAULT FALSE,
    online_booking_advance_days INT DEFAULT 30,
    online_booking_min_notice_hours INT DEFAULT 24,
    online_booking_auto_confirm BOOLEAN DEFAULT FALSE,
    booking_confirmation_sms BOOLEAN DEFAULT TRUE,
    reminder_sms_hours_before INT DEFAULT 24,
    UNIQUE(tenant_id, company_id, location_id)
);
```

### Modified Tables

```sql
-- Add to work_orders (from Workshop module):
ALTER TABLE workshop_work_orders ADD COLUMN appointment_id UUID REFERENCES workshop_appointments(id);
ALTER TABLE workshop_work_orders ADD COLUMN bay_id UUID REFERENCES workshop_bays(id);
```

---

## 10. Implementation Plan

### Phase Integration with Otospex Roadmap

This scheduling feature fits into the Otospex roadmap as follows:

| Roadmap Phase | Scheduling Work |
|---------------|-----------------|
| Phase 1 (Workshop MVP) | Bay + Technician CRUD, basic appointment model |
| Phase 2 (POS Layout) | — |
| Phase 3 (Core Charges + Labor) | Labor time tracking links to technician assignments |
| **New: Phase 4a** | **Calendar UI + capacity management** |
| **New: Phase 4b** | **Online booking (via Storefront integration)** |

### Phase 4a — Calendar & Team Management (3 weeks)

**Week 1: Backend**
- [ ] Create `workshop_bays` table + CRUD API
- [ ] Create `workshop_technicians` table + CRUD API (links to `users`)
- [ ] Create `workshop_technician_certifications` + `time_off` tables + API
- [ ] Create `workshop_appointments` table + CRUD API
- [ ] Create `workshop_schedule_config` table + API
- [ ] Appointment status transitions (with validation)
- [ ] Capacity calculation service (booked vs available hours per bay per day)
- [ ] Collision detection (warn on double-booking)
- [ ] Permissions: `workshop.schedule.view`, `workshop.schedule.manage`, `workshop.team.manage`

**Week 2: Calendar UI**
- [ ] Day view: bay columns, 30-min rows, colored appointment blocks
- [ ] Appointment creation modal (customer, vehicle, service, bay, time)
- [ ] Appointment detail panel (view/edit, status actions)
- [ ] Drag-and-drop: reschedule (vertical) and reassign bay (horizontal)
- [ ] Capacity bar at bottom (per-bay and total)
- [ ] Week view (compressed planning view)
- [ ] Color coding by status (default) with configurable option

**Week 3: Team Management UI + Integration**
- [ ] Team list page (technicians table with skill level, specialties, cost rate)
- [ ] Technician profile form (skill level, specialties, schedule, certifications)
- [ ] Time-off management (add/approve time off, reflected in availability)
- [ ] Certification expiry warnings (dashboard alert for expiring certs)
- [ ] Appointment → Work Order conversion button
- [ ] Work order status → calendar block color sync
- [ ] Technician workload view (secondary view: rows = techs, shows their assigned jobs)

### Phase 4b — Online Booking (1 week, after Storefront API exists)

- [ ] Storefront endpoint: `GET /api/storefront/{slug}/availability` (returns open bay slots)
- [ ] Storefront endpoint: `POST /api/storefront/{slug}/appointments` (create booking)
- [ ] Booking form on Otospex storefront (select service → see available slots → book)
- [ ] SMS confirmation + reminder via notification system
- [ ] Pending approval flow (if not auto-confirm)
- [ ] Online appointments appear on shop calendar tagged as "online" source
