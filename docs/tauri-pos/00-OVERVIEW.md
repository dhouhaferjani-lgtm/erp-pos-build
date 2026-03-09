# Tauri POS - Desktop Point of Sale for F&B

> Desktop POS application built with Tauri 2, React, and TypeScript. Designed for Food & Beverage verticals (Restaurant, Coffee Shop) with offline-first architecture and hardware integration.

## Product Context

The Tauri POS is a dedicated desktop application that replaces the web-based POS for on-premise F&B operations. Unlike the web POS, it has:

- **Offline-first operation** with local SQLite database and sync
- **Hardware integration**: receipt printers, kitchen printers, cash drawers, barcode scanners, customer displays
- **Kiosk/fullscreen mode** for dedicated POS terminals
- **Local report generation** (Z-reports, shift summaries) with PDF export
- **Settings UI** for hardware configuration and terminal preferences
- **Auto-updates** via Tauri's built-in updater

## Target Verticals

| Vertical | Key Features |
|----------|-------------|
| **Restaurant** | Table management, KDS, dine-in/takeout, course management |
| **Coffee Shop** | Quick-service, modifier-heavy orders, loyalty stamps |

Both use the `Menu` module with composite items, modifier groups, and recipe tracking.

## Technology Stack

| Layer | Technology |
|-------|-----------|
| Desktop Shell | Tauri 2 (Rust backend) |
| Frontend | React 19 + TypeScript + Vite |
| Local Storage | SQLite (via `tauri-plugin-sql`) |
| State Management | Zustand 5 |
| Styling | Tailwind CSS 4 (shared design system) |
| Backend API | AutoERP Laravel API (existing) |

## Relationship to Web POS

The Tauri POS is **not a wrapper around the web POS**. It is a standalone application that:

1. Shares the same **backend API** (`apps/api`)
2. Reuses **type definitions** from `packages/shared/types/`
3. May share some **React components** (extracted into a shared package later)
4. Has its **own routing, state management, and offline layer**

Features that exist only in web POS (admin-oriented):
- Terminal CRUD management (admin creates terminals in web, Tauri registers against them)
- Shift history browsing across all terminals
- Receipt search across all terminals

Features that exist only in Tauri POS:
- Offline mode with sync queue
- Hardware integration (printers, scanners, cash drawers)
- Settings pages for hardware and terminal config
- Local Z-report generation and PDF export
- Kiosk/fullscreen mode
- Auto-updates

## Documentation Index

| Document | Purpose |
|----------|---------|
| [01-BACKEND-AUDIT.md](01-BACKEND-AUDIT.md) | Current state of backend modules |
| [02-FEATURE-MATRIX.md](02-FEATURE-MATRIX.md) | Feature comparison: Web vs Tauri POS |
| [03-ARCHITECTURE.md](03-ARCHITECTURE.md) | Tauri app architecture and project structure |
| [04-MODULES.md](04-MODULES.md) | Tauri POS module breakdown |
| [05-BACKEND-GAPS.md](05-BACKEND-GAPS.md) | Backend API gaps to fill |
| [06-ROADMAP.md](06-ROADMAP.md) | Implementation phases and priorities |
