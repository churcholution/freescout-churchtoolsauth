<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\ChurchToolsAuth\Http\Controllers'], function()
{
    Route::post('/app-settings/churchtoolsauth/ajax', ['uses' => 'ChurchToolsAuthController@ajax', 'laroute' => true, 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('churchtoolsauth.ajax');
    Route::get('/app-settings/churchtoolsauth/ajax-search', ['uses' => 'ChurchToolsAuthController@ajaxSearch', 'laroute' => true, 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('churchtoolsauth.ajax_search');
});

// Per mailbox settings
Route::group(['middleware' =>  ['web', 'auth', 'roles'], 'roles' => ['admin'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\ChurchToolsAuth\Http\Controllers'], function()
{
    Route::get('/mailbox/{mailbox_id}/churchtoolsauth', ['uses' => 'ChurchToolsAuthController@settings'])->name('mailboxes.churchtoolsauth.settings');
    Route::post('/mailbox/{mailbox_id}/churchtoolsauth', ['uses' => 'ChurchToolsAuthController@settingsSave']);
});