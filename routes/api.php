<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Authcontroller;

use App\Http\Controllers\GoogleAuthController;



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

//Route::group(['middleware'=>['auth.role:admins']], function(){
    //  Route::get('get_text',[Authcontroller::class, 'text']);
    


//});


