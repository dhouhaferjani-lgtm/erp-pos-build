<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Support;

/**
 * Hard caps for a single variant-label print request.
 */
final class LabelLimits
{
    /** Maximum Σ(quantity) of labels a single prepare/print request may produce. */
    public const MAX_LABELS_PER_REQUEST = 1000;

    /** Maximum number of distinct line items a single request may carry. */
    public const MAX_LABEL_ITEMS = 500;
}
