<?php

namespace App\Modules\CRM\Models;

use App\Models\Customer;
use App\Models\User;
use App\Modules\CRM\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Satu percakapan dengan satu kontak, pada satu kanal, lewat satu nomor bisnis.
 *
 * Ini juga WADAH LEAD: lead = percakapan yang belum punya dokumen ERP. Karena
 * itu tidak ada tabel lead terpisah dan master pelanggan tidak dikotori oleh
 * setiap orang yang baru bertanya-tanya.
 */
class CrmConversation extends Model
{
    /** Antrean berbasis "bola di siapa", bukan sekadar "sudah/belum dikerjakan". */
    public const QUEUE_KITA      = 'menunggu_kita';      // paling menonjol di layar
    public const QUEUE_PELANGGAN = 'menunggu_pelanggan';
    public const QUEUE_DINGIN    = 'dingin';

    /*
     * 'menunggu_desain' DIBUANG 25 Sep 2026 — satu-satunya antrean yang tak
     * pernah dipasang kode mana pun, jadi ia cuma menambah satu chip yang
     * selamanya berangka nol. Lihat migrasi
     * 2026_09_25_100000_hapus_label_menunggu_desain.
     */
    public const QUEUE_LABELS = [
        self::QUEUE_KITA      => 'Menunggu Kita',
        self::QUEUE_PELANGGAN => 'Menunggu Pelanggan',
        self::QUEUE_DINGIN    => 'Dingin',
    ];

    /*
     * Open / Close. Nilai di basis data SENGAJA tetap 'aktif'/'arsip' — lihat
     * migrasi 2026_10_08_100000_add_open_close_to_crm_conversations. Di layar
     * keduanya terbaca "Open" dan "Close".
     */
    public const STATUS_AKTIF = 'aktif';
    public const STATUS_ARSIP = 'arsip';

    /** Label yang digerakkan sistem. Namanya bisa diganti di layar Label, kodenya tidak. */
    public const LABEL_SELESAI     = 'selesai';
    public const LABEL_DISTRIBUTOR = 'distributor';

    /**
     * Chat yang ditutup kurang dari sekian hari lalu kembali ke pemilik &
     * labelnya saat pelanggan menulis lagi. Lebih lama dari itu dianggap
     * pesanan baru: masuk "Belum dioper", tanpa pemilik dan tanpa label.
     */
    public const HARI_KEMBALI_KE_PEMILIK = 30;

    /** Chat berlabel Selesai & chat distributor tutup sendiri setelah sepi sekian hari. */
    public const HARI_TUTUP_OTOMATIS = 3;

    /** Asal `display_name` — lihat migrasi add_name_source_to_crm_conversations. */
    public const NAMA_WHATSAPP = 'whatsapp';
    public const NAMA_MANUAL   = 'manual';

    /** Kanal jalur resmi (api.co.id → Meta). Percakapan yang bisa dibalas dari ERP. */
    public const KANAL_RESMI = 'whatsapp';

    /**
     * Kanal CERMIN: chat nomor utama yang mengalir lewat WAHA.
     *
     * Dipisah dari kanal resmi, bukan digabung, dan itu yang paling menentukan
     * di seluruh Tahap 6. Unique index `crm_conversations` sudah
     * (channel + contact_key + business_number_id), jadi memberi kanal sendiri
     * langsung memisahkan thread cermin dari thread berbayar TANPA migrasi —
     * dan yang lebih penting, tanpa kemungkinan cermin baca-saja mengacaukan
     * jendela 24 jam milik thread yang justru dipakai membalas.
     *
     * Konsekuensinya satu pelanggan bisa punya dua thread. Itu memang harga
     * yang dipilih: riwayat terpecah bisa dibaca, sedangkan balasan yang
     * mendarat di jalur yang salah tidak bisa ditarik kembali.
     */
    public const KANAL_CERMIN = 'whatsapp_waha';

    protected $fillable = [
        'channel', 'contact_key', 'display_name', 'name_source', 'name_checked_at',
        'provider_customer_id', 'business_number_id',
        'customer_id', 'owner_user_id', 'queue_state', 'status',
        'window_expires_at', 'pancingan_untuk_jendela_at',
        'last_inbound_at', 'last_outbound_at', 'last_message_at',
        'unread_count', 'notes', 'order_draft',
        'closed_at', 'is_distributor', 'selalu_tutup',
    ];

    protected $casts = [
        'order_draft'       => 'array',
        'name_checked_at'   => 'datetime',
        'window_expires_at' => 'datetime',
        'pancingan_untuk_jendela_at' => 'datetime',
        'last_inbound_at'   => 'datetime',
        'last_outbound_at'  => 'datetime',
        'last_message_at'   => 'datetime',
        'unread_count'      => 'integer',
        'closed_at'         => 'datetime',
        'is_distributor'    => 'boolean',
        'selalu_tutup'      => 'boolean',
    ];

    /**
     * Riwayat label per pemilik (CRM Tahap 2): setiap kali label ATAU pemilik
     * berubah, label yang sedang dipakai dicatat sebagai "label chat ini di
     * tangan agen itu". Dibaca saat chat dioper balik ke agen yang sama.
     *
     * Lewat event model supaya SEMUA jalan ikut tercatat — ganti label dari
     * chip, oper, tanda distributor, dan (Tahap 3) peristiwa pesanan — tanpa
     * satu pun harus ingat memanggilnya.
     */
    protected static function booted(): void
    {
        static::saved(function (self $p) {
            if (! $p->owner_user_id || ! $p->queue_state) {
                return;
            }

            if (! $p->wasRecentlyCreated && ! $p->wasChanged(['queue_state', 'owner_user_id'])) {
                return;
            }

            DB::table('crm_label_riwayat')->upsert([[
                'conversation_id' => $p->id,
                'user_id'         => $p->owner_user_id,
                'kode'            => $p->queue_state,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]], ['conversation_id', 'user_id'], ['kode', 'updated_at']);
        });
    }

    /**
     * Label yang dipasang saat chat ini dioper ke $userId:
     *  - dilepas (tanpa pemilik)          → label awal (chat baru);
     *  - distributor                      → tetap Distributor;
     *  - pernah dipegang agen itu         → label terakhirnya di agen itu;
     *  - belum pernah                     → label dasar agen itu;
     *  - agen tanpa label dasar           → label tidak berubah.
     */
    public function labelSaatDioperKe(?int $userId): string
    {
        if (! $userId) {
            return $this->labelAwal();
        }

        if ($this->is_distributor) {
            return self::LABEL_DISTRIBUTOR;
        }

        return DB::table('crm_label_riwayat')
                ->where('conversation_id', $this->id)
                ->where('user_id', $userId)
                ->value('kode')
            ?? CrmLabel::dasarUntuk($userId)
            ?? $this->queue_state;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CrmMessage::class, 'conversation_id');
    }

    /**
     * Pesan paling akhir — untuk cuplikan satu baris di daftar chat.
     *
     * Relasi tersendiri, bukan `messages()->latest()->first()` di dalam
     * perulangan: daftar boleh memanjang sampai ratusan baris dan disegarkan
     * tiap 8 detik, jadi satu kueri per baris akan berlipat jadi ribuan kueri
     * semenit. `latestOfMany` menyelesaikannya dengan satu kueri untuk seluruh
     * halaman.
     *
     * Diurut `sent_at` DAN `id`: riwayat impor membawa banyak pesan berdetik
     * sama, dan tanpa pemecah seri cuplikannya bisa menampilkan pesan yang
     * BUKAN yang terakhir terlihat di HP.
     */
    public function pesanTerakhir(): HasOne
    {
        return $this->hasOne(CrmMessage::class, 'conversation_id')
            ->latestOfMany(['sent_at', 'id']);
    }

    /** Notifikasi pesanan yang pernah dikirim lewat percakapan ini. */
    public function outboxMessages(): HasMany
    {
        return $this->hasMany(CrmOutboxMessage::class, 'conversation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Cari atau buat percakapan untuk satu kontak. Nomor dinormalkan lebih dulu
     * supaya '0899…' dan '+62 899…' tidak melahirkan dua percakapan terpisah.
     */
    public static function findOrCreateFor(
        string $contact,
        string $channel = 'whatsapp',
        ?string $businessNumberId = null,
        array $attributes = []
    ): self {
        // Semua kanal WhatsApp (resmi maupun cermin WAHA) berisi NOMOR, jadi
        // semuanya dinormalkan. Diperiksa lewat awalan, bukan daftar nilai,
        // supaya kanal WhatsApp berikutnya tidak diam-diam jatuh ke cabang
        // username di bawah — yang menyimpan '0899…' apa adanya lalu
        // melahirkan thread kedua untuk orang yang sama.
        $key = str_starts_with($channel, 'whatsapp')
            ? (PhoneNumber::normalize($contact) ?? $contact)
            : ltrim(trim($contact), '@');

        /*
         * Nomor bisnis tidak disebut? Pakai percakapan yang sudah ada untuk
         * kontak ini, jangan bikin yang baru. Sebagian peristiwa webhook
         * memuat field itu dan sebagian tidak — kalau ketiadaannya dianggap
         * identitas tersendiri, satu orang pecah jadi dua thread dan balasan
         * admin mendarat di thread yang bukan tempat pelanggan menulis.
         */
        if ($businessNumberId === null) {
            $adaDuluan = static::where('channel', $channel)
                ->where('contact_key', $key)
                ->orderBy('id')
                ->first();

            if ($adaDuluan) {
                return $adaDuluan;
            }
        }

        return static::firstOrCreate(
            ['channel' => $channel, 'contact_key' => $key, 'business_number_id' => $businessNumberId],
            $attributes
        );
    }

    /**
     * Percakapan ini hidup lewat NOMOR UTAMA (WAHA), bukan jalur resmi?
     *
     * Satu-satunya sumber kebenarannya adalah kanal, dan itulah yang dipakai
     * ChatManager memilih jalur kirim. Sejak Tahap 7 thread ini BISA dibalas
     * dari ERP — namanya tetap "cermin" karena asal-usulnya begitu, tapi
     * "baca-saja" sudah tidak berlaku.
     *
     * Yang tidak berubah: balasannya WAJIB berangkat lewat jalur yang sama
     * dengan tempat pelanggan menulis. Lewat jalur yang salah, ia mendarat
     * sebagai pesan dari nomor asing atas percakapan yang tak pernah ia kirim
     * ke situ — dan itu tidak bisa ditarik kembali.
     */
    public function cermin(): bool
    {
        return $this->channel === self::KANAL_CERMIN;
    }

    /**
     * Percakapan ini bebas dari jendela 24 jam?
     *
     * Dipisahkan dari cermin() meski hari ini jawabannya sama persis, dan
     * pemisahan itu bukan hiasan: keduanya menjawab pertanyaan yang berbeda.
     * cermin() = "lewat jalur mana thread ini hidup"; yang ini = "apakah
     * aturan penagihan Meta berlaku padanya". Jendela 24 jam milik Cloud API,
     * bukan milik WhatsApp — perangkat tertaut boleh mengirim kapan saja.
     *
     * Bedanya akan terasa di Tahap 8: kalau jalur resmi dipensiunkan, yang
     * berubah jawabannya yang ini, sedangkan "thread ini dari nomor utama"
     * tetap benar. Menyatukannya sekarang berarti mencari ulang setiap
     * pemakaian dan menebak mana yang dimaksud yang mana.
     */
    public function tanpaJendela(): bool
    {
        return $this->cermin();
    }

    /**
     * Nama yang dipakai di layar untuk percakapan ini.
     *
     * Urutannya sengaja: nama pelanggan ERP (paling bermakna) → nama profil
     * WhatsApp → nomor telepon sebagai jaring terakhir. Dikumpulkan di sini
     * karena urutan yang sama sudah tersebar di kepala thread, daftar kiri, dan
     * pemilih tujuan teruskan — dan yang tercecer cepat jadi berbeda-beda.
     */
    public function namaTampil(): string
    {
        return $this->customer->name ?? $this->display_name ?? $this->contact_key;
    }

    /**
     * Sapaan siap tempel untuk pesan yang dikirim KE pelanggan ini.
     *
     * Beda dari namaTampil(), dan bedanya disengaja: namaTampil() jatuh ke
     * nomor telepon sebagai jaring terakhir — benar untuk layar kita, keliru
     * untuk pesan. "Halo Kak 6289…" yang dibaca pelanggan terbaca seperti
     * robot yang salah sasaran. Tanpa nama, "Kak" saja sudah sopan dan wajar.
     */
    public function sapaan(): string
    {
        $nama = $this->namaUntukPesan();

        return $nama !== null ? 'Kak ' . $nama : 'Kak';
    }

    /**
     * Nama yang PANTAS ditulis di pesan ke pelanggan, null bila tak ada.
     *
     * Nama profil WhatsApp sengaja TIDAK ikut: isinya terserah pemilik akun —
     * nama toko, emoji, "Mama Rafa 🌸" — bagus untuk mengenali chat di layar
     * kita, tapi "Halo Kak Toko Berkah Jaya" terbaca seperti salah sasaran.
     * Yang lolos hanya nama pelanggan ERP dan nama yang sengaja diketik CS.
     */
    public function namaUntukPesan(): ?string
    {
        $nama = trim((string) ($this->customer->name
            ?? ($this->name_source === self::NAMA_MANUAL ? $this->display_name : null)
            ?? ''));

        return $nama !== '' ? $nama : null;
    }

    /**
     * Jendela 24 jam masih terbuka?
     *
     * Dipakai layar Inbox untuk menandai thread SEBELUM admin mengetik panjang
     * lebar — tanpa penanda ini admin mengetik, ditolak API, lalu kembali
     * membalas dari HP, dan sistemnya mati sebelum sempat dipakai.
     */
    public function windowIsOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    /** Sisa jendela dalam jam (dibulatkan ke bawah), null bila sudah tertutup. */
    public function windowHoursLeft(): ?int
    {
        return $this->windowIsOpen() ? (int) now()->diffInHours($this->window_expires_at, false) : null;
    }

    /**
     * Sisa jendela dalam MENIT, null bila sudah tertutup.
     *
     * Ada karena jam saja terlalu kasar di ujung: `windowHoursLeft()`
     * mengembalikan 0 untuk apa pun di bawah satu jam, sehingga "tinggal 55
     * menit" dan "tinggal 30 detik" tak bisa dibedakan — padahal justru di
     * rentang itulah keputusan bertanya ke vendor diambil.
     */
    public function windowMinutesLeft(): ?int
    {
        return $this->windowIsOpen() ? (int) now()->diffInMinutes($this->window_expires_at, false) : null;
    }

    /**
     * Jendela masih terbuka menurut catatan kita, TAPI tinggal sedikit.
     *
     * Inilah satu-satunya rentang yang layak diverifikasi ke vendor: di luar
     * itu, selisih beberapa menit antara jam kita dan jam Meta tak pernah
     * mengubah keputusan apa pun.
     */
    public function windowHampirTutup(int $menit = 60): bool
    {
        return $this->windowIsOpen() && (int) $this->windowMinutesLeft() <= $menit;
    }

    /* ------------------------------------------------------- open / close */

    public function terbuka(): bool
    {
        return $this->status === self::STATUS_AKTIF;
    }

    /** Kolom yang diubah saat chat ditutup — dipakai tombol Close & penjadwal. */
    public static function nilaiTutup(): array
    {
        return ['status' => self::STATUS_ARSIP, 'closed_at' => now()];
    }

    /**
     * Label untuk chat yang (kembali) lahir sebagai pesanan baru — sama
     * dengan yang diberikan saat percakapan pertama kali dibuat di kanalnya.
     */
    public function labelAwal(): string
    {
        return $this->cermin() ? self::QUEUE_DINGIN : self::QUEUE_KITA;
    }

    /**
     * Perubahan kolom saat PELANGGAN menulis ke chat ini. SATU tempat untuk
     * jalur resmi maupun cermin WAHA, supaya aturan buka-kembali tidak bisa
     * berbeda tergantung nomor mana yang kebetulan menerima pesannya.
     *
     *  - masih Open             → tidak ada yang berubah;
     *  - nomor "selalu tutup"   → tetap Close (pesannya tetap tercatat);
     *  - distributor            → Open, SELALU kembali ke pemilik terakhir;
     *  - ditutup < 30 hari      → Open, pemilik & label tetap;
     *  - ditutup ≥ 30 hari      → Open sebagai pesanan baru: tanpa pemilik,
     *                             label kembali ke label awal.
     */
    public function perubahanSaatPesanMasuk(): array
    {
        if ($this->terbuka() || $this->selalu_tutup) {
            return [];
        }

        $ubah = ['status' => self::STATUS_AKTIF, 'closed_at' => null];

        $lama = ! $this->closed_at
            || $this->closed_at->lte(now()->subDays(self::HARI_KEMBALI_KE_PEMILIK));

        if ($lama && ! $this->is_distributor) {
            $ubah['owner_user_id'] = null;
            $ubah['queue_state']   = $this->labelAwal();

            /*
             * Pesanan BARU: riwayat label per agen ikut dibuang. Tanpa ini,
             * chat yang dulu berakhir "Selesai" di tangan Munif akan kembali
             * berlabel Selesai begitu pesanan barunya dioper ke Munif lagi.
             */
            DB::table('crm_label_riwayat')->where('conversation_id', $this->id)->delete();
        }

        return $ubah;
    }

    /**
     * Boleh membalas chat ini?
     *
     * Chat TANPA PEMILIK tidak bisa dibalas siapa pun — termasuk super admin.
     * Harus dioper dulu (ke diri sendiri pun boleh, satu ketukan). Disepakati
     * 8 Okt 2026 "biar disiplin": setiap chat yang dijawab punya penanggung
     * jawab, dan label dasar agen (Tahap 2) ikut terpasang lewat oper.
     *
     * Sesudah ada pemiliknya: pemilik itu sendiri dan super admin. Chat milik
     * orang lain tetap bisa DIBACA — yang dicegah dua orang menjawab satu
     * pelanggan dengan jawaban yang berbeda.
     */
    public function bolehDibalasOleh(?User $pengguna): bool
    {
        return $this->alasanTakBolehDibalas($pengguna) === null;
    }

    /** Null = boleh membalas; selain itu kalimat yang ditunjukkan ke orangnya. */
    public function alasanTakBolehDibalas(?User $pengguna): ?string
    {
        if (! $pengguna) {
            return 'Masuk dulu untuk membalas.';
        }

        if (! $this->owner_user_id) {
            return 'Chat ini belum dioper. Oper dulu — ke diri sendiri atau agen lain — sebelum membalas.';
        }

        if ($pengguna->isSuperAdmin() || (int) $this->owner_user_id === (int) $pengguna->id) {
            return null;
        }

        return 'Chat ini dipegang ' . ($this->owner->name ?? 'agen lain') . '. Minta dioper dulu kalau perlu membalasnya.';
    }

    /**
     * Sesudah membalas dari ERP: chat yang tertutup dibuka lagi.
     *
     * Pemilik hanya diisi untuk chat yang BARU lahir dari "Mulai Chat" ke
     * nomor yang belum pernah ada — chat tanpa pemilik yang sudah ada tidak
     * pernah sampai ke sini, karena membalasnya wajib oper dulu.
     */
    public function catatDibalasOleh(?User $pengguna): void
    {
        $ubah = [];

        if (! $this->owner_user_id && $pengguna) {
            $ubah['owner_user_id'] = $pengguna->id;
        }

        if (! $this->terbuka()) {
            $ubah += ['status' => self::STATUS_AKTIF, 'closed_at' => null];
        }

        if ($ubah) {
            $this->forceFill($ubah)->save();
        }
    }

    public function scopeAktif($query)
    {
        return $query->where('status', self::STATUS_AKTIF);
    }

    public function scopeAntrean($query, string $queueState)
    {
        return $query->where('queue_state', $queueState);
    }

    /** Lead = percakapan yang belum tertaut ke pelanggan mana pun di ERP. */
    public function scopeBelumDikenali($query)
    {
        return $query->whereNull('customer_id');
    }
}
