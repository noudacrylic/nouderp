<?php

namespace App\Modules\CRM\Observers;

use App\Modules\CRM\Models\CrmConversation;
use App\Modules\CRM\Models\CrmMessage;
use App\Modules\CRM\Services\LabelPesananService;
use Illuminate\Database\Eloquent\Model;

/**
 * Tautan pesanan ↔ chat yang lahir dari sisi CHAT (pelengkap
 * CrmLabelPesananObserver yang bekerja dari sisi pesanan).
 *
 * - Chat baru dikenali sebagai pelanggan → pesanan terbuka pelanggan itu yang
 *   belum tertaut ikut ditautkan (pembeli web yang checkout dulu, baru chat).
 * - Pesan masuk menyebut nomor pesanan web → SO itu ditautkan ke chat ini
 *   (pembeli yang mengetik nomor HP lain di checkout).
 *
 * Diamati lewat model, bukan dipanggil dari webhook, supaya jalur resmi dan
 * cermin WAHA tercakup tanpa salinan kode. Catatan: update massal di
 * CrmCustomerObserver tidak memicu event model — tidak masalah, di jalur itu
 * pelanggan disimpan SEBELUM SO-nya dibuat, jadi tautkanOtomatis() sudah
 * menemukan chat-nya.
 */
class CrmTautanPesananObserver
{
    public function saved(Model $model): void
    {
        try {
            if ($model instanceof CrmConversation) {
                $dikenali = $model->customer_id
                    && ($model->wasRecentlyCreated || ($model->wasChanged('customer_id') && ! $model->getOriginal('customer_id')));

                if ($dikenali) {
                    app(LabelPesananService::class)->tautkanPesananTerbuka($model);
                }

                return;
            }

            if ($model instanceof CrmMessage && $model->wasRecentlyCreated && $model->direction === CrmMessage::MASUK) {
                $chat = CrmConversation::find($model->conversation_id);

                if ($chat) {
                    app(LabelPesananService::class)->tautkanDariTeks($chat, $model->content);
                }
            }
        } catch (\Throwable $e) {
            // Gagal menaut tak boleh menggagalkan penyimpanan pesan/chat.
            report($e);
        }
    }
}
