# Receipt Printing Integration Guide

## Overview

The receipt printing system has been fully implemented and is ready for integration into the POS checkout flow.

## Components Created

### Backend
1. **ReceiptPdfService** - Generates thermal-style PDF receipts
   - Location: `apps/api/app/Modules/POS/Application/Services/ReceiptPdfService.php`
   - Optimized for 80mm thermal printer width
   - Includes all fiscal hash and chain information

2. **Receipt PDF Template** - Blade template for receipt rendering
   - Location: `apps/api/resources/views/pos/receipt.blade.php`
   - Clean, thermal-friendly layout
   - Includes:
     - Company info and logo
     - Receipt details (number, date, terminal, cashier)
     - Line items with prices
     - VAT breakdown
     - Payment methods
     - **CRITICAL:** Fiscal hash and chain sequence

3. **ReceiptController** - API endpoints for receipt operations
   - Location: `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php`
   - Endpoints:
     - `GET /api/v1/pos/receipts/{id}` - Get receipt details
     - `GET /api/v1/pos/receipts/{id}/pdf` - Stream PDF for printing
     - `GET /api/v1/pos/receipts/{id}/pdf/download` - Download PDF

4. **Company Receipt Settings** - Database fields for receipt configuration
   - Migration: `database/migrations/2026_01_10_063904_add_receipt_settings_to_companies_table.php`
   - Fields:
     - `auto_print_receipts` (boolean) - Auto-trigger print after checkout
     - `receipt_logo` (string) - Path to receipt-specific logo
     - `receipt_footer` (text) - Custom footer text

### Frontend
1. **receiptApi** - API client for receipt operations
   - Location: `apps/web/src/features/pos/api/receiptApi.ts`
   - Functions:
     - `printReceipt(receiptId)` - Returns PDF blob
     - `downloadReceipt(receiptId)` - Triggers download

2. **useReceiptPrint** - Hook for printing logic
   - Location: `apps/web/src/features/pos/hooks/useReceiptPrint.ts`
   - Handles:
     - PDF blob fetching
     - Browser print dialog
     - Popup blocker fallback
     - Toast notifications

3. **ReceiptPrintButton** - UI component
   - Location: `apps/web/src/features/pos/components/ReceiptPrintButton.tsx`
   - Features:
     - Print button with loading state
     - Optional download button
     - Auto-print support
     - Callback on print completion

4. **CheckoutSuccessDialog** - Example integration
   - Location: `apps/web/src/features/pos/components/CheckoutSuccessDialog.tsx`
   - Shows how to use ReceiptPrintButton after checkout

## Integration Steps

### 1. After Checkout Completion

When a receipt is successfully created (after calling the checkout API), you'll receive a receipt ID. Use it like this:

```typescript
// In your checkout success handler
const handleCheckoutSuccess = (response: { receiptId: string, receiptNumber: string, total: string }) => {
  // Show success dialog with print button
  setCheckoutResult({
    receiptId: response.receiptId,
    receiptNumber: response.receiptNumber,
    total: response.total,
  })
  setShowSuccessDialog(true)
}

// In your component render
<CheckoutSuccessDialog
  isOpen={showSuccessDialog}
  onClose={() => setShowSuccessDialog(false)}
  receiptId={checkoutResult?.receiptId}
  receiptNumber={checkoutResult?.receiptNumber}
  total={checkoutResult?.total}
  autoPrint={company.auto_print_receipts} // From company settings
/>
```

### 2. Using ReceiptPrintButton Directly

If you have your own success UI:

```typescript
import { ReceiptPrintButton } from '@/features/pos/components/ReceiptPrintButton'

// In your component
<ReceiptPrintButton
  receiptId={receiptId}
  showDownload={true}
  autoPrint={company.auto_print_receipts}
  onPrintComplete={() => {
    console.log('Receipt printed')
    // Optional: Track analytics, close dialog, etc.
  }}
/>
```

### 3. Using the Hook Directly

For custom implementations:

```typescript
import { useReceiptPrint } from '@/features/pos/hooks/useReceiptPrint'

const MyComponent = () => {
  const { printReceipt, downloadReceipt, isPrinting } = useReceiptPrint()

  const handlePrint = async () => {
    await printReceipt(receiptId)
    // Print dialog will open automatically
  }

  return (
    <button onClick={handlePrint} disabled={isPrinting}>
      {isPrinting ? 'Printing...' : 'Print Receipt'}
    </button>
  )
}
```

## Company Settings Integration

Allow users to configure auto-print behavior in settings:

```typescript
// In company settings form
<Checkbox
  checked={formData.auto_print_receipts}
  onCheckedChange={(checked) =>
    setFormData({ ...formData, auto_print_receipts: checked })
  }
>
  {t('pos:receipt.autoPrint')}
</Checkbox>

<Input
  label="Receipt Footer Text"
  value={formData.receipt_footer}
  onChange={(e) =>
    setFormData({ ...formData, receipt_footer: e.target.value })
  }
  multiline
  rows={3}
/>
```

## Translations

All user-facing text is translated. Available keys:

**English (`pos.json`):**
- `receipt.print` - "Print Receipt"
- `receipt.download` - "Download Receipt"
- `receipt.printing` - "Printing..."
- `receipt.printSuccess` - "Receipt ready for printing"
- `receipt.printError` - "Failed to print receipt"
- `receipt.downloadSuccess` - "Receipt downloaded successfully"
- `receipt.downloadError` - "Failed to download receipt"
- `receipt.popupBlocked` - "Popup blocked - downloading instead"
- `receipt.autoPrint` - "Auto-print receipts"

**French translations are complete.**

## Testing Checklist

- [ ] PDF generation works with fiscal hash visible
- [ ] Print dialog opens in browser
- [ ] Download fallback works when popup blocked
- [ ] Auto-print respects company setting
- [ ] All receipt data is accurate (totals, taxes, payments)
- [ ] Fiscal hash and chain sequence are displayed
- [ ] Multiple payment methods display correctly
- [ ] VAT breakdown is accurate
- [ ] Receipt logo displays (if configured)
- [ ] Custom footer text displays (if configured)

## Browser Compatibility

The system works in all modern browsers:
- Chrome/Edge: Full support
- Firefox: Full support
- Safari: Full support (may block popups by default)
- Mobile browsers: Downloads PDF instead of opening print dialog

## Future Enhancements (Phase 2)

- Direct thermal printer integration (ESC/POS)
- Receipt reprinting from transaction history
- Email/SMS receipt delivery
- Custom receipt templates per location
- Receipt preview before printing

## Troubleshooting

**Issue: Popup blocked**
- System automatically falls back to download
- User sees toast: "Popup blocked - downloading instead"

**Issue: PDF not generating**
- Check backend logs for errors
- Verify receipt exists in database
- Ensure company_id matches

**Issue: Missing fiscal hash**
- Verify receipt was created properly through POS flow
- Check that ReceiptHashService was called during creation

## Notes

- PDFs are generated on-demand (not stored)
- Print requests require authentication
- Receipt data is immutable (compliance requirement)
- Fiscal hash MUST be visible on all receipts
