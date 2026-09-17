<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PendingAuthorizationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $requestType,
        public string $requestNumber,
        public string $unitName,
        public string $actionUrl,
    ) {
    }

    public function build()
    {
        return $this->subject('Sistema UGDA - Solicitud pendiente de autorización')
            ->view('emails.pending_authorization');
    }
}
