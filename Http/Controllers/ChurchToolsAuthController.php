<?php

namespace Modules\ChurchToolsAuth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use App\Mailbox;
use App\User;

use Modules\ChurchToolsAuth\Libraries\ChurchTools\ChurchToolsClient;
use Modules\ChurchToolsAuth\Helpers\ChurchToolsAuthHelper;

class ChurchToolsAuthController extends Controller
{

    /**
     * Ajax.
     */
    public function ajax(Request $request)
    {

        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $settings = $request->settings;

        switch ( $request->action ) {
            case 'connect':

                $isValid = false;
                $error = '';

                //Connect to ChurchTools
                $CT = New ChurchToolsClient();
                $CT->setUrl($settings['churchtoolsauth_url']);

                if ( $CT->authWithLoginToken($settings['churchtoolsauth_logintoken']) ) {

                    \Option::set('churchtoolsauth_url', $settings['churchtoolsauth_url']);
                    \Option::set('churchtoolsauth_logintoken', encrypt($settings['churchtoolsauth_logintoken']));

                    $whoami = $CT->getData('/whoami');
                    $whoamiId = $whoami['id'] ?? 0;
                    $whoamiUsername = $whoami['cmsUserId'] ?? '';

                    $response['msg_success'] = __('ChurchTools sucessfully connected as: :username (#:id)', ['id' => $whoamiId, 'username' => $whoamiUsername]);
                    $response['status'] = 'success';

                } else {
                    $response['msg'] = __('ChurchTools connection failed');
                }
                
                break;

            case 'sync':

                \Artisan::call('freescout:churchtoolsauth-sync');
                $message = \Artisan::output();
                $message = str_replace(PHP_EOL, '', $message);

                if ( $message == 'Success' ) {
                    $response['msg_success'] = __('Synchronization successful');
                    $response['status'] = 'success';
                } else {
                    $response['msg'] = __('The synchronization could not be executed.');
                }

                break;

            default:
                $response['msg'] = __('Unknown action');
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = __('An unknown error has occurred.');
        }

        return \Response::json($response);

    }

    /**
     * Ajax search.
     */
    public function ajaxSearch(Request $request)
    {

        $response = [
            'results'    => [],
            'pagination' => ['more' => false],
        ];

        switch ( $request->domain ) {

            case 'person':

                $CT = ChurchToolsAuthHelper::getChurchToolsInstance();

                if ( $CT !== null ) {

                    $results = $CT->getData('/search', ['domainTypes'=>['person'], 'query' => $request->q]);

                    if ( ! empty($results) and is_array($results) ) {
                        foreach ( $results as $result ) {
                            $domainIdentifier = $result['domainIdentifier'] ?? 0;
                            $title = $result['title'] ?? '';
                            $response['results'][] = array('id' => $domainIdentifier, 'text' => $title . ' (#' . $domainIdentifier . ')');
                        }
                    }

                }

                break;

            case 'group_role':

                $CT = ChurchToolsAuthHelper::getChurchToolsInstance();

                if ( $CT !== null ) {

                    $results = $CT->getData('/search', ['domainTypes'=>['group'], 'query' => $request->q]);

                    if ( ! empty($results) and is_array($results) ) {
                        foreach ( $results as $result ) {
                            $domainIdentifier = $result['domainIdentifier'] ?? 0;
                            $title = $result['title'] ?? '';
                            
                            $optgroup = array('text' => $title, 'children' => array(array('id' => $domainIdentifier . '_0', 'text' => $title . ' (#' . $domainIdentifier . '): ' . __('All roles'))));
                            $roles = $CT->getData('/groups/' . $domainIdentifier . '/roles');
                            if ( ! empty($roles) and is_array($roles) ) {
                                foreach ( $roles as $role ) {
                                    $groupTypeRoleId = $role['groupTypeRoleId'] ?? 0;
                                    $name = $role['name'] ?? '';
                                    $optgroup['children'][] = array('id' => $domainIdentifier . '_' . $groupTypeRoleId, 'text' => $title . ' (#' . $domainIdentifier . '): ' . $name);
                                }
                                $response['results'][] = $optgroup;
                            }

                        }
                    }

                }

                break;

            default:

                break;

        }

        return \Response::json($response);

    }

    /**
     * Settings per Mailbox
     */
    public function settings($mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);

        if (!auth()->user()->isAdmin()) {
            \Helper::denyAccess();
        }

        $settings = $mailbox->meta[CHURCHTOOLSAUTH_MODULE] ?? [];

        $grouproleslist = array();

        $CT = ChurchToolsAuthHelper::getChurchToolsInstance();

        if ( $CT !== null ) {

            if ( ! empty($settings['groups_roles']) and is_array($settings['groups_roles']) ) {
                foreach ( $settings['groups_roles'] as $group_role ) {

                    $item = explode('_', $group_role);
                    if ( count($item) != 2 ) {
                        continue;
                    }
                    $groupID = intval($item[0]);
                    $roleID = intval($item[1]);

                    $group = $CT->getData('/groups/' . $groupID);
                    $groupName = $group['name'] ?? '';

                    $roleName = '';
                    if ( $roleID == 0 ) {
                        $roleName = __('All roles');
                    } else {
                        $roles = $CT->getData('/groups/' . $groupID. '/roles');
                        if ( ! empty($roles) and is_array($roles) ) {
                            foreach ( $roles as $role ) {
                                $groupTypeRoleId = $role['groupTypeRoleId'] ?? 0;
                                $name = $role['name'] ?? '';
                                if ( $groupTypeRoleId == $roleID ) {
                                    $roleName = $name;
                                    break;
                                }
                            }
                        } 
                    }

                    $grouproleslist[] = array('id' => $group_role, 'text' => $groupName . ' (#' . $groupID . '): ' . $roleName);
                    
                }
            }

        }

        return view('churchtoolsauth::mailboxsettings', [
            'mailbox' => $mailbox,
            'settings' => $settings,
            'groups_roles_list' => $grouproleslist,
        ]);

    }

    /**
     * Settings per Mailbox save.
     */
    public function settingsSave(Request $request, $mailbox_id)
    {
        $mailbox = Mailbox::findOrFail($mailbox_id);

        $settings = $request->settings;

        $mailbox->setMetaParam(CHURCHTOOLSAUTH_MODULE, $settings);
        $mailbox->save();

        \Session::flash('flash_success_floating', __('Settings updated'));

        return redirect()->route('mailboxes.churchtoolsauth.settings', ['mailbox_id' => $mailbox_id]);
    }

    /**
     * Display a listing of the resource.
     * @return Response
     */
    public function index()
    {
        return view('churchtoolsauth::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Response
     */
    public function create()
    {
        return view('churchtoolsauth::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function store(Request $request)
    {
    }

    /**
     * Show the specified resource.
     * @return Response
     */
    public function show()
    {
        return view('churchtoolsauth::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @return Response
     */
    public function edit()
    {
        return view('churchtoolsauth::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param  Request $request
     * @return Response
     */
    public function update(Request $request)
    {
    }

    /**
     * Remove the specified resource from storage.
     * @return Response
     */
    public function destroy()
    {
    }

}