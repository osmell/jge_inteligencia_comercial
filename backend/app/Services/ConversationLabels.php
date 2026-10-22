<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\DB;

/** Etiquetas de una conversación; los cambios quedan en Auditoría. */
class ConversationLabels
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Deja exactamente estas etiquetas.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public function sync(User $actor, WhatsappConversation $conversation, array $labels): array
    {
        $new = array_values(array_unique($labels));
        DB::transaction(function () use ($actor, $conversation, $new) {
            $old = DB::table('conversation_labels')->where('conversation_id', $conversation->id)->pluck('label')->all();
            DB::table('conversation_labels')->where('conversation_id', $conversation->id)->whereNotIn('label', $new)->delete();
            foreach (array_diff($new, $old) as $label) {
                DB::table('conversation_labels')->insert(['conversation_id' => $conversation->id, 'label' => $label, 'user_id' => $actor->id, 'created_at' => now()]);
            }
            if (array_diff($new, $old) !== [] || array_diff($old, $new) !== []) {
                $this->audit->record('conversations.labels_changed', $actor, auditable: $conversation, old: ['labels' => $old], new: ['labels' => $new], companyId: $conversation->company_id);
            }
        });
        ConversationAssignment::refreshLists($conversation);

        return $new;
    }
}
