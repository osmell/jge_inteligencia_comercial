<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\WhatsappConversation;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Ventana de 24 h de WhatsApp: solo dentro de ella se puede escribir libremente. */
final class WhatsappConversationWindowTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-21 12:00:00');
    }

    private function conversation(?Carbon $lastInbound): WhatsappConversation
    {
        return new WhatsappConversation(['last_inbound_at' => $lastInbound]);
    }

    public function test_window_is_closed_without_inbound_messages(): void
    {
        $this->assertFalse($this->conversation(null)->windowOpen($this->now));
    }

    public function test_window_is_open_inside_24_hours(): void
    {
        $this->assertTrue($this->conversation($this->now->copy()->subMinutes(5))->windowOpen($this->now));
        $this->assertTrue($this->conversation($this->now->copy()->subHours(23)->subMinutes(59))->windowOpen($this->now));
    }

    public function test_window_closes_exactly_at_24_hours(): void
    {
        $this->assertFalse($this->conversation($this->now->copy()->subHours(24))->windowOpen($this->now));
        $this->assertFalse($this->conversation($this->now->copy()->subHours(30))->windowOpen($this->now));
    }

    public function test_window_defaults_to_the_current_time(): void
    {
        Carbon::setTestNow($this->now);
        try {
            $this->assertTrue($this->conversation($this->now->copy()->subHour())->windowOpen());
            $this->assertFalse($this->conversation($this->now->copy()->subDays(2))->windowOpen());
        } finally {
            Carbon::setTestNow();
        }
    }
}
