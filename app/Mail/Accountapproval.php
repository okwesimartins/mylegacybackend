<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Accountapproval extends Mailable
{   
    use Queueable, SerializesModels;
    
    public $title;
    public $admin_info;
    public $transaction_details;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($title,$admin_info,$transaction_details)
    {
        $this->title=$title;
        $this->admin_info=$admin_info;
        $this->transaction_details=$transaction_details;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->title)->view('accountapproval');
    }
}
