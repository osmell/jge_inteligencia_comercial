<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Respuestas rápidas del chat: personales o compartidas por supervisión. */
class QuickReplies
{
    /**
     * Activas: las propias y las compartidas.
     *
     * @return Collection<int, \stdClass>
     */
    public function visibleTo(User $user): Collection
    {
        return DB::table('quick_replies as q')->join('users as u', 'u.id', '=', 'q.user_id')->where('q.is_active', true)
            ->where(fn ($w) => $w->where('q.user_id', $user->id)->orWhere('q.shared', true))
            ->orderBy('q.title')->get(['q.id', 'q.title', 'q.body', 'q.shared', 'q.user_id', 'u.name as author']);
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $owner, array $data): int
    {
        return (int) DB::table('quick_replies')->insertGetId($data + ['user_id' => $owner->id, 'is_active' => true, 'created_at' => now()]);
    }

    public function exists(User $owner, int $id): bool
    {
        return DB::table('quick_replies')->where(['id' => $id, 'user_id' => $owner->id])->exists();
    }

    /**
     * Solo la persona dueña puede cambiarla; devuelve false si no existe o no es suya.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $owner, int $id, array $data): bool
    {
        if (! DB::table('quick_replies')->where(['id' => $id, 'user_id' => $owner->id])->exists()) {
            return false;
        }
        DB::table('quick_replies')->where('id', $id)->update($data + ['updated_at' => now()]);

        return true;
    }
}
