<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo simple del sistema: asunto, texto y, si hay, un enlace a la aplicación. */
class AppMail extends Mailable
{
    public function __construct(private readonly string $mailSubject, private readonly string $text, private readonly ?string $url, private readonly string $fromAddress, private readonly ?string $fromName) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: new Address($this->fromAddress, $this->fromName ?? ''), subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body(), text: 'mail.plain', with: ['text' => $this->text, 'url' => $this->url]);
    }

    private function body(): string
    {
        $paragraphs = collect(preg_split("/\n{2,}/", trim($this->text)) ?: [])->map(fn (string $p) => '<p style="margin:0 0 12px;line-height:1.5">'.nl2br(e($p)).'</p>')->implode('');
        $button = $this->url ? '<p style="margin:20px 0"><a href="'.e($this->url).'" style="background:#1e3a8a;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Abrir en la aplicación</a></p>' : '';

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#1f2937">'.$paragraphs.$button.'<p style="color:#6b7280;font-size:12px;margin-top:24px">Este correo lo envió automáticamente JGE Inteligencia Comercial.</p></div>';
    }
}
