<?php

namespace Modules\ChurchToolsAuth\Providers;

// It has to be included here to require vendor service providers in module.json
require_once __DIR__.'/../vendor/autoload.php';

require_once __DIR__.'/../Exceptions/Handler.php';
require_once __DIR__.'/../Helpers/ChurchToolsAuthHelper.php';

use App\User;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

use Modules\ChurchToolsAuth\Http\Controllers\ChurchToolsAuthController;
use Modules\ChurchToolsAuth\Libraries\ChurchTools\ChurchToolsClient;
use Modules\ChurchToolsAuth\Helpers\ChurchToolsAuthHelper;
use Modules\ChurchToolsAuth\Console\Sync;

define('CHURCHTOOLSAUTH_MODULE', 'churchtoolsauth');
define('CHURCHTOOLSAUTH_LABEL', 'ChurchTools Auth');

class ChurchToolsAuthServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->registerCommands();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();

    }

    /**
     * Module hooks.
     */
    public function hooks()
    {

        // Add module's JS file to the application layout.
        \Eventy::addFilter('javascripts', function($javascripts) {
            $javascripts[] = \Module::getPublicPath(CHURCHTOOLSAUTH_MODULE).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(CHURCHTOOLSAUTH_MODULE).'/js/module.js';
            //$javascripts[] = \Module::getPublicPath(CHURCHTOOLSAUTH_MODULE).'/public/select2/js/select2.min.js';
            return $javascripts;
        });

        // Add module's CSS file to the application layout.
        \Eventy::addFilter('stylesheets', function($stylesheets) {
            //$stylesheets[] = \Module::getPublicPath(CHURCHTOOLSAUTH_MODULE).'/select2/css/select2.min.css';
            return $stylesheets;
        });

        // Add item to settings sections.
        \Eventy::addFilter('settings.sections', function($sections) {
            $sections['churchtoolsauth'] = ['title' => CHURCHTOOLSAUTH_LABEL, 'icon' => 'log-in', 'order' => 400];
            return $sections;
        });

        // Section params
        \Eventy::addFilter('settings.section_params', function($params, $section) {

            if ($section != 'churchtoolsauth') {
                return $params;
            }

            return $params;
        }, 20, 2);

        // Section settings
        \Eventy::addFilter('settings.section_settings', function($settings, $section) {

            if (!$section) {
                $section = $settings;
            }
            if ($section != 'churchtoolsauth') {
                return $settings;
            }

            $settings = \Option::getOptions([
                'churchtoolsauth_url',
                'churchtoolsauth_logintoken',
                'churchtoolsauth_admins',
                'churchtoolsauth_schedule',
            ], [
                'churchtoolsauth_url' => '',
                'churchtoolsauth_logintoken' => '',
                'churchtoolsauth_admins' => array(),
                'churchtoolsauth_schedule' => '*/15 * * * *',
            ]);;

            $settings['churchtoolsauth_logintoken'] = \Helper::decrypt($settings['churchtoolsauth_logintoken']);

            $admins_list = array();

            $CT = ChurchToolsAuthHelper::getChurchToolsInstance();
            if ( $CT !== null ) {
                foreach ( $settings['churchtoolsauth_admins'] as $admin ) {
                    $person = $CT->getData('/persons/' . $admin);
                    if ( ! empty($person) and is_array($person) ) {
                        $personId = $person['id'] ?? 0;
                        $personFirstName = $person['firstName'] ?? '';
                        $personLastName = $person['lastName'] ?? '';
                        $admins_list[] = array('id' => $personId, 'text' => $personFirstName . ' ' . $personLastName . ' (#' . $personId . ')');
                    }
                }
            }

            $settings['churchtoolsauth_admins_list'] = $admins_list;

            return $settings;

        }, 20, 2);

        // Settings view name
        \Eventy::addFilter('settings.view', function($view, $section) {
            if ($section != 'churchtoolsauth') {
                return $view;
            } else {
                return 'churchtoolsauth::settings';
            }
        }, 20, 2);

        // Before saving settings
        \Eventy::addFilter('settings.before_save', function($request, $section, $settings) {
            if ($section != 'churchtoolsauth') {
                return $request;
            }

            $settings = $request->settings;
            $settings['churchtoolsauth_logintoken'] = encrypt($settings['churchtoolsauth_logintoken']);

            $request->merge([
                'settings' => $settings
            ]);

            return $request;
        }, 20, 3);

        // Add item to the mailbox menu
        \Eventy::addAction('mailboxes.settings.menu', function($mailbox) {
            if (auth()->user()->isAdmin()) {
                echo \View::make('churchtoolsauth::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 15);

        //Validates login credentials - only will be fired if E-Mail exists in user database
        \Eventy::addFilter('session_guard.validate_credentials', function($result, $user, $credentials) {
            
            if ($result) {
                return $result;
            }

            $url = \Option::get('churchtoolsauth_url');
            if ( empty($url) ) {
                return $result;
            }

            if ( empty($credentials['email']) or empty($credentials['password']) ) {
                return false;
            }

            $isValid = false;

            try {
                
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'User is trying to log in: ' . $credentials['email']);
                
                $CT = New ChurchToolsClient();
                $CT->setUrl($url);

                if ( $CT->authWithCredentials($credentials['email'], $credentials['password'], $_POST['totp'] ?? '') ) {
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'User logged in successfully: ' . $credentials['email']);
                    $personID = intval($CT->getUserId());
                    $isValid = Sync::syncUser($personID);
                }

            } catch ( \Exception $e ) {
                $isValid = false;
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'User login failed: ' . $credentials['email']) . ' (' . $e->getMessage() . ')';
            }

            return $isValid;

        }, 20, 3);

        //Disable integrated password reset feature
        \Eventy::addFilter('auth.password_reset_available', function() {

            $url = \Option::get('churchtoolsauth_url');
            if ( empty($url) ) {
                return true;
            } else {
                return false;
            }

        }, 20, 3);

        //Add a notice to the login form
        \Eventy::addAction('user.edit.before_first_name', function($callback) {
    
            if ( empty($callback->getAttribute('churchtools_id')) ) {
                return;
            }

            $url = \Option::get('churchtoolsauth_url');
            $url .= (substr($url, -1) != '/' ? '/' : '') . '?q=profile#/';

            ?>

                <p><?php echo __('This user profile is synchronized from ChurchTools. Please note that any changes made on this page may be overwritten again. Changes to the profile should be made directly in ChurchTools.'); ?><br><a target="_blank" href="<?php echo $url; ?>"><?php echo __('Go to the ChurchTools profile'); ?></a></p>
                
            <?php

        }, 20, 3);

        //Add a notice to the login form
        \Eventy::addAction('login_form.before', function($callback) {
    
            $url = \Option::get('churchtoolsauth_url');
            $url .= (substr($url, -1) != '/' ? '/' : '') . '?q=forgotpassword';

            ?>

                <div class="form-group">
                    <div class="col-md-4"></div> 
                    <div class="col-md-6">
                        <p><?php echo __('Use your login data for ChurchTools.'); ?> <a target="_blank" href="<?php echo $url; ?>"><?php echo __('Forgot your password?'); ?></a></p>
                    </div>
                </div>
                
            <?php

        }, 20, 3);

        //Add a TOTP field to the login form
        \Eventy::addAction('login_form.before_submit', function($callback) {
    
            ?>

                <div class="form-group">
                    <label for="totp" class="col-md-4 control-label"><?php echo __('TOTP'); ?></label>
                    <div class="col-md-6">
                        <input id="totp" type="number" class="form-control" name="totp" value="" inputmode="numeric" autocomplete="one-time-code">
                        <span class="help-block"><?php echo __('Code for two-factor authentication, if required'); ?></span>
                    </div>
                </div>

            <?php

        }, 20, 3);

        //Run sync on cron job
        \Eventy::addFilter('schedule', function($schedule) {

            $expression = \Option::get('churchtoolsauth_schedule');

            if ( ! empty($schedule) ) {
                $schedule->command('freescout:churchtoolsauth-sync')->cron($expression);
            }
            
            return $schedule;

        });

    }

    public static function getConfigureOptions()
    {
        return \Option::getOptions([
            'churchtoolsauth_url',
            'churchtoolsauth_logintoken',
            'churchtoolsauth_admins',
            'churchtoolsauth_schedule',
        ],[
            'churchtoolsauth_admins' => array(),
            'churchtoolsauth_schedule' => '*/15 * * * *',
        ]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {

        $this->registerTranslations();

        $this->app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            \App\ChurchToolsAuthExceptionHandler::class
        );

    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('churchtoolsauth.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'churchtoolsauth'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/churchtoolsauth');

        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath
        ],'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/churchtoolsauth';
        }, \Config::get('view.paths')), [$sourcePath]), 'churchtoolsauth');
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__ .'/../Resources/lang');
    }

    /**
     * Register an additional directory of factories.
     * @source https://github.com/sebastiaanluca/laravel-resource-flow/blob/develop/src/Modules/ModuleServiceProvider.php#L66
     */
    public function registerFactories()
    {
        if (! app()->environment('production')) {
            app(Factory::class)->load(__DIR__ . '/../Database/factories');
        }
    }

    /**
     * https://github.com/nWidart/laravel-modules/issues/626
     * https://github.com/nWidart/laravel-modules/issues/418#issuecomment-342887911
     * @return [type] [description]
     */
    public function registerCommands()
    {
        $this->commands([
            \Modules\ChurchToolsAuth\Console\Sync::class
        ]);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

}