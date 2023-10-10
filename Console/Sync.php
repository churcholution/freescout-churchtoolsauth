<?php

namespace Modules\ChurchToolsAuth\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use App\Mailbox;
use App\User;
use Exception;

use \CTApi\CTConfig;
use \CTApi\CTSession;
use \CTApi\Models\Groups\Group\GroupRequest;
use \CTApi\Models\Groups\Person\PersonRequest;
use \CTApi\Models\Groups\GroupMember\GroupMemberRequest;

use Modules\ChurchToolsAuth\Helpers\ChurchToolsAuthHelper;

class Sync extends Command
{
    /**
     * The signature of the console command.
     *
     * @var string
     */
    protected $signature = 'freescout:churchtoolsauth-sync';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Syncs ChurchTools persons with FreeScout users.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {

        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Start cron job for synchronization of all users');
        
        if ( self::sync() ) {
            $this->info('Success');
        } else {
            $this->info('Error');
        }

        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Cron job for synchronization of all users finished');

        return;
        
    }

    public static function sync() {

        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Start synchronization of all users');

        //Connect to ChurchTools
        if ( ! ChurchToolsAuthHelper::connectChurchToolsDefault(true, true) ) {
            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Cannot connect to ChurchTools');
            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Cancel synchronization');
            return false;
        }

        $loop = array();

        //Get all the persons from ChurchTools with a matching group assignment
        foreach ( self::getPersonList() as $person ) {
            $loop[] = array('id' => $person['id'], 'data' => $person);
        }

        //Also get all users from FreeScout with assigned ChurchTools ID
        foreach ( User::all() as $user) {

            $personID = $user->getAttribute('churchtools_id');

            if ( ! empty($personID) ) {
                $found = false;
                foreach ( $loop as $entry ) {
                    if ( $entry['id'] == $personID ) {
                        $found = true;
                        break;
                    }
                }
                if ( ! $found ) {
                    $loop[] = array('id' => $personID, 'data' => null);
                }
            }
            
        }

        //Sync all persons
        foreach ( $loop as $entry ) {
            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Preparing synchronization for ChurchTools person #' . $entry['id']);
            self::syncUser($entry['id'], $entry['data']);
        }

        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Synchronization of all users finished');

        return true;

    }

    public static function syncUser($personID, $data = null) {

        if ( empty($personID) ) {
            return false;
        }

        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Start synchronization for ChurchTools person #' . $personID);

        try {

            $user = User::where('churchtools_id', $personID)->first();
            if ( ! empty($user) ) {
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') found for ChurchTools person #' . $personID);
            } else {
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'No FreeScout user found for ChurchTools person #' . $personID);
            }

            if ( ! empty($data) and is_array($data) ) {
                $person = $data;
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'ChurchTools person #' . $personID . ' loaded for syncing');
            } else {
                if ( ChurchToolsAuthHelper::connectChurchToolsDefault(true, true) ) {
                    $person = self::getPersonList($personID);
                    if ( ! empty($person) and is_array($person) ) {
                        $person = $person[0];
                        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'ChurchTools person #' . $personID . ' loaded for syncing');
                    } else {
                        $person = null;
                        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'No ChurchTools person #' . $personID . ' found with required group assignment');
                    }
                } else {
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Cannot connect to ChurchTools');
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Cancel synchronization for ChurchTools person #' . $personID);
                    return false;
                }
            }

            if ( empty($user) and empty($person) ) { //user does not exists in FreeScout, as well as not in ChurchTools
                return false;
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Cancel synchronization');
            }

            if ( ! empty($user) and empty($person) ) { //user exists in FreeScout, but not in ChurchTools
                foreach (Mailbox::all() as $mailbox) {
                    $memberOfMailbox = $user->mailboxes()->where('mailbox_id', $mailbox->id)->exists();
                    if ( $memberOfMailbox ) {
                        $user->mailboxes()->detach($mailbox->id);
                        \Helper::log(CHURCHTOOLSAUTH_LABEL, 'FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') removed from mailbox #' . $mailbox->id . ' (' . $mailbox->name . ')');
                    }
                }
                if ( ! $user->isAdmin() ) { //set user to inactiv if it is not an admin
                    $user->status = User::STATUS_DISABLED;
                    $user->save();
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') disabled');
                } else {
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') cannot be disabled (admin)');
                }
            }

            if ( $person['admin'] ) {
                $role = User::ROLE_ADMIN;
            } else {
                $role = User::ROLE_USER;
            }

            if ( empty($user) and ! empty($person) ) { //user exists in ChurchTools, but not in FreeScout
                $userData = array(
                                'first_name' => $person['firstname'],
                                'last_name' => $person['lastname'],
                                'email' => $person['email'],
                                'password' => User::getDummyPassword(),
                                'role' => $role,
                                'status' => User::STATUS_ACTIVE,
                                'type' => User::TYPE_USER,
                                'timezone' => 'Europe/Berlin',
                                'time_format' => User::TIME_FORMAT_24,
                                'locale' => \Helper::getRealAppLocale(),
                            );
                $user = User::create($userData); //create new user in FreeScout
                if ( ! empty($user) ) {
                    $user->setAttribute('churchtools_id', $person['id']);
                    $user->save();
                    self::setUserAvatarFromChurchTools($user->id, $person['avatar']);
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'New FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') created');
                } else {
                    \Helper::log(CHURCHTOOLSAUTH_LABEL, 'New FreeScout user for ChurchTools person #' . $personID . ' cannot be created');
                }
            }

            if ( ! empty($user) and ! empty($person) ) { //user exists in FreeScout, as well as in ChurchTools
                $user->first_name = $person['firstname'];
                $user->last_name = $person['lastname'];
                $user->email = $person['email'];
                $user->role = $role;
                $user->status = User::STATUS_ACTIVE;
                $user->type = User::TYPE_USER;
                $user->timezone = 'Europe/Berlin';
                $user->time_format = User::TIME_FORMAT_24;
                $user->locale = \Helper::getRealAppLocale();
                $user->save();
                self::setUserAvatarFromChurchTools($user->id, $person['avatar']);
                \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Existing FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') updated');
            }

            if ( ! empty($user) ) {
                foreach (Mailbox::all() as $mailbox) {

                    $memberOfMailbox = $user->mailboxes()->where('mailbox_id', $mailbox->id)->exists();
                    if ( in_array($mailbox->id, $person['mailboxes']) ) {
                        if ( ! $memberOfMailbox ) {
                            $user->mailboxes()->attach($mailbox->id);
                            $user->syncPersonalFolders($mailbox->id);
                            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') attached to mailbox #' . $mailbox->id . ' (' . $mailbox->name . ')');
                        }
                    } else {
                        if ( $memberOfMailbox ) {
                            $user->mailboxes()->detach($mailbox->id);
                            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'FreeScout user #' . $user->id . ' (' . $user->getFullName() . ') removed from mailbox #' . $mailbox->id . ' (' . $mailbox->name . ')');
                        }
                    }

                }
            }

            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'Synchronization successful for ChurchTools Person #' . $personID);
            return true;

        } catch ( \Exception $e ) {
            \Helper::log(CHURCHTOOLSAUTH_LABEL, 'An error occurred while synchronizing ChurchTools person #' . $personID . ': ' . $e->getMessage());
            return false;
        }

    }

    /**
     * Get a person list from ChurchTools with group assignments and accesses to mailboxes that are required
     * Filter argument $personFiler: Array with persons to get (id or e-mail needed)
     */
    public static function getPersonList($personFilter = null) {

        $personlist = array();

        //Connect to ChurchTools
        if ( ! ChurchToolsAuthHelper::connectChurchToolsDefault() ) {
            return $personlist;
        }

        //Get all mailboxes
        $mailboxlist = array();

        foreach (Mailbox::all() as $mailbox) {
            $mailboxitem = array('id' => $mailbox->id, 'name' => $mailbox->name, 'groups_roles' => array());
            if ( array_Key_exists(CHURCHTOOLSAUTH_MODULE, $mailbox->meta) ) {
                if ( is_array($mailbox->meta) and ! empty($mailbox->meta) ) {
                    $meta = $mailbox->meta[CHURCHTOOLSAUTH_MODULE];
                    if ( array_key_exists('groups_roles', $meta) ) {
                        $groups = $meta['groups_roles'];
                        if ( is_array($groups) and ! empty($groups) ) {
                            $mailboxitem['groups_roles'] = $groups;
                        }
                    }
                }
            }
            $mailboxlist[] = $mailboxitem;
        }

        //Get all groups
        $grouplist = array();
        foreach ( $mailboxlist as $mailbox ) {
            foreach ( $mailbox['groups_roles'] as $group ) {
                if ( ! in_array($group, $grouplist) ) {
                    $grouplist[] = $group;
                }
            }
        }

        //Get all group members
        $skeleton = array('id' => null, 'firstname' => '', 'lastname' => '', 'email' => '', 'avatar' => '', 'admin' => false, 'memberships' => array(), 'mailboxes' => array());
        foreach ( $grouplist as $groupitem ) {
            
            $item = explode('_', $groupitem);
            if ( count($item) != 2 ) {
                continue;
            }
            $groupID = intval($item[0]);
            $roleID = intval($item[1]);

            try {
                
                try {
                    $group = GroupRequest::findOrFail($groupID);
                } catch ( \Exception $e ) {
                    continue;
                }

                if ( ! empty($group) ) {

                    if ( $group->getInformation()->getGroupStatusId() != 1 ) { //Process only groups with status "active"
                        continue;
                    }

                    $members = $group->requestMembers()->get();
                    foreach ( $members as $member ) {

                        if ( $roleID != 0 and $roleID != intval($member->getGroupTypeRoleId()) ) {
                            continue;
                        }

                        if ( $member->getGroupMemberStatus() != 'active' ) {
                            continue;
                        }

                        $personID = intval($member->getPersonId());
                        $person = null;

                        if ( ! empty($personFilter) ) {

                            if ( ! is_array($personFilter) ) {
                                $personFilter = array($personFilter);
                            }

                            $found = false;

                            foreach ( $personFilter as $filter ) {

                                if ( is_numeric($filter) ) {
                                    if ( $filter == $personID ) {
                                        $found = true;
                                        break;
                                    }
                                } elseif ( strpos($filter, '@') ) {
                                    $person = PersonRequest::find($personID);
                                    if ( ! empty($person) and $filter == $person->getEmail() ) {
                                        $found = true;
                                        break;
                                    }
                                }

                            }

                            if ( ! $found ) {
                                continue;
                            }

                        }

                        $found = false;
                        $key = null;
                        foreach ( $personlist as $personlistKey => $personlistValue ) {
                            if ( $personlistValue['id'] == $personID ) {
                                $key = $personlistKey;
                                $found = true;
                                break;
                            }
                        }

                        $membership = array('groupID' => $groupID, 'roleID' => intval($member->getGroupTypeRoleId()));

                        if ( ! $found ) {

                            if ( empty($person) ) {
                                $person = PersonRequest::find($personID);
                                if ( empty($person) ) {
                                    continue;
                                }
                            }
                            
                            $personitem = $skeleton;

                            $personitem['id'] = $personID;
                            $personitem['firstname'] = $person->getFirstName();
                            $personitem['lastname'] = $person->getLastName();
                            $personitem['email'] = $person->getEmail();
                            $personitem['avatar'] = $person->getImageUrl();
                            $personitem['admin'] = self::isPersonAdmin($personID);

                            $personitem['memberships'][] = $membership;

                            $personlist[] = $personitem;

                        } else {
                            
                            $personlist[$key]['memberships'][] = $membership;

                        }

                    }
                }

            } catch ( \Exception $e ) {
                //
            }

        }

        //Get all admins
        if ( empty($personFilter) ) {
            $admins = self::getAdminPersons();
            foreach ( $admins as $admin ) {
                $found = false;
                foreach ( $personlist as $item ) {
                    if ( $item['id'] == $admin ) {
                        $found = true;
                        break;
                    }
                }
                if ( ! $found ) {
                    $person = PersonRequest::find($admin);
                    if ( empty($person) ) {
                        continue;
                    }
                    $personitem = $skeleton;
                    $personitem['id'] = $admin;
                    $personitem['firstname'] = $person->getFirstName();
                    $personitem['lastname'] = $person->getLastName();
                    $personitem['email'] = $person->getEmail();
                    $personitem['avatar'] = $person->getImageUrl();
                    $personitem['admin'] = true;
                    $personlist[] = $personitem;
                }
            }
        }

        //Get all mailboxes
        foreach ( $personlist as $personKey => $personValue ) {
            foreach ( $mailboxlist as $mailbox ) {
                foreach ( $mailbox['groups_roles'] as $group ) {
                    
                    $item = explode('_', $group);
                    if ( count($item) != 2 ) {
                        continue;
                    }
                    $groupID = intval($item[0]);
                    $roleID = intval($item[1]);

                    $found = false;
                    foreach ( $personValue['memberships'] as $membership ) {
                        if ( $groupID == $membership['groupID'] and $roleID == 0 ) {
                            $found = true;
                            break;
                        } elseif ( $groupID == $membership['groupID'] and $roleID == $membership['roleID'] ) {
                            $found = true;
                            break;
                        }
                    }

                    if ( $found ) {
                        $personlist[$personKey]['mailboxes'][] = $mailbox['id'];
                    }

                }
            }
        }

        return $personlist;

    }

    public static function getAdminPersons() {

        $return = array();
        $admins = \Option::get('churchtoolsauth_admins');
        if ( empty($admins) or ! is_array($admins) ) {
            return $return;
        }

        foreach ( $admins as $admin ) {
            if ( ! empty($admin) and is_numeric($admin) ) {
                $return[] = intval($admin);
            }
        }

        return $return;

    }

    public static function isPersonAdmin($personID) {

        foreach ( self::getAdminPersons() as $admin ) {
            if ( $admin == $personID ) {
                return true;
            }
        }

        return false;

    }

    public static function setUserAvatarFromChurchTools($userId, $imageUrl) {

        $user = User::find($userId);

        if ( empty($user) ) {
            return false;
        }

        $avatarFileName = preg_replace('/[^A-Za-z0-9]/', '_', $imageUrl) . '.jpg';

        if ( $user->photo_url != $avatarFileName ) {

            if ( ! empty($imageUrl) ) {

                $client = new Client();
                $response = $client->get($imageUrl);
        
                if ( $response->getStatusCode() !== 200 ) {
                    return false;
                }

                $pathToAvatar = storage_path('app/users/') . $avatarFileName;
                Storage::put('users/' . $avatarFileName, $response->getBody());

                $user->photo_url = $avatarFileName;
                $user->save();

            } else {

                $user->photo_url = '';
                $user->save();

            }

        }

        return true;
        
    }

}