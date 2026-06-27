/* eslint-disable local/no-untranslated-literal -- DEV-only token/atom gallery
 * (not a production surface); the French demo labels and token names are
 * intentional fixed content, not user-facing copy to translate. */
import { useState } from 'react';
import {
  Button,
  IconButton,
  Badge,
  SegmentedControl,
  StatusPill,
  StockBadge,
  ProductThumb,
  Avatar,
  Stepper,
  Pill,
} from '@/components/ui';
import { ACCENTS, type AccentName } from '@/lib/theme';
import { useSettingsStore } from '@/stores/settingsStore';
import { formatCurrency } from '@/lib/currency';
import { Search, Printer } from 'lucide-react';

/**
 * DEV-ONLY visual gallery for the Caisse redesign token system + atoms.
 * Reachable at /theme-preview in `pnpm dev` (bypasses auth). Not a production
 * surface — a living swatch sheet to eyeball both themes / all accents / both
 * corner styles without a full POS bootstrap.
 */
const SURFACES = [
  ['surface-canvas', 'bg-surface-canvas'],
  ['surface-raised', 'bg-surface-raised'],
  ['surface-sunken', 'bg-surface-sunken'],
  ['accent', 'bg-accent'],
  ['accent-tint', 'bg-accent-tint'],
  ['success', 'bg-success'],
  ['warning', 'bg-warning'],
  ['danger', 'bg-danger'],
  ['stock-ok', 'bg-stock-ok'],
  ['stock-low', 'bg-stock-low'],
  ['stock-out', 'bg-stock-out'],
  ['pay-navy', 'bg-pay-navy'],
] as const;

const CATEGORIES = ['Visage', 'Solaire', 'Corps & Bain', 'Cheveux', 'Bébé', 'Compléments', 'Hygiène'];

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-panel border border-border-subtle bg-surface-raised p-5 shadow-sm">
      <h2 className="mb-4 font-display text-lg font-bold text-ink-strong">{title}</h2>
      {children}
    </section>
  );
}

export function ThemePreviewPage() {
  const theme = useSettingsStore((s) => s.theme);
  const accent = useSettingsStore((s) => s.accent);
  const corner = useSettingsStore((s) => s.corner);
  const setTheme = useSettingsStore((s) => s.setTheme);
  const setAccent = useSettingsStore((s) => s.setAccent);
  const setCorner = useSettingsStore((s) => s.setCorner);
  const [qty, setQty] = useState(2);
  const [cat, setCat] = useState('Visage');

  return (
    <div className="h-screen overflow-y-auto bg-surface-canvas p-6 text-ink">
      {/* Control bar */}
      <div className="mb-6 flex flex-wrap items-center gap-4 rounded-panel border border-border-subtle bg-surface-raised p-4 shadow-sm">
        <span className="font-display text-xl font-extrabold text-ink-strong">IziPOS · Aperçu du thème</span>
        <SegmentedControl
          ariaLabel="theme"
          value={theme}
          onChange={setTheme}
          options={[{ value: 'light', label: 'Clair' }, { value: 'dark', label: 'Sombre' }]}
        />
        <SegmentedControl
          ariaLabel="corner"
          value={corner}
          onChange={setCorner}
          options={[{ value: 'rounded', label: 'Arrondis' }, { value: 'sharp', label: 'Nets' }]}
        />
        <div className="flex gap-2">
          {ACCENTS.map((a) => (
            <button
              key={a}
              onClick={() => setAccent(a as AccentName)}
              aria-label={a}
              className={`h-9 w-9 rounded-full border-2 ${accent === a ? 'border-ink scale-110' : 'border-border-subtle'}`}
              style={{ backgroundColor: { orange: '#EA661A', green: '#1F8A5B', blue: '#2B6CC4', teal: '#0E8E80' }[a] }}
            />
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Section title="Typographie">
          <p className="font-display text-3xl font-extrabold text-ink-strong">Montserrat 800 — Titres</p>
          <p className="mt-2 text-base text-ink">Public Sans — corps de texte du point de vente, lisible à 50–70 cm.</p>
          <p className="mt-1 text-sm text-ink-muted">Public Sans 14px — métadonnées (SKU, unité).</p>
          <p className="mt-3 font-mono text-[2rem] font-bold tabular-nums text-ink-strong">{formatCurrency(1234.5, 'TND')}</p>
          <p className="font-mono text-xl tabular-nums text-ink">{formatCurrency(89.9, 'TND')} · {formatCurrency(12.345, 'TND')}</p>
        </Section>

        <Section title="Surfaces & couleurs (token)">
          <div className="grid grid-cols-4 gap-2">
            {SURFACES.map(([name, cls]) => (
              <div key={name} className="text-center">
                <div className={`${cls} h-12 rounded-card border border-border-subtle`} />
                <span className="mt-1 block text-xs text-ink-muted">{name}</span>
              </div>
            ))}
          </div>
        </Section>

        <Section title="Boutons">
          <div className="flex flex-wrap items-center gap-3">
            <Button variant="primary" size="lg">Encaisser</Button>
            <Button variant="secondary">Mixte</Button>
            <Button variant="confirm">Payer</Button>
            <Button variant="ghost">Remise</Button>
            <Button variant="destructive">Vider</Button>
            <IconButton aria-label="Rechercher" variant="secondary" icon={<Search className="h-5 w-5" />} />
            <IconButton aria-label="Imprimer" variant="ghost" icon={<Printer className="h-5 w-5" />} />
          </div>
        </Section>

        <Section title="Badges & statut">
          <div className="flex flex-wrap items-center gap-3">
            <Badge tone="neutral">Caisse 1</Badge>
            <Badge tone="action">Service #42</Badge>
            <StatusPill tone="healthy" label="En ligne" />
            <StockBadge status="ok">En stock</StockBadge>
            <StockBadge status="low">Stock faible</StockBadge>
            <StockBadge status="out">Rupture</StockBadge>
          </div>
        </Section>

        <Section title="Vignettes produit (initiales teintées)">
          <div className="flex flex-wrap gap-3">
            {CATEGORIES.map((c) => (
              <div key={c} className="text-center">
                <ProductThumb name={c} category={c} size={72} />
                <span className="mt-1 block text-xs text-ink-muted">{c}</span>
              </div>
            ))}
          </div>
        </Section>

        <Section title="Contrôles">
          <div className="flex flex-wrap items-center gap-6">
            <div className="flex items-center gap-2">
              <Avatar name="Maya Okonkwo" tone="accent" />
              <Avatar name="Sam Tan" />
            </div>
            <Stepper
              value={qty}
              onIncrement={() => setQty((q) => q + 1)}
              onDecrement={() => setQty((q) => Math.max(0, q - 1))}
              onValueClick={() => undefined}
              display={String(qty)}
            />
          </div>
          <div className="mt-4 flex flex-wrap gap-2">
            {CATEGORIES.map((c) => (
              <Pill key={c} selected={cat === c} onClick={() => setCat(c)}>{c}</Pill>
            ))}
          </div>
          <div className="mt-3 flex flex-wrap gap-2">
            <Pill onRemove={() => undefined}>Marque: Avène</Pill>
            <Pill onRemove={() => undefined}>Type de peau: Sensible</Pill>
          </div>
        </Section>
      </div>
    </div>
  );
}
