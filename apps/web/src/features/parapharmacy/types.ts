export interface CertificationTranslationData {
  id: string;
  locale: string;
  name: string;
  description: string | null;
}

/** Response shape from GET /parapharmacy/certifications/:id and list items */
export interface CertificationData {
  id: string;
  type: string;
  slug: string;
  certifying_body: string | null;
  logo_url: string | null;
  verification_url: string | null;
  is_active: boolean;
  display_order: number;
  /** Flattened from the first translation by the backend resource */
  name: string;
  description: string | null;
  translations: CertificationTranslationData[];
}

export interface HealthClaimTranslationData {
  id: string;
  locale: string;
  claim: string;
  disclaimer_text: string | null;
}

/** Response shape from GET /parapharmacy/health-claims/:id and list items */
export interface HealthClaimData {
  id: string;
  claim_type: 'function' | 'reduction_of_disease_risk' | 'development_and_health';
  slug: string;
  regulatory_status: 'approved' | 'pending' | 'rejected';
  efsa_reference: string | null;
  fda_reference: string | null;
  country_restrictions: string[] | null;
  requires_disclaimer: boolean;
  /** Flattened from the first translation by the backend resource */
  claim: string;
  disclaimer_text: string | null;
  translations: HealthClaimTranslationData[];
}

export interface IngredientTranslationData {
  id: string;
  locale: string;
  name: string;
  description: string | null;
}

/** Response shape from GET /parapharmacy/ingredients/:id and list items */
export interface IngredientData {
  id: string;
  slug: string;
  cas_number: string | null;
  is_allergen: boolean;
  allergen_code: string | null;
  regulatory_status: 'approved' | 'restricted' | 'banned';
  notes: string | null;
  /** Flattened from the first translation by the backend resource */
  name: string;
  description: string | null;
  translations: IngredientTranslationData[];
}

export interface KeyComponentTranslationData {
  id: string;
  locale: string;
  name: string;
  description: string | null;
}

/** Response shape from GET /parapharmacy/key-components/:id and list items */
export interface KeyComponentData {
  id: string;
  slug: string;
  is_allergen: boolean;
  /** Flattened from the first translation by the backend resource */
  name: string;
  description: string | null;
  translations: KeyComponentTranslationData[];
}
