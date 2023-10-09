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

use \CTApi\CTConfig;
use \CTApi\CTSession;
use \CTApi\Models\Groups\Group\GroupRequest;
use \CTApi\Models\Groups\Person\PersonRequest;
use \CTApi\Models\Groups\GroupMember\GroupMemberRequest;
use \CTApi\Models\Common\Search\SearchRequest;

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
                if ( ChurchToolsAuthHelper::connectChurchTools($settings['churchtoolsauth_url'], $settings['churchtoolsauth_logintoken'], uniqid(), true) ) {

                    \Option::set('churchtoolsauth_url', $settings['churchtoolsauth_url']);
                    \Option::set('churchtoolsauth_logintoken', encrypt($settings['churchtoolsauth_logintoken']));

                    try {
                    
                        $groups = array();
                        $persons = array();

                        if ( ChurchToolsAuthHelper::connectChurchToolsDefault(true, true) ) {

                            $whoami = PersonRequest::whoami();

                            $response['msg_success'] = __('ChurchTools sucessfully connected as: :username (#:id)', ['id' => $whoami->getId(), 'username' => $whoami->getCmsUserId()]);
                            $response['status'] = 'success';

                        } else {
                            $response['msg'] = __('ChurchTools connection failed');
                        }

                    } catch ( \Exception $e ) {
                        $response['msg'] = $e->getMessage();
                    }

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

                if ( ChurchToolsAuthHelper::connectChurchToolsSearch() ) {

                    $results = SearchRequest::search($request->q)->whereDomainType('person')->get();

                    if ( ! empty($results) and is_array($results) ) {
                        foreach ( $results as $result ) {
                            $response['results'][] = array('id' => $result->getDomainIdentifier(), 'text' => $result->getTitle() . ' (#' . $result->getDomainIdentifier() . ')');
                        }
                    }

                }

                break;

            case 'group_role':

                if ( ChurchToolsAuthHelper::connectChurchToolsSearch() ) {

                    $results = SearchRequest::search($request->q)->whereDomainType('group')->get();

                    if ( ! empty($results) and is_array($results) ) {
                        foreach ( $results as $result ) {
                            $optgroup = array('text' => $result->getTitle(), 'children' => array(array('id' => $result->getDomainIdentifier() . '_0', 'text' => $result->getTitle() . ' (#' . $result->getDomainIdentifier() . '): ' . __('All roles'))));
                            $group = GroupRequest::find($result->getDomainIdentifier());
                            if ( ! empty($group) ) {
                                foreach ( $group->getRoles() as $role ) {
                                    $optgroup['children'][] = array('id' => $result->getDomainIdentifier() . '_' . $role->getGroupTypeRoleId(), 'text' => $result->getTitle() . ' (#' . $result->getDomainIdentifier() . '): ' . $role->getName());
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

        if ( ChurchToolsAuthHelper::connectChurchToolsDefault(true, true) ) {

            if ( ! empty($settings['groups_roles']) and is_array($settings['groups_roles']) ) {
                foreach ( $settings['groups_roles'] as $group_role ) {

                    $item = explode('_', $group_role);
                    if ( count($item) != 2 ) {
                        continue;
                    }
                    $groupID = intval($item[0]);
                    $roleID = intval($item[1]);

                    $group = GroupRequest::find($groupID);

                    $roleName = '';
                    if ( $roleID == 0 ) {
                        $roleName = __('All roles');
                    } else {
                        foreach ( $group->getRoles() as $role ) {
                            if ( $role->getGroupTypeRoleId() == $roleID ) {
                                $roleName = $role->getName();
                                break;
                            }
                        }
                    }

                    $grouproleslist[] = array('id' => $group_role, 'text' => $group->getName() . ' (#' . $groupID . '): ' . $roleName);
                    
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