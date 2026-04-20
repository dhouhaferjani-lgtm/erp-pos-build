<?php

declare(strict_types=1);

namespace App\Modules\Contact\Domain;

use App\Modules\Partner\Domain\Partner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $party_id
 * @property string $contact_id
 * @property string|null $job_title
 * @property string|null $department
 * @property bool $is_primary
 * @property bool $is_invoice_contact
 * @property bool $is_delivery_contact
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Partner $party
 * @property-read Contact $contact
 */
class PartyContact extends Model
{
    use HasUuids;

    protected $table = 'party_contacts';

    protected $fillable = [
        'party_id',
        'contact_id',
        'job_title',
        'department',
        'is_primary',
        'is_invoice_contact',
        'is_delivery_contact',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_invoice_contact' => 'boolean',
            'is_delivery_contact' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'party_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
