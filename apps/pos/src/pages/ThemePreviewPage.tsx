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
  Tabs,
  Toggle,
  KpiCard,
  BreakdownBar,
  Divider,
  Drawer,
} from '@/components/ui';
import { NavRail } from '@/components/NavRail';
import { CartLineItem } from '@/components/molecules/CartLineItem';
import { ACCENTS, type AccentName } from '@/lib/theme';
import { useSettingsStore } from '@/stores/settingsStore';
import { formatCurrency } from '@/lib/currency';
import { Search, Printer, ShoppingCart, Users, BarChart3, Wallet } from 'lucide-react';
import type { CartItem } from '@/types/cart';

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
  const [tab, setTab] = useState('desc');
  const [consent, setConsent] = useState(true);
  const [nav, setNav] = useState('caisse');
  const [expandedLine, setExpandedLine] = useState<string | null>('l2');
  const [drawerOpen, setDrawerOpen] = useState(false);
  const mockCart: CartItem[] = [
    { id: 'l1', quantity: 2, unit_price: '45.500', line_total: '91.000', product: { id: 'p1', name: 'Avène Eau Thermale 300ml', sku: 'AV1', price: '45.500' } } as unknown as CartItem,
    { id: 'l2', quantity: 1, unit_price: '120.000', line_total: '108.000', discount_amount: '12.000', discount_type: 'percentage', discount_percent: 10, product: { id: 'p2', name: 'CeraVe Crème Hydratante', sku: 'CV1', price: '120.000' } } as unknown as CartItem,
    { id: 'l3', quantity: 3, unit_price: '8.900', line_total: '26.700', product: { id: 'p3', name: 'Doliprane 1000mg', sku: 'DL1', price: '8.900' } } as unknown as CartItem,
  ];

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

        <Section title="Onglets & bascule (fiche produit / consentement)">
          <Tabs
            value={tab}
            onChange={setTab}
            tabs={[
              { id: 'desc', label: 'Description' },
              { id: 'equiv', label: 'Équivalents', count: 3 },
              { id: 'comp', label: 'Compléments', count: 2 },
              { id: 'routine', label: 'Routine' },
            ]}
          />
          <div className="mt-3 flex items-center gap-3">
            <Toggle checked={consent} onChange={setConsent} ariaLabel="Consentement marketing" />
            <span className="text-sm text-ink-muted">Consentement marketing</span>
          </div>
        </Section>

        <Section title="Rapports (KPI & répartition)">
          <div className="grid grid-cols-3 gap-3">
            <KpiCard label="Ventes" value="4 820,500 DT" hint="Aujourd’hui" />
            <KpiCard label="Transactions" value="37" />
            <KpiCard label="Panier moyen" value="130,280 DT" />
          </div>
          <div className="mt-4 flex flex-col gap-3">
            <BreakdownBar label="Espèces" valueText="3 100,000 DT" pct={64} fillClass="bg-accent" />
            <BreakdownBar label="Carte" valueText="1 200,500 DT" pct={25} fillClass="bg-action" />
            <BreakdownBar label="Chèque" valueText="520,000 DT" pct={11} fillClass="bg-ink-muted" />
          </div>
          <div className="mt-4 flex items-center gap-2 text-sm text-ink-muted">
            <span>Caisse 1</span>
            <Divider orientation="vertical" />
            <span>Service #42</span>
            <Divider orientation="vertical" />
            <span>Fond 200,000 DT</span>
          </div>
        </Section>

        <Section title="Drawer (panneau coulissant)">
          <Button variant="secondary" onClick={() => setDrawerOpen(true)}>
            Ouvrir le tiroir
          </Button>
          <Drawer
            isOpen={drawerOpen}
            onClose={() => setDrawerOpen(false)}
            title="Filtres"
            closeLabel="Fermer"
            footer={
              <Button variant="primary" fullWidth onClick={() => setDrawerOpen(false)}>
                Appliquer
              </Button>
            }
          >
            <div className="flex flex-col gap-3">
              <Pill selected>Visage</Pill>
              <Pill>Solaire</Pill>
              <Pill>Cheveux</Pill>
              <p className="text-sm text-ink-muted">
                Démo du shell Drawer — fond, glissement 240 ms, piège de focus, fermeture par
                Échap / fond.
              </p>
            </div>
          </Drawer>
        </Section>

        <div className="lg:col-span-2">
          <Section title="Nav rail + panier (repli/expansion — tap pour étendre)">
            <div className="flex h-[420px] overflow-hidden rounded-panel border border-border-subtle">
              <NavRail
                items={[
                  { id: 'caisse', label: 'Caisse', icon: <ShoppingCart className="h-5 w-5" /> },
                  { id: 'clients', label: 'Clients', icon: <Users className="h-5 w-5" /> },
                  { id: 'rapports', label: 'Rapports', icon: <BarChart3 className="h-5 w-5" /> },
                  { id: 'shift', label: 'Caisse', icon: <Wallet className="h-5 w-5" /> },
                ]}
                active={nav}
                onSelect={setNav}
                theme={theme}
                onToggleTheme={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
                brand={<span className="font-display text-xl font-extrabold text-accent">i</span>}
              />
              <div className="flex w-[420px] flex-col bg-surface-canvas p-3">
                <div className="mb-2 flex items-center justify-between">
                  <span className="font-display text-lg font-bold text-ink-strong">Panier</span>
                  <span className="rounded-pill bg-accent-tint px-2 text-sm font-semibold text-accent-strong">
                    {mockCart.length} articles
                  </span>
                </div>
                <div className="flex flex-col gap-2 overflow-y-auto">
                  {mockCart.map((it) => (
                    <CartLineItem
                      key={it.id}
                      item={it}
                      onUpdateQuantity={() => undefined}
                      onRemove={() => undefined}
                      onQuantityTap={() => undefined}
                      onDiscount={() => undefined}
                      expanded={expandedLine === it.id}
                      onToggleExpand={(id) => setExpandedLine((p) => (p === id ? null : id))}
                    />
                  ))}
                </div>
                <div className="mt-auto pt-3">
                  <div className="mb-2 flex items-baseline justify-between">
                    <span className="text-sm text-ink-muted">Total</span>
                    <span className="font-mono text-2xl font-bold tabular-nums text-ink-strong">
                      {formatCurrency(225.7, 'TND')}
                    </span>
                  </div>
                  <div className="flex gap-2">
                    <Button variant="primary" size="lg" fullWidth>Encaisser</Button>
                    <Button variant="secondary" size="lg">Mixte</Button>
                  </div>
                </div>
              </div>
            </div>
          </Section>
        </div>
      </div>
    </div>
  );
}
