<?php

namespace App\Modules\Ticketing\Services;

use Illuminate\Mail\Mailable;

/** One shop mail: prebuilt HTML plus optional attachments (the e-ticket PDF). */
class ShopMail extends Mailable
{
    /** @param list<array{nama:string,mime:string,isi:string}> $files */
    public function __construct(string $subject, public string $body, public array $files = [])
    {
        $this->subject($subject);
    }

    public function build(): static
    {
        $this->from((string) config('laksamana.ticketing.smtp_user'), (string) config('laksamana.ticketing.smtp_from_name', 'Laksamana Muda'))
            ->html($this->body);
        foreach ($this->files as $f) {
            $this->attachData($f['isi'], preg_replace('/[^A-Za-z0-9._-]/', '', $f['nama']), ['mime' => $f['mime']]);
        }

        return $this;
    }
}
