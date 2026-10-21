<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Services\MailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Configuración del servidor de correo y registro de lo enviado. La contraseña se recibe pero nunca se devuelve. */
class MailController extends Controller
{
    public function __construct(private readonly MailService $mail) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->mail->describe()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'host' => ['nullable', 'string', 'max:255'], 'port' => ['required', 'integer', 'min:1', 'max:65535'], 'encryption' => ['required', Rule::in(MailSetting::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'], 'password' => ['nullable', 'string', 'max:500'], 'from_address' => ['nullable', 'email', 'max:255'], 'from_name' => ['nullable', 'string', 'max:255'],
        ]);
        $this->mail->save($request->user(), $data);

        return $this->show();
    }

    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:255']]);
        $log = $this->mail->sendTest($request->user(), $data['to']);

        return response()->json(['data' => $this->present($log)], 201);
    }

    public function log(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in([MailLog::PENDING, MailLog::SENDING, MailLog::SENT, MailLog::FAILED, MailLog::NOT_SENT])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = MailLog::query()->when(! empty($data['status']), fn (Builder $q) => $q->where('status', $data['status']))->orderByDesc('created_at')->orderByDesc('id')->offset(($page - 1) * 25)->limit(26)->get();

        return response()->json(['data' => $rows->take(25)->map(fn (MailLog $l) => $this->present($l))->values()->all(), 'meta' => ['page' => $page, 'has_more' => $rows->count() > 25]]);
    }

    /** @return array<string, mixed> */
    private function present(MailLog $l): array
    {
        return [
            'id' => $l->id, 'to' => $l->to_address, 'kind' => $l->kind, 'subject' => $l->subject, 'status' => $l->status, 'attempt' => $l->attempt, 'error' => $l->error,
            'created_at' => $l->created_at->toIso8601String(), 'next_attempt_at' => $l->next_attempt_at?->toIso8601String(), 'sent_at' => $l->sent_at?->toIso8601String(),
        ];
    }
}
