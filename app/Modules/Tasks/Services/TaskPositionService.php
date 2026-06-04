<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;

class TaskPositionService
{
    /**
     * Pindahkan task ke kategori lain (atau reorder dalam kategori yang sama)
     * pada posisi tertentu. Reposisi sibling supaya position kontigu.
     */
    public function moveTask(Task $task, ?int $newCategoryId, int $newPosition): void
    {
        DB::transaction(function () use ($task, $newCategoryId, $newPosition) {
            $oldCategoryId = $task->category_id;
            $oldPosition   = (int) $task->position;

            // Same category & same position → no-op
            if ($oldCategoryId === $newCategoryId && $oldPosition === $newPosition) {
                return;
            }

            // Pindahkan ke posisi sangat akhir dulu (avoid unique conflict bila ada)
            $task->category_id = $newCategoryId;

            if ($oldCategoryId === $newCategoryId) {
                // Reorder dalam kategori yang sama
                if ($newPosition > $oldPosition) {
                    // turun: geser sibling antara (old+1..new) ke atas (-1)
                    Task::where('category_id', $oldCategoryId)
                        ->where('id', '!=', $task->id)
                        ->whereBetween('position', [$oldPosition + 1, $newPosition])
                        ->decrement('position');
                } else {
                    // naik: geser sibling antara (new..old-1) ke bawah (+1)
                    Task::where('category_id', $oldCategoryId)
                        ->where('id', '!=', $task->id)
                        ->whereBetween('position', [$newPosition, $oldPosition - 1])
                        ->increment('position');
                }
            } else {
                // Pindah kategori: rapatkan posisi di kategori lama (semua > oldPos turun 1)
                Task::where('category_id', $oldCategoryId)
                    ->where('id', '!=', $task->id)
                    ->where('position', '>', $oldPosition)
                    ->decrement('position');

                // Buka slot di kategori baru (semua >= newPos naik 1)
                Task::where('category_id', $newCategoryId)
                    ->where('id', '!=', $task->id)
                    ->where('position', '>=', $newPosition)
                    ->increment('position');
            }

            $task->position = $newPosition;
            $task->save();
        });
    }

    /**
     * Cari posisi terakhir + 1 untuk new task di kategori tertentu.
     */
    public function nextPosition(?int $categoryId): int
    {
        return ((int) Task::where('category_id', $categoryId)->max('position')) + 1;
    }
}
