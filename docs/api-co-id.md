# API api.co.id — catatan integrasi

Salinan dokumentasi vendor per **9 September 2026**, ditambah koreksi yang kita
temukan sendiri di lapangan.

Salinannya lengkap per tanggal itu, tapi tetap **bukan pengganti dasbor
vendor**: yang di sini bisa basi, dan payload sungguhan selalu lebih berhak
dipercaya daripada catatan ini.

Alasan berkas ini ada: pengetahuan tentang API ini sebelumnya cuma hidup di
komentar `ApiCoIdProvider.php`, dan sebagiannya adalah koreksi atas dokumentasi
vendor yang keliru. Kalau berkas itu suatu saat ditulis ulang, koreksinya ikut
hilang bersamanya — lalu ditemukan lagi dengan cara yang mahal.

---

## Dasar

- **Base URL**: `https://chat.api.co.id/api/v1/public`
- **Auth**: `Authorization: Bearer <API_KEY>` + `Content-Type: application/json`
- Ada Postman Collection resmi yang bisa diunduh dari halaman dokumentasi.

---

## ⚠️ Koreksi kita atas dokumentasi vendor

Yang di bawah ini ditemukan lewat percobaan, bukan dari dokumentasi:

### 1. `media_url` hanya menerima URL sungguhan

Dokumentasi menyiratkan `media_url` bisa diisi `media_id` hasil
`POST /media/upload` (lihat contoh di bagian "Upload Media"). Di lapangan,
mengisinya dengan id ditolak:

```json
{"field":"media_url","message":"Invalid URL"}
```

Karena itu `CrmReplyService` menyajikan lampiran keluar lewat rute bertanda
tangan berumur pendek, dan menyerahkan URL itu ke vendor — bukan `media_id`.
Lihat `CrmReplyService::tautanSementara()`.

### 2. Bentuk respons `GET /templates` berbeda antar versi

Dokumentasi menyebut `template_name` dan `content`; sebagian respons yang kita
terima memakai `name` dan `body`/`components[].text`. `ApiCoIdProvider::templates()`
sengaja mengenali **keduanya** — kalau hanya satu yang dibaca, daftar template di
ERP tampil kosong tanpa satu pun pesan galat.

---

## Endpoint yang DIPAKAI ERP

| Endpoint | Dipakai di |
|---|---|
| `GET /phone-numbers` | Settings CRM — memilih nomor bisnis |
| `POST /messages/send` | balas teks, media, template, notifikasi |
| `POST /media/upload` | (tersedia, tidak dipakai — lihat koreksi #1) |
| `GET /templates` | halaman Template Pesan |
| `POST /templates` | halaman Template Pesan — "Buat & Ajukan" |
| `POST /templates/:id/submit` | halaman Template Pesan — "Buat & Ajukan" |
| `GET /customers/:id/window-status` | penjaga jendela 24 jam |
| `GET/POST /webhooks`, `/webhooks/:id/enable` | Settings CRM |

---

## Kirim pesan

`POST /messages/send`

Identifikasi pelanggan: salah satu dari `phone_number`, `customer_id`, atau
`instagram_username`.

| Field | Catatan |
|---|---|
| `channel` | `whatsapp` \| `instagram` \| `messenger` |
| `message_type` | WA: `text`, `image`, `document`, `audio`, `video`, `template`, `interactive` |
| `content` | isi teks |
| `media_url` | URL media (lihat koreksi #1) |
| `caption` | keterangan untuk media |
| `reply_to_message_id` | WA saja — **wamid** pesan yang dikutip, bukan id internal |
| `whatsapp_phone_number_id` | `id` dari `GET /phone-numbers`, bukan `phone_number_id` Meta |

Respons: `data.message_id`, `data.customer_id`, `data.status`,
`data.whatsapp_phone_number_id`.

### Template

```json
{
  "phone_number": "628123456789",
  "channel": "whatsapp",
  "message_type": "template",
  "template": {
    "name": "order_confirmation",
    "language": { "code": "id" },
    "components": [
      { "type": "body", "parameters": [{ "type": "text", "text": "Budi" }] }
    ]
  }
}
```

### Interaktif (tombol balasan cepat)

```json
{
  "phone_number": "628123456789",
  "channel": "whatsapp",
  "message_type": "interactive",
  "interactive": {
    "type": "button",
    "body": { "text": "..." },
    "action": { "buttons": [
      { "type": "reply", "reply": { "id": "lanjut_diskusi", "title": "Lanjutkan diskusi" } }
    ] }
  }
}
```

Maks 3 tombol, judul maks 20 karakter. **Pesan sesi biasa** — hanya sah di
dalam jendela 24 jam, tapi GRATIS dan tanpa peninjauan Meta. Itulah dasar
`crm:pancing-jendela`: tombol yang sama lewat template berarti satu pengajuan
ke Meta plus tarif per kirim.

> ⚠️ Bentuk di atas **belum diuji ke vendor sungguhan** — ia mengikuti Meta
> karena di situlah taruhan terbaiknya (`template` pun diteruskan apa adanya
> dalam bentuk Meta). Kalau vendor menuntut bentuk lain, yang berubah cukup
> `ApiCoIdProvider::buildInteraktif()`.

Komponen lain: `header` (text/image/video/document), `button` dengan
`sub_type: url` + `index` (isi sufiks URL dinamis), atau `sub_type: copy_code`
dengan `{ "type": "coupon_code", "coupon_code": "..." }`.

> **Perhatikan bedanya dengan Broadcast**: di `/messages/send`, `components`
> berupa **array**; di `/broadcast/send` ia **objek datar** `{"1":"...","2":"..."}`.
> Menyamakan keduanya menghasilkan penolakan yang pesannya tidak menjelaskan apa-apa.

---

## Template: buat & ajukan (dua langkah)

**Langkah 1 — `POST /templates`** (baru menyimpan catatan, BELUM ke Meta):

| Field | Catatan |
|---|---|
| `template_name` | huruf kecil, angka, garis bawah. Unik per bahasa. |
| `category` | `MARKETING` \| `UTILITY` \| `AUTHENTICATION` |
| `language` | mis. `id` (bawaan `en_US`) |
| `body` | pakai `{{1}}`, `{{2}}`, … berurutan dari 1 |
| `variables` | contoh nilai tiap `{{n}}`; **jumlahnya wajib sama** dengan jumlah placeholder |
| `header` | `{ type, text, media_handle }` — media butuh *resumable upload handle*, bukan URL publik |
| `footer` | maks 60 karakter |
| `buttons` | `[{ type: QUICK_REPLY\|URL\|PHONE_NUMBER\|OTP, text, url, phone_number }]` |
| `whatsapp_phone_number_id` | menyasar WABA nomor tertentu |

Respons memberi `data.id` dengan `status: "PENDING"`.

**Langkah 2 — `POST /templates/:id/submit`**: baru di sinilah ia dikirim ke Meta.
Sukses → dapat `meta_template_id`, status tetap `PENDING` selama Meta meninjau
(menit sampai 24 jam). Ditolak → status jadi `REJECTED` beserta alasannya.

> Melewatkan langkah 2 adalah kesalahan yang paling mudah terjadi dan paling
> sulit terlihat: templatenya tampak "sudah dibuat" di daftar, PENDING selamanya,
> dan tak pernah sampai ke Meta.

**`GET /templates`** — filter: `status`, `category`, `whatsapp_phone_number_id`,
`limit` (1–500, bawaan 100), `offset`. Respons per baris:
`id`, `template_name`, `language`, `status`, `category`, `content`,
`header_type`, `header_content`, `footer_content`, `buttons`, `variables`,
`has_variables`.

**`GET /templates/:templateId`** — detail satu template.

---

## Jendela 24 jam

`GET /customers/:id/window-status` (`:id` boleh CUID, nomor telepon, atau
username IG).

```json
{ "data": { "whatsapp": {
  "is_window_active": true,
  "window_expires_at": "...",
  "can_send_freeform": true,
  "can_send_template": true
}}}
```

Di luar jendela, hanya template yang boleh berangkat.

---

## Broadcast

`POST /broadcast/send` — `template_name`, `language`, `phone_numbers[]`,
`components` (**objek datar**), `whatsapp_phone_number_id`. Mengembalikan
`job_id`; pantau lewat `GET /broadcast/jobs/:id`, batalkan dengan
`POST /broadcast/jobs/:id/cancel` (hanya yang `queued`/`processing`).

> ERP tidak memakai broadcast, dan itu disengaja. Blast promosi lewat nomor
> yang sama dengan nomor layanan adalah cara tercepat menurunkan
> *quality rating* — yang sekali turun menyeret seluruh jalur.

---

## Webhook

Alurnya: daftarkan URL di dasbor → pilih peristiwa → vendor POST ke URL itu →
server menjawab `200 OK`.

### Peristiwa

`message.received` · `message.sent` · `message.delivered` · `message.read` ·
`message.failed` (plus `test` dari tombol Test Webhook di dasbor).

### Amplop (semua peristiwa)

```json
{
  "event_type": "message.received",
  "event_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "timestamp": "2026-07-24T10:30:00.000Z",
  "data": { }
}
```

### Header

```
Content-Type: application/json
X-Webhook-Signature: <hex HMAC-SHA256 dari BODY MENTAH>
X-Webhook-Event: <event_type>
X-Webhook-Delivery: <event_id>_<endpoint_id>
X-Webhook-Idempotency-Key: <message_id>_<event_type>_<minute_bucket>
```

### Isi `data`

| Field | Catatan |
|---|---|
| `message_id` | id internal vendor — **bukan selalu wamid Meta** |
| `customer_id` | id pelanggan/percakapan internal |
| `customer_phone` | angka gaya E.164, mis. `628123456789` |
| `customer_username` | Instagram saja |
| `channel` | `whatsapp` \| `instagram` \| `messenger` |
| `direction` | `inbound` \| `outbound` |
| `message_type` | `text`, `image`, `video`, `audio`, `document`, `sticker`, `location`, `interactive`, `reaction` |
| `content` | teks atau caption |
| `media_url` | URL media yang sudah di-*rehost* vendor (bisa tidak ada) |
| `media_status` | media saja: `ok` \| `download_failed` \| `unsupported` |
| `phone_number_id` | id **internal** nomor WA (bukan id Meta) |
| `business_phone` | nomor WABA kita yang menerima/mengirim |
| `raw` | payload Meta yang sudah disaring; `raw.id` = wamid, `raw.context.id` = wamid yang dikutip |
| `ctwa_clid` | id klik iklan Click-to-WhatsApp — hanya di pesan masuk PERTAMA dari iklan |
| `referral` | objek CTWA: `source_type`, `source_id`, `headline`, `ctwa_clid`, … |

> ⚠️ **`media_url` hanya boleh dipercaya bila `media_status === "ok"`.**
> `download_failed` dan `unsupported` (mis. pesan sekali-lihat) TETAP membawa
> alamat, tapi alamat itu menjawab 404. `IncomingWebhookService::simpanLampiran`
> menolak menyimpannya sebagai `source_url` dan menuliskan alasannya ke
> `download_error` — kalau tidak, lampirannya duduk selamanya sebagai "belum
> terunduh" yang dicoba ulang tiap menit tanpa seorang pun tahu sebabnya.

### Balas di thread yang sama

Pakai `customer_phone` pada Send Message. Untuk kutipan WhatsApp, kirim wamid
dari `data.raw.id` (pesan masuk) atau `data.raw.context.id` (saat pelanggan
membalas pesan tertentu) sebagai `reply_to_message_id`.

### Keamanan

Tanda tangan = HMAC-SHA256 heksadesimal atas **byte mentah** body, dengan
`webhook_secret` yang hanya diperlihatkan SEKALI saat webhook dibuat.

Empat jebakan yang disebut vendor sendiri, semuanya nyata:

1. **Membandingkan JSON hasil parse, bukan body mentah.** Beda spasi atau
   urutan kunci sudah cukup untuk mematahkan tanda tangan.
2. **Membandingkan dengan `==`/`===`.** Pakai `hash_equals()` — perbandingan
   waktu-tetap.
3. **Menyimpan secret di kode.** Simpan di env.
4. **Menjawab lambat.** Wajib `200` dalam 5 detik; lebih dari itu dikirim ulang.
   Karena itu `IncomingWebhookService` **tidak** mengunduh media di dalam
   permintaan — unduhan diserahkan ke `crm:unduh-lampiran`.

### Endpoint webhook

- `GET /webhooks` — daftar beserta `is_active`, `status`, `failure_count`,
  `last_failed_at`, `disabled_at`, `disable_reason`.
- `GET /webhooks/:id` — satu endpoint.
- `POST /webhooks/:id/enable` — hidupkan lagi + reset `failure_count`.

> **Webhook dimatikan otomatis setelah 10 kegagalan pengiriman beruntun**
> (satu keberhasilan mereset hitungannya). Ini kegagalan yang tak bergejala:
> ERP tetap tenang, cuma tak ada pesan masuk lagi sama sekali. Karena itu ada
> pita peringatan di layar Pengaturan CRM plus tombol menghidupkan ulang.

---

## Galat & batas laju

Kode HTTP: `400` validasi · `401` API key salah · `403` ditolak · `404` tak ada
· `429` kena batas · `500` galat server.

```json
{
  "error": {
    "code": "ValidationError",
    "message": "phone_number is required",
    "details": [{ "field": "phone_number", "message": "phone_number is required" }]
  }
}
```

`ApiCoIdProvider::request()` membaca `error.message`, dan mencatat **seluruh
badan jawaban + badan permintaan** ke log saat ditolak — pesan vendor sering
cuma "Data yang Anda masukkan tidak valid" tanpa menyebut field mana.

**Batas laju**: WhatsApp **60 pesan/menit**, Instagram & Messenger 180/jam.
Tak ada batas jumlah permintaan harian selama nomornya berlisensi. Jawaban
membawa `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `X-RateLimit-Channel`.

> 60/menit adalah alasan `crm:kirim-notifikasi` mengambil paling banyak 50 baris
> per jalan. Menaikkannya berarti harus ikut menghitung jeda antar-kirim.

---

## Yang tersedia tapi TIDAK dipakai ERP

`instagram-accounts`, `facebook-pages`, `conversations` + `/messages`,
`typing`, `messages/:id/read`, CRUD `customers` + `consent` + `notes` +
`blacklist`, `broadcast`, serta sisa `interactive` yang belum dipakai
(cta_url & list menu — reply buttons sudah dipakai, lihat di atas).

Beberapa di antaranya menarik untuk nanti — indikator "sedang mengetik" dan
tanda dibaca akan membuat chat di ERP terasa seperti WhatsApp sungguhan.
