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
import { QuickActions } from '@/components/molecules/QuickActions';
import { ProductGrid } from '@/components/organisms/ProductGrid';
import { ProductCard } from '@/components/molecules/ProductCard';
import { ProductListRow } from '@/components/organisms/ProductGrid/ProductListRow';
import { ProductTable } from '@/components/organisms/ProductGrid/ProductTable';
import { ProductDetailDrawer } from '@/components/organisms/ProductDetailDrawer';
import { PaymentSummary } from '@/components/pos/PaymentSummary';
import { ReportsPage } from '@/pages/ReportsPage';
import { ShiftClosurePage } from '@/pages/ShiftClosurePage';
import { ACCENTS, type AccentName, type Density } from '@/lib/theme';
import { useSettingsStore, type DisplayMode } from '@/stores/settingsStore';
import { useProductStore } from '@/stores/productStore';
import { formatCurrency } from '@/lib/currency';
import { cn } from '@/lib/utils';
import { Search, Printer, ShoppingCart, Users, BarChart3, Wallet } from 'lucide-react';
import type { CartItem } from '@/types/cart';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
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
  imageUrl?: string,
): POSProduct {
  return {
    id: `seed-${i}`,
    name,
    sku: `SKU-${1000 + i}`,
    sale_price: price,
    stock_quantity: stock,
    category,
    brand_name: brand,
    ...(imageUrl ? { image_url: imageUrl } : {}),
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

// ---------------------------------------------------------------------------
// Payment-footer fixtures — a ready payment config (2 active methods + a cash
// repository) so PaymentSummary shows BOTH actions: the dominant cash button
// and the compact icon-only "Autres paiements" control. Monetary string
// fields on the config objects follow the precision contract (strings);
// PaymentSummary's own amount props are numbers (its actual prop shape).
// ---------------------------------------------------------------------------
const PREVIEW_PAYMENT_METHODS: PaymentMethod[] = [
  {
    id: 'preview-pm-cash',
    code: 'CASH',
    name: 'Espèces',
    is_physical: true,
    has_maturity: false,
    requires_third_party: false,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    fee_type: null,
    fee_fixed: '0.000',
    fee_percent: '0.00',
    restriction_type: null,
    is_active: true,
    position: 1,
  },
  {
    id: 'preview-pm-card',
    code: 'CARD',
    name: 'Carte bancaire',
    is_physical: false,
    has_maturity: false,
    requires_third_party: false,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    fee_type: null,
    fee_fixed: '0.000',
    fee_percent: '0.00',
    restriction_type: null,
    is_active: true,
    position: 2,
  },
];

const PREVIEW_PAYMENT_REPOSITORIES: PaymentRepository[] = [
  {
    id: 'preview-repo-caisse',
    code: 'CAISSE1',
    name: 'Caisse 1',
    type: 'cash_register',
    bank_name: null,
    account_number: null,
    iban: null,
    bic: null,
    balance: '0.000',
    is_active: true,
  },
];

// ---------------------------------------------------------------------------
// Task 19 — tri-density fixtures (Vitrine / Liste / Tableau) for the
// auth-free Task 20 Playwright visual pass. `ProductGrid.displayMode` reads
// from a single global `settingsStore` slice, so three `<ProductGrid>`
// instances mounted at once cannot show three different modes — instead we
// render the real per-density leaf components (`ProductCard` / `ProductListRow`
// / `ProductTable`) directly with seeded data, which is what Task 20 actually
// needs to screenshot.
// ---------------------------------------------------------------------------

/**
 * Real parapharmacy products + real product photography, baked in from
 * `DemoImageProductsSeeder` (DigitalOcean CDN, hotlinked — SSRF-guarded on
 * the backend, plain `<img>` here). Used for the Vitrine and Liste fixtures
 * so both densities show genuine imagery, not just the ProductThumb
 * placeholder.
 */
const REAL_IMAGE_PRODUCTS: POSProduct[] = [
  // Deliberately WIDE price — stresses the Liste/Vitrine price-column width at
  // TND scale ("242 400,00") so a long number is verified against the layout,
  // not just short EUR prices. Guards the regression where the price overflowed
  // the (too-narrow) column and collided with the eye control.
  seedProduct(100, 'A-Derma Cytelium Spray 100ml', 'A-Derma', 'Visage', '242400.000', 24, 'sensitive', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/46/034ad053-bf70-4e31-afbd-7515eaadd9a1_0.webp'),
  seedProduct(101, 'Apivita Soin des Lèvres au Cassis', 'Apivita', 'Visage', '14.900', 40, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/5012/d930a71a-45c0-43fc-9a91-8af904a10526_0.webp'),
  seedProduct(102, 'Avène 50+ Émulsion 50ml', 'Avène', 'Solaire', '45.000', 18, 'sensitive', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13564/2cffb696-0204-4819-bb11-10c7c6c91b40_0.webp'),
  seedProduct(103, 'Beurer Vessie à Glace 28cm', 'Beurer', 'Hygiene', '22.000', 9, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14750/38e10b48-3d01-4aa4-ae62-afd78aeaf480_0.webp'),
  seedProduct(104, 'Bioderma Cicabio Lotion', 'Bioderma', 'Visage', '38.500', 3, 'sensitive', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13715/6fa37344-229a-4144-a4db-2a30d4eb313e_0.webp'),
  seedProduct(105, 'Byphasse Crème Hydratante', 'Byphasse', 'Corps', '12.500', 55, 'dry', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/3529/bd54c4ac-2c51-415e-acb4-a46f48053ab0_0.webp'),
  seedProduct(106, 'Canpol Babies Peluche Musicale', 'Canpol Babies', 'Bebe', '28.000', 12, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/3226/f6b94f98-ffba-4276-9ac9-764aad7b5f0e_0.webp'),
  seedProduct(107, 'Caudalie Eau de Beauté 100ml', 'Caudalie', 'Visage', '55.000', 0, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/12186/3af1ade7-34bc-450c-b523-30a0a846d2c4_0.webp'),
  seedProduct(108, 'Cetaphil Crème Hydratante 50g', 'Cetaphil', 'Visage', '18.000', 30, 'dry', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/409/b355050d-3fc2-4c18-b188-20161e96e7ec_0.webp'),
  seedProduct(109, 'Chicco Ciseaux Rose', 'Chicco', 'Bebe', '15.500', 2, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/760/58e99305-bd24-4f97-98d5-15c1fa0a7fd1_0.webp'),
  seedProduct(110, 'Dermedic Lotion Après-Soleil', 'Dermedic', 'Solaire', '26.000', 17, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13753/1b3a7306-3057-4c8b-828f-fe2a1883efd0_0.webp'),
  seedProduct(111, 'Ducray Keracnyl Sérum 30ml', 'Ducray', 'Visage', '42.000', 8, 'oily', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/10830/e04f5301-26e7-4d5c-bf4f-ffd4a9ce31f7_0.webp'),
  seedProduct(112, 'Elgydium Clinic Flex 123', 'Elgydium', 'Hygiene', '9.500', 0, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6313/a822d697-a05e-402b-9d66-ca9e3d3112e0_0.webp'),
  seedProduct(113, 'Eucerin Hyaluron Spray 150ml', 'Eucerin', 'Visage', '48.000', 21, 'dry', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/2679/712fe655-98af-4ce1-bb3f-374327bd07a4_0.webp'),
  seedProduct(114, 'Filorga Scrub & Peel', 'Filorga', 'Visage', '65.000', 4, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13848/23191fcb-6943-407b-8a8f-b1eeed83c462_0.webp'),
  seedProduct(115, 'Gum Dentifrice Kids 3+', 'Gum', 'Hygiene', '8.900', 60, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/140/8d6aff74-5f4a-48b9-a5d4-899ac458ba52_0.webp'),
  seedProduct(116, 'Herbeos Crème de Jouvence 30ml', 'Herbeos', 'Visage', '19.500', 14, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6854/d4d375b3-ca0d-46ab-99dc-9aa21b274b2d_0.webp'),
  seedProduct(117, 'Isdin Flavo-C Sérum 30ml', 'Isdin', 'Visage', '72.000', 1, 'normal', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/1618/82b7f6e4-8d09-4513-869d-ac4f371e000f_0.webp'),
  seedProduct(118, 'K-Reine Coffret Clouds', 'K-Reine', 'Corps', '34.000', 6, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13962/d947bd04-b614-42ab-8129-821c7bc038b6_0.webp'),
  seedProduct(119, 'La Roche-Posay Sérozinc 150ml', 'La Roche-Posay', 'Visage', '24.500', 35, 'oily', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6774/45c2686b-27d9-4353-af37-609d6546a2d5_0.webp'),
  seedProduct(120, 'Laino Eau de Rose 250ml', 'Laino', 'Visage', '11.000', 0, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/1451/70d506d6-0e25-414a-993d-6a617face8e8_0.webp'),
  seedProduct(121, 'Lierac Trousse Phyto', 'Lierac', 'Corps', '58.000', 10, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/6814/ce32a5eb-6008-47be-b6b8-a2b64e3320e5_0.webp'),
  seedProduct(122, 'Mustela Shampooing 500ml', 'Mustela', 'Bebe', '27.500', 27, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/1479/25ac77af-e45c-413a-bbea-171ecca532c5_0.webp'),
  seedProduct(123, 'Naturtint Masque Éco Force 150ml', 'Naturtint', 'Cheveux', '21.000', 5, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/7825/c0b7f94d-daba-4622-b4ed-cf5245f3e209_0.webp'),
  seedProduct(124, 'Noreva Exfoliac Global X Pro', 'Noreva', 'Visage', '36.000', 16, 'oily', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/7415/0f4464b6-4ac3-408a-bf41-6c1e8d2cc120_0.webp'),
  seedProduct(125, 'Nuk Ciseaux Bébé', 'Nuk', 'Bebe', '13.000', 22, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/10817/c433d14a-084e-4a51-bd3e-3dea2b46f0e7_0.webp'),
  seedProduct(126, 'Nuxe Super Sérum [10] 30ml', 'Nuxe', 'Visage', '89.000', 0, 'normal', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/4591/a1bbf247-a767-416f-b8af-cdec27a93694_0.webp'),
  seedProduct(127, 'Oral-B Brosse Enfant 8 Ans', 'Oral-B', 'Hygiene', '7.500', 45, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14612/edc5154f-770c-40d2-9b20-d499ab068ba0_0.webp'),
  seedProduct(128, 'Pampers S6 Boîte de 24', 'Pampers', 'Bebe', '33.000', 19, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/10049/0dbff992-d058-4914-aca3-fa0e3b27d094_0.webp'),
  seedProduct(129, 'Pharmaceris Viti Melo Nuit 40ml', 'Pharmaceris', 'Visage', '47.000', 3, 'dry', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/13453/09c0a726-00e3-425f-813f-709610c4ad64_0.webp'),
  seedProduct(130, 'Phyto Vasculux', 'Phyto', 'Complements', '39.000', 11, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14122/28f7d29c-1445-45c4-8abe-6e96e755d5a1_0.webp'),
  seedProduct(131, 'Roge Cavaillès Antador Gel-Crème 50ml', 'Roge Cavaillès', 'Corps', '22.500', 26, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14546/072e804e-90f5-4db3-805b-9a9ad12d41f9_0.webp'),
  seedProduct(132, 'Sensodyne Fil Dentaire', 'Sensodyne', 'Hygiene', '6.900', 0, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14175/6f5b7582-3523-431a-bb91-7b66fac0f434_0.webp'),
  seedProduct(133, 'SVR Pepti Biotic 50ml', 'SVR', 'Visage', '44.000', 13, 'normal', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14214/2c90caae-f11e-4477-ad0e-f697a32d35c1_0.webp'),
  seedProduct(134, 'Titania Éponge de Bain', 'Titania', 'Hygiene', '4.500', 70, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/4145/9d2a4ee7-7f7b-433b-a21e-0957c6f6d3a1_0.webp'),
  seedProduct(135, 'Tynor Canne en T L07', 'Tynor', 'Hygiene', '38.000', 4, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14352/20871ff9-973b-403d-874b-6224a37a1e93_0.webp'),
  seedProduct(136, 'Uriage Hyséac Mat 40ml', 'Uriage', 'Visage', '29.000', 20, 'oily', 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/333/43386c17-054c-4d46-9794-afc26e2e19d6_0.webp'),
  seedProduct(137, 'Vichy Pastille Cassis & Menthe', 'Vichy', 'Complements', '5.500', 0, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/14106/691671a3-74cd-4be0-b680-0dec97f20a89_0.webp'),
  seedProduct(138, 'Wee Baby Fork-Spoon Travel Case', 'Wee Baby', 'Bebe', '16.500', 9, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/11305/2cdf760a-837e-4ed7-aa44-9059c756f48d_0.webp'),
  seedProduct(139, 'Tommee Tippee Tasse Sport 12M+ 300ml', 'Tommee Tippee', 'Bebe', '19.900', 31, undefined, 'https://iziposapp.fra1.cdn.digitaloceanspaces.com/central/products/3196/fbf826c9-ab24-4aca-b3e1-2ee7149c5f4b_0.webp'),
];

/** Safe indexed read into a non-empty readonly array (`noUncheckedIndexedAccess`). */
function pick<T>(arr: readonly T[], index: number): T {
  const value = arr[((index % arr.length) + arr.length) % arr.length];
  if (value === undefined) {
    throw new Error('pick: index out of bounds on an empty array');
  }
  return value;
}

interface LargeCatalogTemplate {
  name: string;
  brand: string;
  category: string;
  skin?: string;
}

const LARGE_CATALOG_BASE: LargeCatalogTemplate[] = [
  { name: 'Gel Moussant Purifiant', brand: 'La Roche-Posay', category: 'Visage', skin: 'oily' },
  { name: 'Baume Réparateur B5', brand: 'La Roche-Posay', category: 'Visage' },
  { name: 'Eau Thermale Apaisante', brand: 'Avène', category: 'Visage', skin: 'sensitive' },
  { name: 'Crème Hydratante Quotidienne', brand: 'CeraVe', category: 'Visage', skin: 'dry' },
  { name: 'Sérum Vitamine C Éclat', brand: 'Vichy', category: 'Visage', skin: 'normal' },
  { name: 'Fluide Solaire SPF50+', brand: 'La Roche-Posay', category: 'Solaire', skin: 'oily' },
  { name: 'Crème Solaire Minérale', brand: 'Bioderma', category: 'Solaire' },
  { name: 'Lait Après-Soleil', brand: 'Nuxe', category: 'Solaire' },
  { name: 'Lait Corporel Nourrissant', brand: 'Mixa', category: 'Corps', skin: 'dry' },
  { name: 'Huile Sèche Multi-Fonctions', brand: 'Nuxe', category: 'Corps' },
  { name: 'Baume Intensif Corps', brand: 'Bioderma', category: 'Corps', skin: 'sensitive' },
  { name: 'Shampooing Doux', brand: 'Klorane', category: 'Cheveux' },
  { name: 'Shampooing Anti-Pelliculaire', brand: 'Vichy', category: 'Cheveux' },
  { name: 'Shampooing Rééquilibrant', brand: 'Ducray', category: 'Cheveux' },
  { name: 'Liniment Oléo-Calcaire', brand: 'Gilbert', category: 'Bebe' },
  { name: 'Crème Change', brand: 'Mustela', category: 'Bebe' },
  { name: 'Magnésium Marin Fatigue', brand: 'Nutergia', category: 'Complements' },
  { name: 'Vitamine D3 Gouttes', brand: 'ZymaD', category: 'Complements' },
  { name: 'Oméga 3 EPA DHA', brand: 'Arkopharma', category: 'Complements' },
  { name: 'Gel Hydroalcoolique', brand: 'Aniosgel', category: 'Hygiene' },
  { name: 'Bain de Bouche', brand: 'Elmex', category: 'Hygiene' },
  { name: 'Dentifrice Protection', brand: 'Sensodyne', category: 'Hygiene' },
  { name: 'Fluide Sensitive', brand: 'La Roche-Posay', category: 'Visage', skin: 'sensitive' },
  { name: 'Aqua-Gel Hydratant', brand: 'Avène', category: 'Visage', skin: 'combination' },
  { name: 'Crème Compensatrice', brand: 'Bioderma', category: 'Visage', skin: 'oily' },
  { name: 'Crème Riche Nutritive', brand: 'La Roche-Posay', category: 'Visage', skin: 'dry' },
  { name: 'Brume Solaire Invisible', brand: 'Vichy', category: 'Solaire' },
  { name: 'Cold Cream Corps', brand: 'Avène', category: 'Corps', skin: 'dry' },
  { name: 'Sérum Anti-Âge', brand: 'Filorga', category: 'Visage', skin: 'normal' },
  { name: 'Baume à Lèvres Nourrissant', brand: 'Nuxe', category: 'Visage' },
];

const CONTENANCE_LABELS = ['50ml', '100ml', '200ml', 'Format familial'] as const;

const PRICE_POOL = [
  '9.500', '11.000', '13.500', '14.900', '16.900', '18.000', '19.900', '21.000',
  '24.500', '26.000', '28.500', '31.000', '33.000', '36.000', '38.500', '42.000',
  '45.500', '48.900', '52.000', '58.000', '65.000', '72.000', '89.000',
] as const;

/**
 * Synthetic 120-row parapharmacy catalog for the Tableau fixture.
 * `ProductTable` self-virtualizes, so a large row count here actually
 * exercises windowed rendering + scroll for Task 20 — the Vitrine/Liste
 * fixtures above render their leaf components directly (non-virtualized),
 * so they stay at a moderate, real-image-backed size instead. Prices are
 * literal decimal STRINGS drawn from a fixed pool by index — never computed
 * via float/parseFloat arithmetic (precision contract).
 */
function buildLargeCatalog(targetCount: number): POSProduct[] {
  const out: POSProduct[] = [];
  for (let i = 0; i < targetCount; i++) {
    const base = pick(LARGE_CATALOG_BASE, i);
    const variantIdx = Math.floor(i / LARGE_CATALOG_BASE.length) % CONTENANCE_LABELS.length;
    const contenance = pick(CONTENANCE_LABELS, variantIdx);
    const price = pick(PRICE_POOL, i * 3 + variantIdx);
    // Deterministic stock spread: some out-of-stock, some low, rest healthy.
    const stockRoll = (i * 7 + 5) % 20;
    const stock = stockRoll === 0 ? 0 : stockRoll <= 4 ? stockRoll : 8 + stockRoll;
    out.push(seedProduct(1000 + i, `${base.name} ${contenance}`, base.brand, base.category, price, stock, base.skin));
  }
  return out;
}

const LARGE_CATALOG: POSProduct[] = buildLargeCatalog(120);

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
  // Task 19 — tri-density fixtures (Vitrine/Liste/Tableau) share one cart
  // state; the three fixtures render disjoint id ranges (seed-100.. / seed-1000..)
  // so there's no cross-fixture collision. Owner polish 2026-07-09 (sub-task
  // a): now a per-product COUNT map (tap adds 1) so the in-cart count chip is
  // exercised; seed-102 starts in-cart at qty 2 for screenshot verification.
  const [densityCart, setDensityCart] = useState<Record<string, number>>({ 'seed-102': 2 });
  const addDensityCart = (p: POSProduct) =>
    setDensityCart((prev) => ({ ...prev, [p.id]: (prev[p.id] ?? 0) + 1 }));
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

        <Section title="Actions rapides du panier (icon-forward, responsive @container)">
          <p className="mb-3 text-sm text-ink-muted">
            Owner feedback fix: at the real narrow cart width the three labels
            ("Remise" / "Suspendre" / "Rappeler") hard-truncated to unreadable
            fragments. Each button is now icon-forward — icon always visible,
            label shown only once the button itself (a CSS container-query
            context) has room, collapsing to icon-only when narrow. The full
            label is always on `title` / `aria-label`.
          </p>
          <div className="flex flex-wrap items-start gap-6">
            <div>
              <span className="mb-1 block text-xs text-ink-muted">
                Étroit (~300px) — icon-only attendu
              </span>
              <div
                data-testid="quickactions-narrow"
                className="rounded-panel border border-border-subtle bg-surface-canvas p-2"
                style={{ width: 300 }}
              >
                <QuickActions
                  onDiscount={() => undefined}
                  onHold={() => undefined}
                  onRecall={() => undefined}
                  hasItems
                  recallCount={3}
                />
              </div>
            </div>
            <div>
              <span className="mb-1 block text-xs text-ink-muted">
                Large (~640px) — icône + libellé attendus
              </span>
              <div
                data-testid="quickactions-wide"
                className="rounded-panel border border-border-subtle bg-surface-canvas p-2"
                style={{ width: 640 }}
              >
                <QuickActions
                  onDiscount={() => undefined}
                  onHold={() => undefined}
                  onRecall={() => undefined}
                  hasItems
                  recallCount={3}
                />
              </div>
            </div>
          </div>
        </Section>

        <Section title="Pied de paiement (cash dominant + « Autres paiements » compact)">
          <p className="mb-3 text-sm text-ink-muted">
            Owner feedback fix: à largeur réelle du panier (~300-360px), les
            deux boutons quasi égaux tronquaient « Autres paiements » en
            « Autres paieme… ». L'espèces (action dominante) garde icône +
            libellé complet et possède la ligne; « Autres paiements » devient
            un contrôle compact icône-seule 64×64 (nom accessible via
            aria-label/title) qui ne peut jamais tronquer.
          </p>
          <div className="flex flex-wrap items-start gap-6">
            <div>
              <span className="mb-1 block text-xs text-ink-muted">
                Étroit (~300px)
              </span>
              <div
                data-testid="payment-footer-preview-narrow"
                className="rounded-panel bg-surface-canvas p-2"
                style={{ width: 300 }}
              >
                <PaymentSummary
                  subtotal={57.844}
                  grossSubtotal={57.844}
                  taxAmount={9.235}
                  discountAmount={0}
                  total={57.844}
                  hasDiscount={false}
                  onPayCash={() => undefined}
                  onAdvancedPayments={() => undefined}
                  paymentMethods={PREVIEW_PAYMENT_METHODS}
                  paymentRepositories={PREVIEW_PAYMENT_REPOSITORIES}
                />
              </div>
            </div>
            <div>
              <span className="mb-1 block text-xs text-ink-muted">
                Large (~400px)
              </span>
              <div
                data-testid="payment-footer-preview-wide"
                className="rounded-panel bg-surface-canvas p-2"
                style={{ width: 400 }}
              >
                <PaymentSummary
                  subtotal={57.844}
                  grossSubtotal={57.844}
                  taxAmount={9.235}
                  discountAmount={0}
                  total={57.844}
                  hasDiscount={false}
                  onPayCash={() => undefined}
                  onAdvancedPayments={() => undefined}
                  paymentMethods={PREVIEW_PAYMENT_METHODS}
                  paymentRepositories={PREVIEW_PAYMENT_REPOSITORIES}
                />
              </div>
            </div>
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
                cartQuantities={Object.fromEntries(gridCart.map((id) => [id, 1]))}
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

        <div className="lg:col-span-2">
          <Section title="Densités d'affichage — Vitrine / Liste / Tableau (Task 19 — composants réels, données figées)">
            <p className="mb-3 text-sm text-ink-muted">
              Trois composants réels rendus directement (pas via ProductGrid, dont le mode
              d&apos;affichage est un état global partagé) : Vitrine et Liste avec 40 vraies photos
              produit (CDN), Tableau avec un catalogue synthétique de 120 lignes pour vérifier la
              virtualisation / le défilement.
            </p>

            <h3 className="mb-2 font-display text-sm font-bold text-ink-strong">
              Vitrine — ProductCard ({REAL_IMAGE_PRODUCTS.length} produits, images réelles)
            </h3>
            <div
              data-testid="sell-preview-vitrine"
              className="mb-6 grid h-[520px] grid-cols-2 gap-2.5 overflow-y-auto rounded-panel border border-border-subtle bg-surface-canvas p-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
            >
              {REAL_IMAGE_PRODUCTS.map((p) => (
                <ProductCard
                  key={p.id}
                  product={p}
                  displayMode="visual"
                  isInCart={(densityCart[p.id] ?? 0) > 0}
                  cartQuantity={densityCart[p.id]}
                  onAddToCart={addDensityCart}
                  onViewDetails={setDetailProduct}
                />
              ))}
            </div>

            <h3 className="mb-2 font-display text-sm font-bold text-ink-strong">
              Liste — ProductListRow ({REAL_IMAGE_PRODUCTS.length} produits, images réelles)
            </h3>
            <div
              data-testid="sell-preview-liste"
              className="mb-6 flex h-[520px] flex-col overflow-y-auto rounded-panel border border-border-subtle bg-surface-canvas"
            >
              {REAL_IMAGE_PRODUCTS.map((p) => (
                <ProductListRow
                  key={p.id}
                  product={p}
                  onAddToCart={addDensityCart}
                  onViewDetails={setDetailProduct}
                />
              ))}
            </div>

            <h3 className="mb-2 font-display text-sm font-bold text-ink-strong">
              Tableau — ProductTable ({LARGE_CATALOG.length} produits, virtualisation)
            </h3>
            <div
              data-testid="sell-preview-tableau"
              className="h-[520px] overflow-hidden rounded-panel border border-border-subtle"
            >
              <ProductTable
                products={LARGE_CATALOG}
                onAddToCart={addDensityCart}
                onViewDetails={setDetailProduct}
              />
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
