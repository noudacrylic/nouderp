{{-- Panel timbangan bersama (layar Timbang & Sampel Acuan).
     Setiap tangkapan stabil dikabarkan lewat event window `timbangan-tangkap`
     {gram, raw, source}; halaman yang memakai panel ini yang memutuskan ke mana
     angkanya pergi. Mode simulasi meniru alat persis (stabil/goyang, taruh/angkat)
     supaya alur bisa dites sebelum timbangan datang. --}}
<div x-data="panelTimbangan(@js(config('qc')))" x-init="init()"
     class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-3">
    <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2 text-xs">
            <span class="w-2.5 h-2.5 rounded-full" :class="terhubung ? 'bg-emerald-500' : (simulasi ? 'bg-amber-400' : 'bg-gray-300')"></span>
            <span class="text-gray-600" x-text="status"></span>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" x-show="!terhubung" @click="sambung()"
                    class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-semibold">🔌 Sambungkan timbangan</button>
            <button type="button" x-show="terhubung" @click="putus()"
                    class="text-xs border border-gray-200 text-gray-600 px-3 py-1.5 rounded-lg">Putuskan</button>
            <button type="button" @click="toggleSimulasi()"
                    class="text-xs border px-3 py-1.5 rounded-lg font-semibold"
                    :class="simulasi ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-gray-200 text-gray-600'">🧪 Simulasi</button>
        </div>
    </div>

    <div class="text-center py-2">
        <div class="text-5xl font-black tabular-nums" :class="stabil ? 'text-gray-900' : 'text-gray-400'"
             x-text="berat === null ? '—' : berat.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' g'"></div>
        <div class="text-xs mt-1" :class="stabil ? 'text-emerald-600' : 'text-amber-600'"
             x-text="berat === null ? 'Belum ada bacaan' : (stabil ? 'Stabil' : 'Belum stabil…')"></div>
    </div>

    {{-- Simulasi: taruh bungkus, goyangkan, angkat. --}}
    <div x-show="simulasi" x-cloak class="rounded-xl bg-amber-50 border border-amber-200 p-3 space-y-2">
        <div class="flex items-center gap-2">
            <input type="number" step="0.1" min="0" x-model.number="simGram"
                   class="w-28 border border-amber-300 rounded-lg px-2 py-1.5 text-sm text-right" placeholder="gram">
            <button type="button" @click="taruh()" class="text-xs bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded-lg font-semibold">Taruh</button>
            <button type="button" @click="angkat()" class="text-xs border border-amber-400 text-amber-800 px-3 py-1.5 rounded-lg">Angkat</button>
            <button type="button" @click="geser(-1)" class="text-xs border border-amber-300 px-2 py-1.5 rounded-lg">−1 g</button>
            <button type="button" @click="geser(1)" class="text-xs border border-amber-300 px-2 py-1.5 rounded-lg">+1 g</button>
            <label class="text-xs text-amber-800 flex items-center gap-1 ml-auto">
                <input type="checkbox" x-model="simGoyang" @change="terapkanSim()"> goyang
            </label>
        </div>
        <p class="text-[11px] text-amber-700">Taruh = berat stabil → tertangkap otomatis setelah ±{{ config('qc.capture.stable_ms') }} ms. Angkat dulu sebelum bungkus berikutnya.</p>
    </div>

    {{-- Diagnosa: hex mentah tiap paket — pengganti Termite untuk memverifikasi protokol. --}}
    <details class="text-xs">
        <summary class="cursor-pointer text-gray-500">Diagnosa (hex mentah)</summary>
        <div class="mt-2 space-y-2">
            <pre class="bg-gray-900 text-green-300 rounded-lg p-2 h-32 overflow-y-auto text-[11px] leading-snug" x-text="log.join('\n') || '(belum ada paket)'"></pre>
            <div class="flex gap-2">
                <input type="text" x-model="hexUji" placeholder="Tempel hex satu baris, mis. 20 20 30 2e 38 35 35 35 6b 67 0d 0a"
                       class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 font-mono">
                <button type="button" @click="putarHex()" class="border border-gray-200 px-3 rounded-lg">Uji parser</button>
            </div>
        </div>
    </details>
</div>

@once
<script src="{{ asset('js/timbangan.js') }}?v={{ @filemtime(public_path('js/timbangan.js')) }}"></script>
<script>
    function panelTimbangan(cfg) {
        return {
            alat: null, terhubung: false, simulasi: false, status: 'Belum tersambung',
            berat: null, stabil: false, adaBeban: false, log: [], hexUji: '',
            simGram: 855, simGoyang: false,

            init() {
                this.alat = Timbangan.create({
                    config: cfg,
                    onReading: (r) => {
                        this.berat = r.gram; this.stabil = r.stable;
                        // Kabarkan hanya saat beban berubah ada ↔ kosong (bukan tiap bacaan ~10×/detik).
                        const ada = Math.abs(r.gram) > cfg.capture.zero_gram;
                        if (ada !== this.adaBeban) {
                            this.adaBeban = ada;
                            window.dispatchEvent(new CustomEvent('timbangan-beban', { detail: { ada } }));
                        }
                    },
                    onCapture: (c) => window.dispatchEvent(new CustomEvent('timbangan-tangkap', {
                        detail: { gram: c.gram, raw: c.raw, source: this.simulasi ? 'simulasi' : 'timbangan' },
                    })),
                    onStatus: (s) => { this.status = s; },
                    onRaw: (h) => { this.log.push(h); if (this.log.length > 200) this.log.shift(); },
                });
                if (!this.alat.didukung()) {
                    this.status = 'Web Serial tidak tersedia — pakai Chrome/Edge di PC lewat HTTPS, atau mode simulasi.';
                    return;
                }
                // Port yang dulu sudah diizinkan langsung tersambung tanpa klik.
                this.alat.sambungUlang().then((ok) => { this.terhubung = ok; });
                window.addEventListener('timbangan-siapkan', () => this.alat.siapkanUlang());
            },

            async sambung() { this.matikanSimulasi(); this.terhubung = await this.alat.sambung(); },
            async putus() { await this.alat.putus(); this.terhubung = false; },

            toggleSimulasi() { this.simulasi ? this.matikanSimulasi() : this.nyalakanSimulasi(); },
            nyalakanSimulasi() {
                if (this.terhubung) this.putus();
                this.simulasi = true;
                this.alat.aturSimulasi(0, true);
                this.alat.mulaiSimulasi();
            },
            matikanSimulasi() {
                this.simulasi = false;
                this.alat.hentikanSimulasi();
                if (!this.terhubung) { this.berat = null; this.status = 'Belum tersambung'; }
            },
            terapkanSim() {
                const g = this.alat.bacaanSimulasi().gram;
                this.alat.aturSimulasi(g, !this.simGoyang);
            },
            taruh() { this.alat.aturSimulasi(Number(this.simGram) || 0, !this.simGoyang); },
            angkat() { this.alat.aturSimulasi(0, true); },
            geser(d) { this.simGram = Math.round(((Number(this.simGram) || 0) + d) * 10) / 10; this.taruh(); },
            putarHex() { if (this.hexUji.trim()) this.alat.putarHex(this.hexUji); },
        };
    }

    // Bunyi hasil: dua nada naik = lulus, dengung rendah = cek ulang (Web Audio, tanpa berkas).
    window.bunyiQc = (function () {
        let ctx = null;
        const nada = (f, mulai, lama, tipe) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.type = tipe; o.frequency.value = f;
            g.gain.setValueAtTime(0.0001, ctx.currentTime + mulai);
            g.gain.exponentialRampToValueAtTime(0.6, ctx.currentTime + mulai + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + mulai + lama);
            o.connect(g).connect(ctx.destination);
            o.start(ctx.currentTime + mulai); o.stop(ctx.currentTime + mulai + lama + 0.05);
        };
        return function (lulus) {
            try {
                ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
                if (ctx.state === 'suspended') ctx.resume();
                if (lulus) { nada(880, 0, 0.15, 'sine'); nada(1320, 0.16, 0.25, 'sine'); }
                else { nada(220, 0, 0.35, 'square'); nada(180, 0.4, 0.45, 'square'); }
            } catch (e) {}
        };
    })();
</script>
@endonce
