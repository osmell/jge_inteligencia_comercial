<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Cada persona solo escucha sus propios canales; qué conversaciones le llegan lo decide el servidor al emitir (ConversationAccess).
Broadcast::channel('users.{id}.conversations', fn (User $user, int $id) => $user->id === $id && $user->is_active && $user->hasPermission('conversations.view'));
Broadcast::channel('users.{id}.notifications', fn (User $user, int $id) => $user->id === $id && $user->is_active);
