/* eslint-disable local/no-untranslated-literal -- DEV-only token/atom gallery
 * (not a production surface); the French demo labels and token names are
 * intentional fixed content, not user-facing copy to translate. */
import { useState, useEffect } from 'react';
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
import { ProductGrid } from '@/components/organisms/ProductGrid';
import { ProductDetailDrawer } from '@/components/organisms/ProductDetailDrawer';
import { ReportsPage } from '@/pages/ReportsPage';
import { ShiftClosurePage } from '@/pages/ShiftClosurePage';
import { ACCENTS, type AccentName, type Density } from '@/lib/theme';
import { useSettingsStore, type DisplayMode } from '@/stores/settingsStore';
import { useProductStore } from '@/stores/productStore';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { Search, Printer, ShoppingCart, Users, BarChart3, Wallet } from 'lucide-react';
import type { CartItem } from '@/types/cart';
import type { POSProduct } from '@/types/product';
import type { FiltresFilters } from '@/components/organisms/FiltresDrawer';

// ---------------------------------------------------------------------------
// Seeded sell-screen fixtures — realistic parapharmacy catalog for verifying
// ProductGrid/ProductCard sizing without a full POS bootstrap. Prices are
// STRINGS (precision contract); names vary in length to exercise the 1- vs
// 2-line name slot; stock states cover ok / low / out; categories map to the
// tint families in ProductThumb.
// ---------------------------------------------------------------------------
const SELL_CATEGORIES = ['Visage', 'Solaire', 'Corps', 'Cheveux', 'Bebe', 'Complements', 'Hygiene'];

function seedProduct(
  i: number,
  name: string,
  brand: string,
  category: string,
  price: string,
  stock: number,
  skin?: string,
): POSProduct {
  return {
    id: `seed-${i}`,
    name,
    sku: `SKU-${1000 + i}`,
    sale_price: price,
    stock_quantity: stock,
    category,
    brand_name: brand,
    ...(skin
      ? {
          parapharmacy_metadata: {
            suitable_skin_types: [skin],
            equivalent_product_ids: [],
            complement_product_ids: [],
            routine_refs: [{ routine_id: 'pulse-face', step_order: 1, step_label: category }],
          },
        }
      : {}),
  } as unknown as POSProduct;
}

const SELL_PRODUCTS: POSProduct[] = [
  seedProduct(1, 'Effaclar Gel Moussant Purifiant 200ml', 'La Roche-Posay', 'Visage', '38.500', 24, 'oily'),
  seedProduct(2, 'Cicaplast Baume B5', 'La Roche-Posay', 'Visage', '29.900', 6),
  seedProduct(3, 'Eau Thermale 300ml', 'Avène', 'Visage', '45.500', 40, 'sensitive'),
  seedProduct(4, 'Crème Hydratante Visage', 'CeraVe', 'Visage', '52.000', 0, 'dry'),
  seedProduct(5, 'Sérum Vitamine C Éclat Anti-Taches Intense', 'Vichy', 'Visage', '89.000', 3, 'normal'),
  seedProduct(6, 'Anthelios UVMune 400 SPF50+', 'La Roche-Posay', 'Solaire', '48.900', 18, 'oily'),
  seedProduct(7, 'Photoderm MAX Crème SPF50+', 'Bioderma', 'Solaire', '54.500', 12),
  seedProduct(8, 'Lait Après-Soleil Réparateur', 'Nuxe', 'Solaire', '33.000', 9),
  seedProduct(9, 'Lait Corporel Nourrissant Karité', 'Mixa', 'Corps', '14.900', 60, 'dry'),
  seedProduct(10, 'Huile Prodigieuse Multi-Fonctions', 'Nuxe', 'Corps', '76.000', 21),
  seedProduct(11, 'Atoderm Intensive Baume', 'Bioderma', 'Corps', '41.500', 5, 'sensitive'),
  seedProduct(12, 'Shampooing Doux Usage Fréquent', 'Klorane', 'Cheveux', '19.900', 33),
  seedProduct(13, 'Dercos Anti-Pelliculaire', 'Vichy', 'Cheveux', '37.000', 0),
  seedProduct(14, 'Élution Shampooing Rééquilibrant', 'Ducray', 'Cheveux', '28.500', 14),
  seedProduct(15, 'Liniment Oléo-Calcaire Bio', 'Gilbert', 'Bebe', '12.000', 48),
  seedProduct(16, 'Crème Change 1 2 3', 'Mustela', 'Bebe', '22.900', 7),
  seedProduct(17, 'Magnésium Marin B6 Fatigue', 'Nutergia', 'Complements', '31.000', 26),
  seedProduct(18, 'Vitamine D3 1000 UI Gouttes', 'ZymaD', 'Complements', '9.500', 2),
  seedProduct(19, 'Oméga 3 EPA DHA 60 caps', 'Arkopharma', 'Complements', '44.000', 15),
  seedProduct(20, 'Gel Hydroalcoolique 500ml', 'Aniosgel', 'Hygiene', '11.500', 80),
  seedProduct(21, 'Bain de Bouche Sans Alcool', 'Elmex', 'Hygiene', '16.900', 4),
  seedProduct(22, 'Dentifrice Protection Caries', 'Sensodyne', 'Hygiene', '13.500', 0),
  seedProduct(23, 'Tolériane Sensitive Fluide', 'La Roche-Posay', 'Visage', '39.900', 11, 'sensitive'),
  seedProduct(24, 'Hydrance Aqua-Gel', 'Avène', 'Visage', '42.500', 19, 'combination'),
  seedProduct(25, 'Sébium Hydra Crème Compensatrice', 'Bioderma', 'Visage', '35.000', 8, 'oily'),
  seedProduct(26, 'Nutritic Intense Riche', 'La Roche-Posay', 'Visage', '46.000', 22, 'dry'),
  seedProduct(27, 'Capital Soleil Brume Invisible SPF50', 'Vichy', 'Solaire', '43.500', 17),
  seedProduct(28, 'Cold Cream Corps', 'Avène', 'Corps', '24.900', 30, 'dry'),
];

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
  const [detailProduct, setDetailProduct] = useState<POSProduct | null>(null);
  // Sell-screen preview state
  const displayMode = useSettingsStore((s) => s.displayMode);
  const setDisplayMode = useSettingsStore((s) => s.setDisplayMode);
  const density = useSettingsStore((s) => s.density);
  const setDensity = useSettingsStore((s) => s.setDensity);
  const [gridFilters, setGridFilters] = useState<FiltresFilters>({ brands: [], categories: [], skinTypes: [], routines: [] });
  const [gridCart, setGridCart] = useState<string[]>(['seed-3']);
  // Enable the Merchandising surface (Filtres + product detail merchandising) in the harness.
  useEffect(() => {
    useProductStore.setState({
      companyConfig: { all_enabled_modules: ['Merchandising'] },
      products: SELL_PRODUCTS,
    });
  }, []);
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
        <SegmentedControl
          ariaLabel="density"
          value={density}
          onChange={(d) => setDensity(d as Density)}
          options={[{ value: 'comfortable', label: 'Confort' }, { value: 'dense', label: 'Dense' }]}
        />
        <SegmentedControl
          ariaLabel="displayMode"
          value={displayMode}
          onChange={(m) => setDisplayMode(m as DisplayMode)}
          options={[
            { value: 'vitrine', label: 'Vitrine' },
            { value: 'liste', label: 'Liste' },
            { value: 'tableau', label: 'Tableau' },
          ]}
        />
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

        <div className="lg:col-span-2">
          <Section title="Écran de vente — grille produits (données de test)">
            <div data-testid="sell-preview" className="h-[640px] overflow-hidden rounded-panel border border-border-subtle bg-surface-canvas">
              <ProductGrid
                products={SELL_PRODUCTS}
                categories={SELL_CATEGORIES}
                onAddToCart={(p) =>
                  setGridCart((prev) =>
                    prev.includes(p.id) ? prev.filter((id) => id !== p.id) : [...prev, p.id],
                  )
                }
                cartProductIds={gridCart}
                filters={gridFilters}
                onFiltersChange={setGridFilters}
                onViewDetails={setDetailProduct}
              />
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button
                variant="secondary"
                size="md"
                data-testid="open-product-detail-preview"
                onClick={() => setDetailProduct(SELL_PRODUCTS[0] ?? null)}
              >
                Ouvrir fiche produit
              </Button>
            </div>
          </Section>
        </div>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Section title="Rapports — Pulse">
          <div data-testid="reports-preview" className="h-[640px] overflow-hidden rounded-panel border border-border-subtle">
            <ReportsPage />
          </div>
        </Section>

        <Section title="Cloture service — X/Z">
          <div data-testid="shift-preview" className="h-[640px] overflow-hidden rounded-panel border border-border-subtle">
            <ShiftClosurePage />
          </div>
        </Section>
      </div>

      <div className="mt-6">
        <Section title="Clients — tactile">
          <div data-testid="customers-preview" className="grid h-[600px] overflow-hidden rounded-panel border border-border-subtle bg-surface-canvas md:grid-cols-[360px_minmax(0,1fr)]">
            <aside className="flex min-h-0 flex-col border-r border-border-subtle">
              <div className="border-b border-border-subtle p-3">
                <input
                  aria-label="Recherche clients"
                  className="min-h-12 w-full rounded-xl border border-border-strong bg-surface-raised px-3 py-2 text-base text-ink"
                  defaultValue="Ben"
                />
              </div>
              <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                {['Ben Salem Amira', 'Ben Youssef Nadia', 'Ben Romdhane Imen', 'Bennour Sami'].map((name, index) => (
                  <button
                    key={name}
                    type="button"
                    className={cn(
                      'flex min-h-[64px] w-full flex-col justify-center gap-1 rounded-xl px-4 py-3 text-left',
                      index === 0 ? 'bg-action text-ink-inverse' : 'bg-surface-raised text-ink',
                    )}
                  >
                    <span className="text-base font-semibold">{name}</span>
                    <span className={cn('text-sm', index === 0 ? 'text-ink-inverse/70' : 'text-ink-faint')}>
                      98 123 45{index} · peau sensible
                    </span>
                  </button>
                ))}
              </div>
            </aside>
            <div className="min-h-0 p-5">
              <div className="flex h-full flex-col rounded-2xl bg-surface-raised shadow-sm">
                <header className="border-b border-border-subtle px-5 py-4">
                  <h3 className="text-lg font-bold text-ink">Ben Salem Amira</h3>
                </header>
                <div className="flex-1 space-y-5 overflow-y-auto p-5">
                  <dl className="grid gap-3 text-sm">
                    <div>
                      <dt className="text-xs text-ink-faint">Téléphone</dt>
                      <dd className="text-ink">98 123 450</dd>
                    </div>
                    <div>
                      <dt className="text-xs text-ink-faint">E-mail</dt>
                      <dd className="text-ink">amira@example.test</dd>
                    </div>
                  </dl>
                  <div className="rounded-xl bg-surface-sunken p-4">
                    <div className="mb-3 flex items-center justify-between">
                      <h4 className="text-sm font-semibold text-ink">Profil de peau</h4>
                      <Button variant="ghost" size="md">Modifier</Button>
                    </div>
                    <p className="text-sm text-ink">Sensible · éviter les parfums, conseiller SPF50.</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </Section>
      </div>

      <ProductDetailDrawer
        isOpen={detailProduct !== null}
        product={detailProduct}
        onClose={() => setDetailProduct(null)}
      />
    </div>
  );
}
