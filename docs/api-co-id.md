# API api.co.id — catatan integrasi

Salinan dokumentasi vendor per **9 September 2026**, ditambah koreksi yang kita
temukan sendiri di lapangan.

> **Salinan ini TIDAK lengkap.** Bagian Webhooks terpotong di tengah daftar
> `data` fields. Untuk apa pun yang tidak ada di sini, buka dasbor vendor —
> jangan menebak bentuk payload.

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

Envelope tiap kiriman:

```json
{
  "event_type": "message.received",
  "event_id": "uuid",
  "timestamp": "2026-07-24T10:30:00.000Z",
  "data": { }
}
```

Peristiwa: `message.received`, `message.sent`, `message.delivered`,
`message.read`, `message.failed`.

Header:

```
X-Webhook-Signature: <hex HMAC-SHA256 dari BODY MENTAH>
X-Webhook-Event: <event_type>
X-Webhook-Delivery: <event_id>_<endpoint_id>
X-Webhook-Idempotency-Key: <message_id>_<event_type>_<minute_bucket>
```

Tanda tangan diverifikasi atas **byte mentah** body, bukan hasil parse —
lihat `VerifyCrmWebhookSignature`.

Sebagian `data`: `message_id` (id internal, **bukan selalu wamid Meta**),
`customer_id`, `customer_phone`, `customer_username`, `channel`, `direction`,
`message_type`, `content`, `media_url`, `media_status`
(`ok|download_failed|unsupported`), `phone_number_id`, `business_phone`, `raw`.

> Daftar `data` di salinan ini terpotong. Lengkapi dari dasbor vendor saat perlu.

---

## Yang tersedia tapi TIDAK dipakai ERP

`instagram-accounts`, `facebook-pages`, `conversations` + `/messages`,
`typing`, `messages/:id/read`, CRUD `customers` + `consent` + `notes` +
`blacklist`, `interactive` (cta_url / reply buttons / list menu), `broadcast`.

Beberapa di antaranya menarik untuk nanti — indikator "sedang mengetik" dan
tanda dibaca akan membuat chat di ERP terasa seperti WhatsApp sungguhan.
