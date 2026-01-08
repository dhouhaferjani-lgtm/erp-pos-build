<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

/**
 * Parapharmacy product categories for health and wellness products.
 */
enum ParapharmacyCategory: string
{
    case Supplement = 'supplement';
    case Cosmetic = 'cosmetic';
    case MedicalDevice = 'medical_device';
    case Herbal = 'herbal';
    case BabyCare = 'baby_care';
    case SportsNutrition = 'sports_nutrition';
    case Other = 'other';

    /**
     * Get human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::Supplement => 'Dietary Supplement',
            self::Cosmetic => 'Cosmetic & Skincare',
            self::MedicalDevice => 'Medical Device',
            self::Herbal => 'Herbal Product',
            self::BabyCare => 'Baby Care',
            self::SportsNutrition => 'Sports Nutrition',
            self::Other => 'Other',
        };
    }

    /**
     * Check if this category typically requires pharmacist consultation.
     */
    public function typicallyRequiresConsultation(): bool
    {
        return match ($this) {
            self::Supplement, self::Herbal, self::MedicalDevice => true,
            default => false,
        };
    }
}
