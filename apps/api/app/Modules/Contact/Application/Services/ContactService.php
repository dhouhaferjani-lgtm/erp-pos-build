<?php

declare(strict_types=1);

namespace App\Modules\Contact\Application\Services;

use App\Modules\Contact\Domain\Contact;
use App\Modules\Contact\Domain\PartyContact;
use Illuminate\Support\Facades\DB;

final class ContactService
{
    /**
     * Create a new contact.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Contact
    {
        return Contact::create($data);
    }

    /**
     * Update an existing contact.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        $contact->update($data);

        /** @var Contact $fresh */
        $fresh = $contact->fresh();

        return $fresh;
    }

    /**
     * Link a contact to a party (partner).
     */
    public function linkToParty(
        Contact $contact,
        string $partyId,
        ?string $jobTitle,
        ?string $department,
        bool $isPrimary,
    ): PartyContact {
        return DB::transaction(function () use ($contact, $partyId, $jobTitle, $department, $isPrimary): PartyContact {
            if ($isPrimary) {
                $this->clearPrimaryFlag($partyId);
            }

            return PartyContact::create([
                'party_id' => $partyId,
                'contact_id' => $contact->id,
                'job_title' => $jobTitle,
                'department' => $department,
                'is_primary' => $isPrimary,
            ]);
        });
    }

    /**
     * Unlink a contact from a party (partner).
     */
    public function unlinkFromParty(Contact $contact, string $partyId): void
    {
        PartyContact::where('contact_id', $contact->id)
            ->where('party_id', $partyId)
            ->delete();
    }

    /**
     * Set a contact as the primary contact for a party.
     */
    public function setPrimaryContact(string $partyId, string $contactId): void
    {
        DB::transaction(function () use ($partyId, $contactId): void {
            $this->clearPrimaryFlag($partyId);

            PartyContact::where('party_id', $partyId)
                ->where('contact_id', $contactId)
                ->update(['is_primary' => true]);
        });
    }

    /**
     * Clear the primary flag for all contacts of a given party.
     */
    private function clearPrimaryFlag(string $partyId): void
    {
        PartyContact::where('party_id', $partyId)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
