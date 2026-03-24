<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use PHPUnit\Framework\TestCase;

class JournalEntryEntityTest extends TestCase
{
    public function test_journal_entry_class_exists(): void
    {
        $this->assertTrue(class_exists(JournalEntry::class));
    }

    public function test_journal_line_class_exists(): void
    {
        $this->assertTrue(class_exists(JournalLine::class));
    }

    public function test_journal_entry_status_enum_exists(): void
    {
        $this->assertTrue(enum_exists(JournalEntryStatus::class));
    }

    public function test_journal_entry_status_has_required_cases(): void
    {
        $cases = JournalEntryStatus::cases();
        $caseValues = array_map(fn ($case) => $case->value, $cases);

        $this->assertContains('draft', $caseValues);
        $this->assertContains('posted', $caseValues);
        $this->assertContains('reversed', $caseValues);
    }

    public function test_journal_entry_has_required_properties(): void
    {
        $entry = new JournalEntry;
        $fillable = $entry->getFillable();

        $this->assertContains('entry_number', $fillable);
        $this->assertContains('entry_date', $fillable);
        $this->assertContains('description', $fillable);
        $this->assertContains('status', $fillable);
    }

    public function test_journal_line_has_required_properties(): void
    {
        $line = new JournalLine;
        $fillable = $line->getFillable();

        $this->assertContains('account_id', $fillable);
        $this->assertContains('debit', $fillable);
        $this->assertContains('credit', $fillable);
        $this->assertContains('description', $fillable);
    }

    public function test_journal_entry_has_lines_relationship(): void
    {
        $this->assertTrue(method_exists(JournalEntry::class, 'lines'));
    }

    public function test_journal_entry_has_hash_field(): void
    {
        $entry = new JournalEntry;
        $fillable = $entry->getFillable();

        $this->assertContains('fiscal_hash', $fillable);
        $this->assertContains('previous_hash', $fillable);
    }
}
