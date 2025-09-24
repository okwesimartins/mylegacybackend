<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\Sendordermessage;
class Sendorderalert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
     public $title;
    public $admins_info;
    public $transaction_details;
     public function __construct($title,$admins_info,$transaction_details)
    {
        $this->title=$title;
        $this->admins_info=$admins_info;
        $this->transaction_details=$transaction_details;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $email = new Sendordermessage($this->title,$this->admins_info,$this->transaction_details);
        Mail::to($this->admins_info['email'])->send($email);
    }
}
