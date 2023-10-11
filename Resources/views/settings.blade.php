<form class="form-horizontal margin-top" method="POST" action="" id="churchtoolsauth_form">
    {{ csrf_field() }}

    {{--<div class="descr-block">
        <p>{{ __('These settings are used to connect to the ChurchTools API.'') }}</p>
    </div>--}}

    <h3 class="subheader">{{ __('Connection to ChurchTools') }}</h3>

    <div class="form-group{{ $errors->has('settings.churchtoolsauth_url') ? ' has-error' : '' }}">
        <label for="churchtoolsauth_url" class="col-sm-2 control-label">{{ __('ChurchTools URL') }}</label>

        <div class="col-sm-6">
            <input id="churchtoolsauth_url" type="text" class="form-control input-sized" name="settings[churchtoolsauth_url]" value="{{ old('settings.churchtoolsauth_url', $settings['churchtoolsauth_url']) }}" required autofocus>
            <p class="help-block">{{ __('Example: https://mychurch.church.tools') }}</p>
            @include('partials/field_error', ['field'=>'settings.churchtoolsauth_url'])
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.churchtoolsauth_logintoken') ? ' has-error' : '' }}">
        <label for="churchtoolsauth_logintoken" class="col-sm-2 control-label">{{ __('Login Token') }}</label>

        <div class="col-sm-6">
            <input id="churchtoolsauth_logintoken" type="password" class="form-control input-sized" name="settings[churchtoolsauth_logintoken]" value="{{ old('settings.churchtoolsauth_logintoken', $settings['churchtoolsauth_logintoken']) }}" required autofocus>
            <p class="help-block">{{ __('Login token of the API user to retrieve the information from ChurchTools (Permissions for viewing people and groups are required)') }}<br><a target="_blank" href="https://hilfe.church.tools/wiki/0/API%20Authentifizierung?search=Token">{{ __('Learn more about login tokens') }}</a></p>
            @include('partials/field_error', ['field'=>'settings.churchtoolsauth_logintoken'])
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-default" id="churchtoolsauth_connect" data-loading-text="{{ __('Connecting') }}…">
                {{ __('Check connection') }}
            </button>
        </div>
    </div>

    <h3 class="subheader">{{ __('User synchronization') }}</h3>

    <div class="form-group{{ $errors->has('settings.churchtoolsauth_admins') ? ' has-error' : '' }}">
        <label for="churchtoolsauth_admins" class="col-sm-2 control-label">{{ __('Admin(s)') }}</label>

        <div class="col-sm-6">
            <select id="churchtoolsauth_admins" class="form-control input-sized-lg" name="settings[churchtoolsauth_admins][]" multiple>
                <option value="">{{ __('Select person(s)') }}</option>
                @foreach ($settings['churchtoolsauth_admins_list'] as $admin)
                    <option value="{{ $admin['id'] }}" selected>{{ $admin['text'] }}</option>
                @endforeach
            </select>
            <p class="help-block">{{ __('Person(s) for which admin permissions should be applied') }}</p>
            @include('partials/field_error', ['field'=>'settings.churchtoolsauth_admins'])
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings.churchtoolsauth_schedule') ? ' has-error' : '' }}">
        <label for="churchtoolsauth_schedule" class="col-sm-2 control-label">{{ __('Schedule') }}</label>

        <div class="col-sm-6">
            <input id="churchtoolsauth_schedule" type="text" class="form-control input-sized" name="settings[churchtoolsauth_schedule]" value="{{ old('settings.churchtoolsauth_schedule', $settings['churchtoolsauth_schedule']) }}" autofocus>
            <p class="help-block">{{ __('Cron expression for automatic execution of synchronization') }}<br>{{ __('Example for execution every 15 minutes') }}: <code>*/15 * * * *</code><br><a target="_blank" href="https://en.wikipedia.org/wiki/Cron">{{ __('Learn more about cron expressions') }}</a></p>
            @include('partials/field_error', ['field'=>'settings.churchtoolsauth_schedule'])
        </div>
    </div>

    <div class="form-group">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-default" id="churchtoolsauth_sync" data-loading-text="{{ __('Syncing') }}…">
                {{ __('Execute synchronization') }}
            </button>
            <p class="help-block">{{ __('Please note that the (initial) synchronization may take some time, depending on the number of people/groups. If the synchronization is performed manually here, this can lead to an error if the values for the timeout are set too low (web server/PHP). In this case wait for the automatic synchronization, which is executed by the cron job. Details can be found in the log.') }}</p>
        </div>
    </div>
    
    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>

@section('javascript')
    @parent

    var text_inputTooShort = '{{ __('Enter more characters to search') }}';
    var text_searching = '{{ __('Searching') }}';
    var text_noResults = '{{ __('No results found') }}';

    initChurchToolsAuthSettings();
@endsection