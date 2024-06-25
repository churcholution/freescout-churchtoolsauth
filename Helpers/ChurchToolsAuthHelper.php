<?php

namespace Modules\ChurchToolsAuth\Helpers;

use Illuminate\Support\Facades\DB;
use App\User;

use Modules\ChurchToolsAuth\Libraries\ChurchTools\ChurchToolsClient;

global $CT_INSTANCE;

class ChurchToolsAuthHelper
{

    public static function getChurchToolsInstance() : ?ChurchToolsClient {

        global $CT_INSTANCE;

        if ( $CT_INSTANCE instanceof ChurchToolsClient ) {
            return $CT_INSTANCE;
        }

        $url = \Option::get('churchtoolsauth_url');
        $token = \Helper::decrypt(\Option::get('churchtoolsauth_logintoken'));

        if ( empty($url) or empty($token) ) {
            return null;
        }

        $CT_INSTANCE = New ChurchToolsClient();
        $CT_INSTANCE->setUrl($url);

        //Connect to ChurchTools
        if ( ! $CT_INSTANCE->authWithLoginToken($token) ) {
            $CT_INSTANCE = null;
        }

        return $CT_INSTANCE;

    }
    
}