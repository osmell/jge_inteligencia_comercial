<?php

return [
    // Adjunto de la cotización (PDF o foto): se guarda en el servidor.
    'max_attachment_kb' => (int) env('QUOTATION_MAX_ATTACHMENT_KB', 15360),
    'attachment_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
];
