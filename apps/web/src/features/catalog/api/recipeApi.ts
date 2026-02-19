import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type {
  RecipeData,
  RecipeLineData,
  RecipeCostData,
  CreateRecipeData,
  CreateRecipeLineData,
  CompositeItemVariantData,
  CreateVariantData,
} from '../types/compositeItem'

// Recipes
export async function getRecipes(compositeItemId: string): Promise<RecipeData[]> {
  const response = await apiGet(`/composite-items/${compositeItemId}/recipes`)
  return response as unknown as RecipeData[]
}

export async function getRecipe(id: string): Promise<RecipeData> {
  return apiGet(`/recipes/${id}`)
}

export async function createRecipe(compositeItemId: string, data: CreateRecipeData): Promise<RecipeData> {
  return apiPost(`/composite-items/${compositeItemId}/recipes`, data)
}

export async function updateRecipe(id: string, data: Partial<CreateRecipeData>): Promise<RecipeData> {
  return apiPatch(`/recipes/${id}`, data)
}

export async function activateRecipe(id: string): Promise<RecipeData> {
  return apiPost(`/recipes/${id}/activate`)
}

export async function calculateRecipeCost(id: string): Promise<RecipeCostData> {
  return apiPost(`/recipes/${id}/calculate-cost`)
}

// Recipe Lines
export async function createRecipeLine(recipeId: string, data: CreateRecipeLineData): Promise<RecipeLineData> {
  return apiPost(`/recipes/${recipeId}/lines`, data)
}

export async function updateRecipeLine(
  recipeId: string,
  lineId: string,
  data: Partial<CreateRecipeLineData>
): Promise<RecipeLineData> {
  return apiPatch(`/recipes/${recipeId}/lines/${lineId}`, data)
}

export async function deleteRecipeLine(recipeId: string, lineId: string): Promise<void> {
  return apiDelete(`/recipes/${recipeId}/lines/${lineId}`)
}

// Variants
export async function getVariants(compositeItemId: string): Promise<CompositeItemVariantData[]> {
  const response = await apiGet(`/composite-items/${compositeItemId}/variants`)
  return response as unknown as CompositeItemVariantData[]
}

export async function createVariant(compositeItemId: string, data: CreateVariantData): Promise<CompositeItemVariantData> {
  return apiPost(`/composite-items/${compositeItemId}/variants`, data)
}

export async function updateVariant(id: string, data: Partial<CreateVariantData>): Promise<CompositeItemVariantData> {
  return apiPatch(`/variants/${id}`, data)
}

export async function deleteVariant(id: string): Promise<void> {
  return apiDelete(`/variants/${id}`)
}
