Add a new i18n translation namespace "$ARGUMENTS" to AutoERP.

## Steps

1. **Create English translations** at `apps/web/src/locales/en/{namespace}.json`:
   ```json
   {
     "title": "...",
     "create": "...",
     "edit": "...",
     "fields": {
       "name": "...",
       "description": "..."
     }
   }
   ```

2. **Create French translations** at `apps/web/src/locales/fr/{namespace}.json`:
   - Mirror the same key structure as English
   - Translate all values to French

3. **Update `apps/web/src/lib/i18n.ts`** in exactly 3 places:

   **Place 1 — Import:**
   ```typescript
   import {namespace}En from '../locales/en/{namespace}.json'
   import {namespace}Fr from '../locales/fr/{namespace}.json'
   ```

   **Place 2 — Resources object:**
   ```typescript
   resources: {
     en: {
       // ... existing
       {namespace}: {namespace}En,
     },
     fr: {
       // ... existing
       {namespace}: {namespace}Fr,
     },
   }
   ```

   **Place 3 — ns array:**
   ```typescript
   ns: ['common', ..., '{namespace}'],
   ```

4. **Use in components:**
   ```tsx
   const { t } = useTranslation('{namespace}')
   <h1>{t('{namespace}:title')}</h1>
   ```

## Key naming convention
Use `{namespace}.{feature}.{element}` hierarchy. See `.claude/context/i18n.md` for details.
