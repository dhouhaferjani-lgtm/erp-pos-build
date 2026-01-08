<?php

declare(strict_types=1);

namespace App\Shared\Domain\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\App;

trait HasTranslations
{
    /**
     * Get translation for current locale.
     */
    public function translate(?string $locale = null): ?object
    {
        $locale = $locale ?? App::getLocale();

        return $this->translations()
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Get translated attribute or fallback to English.
     */
    public function getTranslatedAttribute(string $attribute, ?string $locale = null): ?string
    {
        $translation = $this->translate($locale);

        if ($translation && isset($translation->$attribute)) {
            return $translation->$attribute;
        }

        // Fallback to English
        if ($locale !== 'en') {
            $translation = $this->translate('en');

            return $translation?->$attribute;
        }

        return null;
    }

    /**
     * Accessor for 'name' attribute (auto-translated).
     */
    public function getNameAttribute(): ?string
    {
        return $this->getTranslatedAttribute('name');
    }

    /**
     * Define translations relationship (to be implemented in each model).
     */
    abstract public function translations(): HasMany;
}
