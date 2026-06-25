@php
    $karyawan = $karyawan ?? null;
    $isEdit   = isset($karyawan) && $karyawan->exists;

    // Default master schedule Pasal 3: 08:00-16:00 istirahat 11:30-12:30, Minggu libur.
    $defaultMaster = [
        'jam_masuk'                  => '08:00',
        'awal_absen_masuk'           => 90,
        'late_in_minutes'            => 10,
        'akhir_absen_masuk'          => 120,
        'jam_pulang'                 => '16:00',
        'awal_absen_pulang'          => 120,
        'early_out_minutes'          => 0,
        'akhir_absen_pulang'         => 15,
        'jam_istirahat_start'        => '11:30',
        'jam_istirahat_end'          => '12:30',
        'has_lembur'                 => false,
        'jam_masuk_lembur'           => '16:30',
        'awal_absen_lembur_masuk'    => 15,
        'toleransi_lembur_masuk'     => 0,
        'akhir_absen_lembur_masuk'   => 120,
        'jam_pulang_lembur'          => '20:00',
        'awal_absen_lembur_pulang'   => 160,
        'toleransi_lembur_pulang'    => 150,
        'akhir_absen_lembur_pulang'  => 90,
        'jam_istirahat_lembur_start' => '18:00',
        'jam_istirahat_lembur_end'   => '18:30',
    ];
    $defaultLibur = [0]; // Minggu

    $masterSchedule = $defaultMaster;
    $liburDays      = $defaultLibur;

    if ($isEdit && $karyawan->schedules->count()) {
        $first = $karyawan->schedules->firstWhere('is_off', false) ?? $karyawan->schedules->first();
        if ($first) {
            $masterSchedule = [
                'jam_masuk'                  => $first->jam_masuk ? substr($first->jam_masuk, 0, 5) : '08:00',
                'awal_absen_masuk'           => $first->awal_absen_masuk ?? 90,
                'late_in_minutes'            => $first->late_in_minutes ?? 10,
                'akhir_absen_masuk'          => $first->akhir_absen_masuk ?? 120,
                'jam_pulang'                 => $first->jam_pulang ? substr($first->jam_pulang, 0, 5) : '16:00',
                'awal_absen_pulang'          => $first->awal_absen_pulang ?? 120,
                'early_out_minutes'          => $first->early_out_minutes ?? 0,
                'akhir_absen_pulang'         => $first->akhir_absen_pulang ?? 15,
                'jam_istirahat_start'        => $first->jam_istirahat_start ? substr($first->jam_istirahat_start, 0, 5) : '11:30',
                'jam_istirahat_end'          => $first->jam_istirahat_end ? substr($first->jam_istirahat_end, 0, 5) : '12:30',
                'has_lembur'                 => (bool) $first->has_lembur,
                'jam_masuk_lembur'           => $first->jam_masuk_lembur ? substr($first->jam_masuk_lembur, 0, 5) : '16:30',
                'awal_absen_lembur_masuk'    => $first->awal_absen_lembur_masuk ?? 15,
                'toleransi_lembur_masuk'     => $first->toleransi_lembur_masuk ?? 0,
                'akhir_absen_lembur_masuk'   => $first->akhir_absen_lembur_masuk ?? 120,
                'jam_pulang_lembur'          => $first->jam_pulang_lembur ? substr($first->jam_pulang_lembur, 0, 5) : '20:00',
                'awal_absen_lembur_pulang'   => $first->awal_absen_lembur_pulang ?? 160,
                'toleransi_lembur_pulang'    => $first->toleransi_lembur_pulang ?? 150,
                'akhir_absen_lembur_pulang'  => $first->akhir_absen_lembur_pulang ?? 90,
                'jam_istirahat_lembur_start' => $first->jam_istirahat_lembur_start ? substr($first->jam_istirahat_lembur_start, 0, 5) : '18:00',
                'jam_istirahat_lembur_end'   => $first->jam_istirahat_lembur_end ? substr($first->jam_istirahat_lembur_end, 0, 5) : '18:30',
            ];
        }
        $liburDays = $karyawan->schedules->where('is_off', true)->pluck('day_of_week')->map(fn($d) => (int) $d)->all();
    }

    $dayNames = [1=>'Senin', 2=>'Selasa', 3=>'Rabu', 4=>'Kamis', 5=>'Jumat', 6=>'Sabtu', 0=>'Minggu'];
@endphp


<div class="max-w-5xl mx-auto" x-data="{ tab: (window.location.hash || '').replace('#','') || 'umum' }" x-init="$watch('tab', v => history.replaceState(null, '', v === 'umum' ? window.location.pathname + window.location.search : '#' + v))">

    <div class="bg-white rounded-t shadow border-b flex text-sm">
        <button type="button" @click="tab='umum'" :class="tab==='umum' ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900'" class="px-5 py-3">Umum</button>
        <button type="button" @click="tab='jadwal'" :class="tab==='jadwal' ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900'" class="px-5 py-3">Jadwal Kerja</button>
        <button type="button" @click="tab='pribadi'" :class="tab==='pribadi' ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900'" class="px-5 py-3">Data Pribadi</button>
        <button type="button" @click="tab='bpjs'" :class="tab==='bpjs' ? 'border-b-2 border-blue-600 text-blue-600 font-semibold' : 'text-gray-600 hover:text-gray-900'" class="px-5 py-3">Pajak &amp; BPJS</button>
    </div>

    {{-- TAB 1: UMUM --}}
    <div class="bg-white rounded-b shadow p-5" x-show="tab==='umum'" x-cloak>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Staf Code</label>
                @if($isEdit)
                    <input type="text" name="staf_code" value="{{ old('staf_code', $karyawan->staf_code) }}" required readonly class="border rounded px-3 py-2 w-full bg-gray-50 text-gray-700 font-mono">
                    <div class="text-[11px] text-gray-400 mt-1">Kode otomatis, tidak bisa diubah.</div>
                @else
                    <input type="text" value="(otomatis: KRY-xxx)" disabled class="border rounded px-3 py-2 w-full bg-gray-50 text-gray-400">
                    <div class="text-[11px] text-gray-400 mt-1">Kode di-generate otomatis saat disimpan.</div>
                @endif
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Nama Lengkap <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $karyawan->name ?? '') }}" required class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">User ID Fingerprint</label>
                <input type="text" name="user_id_fingerprint" value="{{ old('user_id_fingerprint', $karyawan->user_id_fingerprint ?? '') }}" class="border rounded px-3 py-2 w-full">
                <div class="text-[11px] text-gray-400 mt-1">PIN/User ID di mesin fingerprint. Harus sama dengan yang di-enroll di mesin.</div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Jabatan</label>
                <input type="text" name="jabatan" value="{{ old('jabatan', $karyawan->jabatan ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Mulai Kerja</label>
                <input type="date" name="mulai_kerja" value="{{ old('mulai_kerja', optional($karyawan->mulai_kerja ?? null)->format('Y-m-d')) }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Gaji Pokok / Bulan (Rp)</label>
                <input type="text" inputmode="numeric" name="gaji_pokok" value="{{ old('gaji_pokok', isset($karyawan) ? number_format((float)$karyawan->gaji_pokok, 0, ',', '.') : '0') }}" class="rupiah-input border rounded px-3 py-2 w-full text-right">
                <div class="text-[11px] text-gray-400 mt-1">Tunjangan otomatis &amp; bonus diatur di <a href="{{ route('sdm.kebijakan.kolom.index') }}" class="text-blue-600 hover:underline">menu Kebijakan</a>.</div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tunjangan Pegawai / Bulan (Rp)</label>
                <input type="text" inputmode="numeric" name="tunjangan_pegawai" value="{{ old('tunjangan_pegawai', isset($karyawan) ? number_format((float)$karyawan->tunjangan_pegawai, 0, ',', '.') : '0') }}" class="rupiah-input border rounded px-3 py-2 w-full text-right">
                <div class="text-[11px] text-gray-400 mt-1">Tunjangan khusus per pegawai (mis. masa kerja, kinerja). Tampil di slip gaji.</div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Hak Cuti (hari/tahun)</label>
                <input type="number" min="0" max="60" name="hak_cuti" value="{{ old('hak_cuti', $karyawan->hak_cuti ?? 12) }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="is_active" class="border rounded px-3 py-2 w-full">
                    <option value="1" @selected(old('is_active', $karyawan->is_active ?? true))>Aktif</option>
                    <option value="0" @selected(! old('is_active', $karyawan->is_active ?? true))>Nonaktif</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Divisi</label>
                <select name="department_id" class="border rounded px-3 py-2 w-full">
                    <option value="">— Tanpa Divisi —</option>
                    @foreach($departments as $d)
                        <option value="{{ $d->id }}" @selected((string) old('department_id', $karyawan->department_id ?? '') === (string) $d->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($isEdit)
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Sanksi (SP) Aktif</label>
                    <input type="text" disabled value="{{ $karyawan->sanksi }}" class="border rounded px-3 py-2 w-full bg-gray-50 text-gray-700 font-mono">
                    <div class="text-[11px] text-gray-400 mt-1">Default SP0. Berubah otomatis saat Surat Peringatan diterbitkan di <a href="{{ route('sdm.sp.index') }}" class="text-blue-600 hover:underline">menu SP</a>.</div>
                </div>
            @endif
        </div>
    </div>

    {{-- TAB 3: JADWAL KERJA --}}
    <div class="bg-white rounded-b shadow p-5" x-show="tab==='jadwal'" x-cloak>
        <p class="text-xs text-gray-500 mb-4">
            Jam kerja berlaku sama untuk semua hari kerja. <strong>Awal Absen</strong> = window scan dibuka (menit sebelum waktu),
            <strong>Toleransi</strong> = grace period, <strong>Akhir Absen</strong> = window scan ditutup (menit sesudah waktu).
            Centang hari yang <strong>Libur</strong> untuk dikosongkan otomatis.
        </p>

        {{-- Jam Reguler --}}
        <div class="border rounded mb-4 overflow-hidden">
            <div class="bg-gray-50 px-4 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wider border-b">Jam Reguler</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50/50 text-[11px] text-gray-500 border-b">
                    <tr>
                        <th class="px-2 py-1.5 text-center w-12 align-bottom">Slot</th>
                        <th class="px-2 py-1.5 text-center font-normal">Awal Absen<br><span class="text-gray-400">(menit sebelum)</span></th>
                        <th class="px-2 py-1.5 text-center font-normal">Waktu</th>
                        <th class="px-2 py-1.5 text-center font-normal">Toleransi<br><span class="text-gray-400">(menit)</span></th>
                        <th class="px-2 py-1.5 text-center font-normal">Akhir Absen<br><span class="text-gray-400">(menit sesudah)</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b">
                        <td class="px-2 py-2 text-xs font-medium text-gray-600 text-center bg-gray-50">Datang</td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[awal_absen_masuk]" value="{{ old('schedule_master.awal_absen_masuk', $masterSchedule['awal_absen_masuk']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                        <td class="px-2 py-2"><input type="time" name="schedule_master[jam_masuk]" value="{{ old('schedule_master.jam_masuk', $masterSchedule['jam_masuk']) }}" class="border rounded px-2 py-1.5 w-full"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="240" name="schedule_master[late_in_minutes]" value="{{ old('schedule_master.late_in_minutes', $masterSchedule['late_in_minutes']) }}" class="border rounded px-2 py-1.5 w-full text-center" title="Scan datang > N menit dari Waktu = Terlambat"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[akhir_absen_masuk]" value="{{ old('schedule_master.akhir_absen_masuk', $masterSchedule['akhir_absen_masuk']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                    </tr>
                    <tr>
                        <td class="px-2 py-2 text-xs font-medium text-gray-600 text-center bg-gray-50">Pulang</td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[awal_absen_pulang]" value="{{ old('schedule_master.awal_absen_pulang', $masterSchedule['awal_absen_pulang']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                        <td class="px-2 py-2"><input type="time" name="schedule_master[jam_pulang]" value="{{ old('schedule_master.jam_pulang', $masterSchedule['jam_pulang']) }}" class="border rounded px-2 py-1.5 w-full"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="240" name="schedule_master[early_out_minutes]" value="{{ old('schedule_master.early_out_minutes', $masterSchedule['early_out_minutes']) }}" class="border rounded px-2 py-1.5 w-full text-center" title="Scan pulang > N menit sebelum Waktu = Pulang Awal"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[akhir_absen_pulang]" value="{{ old('schedule_master.akhir_absen_pulang', $masterSchedule['akhir_absen_pulang']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Jam Lembur --}}
        <div class="border rounded mb-4 overflow-hidden">
            <div class="bg-gray-50 px-4 py-2 border-b">
                <div class="text-xs font-semibold text-gray-600 uppercase tracking-wider">Jam Lembur</div>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50/50 text-[11px] text-gray-500 border-b">
                    <tr>
                        <th class="px-2 py-1.5 text-center w-12 align-bottom">Slot</th>
                        <th class="px-2 py-1.5 text-center font-normal">Awal Absen<br><span class="text-gray-400">(menit sebelum)</span></th>
                        <th class="px-2 py-1.5 text-center font-normal">Waktu</th>
                        <th class="px-2 py-1.5 text-center font-normal">Toleransi<br><span class="text-gray-400">(menit)</span></th>
                        <th class="px-2 py-1.5 text-center font-normal">Akhir Absen<br><span class="text-gray-400">(menit sesudah)</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b">
                        <td class="px-2 py-2 text-xs font-medium text-gray-600 text-center bg-gray-50">Datang</td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[awal_absen_lembur_masuk]" value="{{ old('schedule_master.awal_absen_lembur_masuk', $masterSchedule['awal_absen_lembur_masuk']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                        <td class="px-2 py-2"><input type="time" name="schedule_master[jam_masuk_lembur]" value="{{ old('schedule_master.jam_masuk_lembur', $masterSchedule['jam_masuk_lembur']) }}" class="border rounded px-2 py-1.5 w-full"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="240" name="schedule_master[toleransi_lembur_masuk]" value="{{ old('schedule_master.toleransi_lembur_masuk', $masterSchedule['toleransi_lembur_masuk']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[akhir_absen_lembur_masuk]" value="{{ old('schedule_master.akhir_absen_lembur_masuk', $masterSchedule['akhir_absen_lembur_masuk']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                    </tr>
                    <tr>
                        <td class="px-2 py-2 text-xs font-medium text-gray-600 text-center bg-gray-50">Pulang</td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[awal_absen_lembur_pulang]" value="{{ old('schedule_master.awal_absen_lembur_pulang', $masterSchedule['awal_absen_lembur_pulang']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                        <td class="px-2 py-2"><input type="time" name="schedule_master[jam_pulang_lembur]" value="{{ old('schedule_master.jam_pulang_lembur', $masterSchedule['jam_pulang_lembur']) }}" class="border rounded px-2 py-1.5 w-full"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="240" name="schedule_master[toleransi_lembur_pulang]" value="{{ old('schedule_master.toleransi_lembur_pulang', $masterSchedule['toleransi_lembur_pulang']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                        <td class="px-2 py-2"><input type="number" min="0" max="480" name="schedule_master[akhir_absen_lembur_pulang]" value="{{ old('schedule_master.akhir_absen_lembur_pulang', $masterSchedule['akhir_absen_lembur_pulang']) }}" class="border rounded px-2 py-1.5 w-full text-center"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Jam Istirahat (Reguler & Lembur) --}}
        <div class="border rounded p-4 mb-4">
            <div class="text-xs font-semibold text-gray-600 mb-3 uppercase tracking-wider">Jam Istirahat</div>
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <div class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2">Istirahat Reguler</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Mulai</label>
                            <input type="time" name="schedule_master[jam_istirahat_start]" value="{{ old('schedule_master.jam_istirahat_start', $masterSchedule['jam_istirahat_start']) }}" class="border rounded px-2 py-1.5 w-full">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Selesai</label>
                            <input type="time" name="schedule_master[jam_istirahat_end]" value="{{ old('schedule_master.jam_istirahat_end', $masterSchedule['jam_istirahat_end']) }}" class="border rounded px-2 py-1.5 w-full">
                        </div>
                    </div>
                </div>
                <div>
                    <div class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2">Istirahat Lembur</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Mulai</label>
                            <input type="time" name="schedule_master[jam_istirahat_lembur_start]" value="{{ old('schedule_master.jam_istirahat_lembur_start', $masterSchedule['jam_istirahat_lembur_start']) }}" class="border rounded px-2 py-1.5 w-full">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Selesai</label>
                            <input type="time" name="schedule_master[jam_istirahat_lembur_end]" value="{{ old('schedule_master.jam_istirahat_lembur_end', $masterSchedule['jam_istirahat_lembur_end']) }}" class="border rounded px-2 py-1.5 w-full">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Hari Libur --}}
        <div>
            <label class="block text-xs text-gray-500 mb-2">Hari Libur</label>
            <div class="flex flex-wrap gap-3 text-sm">
                @php $oldLibur = old('libur_days', $liburDays); @endphp
                @foreach([1,2,3,4,5,6,0] as $dow)
                    <label class="flex items-center gap-1.5 px-3 py-1.5 border rounded cursor-pointer hover:bg-gray-50">
                        <input type="checkbox" name="libur_days[]" value="{{ $dow }}" @checked(in_array($dow, $oldLibur)) class="rounded">
                        <span>{{ $dayNames[$dow] }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        @if($isEdit)
            <div class="mt-5 border-t pt-3 flex items-center justify-end gap-3">
                <span class="text-[11px] text-gray-400 max-w-md text-right">Salin jam reguler, lembur, istirahat, dan hari libur di atas ke semua karyawan aktif lain.</span>
                <button type="submit" name="apply_to_all" value="1"
                        onclick="return confirm('Terapkan jadwal kerja & hari libur ini ke SEMUA karyawan aktif lainnya? Data karyawan ini juga akan disimpan.')"
                        class="text-xs text-gray-600 hover:text-emerald-700 border border-gray-200 hover:border-emerald-300 hover:bg-emerald-50 px-3 py-1.5 rounded whitespace-nowrap">
                    Terapkan ke seluruh karyawan
                </button>
            </div>
        @endif
    </div>

    {{-- TAB 4: DATA PRIBADI --}}
    <div class="bg-white rounded-b shadow p-5" x-show="tab==='pribadi'" x-cloak>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">NIK</label>
                <input type="text" name="nik" value="{{ old('nik', $karyawan->nik ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">HP</label>
                <input type="text" name="hp" value="{{ old('hp', $karyawan->hp ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $karyawan->email ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">NPWP</label>
                <input type="text" name="npwp" value="{{ old('npwp', $karyawan->npwp ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div class="col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Alamat</label>
                <textarea name="alamat" rows="2" class="border rounded px-3 py-2 w-full">{{ old('alamat', $karyawan->alamat ?? '') }}</textarea>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">No. BPJS</label>
                <input type="text" name="bpjs" value="{{ old('bpjs', $karyawan->bpjs ?? '') }}" class="border rounded px-3 py-2 w-full">
                <div class="text-[11px] text-gray-400 mt-1">Nomor peserta BPJS. Tarif & toggle aktif diatur di tab <strong>Pajak &amp; BPJS</strong>.</div>
            </div>

            {{-- Foto diri & KTP — diunggah karyawan saat daftar di aplikasi; bisa dilihat/ganti di sini. --}}
            <div class="col-span-2">
                <div class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider border-t pt-3">Foto Diri &amp; KTP</div>
                <p class="text-[11px] text-gray-400 mb-1">Diisi saat karyawan mendaftar di aplikasi. Bisa diunggah / diganti dari sini (format gambar, maks 5&nbsp;MB).</p>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Foto Diri</label>
                @if($isEdit && $karyawan->foto_path)
                    <a href="{{ asset('storage/'.$karyawan->foto_path) }}" target="_blank" rel="noopener" class="inline-block mb-2">
                        <img src="{{ asset('storage/'.$karyawan->foto_path) }}" alt="Foto diri" class="h-32 w-32 object-cover rounded-lg border hover:opacity-90">
                    </a>
                @else
                    <div class="h-32 w-32 rounded-lg border border-dashed flex items-center justify-center text-gray-300 text-xs mb-2">Belum ada</div>
                @endif
                <input type="file" name="foto" accept="image/*" class="block text-sm w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Foto KTP</label>
                @if($isEdit && $karyawan->ktp_path)
                    <a href="{{ asset('storage/'.$karyawan->ktp_path) }}" target="_blank" rel="noopener" class="inline-block mb-2">
                        <img src="{{ asset('storage/'.$karyawan->ktp_path) }}" alt="Foto KTP" class="h-32 w-auto max-w-full object-contain rounded-lg border bg-gray-50 hover:opacity-90">
                    </a>
                @else
                    <div class="h-32 w-full rounded-lg border border-dashed flex items-center justify-center text-gray-300 text-xs mb-2">Belum ada</div>
                @endif
                <input type="file" name="ktp" accept="image/*" class="block text-sm w-full">
            </div>

            <div class="col-span-2">
                <div class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider border-t pt-3">Bank Rekening</div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Bank</label>
                <input type="text" name="bank_name" value="{{ old('bank_name', $karyawan->bank_name ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">No Rekening</label>
                <input type="text" name="bank_account_number" value="{{ old('bank_account_number', $karyawan->bank_account_number ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Atas Nama Rekening</label>
                <input type="text" name="bank_account_holder" value="{{ old('bank_account_holder', $karyawan->bank_account_holder ?? '') }}" class="border rounded px-3 py-2 w-full">
            </div>
        </div>
    </div>

    {{-- TAB 5: PAJAK & BPJS --}}
    <div class="bg-white rounded-b shadow p-5" x-show="tab==='bpjs'" x-cloak>
        <p class="text-xs text-gray-500 mb-4">
            Aktifkan program BPJS yang diikuti karyawan ini & pilih <strong>tier PTKP</strong> untuk hitungan PPh 21.
            Tarif % &amp; dasar maks diatur global di
            <a href="{{ route('sdm.kebijakan.pengaturan.edit') }}" class="text-blue-600 hover:underline">Pengaturan Akun &amp; Rate</a>.
        </p>

        {{-- Toggle BPJS --}}
        <div class="border rounded p-4 mb-4">
            <div class="text-xs font-semibold text-gray-600 mb-3 uppercase tracking-wider">Status Keikutsertaan BPJS</div>
            <div class="grid grid-cols-2 gap-4">
                <label class="flex items-start gap-3 p-3 border rounded cursor-pointer hover:bg-gray-50 hover:border-blue-300">
                    <input type="hidden" name="ikut_bpjs_kesehatan" value="0">
                    <input type="checkbox" name="ikut_bpjs_kesehatan" value="1"
                           @checked(old('ikut_bpjs_kesehatan', $karyawan->ikut_bpjs_kesehatan ?? false))
                           class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 mt-0.5">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Ikut BPJS Kesehatan</div>
                        <div class="text-[11px] text-gray-500 mt-0.5">Saat aktif, potongan 1% karyawan + 4% perusahaan otomatis dihitung dari bruto bulanan (dasar maks Rp 12 jt).</div>
                    </div>
                </label>
                <label class="flex items-start gap-3 p-3 border rounded cursor-pointer hover:bg-gray-50 hover:border-blue-300">
                    <input type="hidden" name="ikut_bpjs_tk" value="0">
                    <input type="checkbox" name="ikut_bpjs_tk" value="1"
                           @checked(old('ikut_bpjs_tk', $karyawan->ikut_bpjs_tk ?? false))
                           class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 mt-0.5">
                    <div>
                        <div class="text-sm font-semibold text-gray-800">Ikut BPJS Ketenagakerjaan</div>
                        <div class="text-[11px] text-gray-500 mt-0.5">JHT 2%/3,7% + JP 1%/2% (dasar maks Rp 10,5 jt) + JKK 0,24% + JKM 0,30%. Potongan karyawan & beban perusahaan otomatis dihitung.</div>
                    </div>
                </label>
            </div>
        </div>

        {{-- PPh 21 Tier --}}
        <div class="border rounded p-4 mb-4">
            <div class="text-xs font-semibold text-gray-600 mb-3 uppercase tracking-wider">Tier PPh 21 (Kategori PTKP)</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Kategori PTKP</label>
                    <select name="ptkp_category" class="border rounded px-3 py-2 w-full">
                        @foreach(['NONE' => 'Tidak kena pajak', 'A' => 'A — TK/0, TK/1, K/0 (PTKP 54-58,5 jt)', 'B' => 'B — TK/2, TK/3, K/1, K/2 (PTKP 63-67,5 jt)', 'C' => 'C — K/3 (PTKP 72 jt)'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('ptkp_category', $karyawan->ptkp_category ?? 'NONE') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="text-[11px] text-gray-400 mt-1">Pilih sesuai status keluarga karyawan. TER (Tarif Efektif Rata-rata) di-lookup dari bruto bulanan.</div>
                </div>
                <div class="text-xs text-gray-600 bg-gray-50 rounded p-3 leading-relaxed">
                    <div class="font-semibold mb-1">Cheat sheet PTKP:</div>
                    <ul class="list-disc list-inside space-y-0.5">
                        <li><strong>A</strong>: lajang / kawin tanpa tanggungan</li>
                        <li><strong>B</strong>: punya 1-3 tanggungan (lajang) / 1-2 anak (kawin)</li>
                        <li><strong>C</strong>: kawin + 3 anak</li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Override legacy --}}
        <details class="text-xs text-gray-500 border rounded p-3">
            <summary class="cursor-pointer hover:text-gray-700 font-semibold">Override manual nominal (opsional, untuk Slip Gaji legacy)</summary>
            <p class="text-[11px] text-gray-400 mt-2 mb-2">
                Hanya pakai bila ingin menetapkan nominal tetap, melewati perhitungan otomatis. Biasanya kosong saja.
            </p>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block mb-1">BPJS Kesehatan (Rp/bln)</label>
                    <input type="text" inputmode="numeric" name="bpjs_kesehatan_amount" value="{{ old('bpjs_kesehatan_amount', isset($karyawan) ? number_format((float)$karyawan->bpjs_kesehatan_amount, 0, ',', '.') : '0') }}" class="rupiah-input border rounded px-2 py-1 w-full text-right">
                </div>
                <div>
                    <label class="block mb-1">BPJS TK (Rp/bln)</label>
                    <input type="text" inputmode="numeric" name="bpjs_tk_amount" value="{{ old('bpjs_tk_amount', isset($karyawan) ? number_format((float)$karyawan->bpjs_tk_amount, 0, ',', '.') : '0') }}" class="rupiah-input border rounded px-2 py-1 w-full text-right">
                </div>
                <div>
                    <label class="block mb-1">PPh 21 (Rp/bln)</label>
                    <input type="text" inputmode="numeric" name="pph21_amount" value="{{ old('pph21_amount', isset($karyawan) ? number_format((float)$karyawan->pph21_amount, 0, ',', '.') : '0') }}" class="rupiah-input border rounded px-2 py-1 w-full text-right">
                </div>
            </div>
        </details>
    </div>

    <div class="flex items-center gap-2 mt-4">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-semibold">Simpan</button>
        <a href="{{ route('sdm.karyawan.index') }}" class="text-gray-600 px-3 py-2 text-sm">Batal</a>
    </div>
</div>

@push('scripts')
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>[x-cloak] { display: none !important; }</style>
@endpush
