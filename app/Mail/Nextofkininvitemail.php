<?php
// app/Mail/NextOfKinInviteMail.php
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Nextofkininvitemail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $payload // name, owner_name, relationship, journal_meta[], deep_link, passkey, message
    ) {}

    public function build()
    {
        return $this->subject('A Special Memory Awaits You — My Legacy Journals')
            ->view('nok_invite') // create a blade from your screenshot style
            ->with($this->payload);
    }
}
