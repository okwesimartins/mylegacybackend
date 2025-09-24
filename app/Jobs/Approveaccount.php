<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\Accountapproval;
class Approveaccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
     public $title;
    public $admin_info;
    public $transaction_details;
     public function __construct($title,$admin_info,$transaction_details)
    {
        $this->title=$title;
        $this->admin_info=$admin_info;
        $this->transaction_details=$transaction_details;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $email = new Accountapproval($this->title,$this->admin_info,$this->transaction_details);
        Mail::to($this->admin_info['email'])->send($email);
    }
}
