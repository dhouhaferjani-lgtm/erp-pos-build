import type { POSProduct } from '@/types/product';

type CategoryData = App.Modules.Product.Application.DTOs.CategoryData;

/** `/products` sends CategoryData; the POS catalogue stores its display name. */
export type POSProductPayload = Omit<POSProduct, 'category'> & {
  category?: CategoryData | string | null;
};

export function productCategoryLabel(category: POSProductPayload['category']): string | undefined {
  return typeof category === 'string' ? category : category?.name;
}

export function toPOSProduct(product: POSProductPayload): POSProduct {
  return { ...product, category: productCategoryLabel(product.category) };
}

/**
 * Discriminate a cached CategoryData from an operator-typed label with the
 * creation-era fields only (CategoryData.php, 2025-12-27): newer optional
 * fields come and go, and must not decide whether a legacy row is readable.
 */
function isCachedCategoryData(value: unknown): value is Pick<CategoryData, 'id' | 'name' | 'slug'> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
  const category = value as Record<string, unknown>;
  return typeof category.id === 'number'
    && typeof category.name === 'string'
    && typeof category.slug === 'string';
}

/**
 * Old app builds bound the CategoryData object and SQLx stored it as JSON text.
 * Incremental sync is `updated_since` (syncService.ts pullProductsCore), so such
 * rows are NEVER re-pulled unless the product changes server-side; migration 68
 * rewrites them once, and this decoder covers reads in between plus any row the
 * migration could not parse.
 */
export function cachedProductCategoryLabel(category: string | null): string | undefined {
  if (category === null) return undefined;
  if (!category.trimStart().startsWith('{')) return category;
  try {
    const parsed: unknown = JSON.parse(category);
    return isCachedCategoryData(parsed) ? parsed.name : category;
  } catch {
    // A category name may itself contain braces; only recognize the known DTO.
    return category;
  }
}
