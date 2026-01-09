# Clear Cache and Restart - POS Demo Issue

The POS demo is importing the correct components but showing the old UI due to a build cache issue.

## Quick Fix - Run These Commands:

```bash
cd apps/web

# 1. Clear Vite cache
rm -rf node_modules/.vite

# 2. Clear dist folder
rm -rf dist

# 3. Restart dev server
# Press Ctrl+C to stop current server, then:
npm run dev
# OR
pnpm dev
```

## Then:

1. **Hard refresh browser:** Cmd+Shift+R (Mac) or Ctrl+Shift+R (Windows)
2. **Navigate to:** http://localhost:5173/pos/demo
3. **You should see the NEW POS!**

---

## What You Should See (NEW POS):

✅ **Product Grid on the left** (60% width)
- Search bar at top
- Category filter buttons (Filters, Brakes, Engine, Fluids, etc.)
- Product cards in a grid (3-4 columns)
- Stock badges (green/yellow/red)
- Scrollable product list

✅ **Cart on the right** (40% width)
- Transaction Cart title
- Cart items with quantity controls
- Subtotal, Tax, Total calculations
- Clear cart button
- Quick Checkout button (green)
- Advanced Payments button (blue)
- Calculator button

✅ **Professional Design**
- Clean, modern interface
- Proper spacing and typography
- Touch-friendly buttons
- Real-time calculations

---

## What You're Currently Seeing (OLD POS):

❌ Basic interface
❌ Can't scroll products
❌ Fixed notification widget bottom right
❌ Simple cart UI
❌ No product grid

---

## If Still Not Working:

Check that imports are resolving correctly:

```bash
cd apps/web
npx tsc --noEmit
```

This will show TypeScript errors if path aliases aren't working.
