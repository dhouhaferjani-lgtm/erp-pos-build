export function productCategory(
  overrides: Partial<App.Modules.Product.Application.DTOs.CategoryData> = {},
): App.Modules.Product.Application.DTOs.CategoryData {
  return {
    id: 12,
    company_id: '11111111-1111-4111-8111-111111111111',
    parent_id: null,
    name: 'Soins visage',
    slug: 'soins-visage',
    description: null,
    image_url: null,
    path: '12',
    depth: 0,
    sort_order: 0,
    is_active: true,
    products_count: null,
    breadcrumb: [{ id: 12, name: 'Soins visage', slug: 'soins-visage' }],
    children: null,
    default_tax_rate: null,
    default_tax_configuration_id: null,
    max_discount_percent: null,
    ...overrides,
  };
}
