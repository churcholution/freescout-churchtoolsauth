@extends('layouts.app')

@section('title_full', CHURCHTOOLSAUTH_LABEL . ' - ' . $mailbox->name)

@section('sidebar')
@include('partials/sidebar_menu_toggle')
@include('mailboxes/sidebar_menu')
@endsection

@section('content')

<div class="section-heading margin-bottom">
    {{ __('ChurchTools Auth') }}
</div>

<div class="col-xs-12">

    @include('partials/flash_messages')

    <form class="form-horizontal margin-bottom" method="POST" action="" autocomplete="off">
        {{ csrf_field() }}

        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Groups / Roles') }}</label>

            <div class="col-sm-6">
                <select id="churchtoolsauth_groups_roles" class="form-control input-sized-lg" name="settings[groups_roles][]" multiple>
                    <option value="">{{ __('Select group(s)') }}</option>    
                    @foreach ($groups_roles_list as $group_role)
                        <option value="{{ $group_role['id'] }}" selected>{{ $group_role['text'] }}</option>
                    @endforeach
                </select>
                <div class="form-help">{{ __('Members of at least one of the selected groups get access to the mailbox.') }}</div>
            </div>
        </div>

        <div class="form-group margin-top">
            <div class="col-sm-6 col-sm-offset-2">
                <button type="submit" class="btn btn-primary">
                    {{ __('Save') }}
                </button>
            </div>
        </div>
    </form>
</div>
@endsection

@section('javascript')
    @parent

    var text_inputTooShort = '{{ __('Enter more characters to search') }}';
    var text_searching = '{{ __('Searching') }}';
    var text_noResults = '{{ __('No results found') }}';

    initChurchToolsAuthMailSettings();
@endsection