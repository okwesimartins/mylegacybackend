<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Sendorderreceipt extends Mailable
{   
    use Queueable, SerializesModels;
    
    public $title;
    public $admins_info;
    public $transaction_details;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($title,$admins_info,$transaction_details)
    {
        $this->title=$title;
        $this->admins_info=$admins_info;
        $this->transaction_details=$transaction_details;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->title)->view('orderreceipt');
    }
}
