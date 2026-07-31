// ── Riverside Pharmacy · demo data ──────────────────────────────────────────

export type Tone = 'brand' | 'honey' | 'clay' | 'sky' | 'iris' | 'neutral'

export type Product = {
  id: string
  name: string
  detail: string
  kind: 'pill' | 'inhaler' | 'cold' | 'liquid' | 'cream'
  stock: number
  unit: string
  pacePerDay: number
  price: number
  rx: boolean
  supplier: string
  expiry?: { lot: string; days: number; units: number }
  cold?: boolean
  trend?: number // % vs last week
}

export const products: Product[] = [
  {
    id: 'lisinopril',
    name: 'Lisinopril',
    detail: '10mg tablets · 30s',
    kind: 'pill',
    stock: 8,
    unit: 'packs',
    pacePerDay: 4,
    price: 6.5,
    rx: true,
    supplier: 'MedSource',
  },
  {
    id: 'amoxicillin',
    name: 'Amoxicillin',
    detail: '500mg capsules · 20s',
    kind: 'pill',
    stock: 12,
    unit: 'packs',
    pacePerDay: 3,
    price: 9.75,
    rx: true,
    supplier: 'MedSource',
  },
  {
    id: 'sertraline',
    name: 'Sertraline',
    detail: '50mg tablets · 30s',
    kind: 'pill',
    stock: 9,
    unit: 'packs',
    pacePerDay: 2.5,
    price: 7.2,
    rx: true,
    supplier: 'Al Safeer Wholesale',
  },
  {
    id: 'ibuprofen',
    name: 'Ibuprofen',
    detail: '400mg tablets · 24s',
    kind: 'pill',
    stock: 34,
    unit: 'packs',
    pacePerDay: 1.2,
    price: 3.4,
    rx: false,
    supplier: 'Al Safeer Wholesale',
    expiry: { lot: 'K2204', days: 18, units: 34 },
  },
  {
    id: 'coamoxiclav',
    name: 'Co-Amoxiclav',
    detail: '625mg tablets · 14s',
    kind: 'pill',
    stock: 11,
    unit: 'packs',
    pacePerDay: 0.3,
    price: 12.9,
    rx: true,
    supplier: 'MedSource',
    expiry: { lot: 'C8817', days: 24, units: 11 },
  },
  {
    id: 'ventolin',
    name: 'Ventolin',
    detail: 'Salbutamol inhaler 100mcg',
    kind: 'inhaler',
    stock: 46,
    unit: 'inhalers',
    pacePerDay: 1.8,
    price: 11.5,
    rx: true,
    supplier: 'MedSource',
  },
  {
    id: 'insulin',
    name: 'Insulin Glargine',
    detail: '100IU/mL pens · 5s',
    kind: 'cold',
    stock: 14,
    unit: 'boxes',
    pacePerDay: 0.9,
    price: 42.0,
    rx: true,
    supplier: 'ColdChain Direct',
    cold: true,
  },
  {
    id: 'loratadine',
    name: 'Loratadine',
    detail: '10mg tablets · 10s',
    kind: 'pill',
    stock: 58,
    unit: 'packs',
    pacePerDay: 7.5,
    price: 4.1,
    rx: false,
    supplier: 'Al Safeer Wholesale',
    trend: 38,
  },
  {
    id: 'metformin',
    name: 'Metformin',
    detail: '850mg tablets · 60s',
    kind: 'pill',
    stock: 240,
    unit: 'packs',
    pacePerDay: 5,
    price: 5.6,
    rx: true,
    supplier: 'Al Safeer Wholesale',
  },
  {
    id: 'atorvastatin',
    name: 'Atorvastatin',
    detail: '20mg tablets · 30s',
    kind: 'pill',
    stock: 132,
    unit: 'packs',
    pacePerDay: 3.4,
    price: 8.3,
    rx: true,
    supplier: 'MedSource',
  },
  {
    id: 'omeprazole',
    name: 'Omeprazole',
    detail: '20mg capsules · 28s',
    kind: 'pill',
    stock: 96,
    unit: 'packs',
    pacePerDay: 2.2,
    price: 6.9,
    rx: false,
    supplier: 'Al Safeer Wholesale',
  },
  {
    id: 'saline',
    name: 'Saline Nasal Spray',
    detail: '30ml · sterile',
    kind: 'liquid',
    stock: 41,
    unit: 'bottles',
    pacePerDay: 1.4,
    price: 3.9,
    rx: false,
    supplier: 'Al Safeer Wholesale',
  },
]

export const daysLeft = (p: Product) => Math.max(1, Math.round(p.stock / p.pacePerDay))

export const money = (n: number) =>
  n.toLocaleString('en-US', { style: 'currency', currency: 'USD' })

// ── Today ───────────────────────────────────────────────────────────────────

export type BriefingAction = {
  id: string
  tone: Tone
  icon: 'truck' | 'clock' | 'scale'
  title: string
  body: string
  primary: string
  secondary?: string
}

export const briefing: BriefingAction[] = [
  {
    id: 'po-lisinopril',
    tone: 'clay',
    icon: 'truck',
    title: 'Lisinopril 10mg runs out Thursday',
    body: 'You sell about 4 packs a day and 8 are left. I drafted an order with MedSource — 24 packs, $42.60, arrives Thursday before you open.',
    primary: 'Approve order',
    secondary: 'Adjust',
  },
  {
    id: 'md-ibuprofen',
    tone: 'honey',
    icon: 'clock',
    title: '34 packs of Ibuprofen 400mg expire in 18 days',
    body: 'Batch K2204 won’t sell through in time at the current pace. A 30% markdown usually clears it in about a week — you’d keep $81 instead of losing $116.',
    primary: 'Mark down 30%',
    secondary: 'Return instead',
  },
  {
    id: 'rec-till',
    tone: 'sky',
    icon: 'scale',
    title: 'Yesterday’s card total was $18.20 short',
    body: 'I found it — a voided sale at 6:42pm never got matched. Nothing is missing, it just needs a note in the books.',
    primary: 'Add the note',
    secondary: 'Show me',
  },
]

export const feed = [
  {
    time: '6:04',
    text: 'Ordered 40 packs of Amoxicillin 500mg from MedSource — arrives Thursday.',
    tag: 'Purchases',
  },
  {
    time: '6:02',
    text: 'Filed yesterday’s controlled-substances log. Nothing unusual.',
    tag: 'Compliance',
  },
  {
    time: '6:01',
    text: 'Reconciled Tuesday’s card batch — every cent accounted for.',
    tag: 'Books',
  },
  {
    time: 'Yesterday',
    text: 'Booked a $96.40 return credit with Al Safeer Wholesale for the damaged carton.',
    tag: 'Money',
  },
  {
    time: 'Yesterday',
    text: 'Raised the Loratadine reorder point — allergy season lifted sales 38%.',
    tag: 'Stock',
  },
]

export const schedule = [
  { time: '11:00', title: 'MedSource delivery', note: '2 boxes · Amoxicillin + 5 others' },
  { time: '14:30', title: 'Flu-shot clinic', note: 'Dr. Owens · 18 people booked' },
  { time: '17:00', title: 'Weekly reorder review', note: 'I’ll bring 3 suggestions' },
]

// ── Orders ──────────────────────────────────────────────────────────────────

export const incoming = [
  {
    id: 'po-1041',
    supplier: 'MedSource',
    eta: 'Thursday · before 11:00',
    summary: 'Amoxicillin 500mg ×40, Ventolin ×12, + 4 more',
    total: 214.96,
    status: 'On the way',
    steps: ['Ordered', 'Packed', 'Arriving'],
    step: 2,
  },
  {
    id: 'po-1038',
    supplier: 'Al Safeer Wholesale',
    eta: 'Now Friday · was Thursday',
    summary: 'Metformin 850mg ×60, Omeprazole ×24, + 3 more',
    total: 388.2,
    status: 'Delayed a day',
    steps: ['Ordered', 'Packed', 'Arriving'],
    step: 1,
    note: 'I moved 20 packs of Metformin onto the MedSource order so you won’t run short. — Pim',
  },
]

export const pastOrders = [
  { id: 'PO-1036', supplier: 'ColdChain Direct', date: 'Monday', total: 588.0, items: 'Insulin Glargine ×14' },
  { id: 'PO-1033', supplier: 'MedSource', date: 'Last Friday', total: 742.35, items: '12 lines' },
  { id: 'PO-1031', supplier: 'Al Safeer Wholesale', date: 'Last Wednesday', total: 419.1, items: '9 lines' },
]

// ── Money ───────────────────────────────────────────────────────────────────

export const week = [
  { d: 'Mon', v: 1180 },
  { d: 'Tue', v: 1246 },
  { d: 'Wed', v: 1092 },
  { d: 'Thu', v: 1318 },
  { d: 'Fri', v: 1490 },
  { d: 'Sat', v: 1244 },
  { d: 'Today', v: 842 },
]

export const takenCareOf = [
  { title: 'Sales tax for April', note: 'Filed and paid — receipt saved', done: true },
  { title: 'The books', note: 'Every sale matched to the bank, to the cent', done: true },
  { title: 'Payroll for 4 people', note: 'Runs Friday at 9:00 — nothing to do', done: false },
  { title: 'Controlled-substances log', note: 'Up to date, nothing unusual', done: true },
]

// ── Ask ─────────────────────────────────────────────────────────────────────

export const suggestions = [
  'How’s amoxicillin doing?',
  'What should I mark down?',
  'How was this week?',
  'Draft my Tuesday order',
]

export const handled = [
  'Placed PO-1041 with MedSource',
  'Filed the controlled-substances log',
  'Matched 41 card payments to the bank',
]
