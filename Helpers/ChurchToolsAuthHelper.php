<?php

namespace Modules\ChurchToolsAuth\Helpers;

use Illuminate\Support\Facades\DB;
use App\User;

use \CTApi\CTConfig;
use \CTApi\CTSession;

global $CHURCHTOOLSAUTH_SESSION;

class ChurchToolsAuthHelper
{

    public static function connectChurchToolsDefault($forceReconnect = false, $clearCache = false) {
        
        $url = \Option::get('churchtoolsauth_url');
        $token = \Helper::decrypt(\Option::get('churchtoolsauth_logintoken'));
        
        if ( empty($url) or empty($token) ) {
            return false;
        }

        return self::connectChurchTools($url, $token, '', $forceReconnect, $clearCache );

    }

    public static function connectChurchToolsSearch() {
        
        $url = \Option::get('churchtoolsauth_url');
        $token = \Helper::decrypt(\Option::get('churchtoolsauth_logintoken'));
        
        if ( empty($url) or empty($token) ) {
            return false;
        }

        return self::connectChurchTools($url, $token, 'search', false, true);

    }

    public static function connectChurchTools($url = '', $token = '', $session = '', $forceReconnect = false, $clearCache = false) {

        global $CHURCHTOOLSAUTH_SESSION;

        if ( empty($url) or empty($token) ) {
            return false;
        }

        $isValid = false;

        if ( $forceReconnect ) {
            $isValid = false;
        }  else {
            try {
                $isValid = CTConfig::validateAuthentication();
            } catch ( \Exception $e ) {
                $isValid = false;
            }
        }

        if ( $session != $CHURCHTOOLSAUTH_SESSION ) {
            $isValid = false;
        }        
        
        if ( $isValid ) {
            
            if ( $clearCache ) {
                CTConfig::clearCookies();
                CTConfig::clearConfig();
                CTConfig::clearCache();
            }

            return true;
        } else {

            try {

                CTSession::switchSession($session);
                $CHURCHTOOLSAUTH_SESSION = $session;
                CTConfig::clearCookies();
                CTConfig::clearConfig();
                CTConfig::clearCache();
                
                CTConfig::enableCache();
                CTConfig::setApiUrl($url);
                $success = CTConfig::authWithLoginToken($token);
                $isValid = CTConfig::validateAuthentication();

                return $isValid;
    
            } catch ( \Exception $e ) {
                return false;
            }

        }

    }

}