<?php

namespace App\Console\Commands;

use App\Models\R2Setting;
use App\Models\StoreArticle;
use App\Models\StoreProductMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Pindahkan media Produk Store (+cover artikel) yang masih tersimpan di disk
 * LOKAL ke Cloudflare R2, lalu tulis ulang URL-nya ke domain publik R2
 * (galeri.noudakrilik.com). Idempoten: baris yang URL-nya sudah menunjuk R2
 * dilewati. Jalankan setelah kredensial R2 diisi & aktif di Settings.
 *
 *   php artisan store:migrate-media-to-r2 --dry-run
 *   php artisan store:migrate-media-to-r2
 */
class MigrateStoreMediaToR2 extends Command
{
    protected $signature = 'store:migrate-media-to-r2 {--dry-run : Tampilkan rencana tanpa mengubah apa pun}';

    protected $description = 'Migrasikan media Produk Store dari disk lokal ke Cloudflare R2';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $set = R2Setting::current();
        if (!$set || !$set->isConfigured()) {
            $this->error('R2 belum terkonfigurasi/aktif. Isi & aktifkan di Settings → Integrasi → Cloudflare R2.');
            return self::FAILURE;
        }

        $r2         = Storage::build($set->diskConfig());
        $local      = Storage::disk(config('store.local_disk', 'public'));
        $publicBase = rtrim((string) $set->public_url, '/');

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Target R2: {$publicBase}");

        $migrated = 0;
        $skipped  = 0;
        $missing  = 0;

        // ── Media produk (foto & video ber-r2_key; youtube dilewati otomatis) ──
        StoreProductMedia::withTrashed()
            ->whereNotNull('r2_key')
            ->where('source', '!=', 'youtube')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($r2, $local, $publicBase, $dry, &$migrated, &$skipped, &$missing) {
                foreach ($rows as $m) {
                    $key = (string) $m->r2_key;

                    if ($publicBase !== '' && str_starts_with((string) $m->url, $publicBase)) {
                        $skipped++;
                        continue;
                    }
                    if (!$local->exists($key)) {
                        $this->warn("  ✗ file lokal hilang: {$key} (media#{$m->id})");
                        $missing++;
                        continue;
                    }

                    if (!$dry) {
                        $stream = $local->readStream($key);
                        $r2->writeStream($key, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        $m->url = $r2->url($key);
                        $m->save();
                    }

                    $this->line("  ✓ media#{$m->id}  {$key}");
                    $migrated++;
                }
            });

        // ── Cover artikel blog (opsional, bila kolom ada) ──
        if (Schema::hasColumn('store_articles', 'cover_key')) {
            StoreArticle::query()
                ->whereNotNull('cover_key')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($r2, $local, $publicBase, $dry, &$migrated, &$skipped, &$missing) {
                    foreach ($rows as $a) {
                        $key = (string) $a->cover_key;

                        if ($publicBase !== '' && str_starts_with((string) $a->cover_url, $publicBase)) {
                            $skipped++;
                            continue;
                        }
                        if (!$local->exists($key)) {
                            $this->warn("  ✗ cover lokal hilang: {$key} (artikel#{$a->id})");
                            $missing++;
                            continue;
                        }

                        if (!$dry) {
                            $stream = $local->readStream($key);
                            $r2->writeStream($key, $stream);
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                            $a->cover_url = $r2->url($key);
                            $a->save();
                        }

                        $this->line("  ✓ artikel#{$a->id}  {$key}");
                        $migrated++;
                    }
                });
        }

        $this->newLine();
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Selesai. Dimigrasi: {$migrated} · Dilewati (sudah di R2): {$skipped} · File hilang: {$missing}");

        if ($dry) {
            $this->comment('Jalankan tanpa --dry-run untuk benar-benar memindahkan.');
        }

        return self::SUCCESS;
    }
}
