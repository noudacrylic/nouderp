{{--
    Header dokumen gaya Accurate.
    Kiri  : logo + nama bisnis + alamat + kontak
    Kanan : judul dokumen besar + Nomor : ... + Tanggal : ... (rata kiri)

    Param:
      $profile     - BusinessProfile (opsional, fallback ke instance())
      $docTitle    - judul besar, contoh: "Pesanan Penjualan"
      $docNumber   - nomor dokumen
      $docDate     - tanggal (Carbon|string)
      $extraMeta   - array assoc tambahan, contoh: ['No. P.O' => '...']
--}}
@php
    $profile   = $profile ?? \App\Models\BusinessProfile::instance();
    $docTitle  = $docTitle  ?? 'Dokumen';
    $docNumber = $docNumber ?? '-';

    $bulan = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    if (!empty($docDate)) {
        $d = $docDate instanceof \Carbon\Carbon ? $docDate : \Carbon\Carbon::parse($docDate);
        $docDateFmt = $d->day . ' ' . $bulan[(int) $d->month] . ' ' . $d->year;
    } else {
        $docDateFmt = '-';
    }

    $cityLine = trim(($profile->city ? 'Kota ' . $profile->city : '') . ($profile->postal_code ? ' ' . $profile->postal_code : ''));
    $contacts = array_filter([
        $profile->phone    ? 'Telp. ' . $profile->phone : null,
        $profile->whatsapp ? 'WA '    . $profile->whatsapp : null,
        $profile->email    ?: null,
    ]);
    $extraMeta = $extraMeta ?? [];
@endphp

<table style="width:100%; border-collapse:collapse; margin: 0;">
    <tr>
        <td style="vertical-align: top; padding-right: 16px; width: 60%;">
            <table style="border-collapse: collapse;">
                <tr>
                    @if(!empty($profile->logo_data_uri))
                        <td style="width: 86px; vertical-align: middle; padding-right: 12px;">
                            <img src="{{ $profile->logo_data_uri }}" alt="Logo"
                                 style="width: 74px; height: 74px; object-fit: contain; display: block;">
                        </td>
                    @endif
                    <td style="vertical-align: middle;">
                        <div style="font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1.2;">
                            {{ $profile->name ?: '—' }}
                        </div>
                        <div style="font-size: 12.5px; color: #0f172a; line-height: 1.55; margin-top: 5px;">
                            {{ $profile->address ?: '' }}
                            @if($cityLine !== '')<br>{{ $cityLine }}@endif
                            @if(count($contacts))<br>{{ implode(' · ', $contacts) }}@endif
                        </div>
                    </td>
                </tr>
            </table>
        </td>
        <td style="vertical-align: top; text-align: right; width: 40%;">
            <div style="display: inline-block; text-align: left;">
                <div style="font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.1; margin-bottom: 10px;">
                    {{ $docTitle }}
                </div>
                <table style="border-collapse: collapse; text-align: left;">
                <tr>
                    <td style="font-size: 12px; color: #475569; padding: 1px 8px 1px 0; vertical-align: top; white-space: nowrap;">Nomor</td>
                    <td style="color: #475569; padding: 1px 6px 1px 0; vertical-align: top;">:</td>
                    <td style="font-size: 13px; color: #0f172a; padding: 1px 0;">{{ $docNumber }}</td>
                </tr>
                <tr>
                    <td style="font-size: 12px; color: #475569; padding: 1px 8px 1px 0; vertical-align: top; white-space: nowrap;">Tanggal</td>
                    <td style="color: #475569; padding: 1px 6px 1px 0; vertical-align: top;">:</td>
                    <td style="font-size: 13px; color: #0f172a; padding: 1px 0;">{{ $docDateFmt }}</td>
                </tr>
                @foreach($extraMeta as $label => $value)
                    @if(!empty($value))
                        <tr>
                            <td style="font-size: 12px; color: #475569; padding: 1px 8px 1px 0; vertical-align: top; white-space: nowrap;">{{ $label }}</td>
                            <td style="color: #475569; padding: 1px 6px 1px 0; vertical-align: top;">:</td>
                            <td style="font-size: 13px; color: #0f172a; padding: 1px 0;">{{ $value }}</td>
                        </tr>
                    @endif
                @endforeach
                </table>
            </div>
        </td>
    </tr>
</table>
<div style="border-bottom: 1px solid #cbd5e1; margin: 10px 0 14px 0;"></div>
