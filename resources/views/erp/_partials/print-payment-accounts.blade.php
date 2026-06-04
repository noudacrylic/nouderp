{{--
    Tampilan rekening pembayaran (kartu bank) — mendukung multi-bank.

    Sumber data (urutan prioritas):
      1. $banks - array assoc [['bank_name'=>..., 'account_number'=>..., 'holder'=>...], ...]
      2. $profile->bankAccounts (relasi BankAccount[])
      3. Legacy single-bank di $profile->bank_name / bank_account_number / bank_account_holder
--}}
@php
    $banks = $banks ?? [];

    if (empty($banks) && isset($profile)) {
        if (!$profile->relationLoaded('bankAccounts')) {
            $profile->load('bankAccounts');
        }
        foreach ($profile->bankAccounts as $row) {
            if (empty($row->bank_name) || empty($row->account_number)) continue;
            $banks[] = [
                'bank_name'      => $row->bank_name,
                'account_number' => $row->account_number,
                'holder'         => $row->holder,
            ];
        }
        if (empty($banks) && !empty($profile->bank_name) && !empty($profile->bank_account_number)) {
            $banks[] = [
                'bank_name'      => $profile->bank_name,
                'account_number' => $profile->bank_account_number,
                'holder'         => $profile->bank_account_holder,
            ];
        }
    }

    $bankColor = function (string $name): string {
        $key = strtoupper(preg_replace('/\s+/', '', $name));
        return match (true) {
            str_contains($key, 'BCA')     => '#0061a8',
            str_contains($key, 'BRI')     => '#003d79',
            str_contains($key, 'BNI')     => '#f37021',
            str_contains($key, 'MANDIRI') => '#003d79',
            str_contains($key, 'BSI')     => '#00a39d',
            str_contains($key, 'CIMB')    => '#7a0019',
            str_contains($key, 'PERMATA') => '#00833f',
            str_contains($key, 'DANAMON') => '#f47920',
            str_contains($key, 'BTN')     => '#005baa',
            default                       => '#1e293b',
        };
    };
@endphp

@if(count($banks))
<style>
    .pay-accounts { margin-top: 4px; }
    .pay-accounts .pa-title {
        font-size: 11px; font-weight: 700;
        color: #0f172a;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .pay-accounts .pa-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 8px;
    }
    .pay-accounts .bank-card {
        display: flex;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        overflow: hidden;
        background: #fff;
    }
    .pay-accounts .bank-strip { width: 5px; flex-shrink: 0; }
    .pay-accounts .bank-body { padding: 8px 12px; flex: 1; min-width: 0; }
    .pay-accounts .bank-name {
        font-size: 11px; font-weight: 800;
        color: #0f172a;
        letter-spacing: 0.6px;
    }
    .pay-accounts .bank-acc {
        font-family: 'Courier New', monospace;
        font-size: 14px; font-weight: 700;
        color: #0f172a;
        margin-top: 2px;
        letter-spacing: 0.5px;
        word-break: break-all;
    }
    .pay-accounts .bank-holder {
        font-size: 11px; color: #64748b;
        margin-top: 1px;
    }
</style>
<div class="pay-accounts">
    <div class="pa-title">Rekening Pembayaran</div>
    <div class="pa-grid">
        @foreach($banks as $b)
            <div class="bank-card">
                <div class="bank-strip" style="background: {{ $bankColor($b['bank_name'] ?? '') }};"></div>
                <div class="bank-body">
                    <div class="bank-name">{{ strtoupper($b['bank_name'] ?? '-') }}</div>
                    <div class="bank-acc">{{ $b['account_number'] ?? '-' }}</div>
                    @if(!empty($b['holder']))
                        <div class="bank-holder">a.n. {{ $b['holder'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif
