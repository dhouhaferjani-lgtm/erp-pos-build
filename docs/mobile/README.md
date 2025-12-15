# Mobile Application

> React Native mobile app for field operations.

---

## Purpose

The mobile app provides field-optimized interfaces for operations that benefit from mobility:
- **Inventory Counting** - Blind counting with barcode scanning
- **Task Management** - View and complete assigned tasks
- **Future**: Vehicle reception, work order updates

---

## Tech Stack

| Technology | Version | Purpose |
|------------|---------|---------|
| React Native | Latest | Mobile framework |
| Expo | 54+ | Development platform |
| TypeScript | 5+ | Type safety |
| Expo Router | 6+ | File-based routing |
| TanStack Query | 5+ | Server state |
| Zustand | 4+ | Client state |
| Axios | Latest | API client |

---

## Project Structure

```
apps/mobile/
├── app/                    # Expo Router screens
│   ├── (auth)/            # Login screens
│   │   └── login.tsx
│   ├── (app)/             # Authenticated screens
│   │   ├── index.tsx      # Dashboard
│   │   ├── tasks.tsx      # Task list
│   │   ├── profile.tsx    # User profile
│   │   └── counting/      # Inventory counting
│   │       └── [id]/      # Count session screens
│   └── _layout.tsx        # Root layout
├── src/
│   ├── features/          # Feature modules
│   │   └── counting/      # Counting feature
│   │       ├── api/       # API calls
│   │       ├── hooks/     # React hooks
│   │       └── store/     # Zustand store
│   ├── components/        # Shared components
│   ├── lib/               # Utilities
│   └── providers/         # Context providers
├── assets/                # Images, fonts
└── constants/             # App constants
```

---

## Key Features

### Inventory Counting

Primary feature - enables blind counting workflow:
- Counters see only product info (no quantities)
- Barcode scanning for fast item lookup
- Offline capability with sync
- See [Inventory Counting Feature](../features/inventory-counting.md)

### Authentication

- Secure token storage (Expo SecureStore)
- Auto-refresh tokens
- Biometric unlock (future)

### Offline Support

- Queue actions when offline
- Sync when connection restored
- NetInfo for connection detection

---

## Development

```bash
# Start development server
cd apps/mobile
pnpm start

# Run on iOS simulator
pnpm ios

# Run on Android emulator
pnpm android

# Type check
pnpm typecheck

# Run tests
pnpm test
```

---

## API Integration

Uses same API as web app with mobile-optimized endpoints:

```typescript
// Base configuration
const api = axios.create({
  baseURL: process.env.EXPO_PUBLIC_API_URL,
});

// Auth header injection
api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
```

---

## Related Documentation

- [Inventory Counting](../features/inventory-counting.md) - Main feature spec
- [Tech Stack](../architecture/tech-stack.md) - Full stack overview
