<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Authcontroller;

use App\Http\Controllers\GoogleAuthController;

use App\Http\Controllers\AffirmationController;

use App\Http\Controllers\JournalController;

use App\Http\Controllers\CronController;

use App\Http\Controllers\MetaDropdownController;

use App\Http\Controllers\JournalNextOfKinController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


//user auth
Route::post('register_user',[Authcontroller::class, 'register']);

Route::post('login_user',[Authcontroller::class, 'login']);


Route::post('/auth/google', [GoogleAuthController::class, 'verifyIdToken']);

//send otp
Route::post('request_otp', [Authcontroller::class, 'requestOTP']);

//verify otp
Route::post('verify_otp', [Authcontroller::class, 'verifyOTP']);

//update password
Route::post('reset_password', [Authcontroller::class, 'resetForgottenPassword']);

Route::get('getuser_for_ai', [Authcontroller::class, 'getuserinfoForai']);


//Route::group(['middleware'=>['auth.role:admins']], function(){
    //  Route::get('get_text',[Authcontroller::class, 'text']);
    
   


//});

Route::group(['middleware'=>['auth.customer']], function(){
    Route::get('/affirmations/categories', [AffirmationController::class, 'listCategories']);
    Route::post('/affirmations/prefs', [AffirmationController::class, 'saveUserPrefs']);
    Route::post('/devices/token', [AffirmationController::class, 'saveDeviceToken']);
    Route::post('reset_password_from_dashboard', [Authcontroller::class, 'update_password_from_dashboard']);
    Route::get('getusers_profile', [Authcontroller::class, 'getusersProfile']);
    Route::get('getnotifications', [Authcontroller::class, 'getnotifications']);

    Route::get('/journal_template', [JournalController::class, 'getJournaltemplate']);

    Route::post('/journals', [JournalController::class, 'saveJournal']);
    
    Route::get('/journals', [JournalController::class, 'getJournals']);
    
    Route::post('delete/journals', [JournalController::class, 'deleteJournal']);
    
    // Entries
    Route::post('/journals/entry', [JournalController::class, 'saveJournalEntry']);
    Route::get('/journals/{journalId}/entries', [JournalController::class, 'getJournalEntries']);
    
    //Delete journal entries 
    Route::post('delete/journals/entry', [JournalController::class, 'deleteJournalEntry']);
    
    // Attachments streaming
    Route::get('/journals/attachment/{attachmentId}', [JournalController::class, 'streamAttachment']);
    // Generate & schedule for the current user (on-demand)
    

    //next of kin
    Route::post('/createnok', [JournalNextOfKinController::class,'createnexofkin']);
    Route::get('/nok', [JournalNextOfKinController::class,'getnexofkin']);
    Route::post('/updatenok/{id}', [JournalNextOfKinController::class,'updatenexofkin']);
    
    //meta 
    Route::get('/meta/relationships', [MetaDropdownController::class,'listRelationships']);

    Route::get('/meta/triggers', [MetaDropdownController::class,'listTriggers']);
    
    Route::post('/me/last-active', [Authcontroller::class, 'touchLastActive']);
    //Route::post('/affirmations/generate-and-schedule', [AffirmationController::class, 'generateAndScheduleForUser']);
});

Route::get('/cron/nok-dispatch', [CronController::class, 'dispatchNoKInvites']);

// CRON (public or protect with a secret query param)
Route::get('/cron/affirmations/generate-today', [AffirmationController::class, 'cronGenerateToday']);
Route::get('/cron/affirmations/dispatch-due', [AffirmationController::class, 'cronDispatchDue']);


