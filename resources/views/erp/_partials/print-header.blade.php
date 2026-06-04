{{--
    Reusable kop surat untuk dokumen print (Quotation, Invoice, PO, Delivery, Payment).
    Style: gaya Accurate — logo kiri, info perusahaan teks polos.
    Param: $profile (App\Models\BusinessProfile). Kalau tidak di-pass, ambil instance().
--}}
@php
    $profile = $profile ?? \App\Models\BusinessProfile::instance();
    $hasLogo = !empty($profile->logo_data_uri);

    $contacts = array_filter([
        $profile->phone ? 'Telp. ' . $profile->phone : null,
        $profile->whatsapp ? 'WA ' . $profile->whatsapp : null,
        $profile->email ?: null,
    ]);

    $cityLine = trim(
        ($profile->city ? 'Kota ' . $profile->city : '')
        . ($profile->postal_code ? ' ' . $profile->postal_code : '')
    );
@endphp
<table style="width:100%; border-collapse:collapse; margin:0 0 6px 0;">
    <tr>
        @if($hasLogo)
            <td style="width:90px; vertical-align:middle; padding:0 14px 0 0;">
                <img src="{{ $profile->logo_data_uri }}" alt="Logo" style="max-width:80px; max-height:80px; display:block;">
            </td>
        @endif
        <td style="vertical-align:middle; text-align:left;">
            <div style="font-size:20px; font-weight:800; color:#0f172a; line-height:1.2;">
                {{ $profile->name ?: 'Nama Bisnis' }}
            </div>
            <div style="font-size:11px; color:#475569; margin-top:4px; line-height:1.5;">
                {{ $profile->address ?: '—' }}
                @if($cityLine !== '')<br>{{ $cityLine }}@endif
                @if(count($contacts))<br>{{ implode(' · ', $contacts) }}@endif
            </div>
        </td>
    </tr>
</table>
<div style="border-bottom:1px solid #cbd5e1; margin:6px 0 12px 0;"></div>
