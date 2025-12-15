# Tech Stack

> Complete technology stack for AutoERP.

---

## Backend

| Technology | Version | Purpose |
|------------|---------|---------|
| **PHP** | 8.3+ | Runtime |
| **Laravel** | 12.x | Framework |
| **PostgreSQL** | 16+ | Primary database |
| **Redis** | 7+ | Cache, queues, sessions |
| **Laravel Horizon** | Latest | Queue management |
| **Meilisearch** | Latest | Full-text search |

---

## Frontend (Web)

| Technology | Version | Purpose |
|------------|---------|---------|
| **React** | 18+ | UI library |
| **TypeScript** | 5+ | Type safety (strict mode) |
| **Vite** | 5+ | Build tool |
| **TanStack Query** | 5+ | Server state management |
| **Zustand** | 4+ | Client state (minimal) |
| **React Router** | 6+ | Routing |
| **Tailwind CSS** | 4+ | Styling |
| **Lucide React** | Latest | Icons |

---

## Mobile

| Technology | Version | Purpose |
|------------|---------|---------|
| **React Native** | Latest | Mobile framework |
| **Expo** | Latest | Development platform |
| **TypeScript** | 5+ | Type safety |

---

## Desktop POS (Future)

| Technology | Version | Purpose |
|------------|---------|---------|
| **Tauri 2** | Latest | Desktop runtime |
| **React** | 18+ | UI |

---

## Development Tools

| Tool | Purpose |
|------|---------|
| **PHPStan** | Static analysis (level 8) |
| **Pint** | PHP code style |
| **PHPUnit** | Backend testing |
| **Vitest** | Frontend testing |
| **ESLint** | TypeScript linting |
| **Playwright** | E2E testing |

---

## Infrastructure

| Service | Purpose |
|---------|---------|
| **Docker** | Local development |
| **TimescaleDB** | Time-series audit logs (PostgreSQL extension) |

---

## Code Quality Gates

```bash
# Backend
composer test              # PHPUnit
./vendor/bin/phpstan      # Static analysis (level 8)
./vendor/bin/pint         # Code style

# Frontend
pnpm test                 # Vitest
pnpm lint                 # ESLint
pnpm typecheck           # TypeScript strict
```

**Requirements:**
- PHPStan level 8 (no errors)
- TypeScript strict (no `any` types)
- Code coverage > 80% for domain layer
