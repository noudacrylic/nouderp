# Rencana Implementasi — Integrasi Pengiriman & Partial Surat Jalan

Status: DRAFT untuk direview sebelum eksekusi. Disusun 2026-05-29, Bagian A direvisi final.

Rencana ini dua bagian:
- **Bagian A — Partial Surat Jalan (stok minus + COGS ditunda)** — fondasi, dikerjakan DULU.
- **Bagian B — Integrasi kurir Biteship + Kirimin Aja** (Fase 0–6).

---

## STATUS PROGRES (update 2026-06-02)

- **Bagian A** — ✅ SELESAI diimplementasi (2026-05-29).
- **Bagian B Fase 0** — ✅ SELESAI: produk `weight_gram`+dimensi (+form di setup_ready/preorder/bundle via `ProductController::updateInfo`); customer `province/district/postal_code/recipient_phone/biteship_area_id`; business_profiles `province/biteship_area_id`.
- **Bagian B Fase 1** — ✅ SELESAI (milestone "Cek Ongkir via Biteship"): tabel+model `ShippingSetting` (singleton per provider) + `config/services.php` biteship; modul `App\Modules\Shipping` (`Contracts\ShippingProvider`, `Providers\BiteshipProvider` rates+searchAreas, `ShippingManager`); Settings→Biteship (`Settings\ShippingSettingController`, route `settings.shipping.biteship.*`, view, menu); halaman Cek Ongkir (`Sales\CekOngkirController`, route `sales.cek-ongkir.*`, view, menu). Diverifikasi via Http::fake (booking/track masih stub).
- **MENUNGGU**: verifikasi/akun + **API key Biteship** dari user. Begitu key siap → isi di Settings→Biteship → tes Cek Ongkir real.

### TITIK LANJUT (saat resume)
1. (opsional) Form alamat baru di create/edit **customer** & **business-profile**; katalog `shipping_couriers` (mutual-exclusive) + UI mapping `biteship_area_id`.
2. **Fase 2**: field `delivery_method`/`courier_code`/`service_code`/`shipping_discount` di SO & INV; cek ongkir embedded; ongkir mengalir SO → Surat Jalan + Invoice.
3. **Fase 3**: kolom booking di Surat Jalan + `BiteshipProvider::createOrder()` → resi otomatis.
4. **Fase 4**: webhook tracking (signature-verify ala VerifyMidtransSignature).
5. **Fase 5**: sambung `shipping_cost` ke titipan ongkir (akun 1203) + diskon ongkir contra-revenue.
6. **Fase 6**: `KiriminAjaProvider`.
7. Catatan: `sales_returns` belum punya `warehouse_id` (untuk kunci gudang OP perbaikan dari retur).

---

## BAGIAN A — Partial Surat Jalan + COGS Ditunda

### Masalah yang dipecahkan
Customer pesan 200 pcs (produk preorder/custom yang harus diproduksi). Baru sebagian jadi, customer mau ambil "sejadainya dulu" 2 hari lagi, sisanya menyusul. Sistem harus bisa:
1. Bikin Surat Jalan **sebagian** dari SO (multi pengiriman per SO).
2. Boleh kirim walau stok sistem belum mencukupi (barang fisik sudah jadi, finalisasi produksi belum dilakukan).
3. Lacak sisa sampai SO terkirim penuh.

### Pendekatan final (disepakati 2026-05-29)
**Stok minus + COGS ditunda**, TANPA menyentuh modul produksi sama sekali:
- SJ stok-kurang: **hanya gerakkan stok** (ledger boleh minus), `cogs_total` dibiarkan pending, **lewati FIFO consume**.
- Produksi jalan normal; `finalize()` bikin layer HPP aktual seperti biasa.
- **COGS dihitung saat invoice di-post**, dari layer aktual yang sudah ada (qty invoice = acuan).
- **Invoice DIBLOK** kalau masih ada barang pending yang layer HPP-nya belum tersedia (produksi belum selesai). Pesan jelas: "Selesaikan produksi dulu." → COGS selalu aktual, nol estimasi.
- **Partial invoice dihapus** sebagai sumbu mandiri; partial murni dari delivery. Auto-SJ-dari-invoice hanya bikin **sisa** yang belum dikirim.

### Kondisi sekarang (terverifikasi)
- `SalesOrder::deliveries()` sudah `hasMany` — data model dukung multi-delivery. (`SalesOrder.php:60`)
- TAPI `SalesDeliveryController::create()` memblokir delivery ke-2: "Pengiriman sudah dibuat". (`SalesDeliveryController.php:49-57`)
- Pola partial invoice sudah ada: `SalesOrder::getInvoiceStatus()`. (`SalesOrder.php:88-119`) → ditiru untuk `getDeliveryStatus()`.
- **HPP dihitung saat SJ-POST, bukan invoice**: `SalesDeliveryService::post()` → `InventoryEngine::ship()` → `FifoService::consume()` → set `cogs_total`. (`SalesDeliveryService.php:121`)
- `FifoService::consume()` **throw "Stock not enough"** kalau layer kurang. (`FifoService.php:73-75`)
- `ledger()` **throw "Stock tidak mencukupi"** kalau balance < 0. (`InventoryEngine.php:76-78`)
- `InvoicePostingService::assignHppFromDelivery()` cuma **baca** `cogs_total` SJ & **throw kalau null**; ambil **satu** SJ via `->first()`. (`InvoicePostingService.php:95-131, 184`)
- Produksi `finalize()` bikin layer FIFO di `WIP ÷ qty` + jurnal Dr Persediaan/Cr WIP; layer custom ditag `sales_order_id`. (`ProductionOrderService.php:643-713`)

### Desain rinci
1. **`SalesOrder::getDeliveryStatus()`** (baru, tiru `getInvoiceStatus()`): `not_delivered` / `partial` / `delivered` dari Σ qty SJ posted+draft (non-void) vs Σ qty SO. + `getDeliveryStatusLabel()` untuk badge.
2. **Hapus blok single-delivery** di `create()`. Cegah hanya jika `getDeliveryStatus()==='delivered'`.
3. **Per baris SO di `create()`**: `Dipesan` / `Sudah dikirim` (Σ non-void) / `Sisa` / `Tersedia (stok)` = ledger balance. Default qty kirim = `Sisa` (boleh > stok → memicu defer). Info opsional progres produksi (Σ `production_order_outputs.qty_produced` where `production_orders.sales_order_id=SO`).
4. **Mode defer di `ship()`**: per baris SJ, jika ledger balance ≥ qty → FIFO consume normal (COGS dihitung sekarang). Jika balance < qty → **ledger boleh minus** (param `allowNegative`) + StockShipment + decrement reservasi, **skip consume**, `cogs_total=null`, set flag `cogs_deferred=true`. (Tidak split per baris — kekurangan → seluruh baris defer.)
5. **Validasi `store()`**: `qty ≤ Sisa` saja (TIDAK dibatasi stok — itu inti fitur). Bundle di-expand, service/non_stock skip.
6. **`InvoicePostingService` dirombak**:
   - Agregasi **SEMUA** SJ non-void untuk SO (bukan `->first()`); COGS invoice = total dari semua SJ.
   - Untuk item `cogs_deferred`: **hitung COGS sekarang** via FIFO consume dari layer aktual → isi `cogs_total`, matikan flag. Kalau layer tak cukup → **BLOK** dengan pesan "Selesaikan produksi dulu sebelum invoice."
   - Auto-SJ-dari-invoice: qty = qty invoice − total sudah dikirim (sisa saja).
7. **Badge status pengiriman** di index SO (mirror badge PARTIAL invoice): NOT DELIVERED / PARTIAL / FULL.
8. **Partial invoice dihapus** sebagai sumbu: fulfillment dilacak via delivery. `getInvoiceStatus()` tetap untuk cek "fully invoiced" tapi bukan jalur partial.

### File yang disentuh (Bagian A)
- `app/Modules/Sales/Models/SalesOrder.php` — `getDeliveryStatus()` + label.
- `app/Http/Controllers/Sales/SalesDeliveryController.php` — `create()` (hapus blok, kolom sisa/tersedia/produksi), `store()` (validasi sisa).
- `app/Core/Inventory/InventoryEngine.php` — `ship()` mode defer; `ledger()` param `allowNegative`.
- `app/Modules/Sales/Services/SalesDeliveryService.php` — teruskan flag defer; `createFromInvoice()` qty sisa.
- `app/Services/InvoicePostingService.php` — agregasi multi-SJ, hitung COGS pending, guard blok, sisa auto-SJ.
- `resources/views/erp/sales/deliveries/create.blade.php` & `edit.blade.php` — kolom baru.
- `resources/views/erp/sales/orders/index.blade.php` — badge status pengiriman.

### Migration (Bagian A)
- `sales_delivery_items`: tambah `cogs_deferred` (boolean, default false). (Bedakan dari service/non_stock yang cogs=0.)
- Pastikan `sales_deliveries.posted_at` & `voided_at` ada (verifikasi saat eksekusi).

### Edge cases (Bagian A)
- **Void SJ pending** (`cogs_deferred`): tidak ada FIFO yang dibalik — cukup kembalikan ledger (add-back). Beda dari void SJ normal yang reverse layer.
- **Window jeda (SJ minus → invoice)**: stok produk tampil **minus** di laporan; saldo ledger sementara beda dari total layer. Ter-rekonsiliasi saat invoice consume layer. Disadari saat lihat laporan di tengah jeda.
- **Void delivery** mengembalikan qty ke "Sisa" otomatis (status void diabaikan `getDeliveryStatus()`).
- **Garansi**: delivery dari `warranty_order` ikut pola seragam.

---

## BAGIAN B — Integrasi Kurir (Biteship + Kirimin Aja)

Arsitektur: adapter/strategy, meniru pola integrasi Midtrans (singleton setting + `Http` facade + webhook idempotent + signature-verify — `MidtransService`, `MidtransSetting`, `VerifyMidtransSignature`).

```
App\Modules\Shipping\
 ├─ Contracts\ShippingProvider.php       interface: rates / createOrder / track / cancel
 ├─ Providers\BiteshipProvider.php       adapter ke-1 (dibangun DULU)
 ├─ Providers\KiriminAjaProvider.php     adapter ke-2 (Fase 6)
 ├─ ShippingManager.php                  resolver kurir→provider, gabung+dedup ongkir
 └─ Services\ShippingWebhookService.php  idempotent
```

### Keputusan desain
- Cakupan **full** (ongkir + booking + resi + tracking webhook). Abstraksi + 1 provider dulu.
- **Booking + resi HANYA di Surat Jalan** (single point; bisa lahir dari SO/INV otomatis + Garansi, seragam). SO/INV pilih kurir + estimasi ongkir; ongkir ditetapkan di SO, mengalir ke Surat Jalan + Invoice. PO reuse sistem, tanpa booking terpisah.
- **`delivery_method`** enum `kurir` / `ambil_toko`.
- **Diskon ongkir = pengurang pendapatan ongkir (contra-revenue)**.
- **Kurir mutual-exclusive per provider via UI config**.
- **Settings → sub-menu "Integrasi"**: Midtrans (dipindah) + Biteship + Kirimin Aja; rencana lanjut Jubelio + WooCommerce.
- **Menu "Cek Ongkir" standalone di Sales**.
- **Berat WAJIB** produk shippable; estimasi dari produk, override paket aktual di Surat Jalan; volumetric diurus aggregator.

### Roadmap fase
- **Fase 0** (tanpa kredensial): alamat terstruktur customer (`province/city/district/postal_code/recipient_phone` + `biteship_area_id`, `kiriminaja_subdistrict_id`); origin di `business_profiles`; produk `weight_gram/length_cm/width_cm/height_cm` (berat wajib); refactor Settings → hub Integrasi (pindah Midtrans) + entri `config/menu_permissions.php`.
- **Fase 1**: `ShippingProvider` + `BiteshipProvider` (rates); `shipping_settings` (per-provider singleton); `shipping_couriers` (katalog mutual-exclusive); `ShippingManager::rates()`; UI map area; menu Cek Ongkir.
- **Fase 2**: field `delivery_method`/`courier_code`/`service_code`/`shipping_cost`/`shipping_discount` di SO & INV; cek ongkir embedded; mengalir ke Surat Jalan.
- **Fase 3**: kolom Surat Jalan (`shipping_provider`/`provider_order_id`/`service_code`/`shipping_cost`/`shipping_status`/`raw_response` + paket aktual berat/dimensi override); `createOrder()` → resi otomatis.
- **Fase 4**: webhook tracking (signature-verify middleware ala `VerifyMidtransSignature`) + status internal.
- **Fase 5**: sambung `shipping_cost` ke flow titipan ongkir (`FreightSetting` + akun 1203) + akun diskon ongkir (contra-revenue).
- **Fase 6**: `KiriminAjaProvider` adapter ke-2 + routing (JNT Cargo dll).

### Kredensial
- Fase 0: tidak butuh. Fase 1+: API key sandbox Biteship. Fase 6: API key Kirimin Aja.

---

## Urutan eksekusi
1. **Bagian A** (partial delivery + deferred COGS) — mandiri, nilai operasional langsung.
2. **Fase 0** (fondasi data + hub Integrasi) — mandiri.
3. **Fase 1–6** berurutan begitu kredensial Biteship siap.
