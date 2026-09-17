import type {} from '../../../../packages/shared/types/generated';
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

type CachedCategoryData = Pick<CategoryData,
  'id' | 'company_id' | 'name' | 'slug' | 'path' | 'depth' | 'breadcrumb' | 'default_tax_configuration_id'
>;

function isCachedCategoryData(value: unknown): value is CachedCategoryData {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
  const category = value as Record<string, unknown>;
  return typeof category.id === 'number'
    && typeof category.company_id === 'string'
    && typeof category.name === 'string'
    && typeof category.slug === 'string'
    && typeof category.path === 'string'
    && typeof category.depth === 'number'
    && (category.breadcrumb === null || Array.isArray(category.breadcrumb))
    && (category.default_tax_configuration_id === null || typeof category.default_tax_configuration_id === 'string');
}

/** Recover old SQLx JSON bindings before incremental sync replaces those rows. */
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
