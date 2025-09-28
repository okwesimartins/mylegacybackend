<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Sendotp extends Mailable
{   
    use Queueable, SerializesModels;
    
    public $title;
    public $name;
    public $otp;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($name,$otp)
    {
        $this->title="OTP";
        $this->name=$name;
        $this->otp=$otp;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->title)->view('otp');
    }
}
