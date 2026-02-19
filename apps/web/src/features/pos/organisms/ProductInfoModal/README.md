# Product Info Modal

Touch-optimized modal component for displaying detailed product information in the POS system.

## Features

- **Three Tabs**: Details, Stock Levels, and Parapharmacy Information (conditional)
- **Touch Optimization**: Optional touch-friendly UI with larger tap targets
- **Lazy Loading**: Fetches data only when modal opens, loads stock data only when Stock tab is accessed
- **Parapharmacy Support**: Conditionally displays parapharmacy data (ingredients, key components, health claims, certifications) when available
- **i18n Ready**: All text uses translation keys for multi-language support
- **Design Tokens**: Uses design tokens for consistent styling

## Usage

```tsx
import { ProductInfoModal } from '@/features/pos/organisms/ProductInfoModal'

function MyComponent() {
  const [productId, setProductId] = useState<string | null>(null)

  return (
    <>
      <button onClick={() => setProductId('product-123')}>
        View Product Info
      </button>

      {productId && (
        <ProductInfoModal
          isOpen={!!productId}
          onClose={() => setProductId(null)}
          productId={productId}
          touchOptimized={false}
        />
      )}
    </>
  )
}
```

## Props

| Prop | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `isOpen` | `boolean` | Yes | - | Controls modal visibility |
| `onClose` | `() => void` | Yes | - | Callback when modal should close |
| `productId` | `string` | Yes | - | ID of the product to display |
| `onAddToCart` | `(product: ProductDetailResponse) => void` | No | `undefined` | Optional callback to add product to cart. If provided, shows "Add to Cart" button |
| `touchOptimized` | `boolean` | No | `false` | Enable touch-friendly larger UI elements |

## API Integration

### Product Details Endpoint
```
GET /products/{id}
```

Returns product with optional parapharmacy metadata (automatically loaded when vertical is Parapharmacy).

### Stock Levels Endpoint
```
GET /products/{id}/stock
```

Returns array of stock levels per location.

## Tabs

### Details Tab (Always Shown)
- Product image (or placeholder)
- Name, SKU, Category
- Price (with/without tax)
- Tax rate
- Description
- Optional "Add to Cart" button

### Stock Tab
- Table showing stock per location
- Columns: Location | Available | Reserved | Total
- Color-coded availability:
  - Green: Available >= 10
  - Yellow: Available < 10
  - Red: Out of stock (0)

### Parapharmacy Tab (Conditional)
Only shown if `product.parapharmacy_metadata` exists.

Displays:
- **Ingredients**: List with concentrations
- **Key Components**: With benefits
- **Health Claims**: With regulation references
- **Certifications**: With logos and issuing bodies

All parapharmacy text supports multilingual display based on current language (en, fr, ar).

## Translation Keys

All translation keys are in `locales/{lang}/pos.json` under `productInfo.*`:

```json
{
  "pos": {
    "productInfo": {
      "title": "Product Information",
      "tabs": {
        "details": "Details",
        "stock": "Stock Levels",
        "parapharmacy": "Product Information"
      },
      "fields": {
        "sku": "SKU",
        "category": "Category",
        "price": "Price",
        "taxRate": "VAT Rate",
        "available": "Available",
        "reserved": "Reserved",
        "total": "Total",
        "location": "Location"
      }
    }
  }
}
```

## Testing

Comprehensive test suite with 18 tests covering:
- Modal open/close behavior
- Loading and error states
- Product details display
- Tab switching
- Conditional parapharmacy tab rendering
- Stock levels with color coding
- Touch-optimized styling
- onAddToCart callback

Run tests:
```bash
npm run test -- ProductInfoModal.test.tsx
```

## Design Decisions

1. **Lazy Loading**: Stock data is only fetched when the Stock tab is clicked to minimize API calls
2. **Modal Size**: Uses `xl` size to accommodate detailed information
3. **Conditional Rendering**: Parapharmacy tab only appears for parapharmacy products
4. **Translation Support**: All user-facing text uses i18n for multi-language support
5. **Touch Optimization**: Optional larger touch targets for tablet/touchscreen use

## Backend Dependencies

- `GET /products/{id}` - Product details endpoint (ProductController@show)
- `GET /products/{id}/stock` - Stock levels endpoint
- Parapharmacy metadata loaded automatically for Parapharmacy vertical companies

## Related Components

- `Modal` - Base modal component
- `Tabs` - Tab navigation component
- `ProductCard` - Triggers this modal via info button
- `POSPage` - Parent POS page component
