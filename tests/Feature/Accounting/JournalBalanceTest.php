<?php

namespace Tests\Feature\Accounting;

use App\Core\Accounting\Account;
use App\Core\Journal\Journal;
use App\Core\Journal\JournalPostingService;
use App\Core\Period\AccountingPeriod;
use App\DTO\JournalEntryDTO;
use App\DTO\JournalLineDTO;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lindungi invarian akuntansi inti: jurnal HARUS balance (sum debit == sum credit
 * dlm toleransi). Gate balance pakai abs(diff) > epsilon, bukan float strict !==.
 */
class JournalBalanceTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $svc;
    private Account $kas;
    private Account $modal;

    protected function setUp(): void
    {
        parent::setUp();

        AccountingPeriod::create([
            'year' => 2026, 'month' => 6,
            'start_date' => '2026-06-01', 'end_date' => '2026-06-30',
            'status' => 'open',
        ]);

        $this->kas = Account::create([
            'code' => '1101', 'name' => 'Kas', 'type' => 'asset',
            'normal_balance' => 'debit', 'account_category' => 'cash', 'is_active' => true,
        ]);
        $this->modal = Account::create([
            'code' => '3101', 'name' => 'Modal', 'type' => 'equity',
            'normal_balance' => 'credit', 'account_category' => 'equity', 'is_active' => true,
        ]);

        $this->svc = app(JournalPostingService::class);
    }

    private function dto(float $debit, float $credit, ?int $refId = null): JournalEntryDTO
    {
        return new JournalEntryDTO(
            date: '2026-06-05',
            reference_type: 'test',
            reference_id: $refId,
            description: 'Test journal',
            lines: [
                new JournalLineDTO($this->kas->id, $debit, 0),
                new JournalLineDTO($this->modal->id, 0, $credit),
            ],
        );
    }

    public function test_jurnal_balance_berhasil_posted(): void
    {
        $journal = $this->svc->post($this->dto(100000, 100000, 1));

        $this->assertInstanceOf(Journal::class, $journal);
        $this->assertSame('posted', $journal->status);
        $this->assertEqualsWithDelta(
            100000.0,
            (float) $journal->lines()->sum('debit'),
            0.001
        );
        $this->assertEqualsWithDelta(
            (float) $journal->lines()->sum('debit'),
            (float) $journal->lines()->sum('credit'),
            0.005,
            'jurnal tersimpan harus balance'
        );
    }

    public function test_jurnal_tidak_balance_ditolak(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not balanced');
        $this->svc->post($this->dto(100000, 99000, 2));
    }

    public function test_selisih_dalam_toleransi_tetap_posted(): void
    {
        // diff 0.00002 (< epsilon 0.00005) → harus tetap posted, tidak ditolak.
        $journal = $this->svc->post($this->dto(100000.00002, 100000.0, 3));
        $this->assertSame('posted', $journal->status);
    }
}
