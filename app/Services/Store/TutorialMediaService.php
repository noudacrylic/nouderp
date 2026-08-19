<?php

namespace App\Services\Store;

/**
 * Gambar tutorial menumpang seluruh jalur unggah artikel — disk yang sama
 * (R2 bila aktif), penamaan versioned yang sama, pengecilan yang sama — dan
 * hanya berbeda folder.
 *
 * Dipisah per folder, bukan dicampur ke store/articles, supaya berkasnya bisa
 * ditelusuri & dibersihkan per jenis konten.
 */
class TutorialMediaService extends ArticleMediaService
{
    protected function dir(): string
    {
        return trim(config('store.tutorial_path', 'store/tutorials'), '/');
    }

    protected function baseName(): string
    {
        return 'tutorial';
    }
}
