<?php
/**
 * Activity classes for notification types
 *
 * @package    mahara
 * @subpackage core
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

defined('INTERNAL') || die();

/**
 * This is the function to call whenever anything happens
 * that is going to end up on a user's activity page.
 *
 * @param string $activitytype type of activity
 * @param array $data must contain the fields specified by get_required_parameters of the activity type subclass.
 * @param string $plugintype
 * @param string $pluginname
 * @param bool $delay
 *
 * NOTE: If the $data object contains an 'id' property this needs to be the id of the activitytype
 */
function activity_occurred($activitytype, $data, $plugintype=null, $pluginname=null, $delay=null) {
    try {
        $at = activity_locate_typerecord($activitytype, $plugintype, $pluginname);
    }
    catch (Exception $e) {
        return;
    }
    if (is_null($delay)) {
        $delay = !empty($at->delay);
    }
    if ($delay) {
        $delayed = new stdClass();
        $delayed->type = $at->id;
        $delayed->data = serialize($data);
        $delayed->ctime = db_format_timestamp(time());
        if (!record_exists('activity_queue', 'type', $delayed->type, 'data', $delayed->data)) {
            if ($delayed->type == 4 && isset($data->views[0]['collection_id'])) {
                // try to ensure we don't end up with multiple notifications when sharing collections
                $sql = 'SELECT * FROM {activity_queue} WHERE type = ? AND data like ';
                $sql .= "'%" . '"collection_id"' . ";s:%" . '"' . $data->views[0]['collection_id'] . '"' . ";%'";
                if (!record_exists_sql($sql, array($delayed->type))) {
                    insert_record('activity_queue', $delayed);
                }
            }
            else {
                insert_record('activity_queue', $delayed);
            }
        }
    }
    else {
        handle_activity($at, $data);
    }
}

/**
 * This function dispatches all the activity stuff to whatever notification
 * plugin it needs to, and figures out all the implications of activity and who
 * needs to know about it.
 *
 * @param object $activitytype record from database table activity_type
 * @param mixed $data must contain message to save.
 * each activity type has different requirements of $data -
 *  - <b>viewaccess</b> must contain $owner userid of view owner AND $view (id of view) and $oldusers array of userids before access change was committed.
 * @param $cron = true if called by a cron job
 * @param object $queuedactivity  record of the activity in the queue (from the table activity_queue)
 * @return int The ID of the last processed user
 *      = 0 if all users get processed
 */
function handle_activity($activitytype, $data, $cron=false, $queuedactivity=null) {
    $data = (object)$data;

    if ($cron && isset($queuedactivity)) {
        $data->last_processed_userid = $queuedactivity->last_processed_userid;
        $data->activity_queue_id = $queuedactivity->id;
    }

    $classname = get_activity_type_classname($activitytype);
    $activity = new $classname($data, $cron);
    if (!$activity->any_users()) {
        return 0;
    }

    return $activity->notify_users();
}

/**
 * Given an activity type id or record, calculate the class name.
 *
 * @param mixed $activitytype either numeric activity type id or an activity type record (containing name, plugintype, pluginname)
 * @return string
 */
function get_activity_type_classname($activitytype) {
    $activitytype = activity_locate_typerecord($activitytype);

    $classname = 'ActivityType' . ucfirst($activitytype->name);
    if (!empty($activitytype->plugintype)) {
        safe_require($activitytype->plugintype, $activitytype->pluginname);
        $classname = 'ActivityType' .
            ucfirst($activitytype->plugintype) .
            ucfirst($activitytype->pluginname) .
            ucfirst($activitytype->name);
    }
    return $classname;
}

/**
 * This function returns an array of users who subscribe to a particular activitytype
 * including the notification method they are using to subscribe to it.
 *
 * @param int $activitytype the id of the activity type
 * @param array $userids an array of userids to filter by
 * @param array $userobjs an array of user objects to filterby
 * @param bool $adminonly whether to filter by admin flag
 * @param array $admininstitutions list of institution names to get admins for
 * @param bool $includesuspendedusers whether to include suspended people in the results
 * @return array of users
 */
function activity_get_users($activitytype, $userids=null, $userobjs=null, $adminonly=false,
                            $admininstitutions = array(), $includesuspendedusers=false) {
    $values = array($activitytype);
    $sql = '
        SELECT
            u.id, u.username, u.firstname, u.lastname, u.preferredname, u.email, u.admin, u.staff,
            u.suspendedctime,
            p.method, ap.value AS lang, apm.value AS maildisabled, aic.value AS mnethostwwwroot,
            h.appname AS mnethostapp
        FROM {usr} u
        LEFT JOIN {usr_activity_preference} p
            ON (p.usr = u.id AND p.activity = ?)' . (empty($admininstitutions) ? '' : '
        LEFT OUTER JOIN {usr_institution} ui
            ON (u.id = ui.usr
                AND ui.institution IN ('.join(',',array_map('db_quote',$admininstitutions)).'))') . '
        LEFT OUTER JOIN {usr_account_preference} ap
            ON (ap.usr = u.id AND ap.field = \'lang\')
        LEFT OUTER JOIN {usr_account_preference} apm
            ON (apm.usr = u.id AND apm.field = \'maildisabled\')
        LEFT OUTER JOIN {auth_instance} ai
            ON (ai.id = u.authinstance AND ai.authname = \'xmlrpc\')
        LEFT OUTER JOIN {auth_instance_config} aic
            ON (aic.instance = ai.id AND aic.field = \'wwwroot\')
        LEFT OUTER JOIN {host} h
            ON aic.value = h.wwwroot
        WHERE u.deleted = 0';
    if (!empty($userobjs) && is_array($userobjs)) {
        $sql .= ' AND u.id IN (' . implode(',',db_array_to_ph($userobjs)) . ')';
        $values = array_merge($values, array_to_fields($userobjs));
    }
    else if (!empty($userids) && is_array($userids)) {
        $sql .= ' AND u.id IN (' . implode(',',db_array_to_ph($userids)) . ')';
        $values = array_merge($values, $userids);
    }
    if (!$includesuspendedusers) {
        $sql .= ' AND u.suspendedctime IS NULL ';
    }
    if (!empty($admininstitutions)) {
        $sql .= '
        GROUP BY
            u.id, u.username, u.firstname, u.lastname, u.preferredname, u.email, u.admin, u.staff,
            u.suspendedctime,
            p.method, ap.value, apm.value, aic.value, h.appname
        HAVING (u.admin = 1 OR SUM(ui.admin) > 0)';
    } else if ($adminonly) {
        $sql .= ' AND u.admin = 1';
    }
    return get_records_sql_assoc($sql, $values);
}


/**
 * This function inserts a default set of activity preferences for a given user
 * @param mixed $eventdata  List of event types and their settings
 */
function activity_set_defaults($eventdata) {
    $user_id = is_object($eventdata) ? $eventdata->id : $eventdata['id'];
    $activitytypes = get_records_array('activity_type', 'admin', 0);

    foreach ($activitytypes as $type) {
        insert_record('usr_activity_preference', (object)array(
            'usr' => $user_id,
            'activity' => $type->id,
            'method' => $type->defaultmethod,
        ));
    }
}

/**
 * This function inserts the default set of administrator activity preferences for the given people
 * @param array $userids  List of people's IDs
 */
function activity_add_admin_defaults($userids) {
    $activitytypes = get_records_array('activity_type', 'admin', 1);

    foreach ($activitytypes as $type) {
        foreach ($userids as $id) {
            if (!record_exists('usr_activity_preference', 'usr', $id, 'activity', $type->id)) {
                insert_record('usr_activity_preference', (object)array(
                    'usr' => $id,
                    'activity' => $type->id,
                    'method' => $type->defaultmethod,
                ));
            }
        }
    }
}

/**
 * Process the queue of delayed activity notifications
 */
function activity_process_queue() {

    if ($toprocess = get_records_array('activity_queue')) {
        // Hack to avoid duplicate watchlist notifications on the same view
        $watchlist = activity_locate_typerecord('watchlist');
        $viewsnotified = array();
        foreach ($toprocess as $activity) {
            $data = unserialize($activity->data);
            if ($activity->type == $watchlist->id && !empty($data->view)) {
                if (isset($viewsnotified[$data->view])) {
                    continue;
                }
                $viewsnotified[$data->view] = true;
            }

            try {
                $last_processed_userid = handle_activity($activity->type, $data, true, $activity);
            }
            catch (MaharaException $e) {
                // Exceptions can happen while processing the queue, we just
                // log them and continue
                log_debug($e->getMessage());
            }
            // Update the activity queue
            // or Remove this activity from the queue if all the users get processed
            // to make sure we
            // never send duplicate emails even if part of the
            // activity handler fails for whatever reason
            if (!empty($last_processed_userid)) {
                update_record('activity_queue', array('last_processed_userid' => $last_processed_userid), array('id' => $activity->id));
            }
            else {
                if (!delete_records('activity_queue', 'id', $activity->id)) {
                    log_warn("Unable to remove activity $activity->id from the queue. Skipping it.");
                }
            }
        }
    }
}

/**
 * The event-listener is called when an artefact is changed or a block instance
 * is committed. Saves the view, the block instance, user and time into the
 * database
 *
 * @global User $USER
 * @param string $event
 */
function watchlist_record_changes($event) {
    global $USER;

    // don't catch root's changes, especially not when installing...
    if ($USER->get('id') <= 0) {
        return;
    }
    if ($event instanceof BlockInstance) {
        $viewid = $event->get('view');
        if ($viewid) {
            set_field('view', 'mtime', db_format_timestamp(time()), 'id', $viewid);
        }
        if (record_exists('usr_watchlist_view', 'view', $viewid)) {
            $whereobj = new stdClass();
            $whereobj->block = $event->get('id');
            $whereobj->view = $viewid;
            $whereobj->usr = $USER->get('id');
            $dataobj = clone $whereobj;
            $dataobj->changed_on = date('Y-m-d H:i:s');
            ensure_record_exists('watchlist_queue', $whereobj, $dataobj);
        }
    }
    else if ($event instanceof ArtefactType) {
        $blockid = $event->get('id');
        $getcolumnquery = '
            SELECT DISTINCT
             "view", "block"
            FROM
             {view_artefact}
            WHERE
             artefact =' . $blockid;
        $relations = get_records_sql_array($getcolumnquery, array());

        // fix unnecessary type-inconsistency of get_records_sql_array
        if (false === $relations) {
            $relations = array();
        }

        foreach ($relations as $rel) {
            $viewid = $rel->view;
            if ($viewid) {
                set_field('view', 'mtime', db_format_timestamp(time()), 'id', $viewid);
            }
            if (!record_exists('usr_watchlist_view', 'view', $viewid)) {
                continue;
            }
            $whereobj = new stdClass();
            $whereobj->block = $rel->block;
            $whereobj->view = $viewid;
            $whereobj->usr = $USER->get('id');
            $dataobj = clone $whereobj;
            $dataobj->changed_on = date('Y-m-d H:i:s');
            ensure_record_exists('watchlist_queue', $whereobj, $dataobj);
        }
    }
    else if (!is_object($event) && !empty($event['id'])) {
        $viewid = $event['id'];
        if ($viewid) {
            set_field('view', 'mtime', db_format_timestamp(time()), 'id', $viewid);
        }
        if (record_exists('usr_watchlist_view', 'view', $viewid)) {
            $whereobj = new stdClass();
            $whereobj->view = $viewid;
            $whereobj->usr = $USER->get('id');
            $whereobj->block = null;
            $dataobj = clone $whereobj;
            $dataobj->changed_on = date('Y-m-d H:i:s');
            ensure_record_exists('watchlist_queue', $whereobj, $dataobj);
        }
    }
    else {
        return;
    }
}

/**
 * Is triggered when a blockinstance is deleted. Deletes all watchlist_queue
 * entries that refer to this blockinstance
 *
 * @param BlockInstance $block
 */
function watchlist_block_deleted(BlockInstance $block) {
    global $USER;

    // don't catch root's changes, especially not when installing...
    if ($USER->get('id') <= 0) {
        return;
    }

    delete_records('watchlist_queue', 'block', $block->get('id'));

    if (record_exists('usr_watchlist_view', 'view', $block->get('view'))) {
        $whereobj = new stdClass();
        $whereobj->view = $block->get('view');
        $whereobj->block = null;
        $whereobj->usr = $USER->get('id');
        $dataobj = clone $whereobj;
        $dataobj->changed_on = date('Y-m-d H:i:s');
        ensure_record_exists('watchlist_queue', $whereobj, $dataobj);
    }
}

/**
 * is called by the cron-job to process the notifications stored into
 * watchlist_queue.
 */
function watchlist_process_notifications() {
    $delayMin = get_config('watchlistnotification_delay');
    $comparetime = time() - $delayMin * 60;

    $sql = "SELECT usr, view, MAX(changed_on) AS time
            FROM {watchlist_queue}
            GROUP BY usr, view";
    $results = get_records_sql_array($sql, array());

    if (false === $results) {
        return;
    }

    foreach ($results as $viewuserdaterow) {
        if ($viewuserdaterow->time > date('Y-m-d H:i:s', $comparetime)) {
            continue;
        }

        // don't send a notification if only blockinstances are referenced
        // that were deleted (block exists but corresponding
        // block_instance doesn't)
        $sendnotification = false;

        $blockinstance_ids = get_column('watchlist_queue', 'block', 'usr', $viewuserdaterow->usr, 'view', $viewuserdaterow->view);
        if (is_array($blockinstance_ids)) {
            $blockinstance_ids = array_unique($blockinstance_ids);
        }

        $viewuserdaterow->blocktitles = array();

        // need to check if view has an owner, group or institution
        $view = get_record('view', 'id', $viewuserdaterow->view);
        if (empty($view->owner) && empty($view->group) && empty($view->institution)) {
            continue;
        }
        // ignore root pages, owner = 0, this account is not meant to produce content
        if (isset($view->owner) && empty($view->owner)) {
            continue;
        }
        // Ignore system templates, institution = 'mahara' and template = 2
        require_once(get_config('libroot') . 'view.php');
        if (isset($view->institution)
            && $view->institution == 'mahara'
            && $view->template == View::SITE_TEMPLATE) {
            continue;
        }

        foreach ($blockinstance_ids as $blockinstance_id) {
            if (empty($blockinstance_id)) {
                // if no blockinstance is given, assume that the form itself
                // was changed, e.g. the theme, or a block was removed
                $sendnotification = true;
                continue;
            }
            require_once(get_config('docroot') . 'blocktype/lib.php');

            try {
                $block = new BlockInstance($blockinstance_id);
            }
            catch (BlockInstanceNotFoundException $exc) {
                // maybe the block was deleted
                continue;
            }

            $blocktype = $block->get('blocktype');
            $title = '';

            // try to get title rendered by plugin-class
            safe_require('blocktype', $blocktype);
            if (class_exists(generate_class_name('blocktype', $blocktype))) {
                $title = $block->get_title();
            }
            else {
                log_warn('class for blocktype could not be loaded: ' . $blocktype);
                $title = $block->get('title');
            }

            // if no title was given to the blockinstance, try to get one
            // from the artefact
            if (empty($title)) {
                $configdata = $block->get('configdata');

                if (array_key_exists('artefactid', $configdata)) {
                    try {
                        $artefact = $block->get_artefact_instance($configdata['artefactid']);
                        $title = $artefact->get('title');
                    }
                    catch(Exception $exc) {
                        log_warn('couldn\'t identify title of blockinstance ' .
                                 $block->get('id') . $exc->getMessage());
                    }
                }
            }

            // still no title, maybe the default-name for the blocktype
            if (empty($title)) {
                $title = get_string('title', 'blocktype.' . $blocktype);
            }

            // no title could be retrieved, so let's tell the user at least
            // what type of block was changed
            if (empty($title)) {
                $title = '[' . $blocktype . '] (' .
                    get_string('nonamegiven', 'activity') . ')';
            }

            $viewuserdaterow->blocktitles[] = $title;
            $sendnotification = true;
        }

        // only send notification if there is something to talk about (don't
        // send notification for example when new blockelement was aborted)
        if ($sendnotification) {
            try{
                $watchlistnotification = new ActivityTypeWatchlistnotification($viewuserdaterow, false);
                $watchlistnotification->notify_users();
            }
            catch (ViewNotFoundException $exc) {
                // Seems like the view has been deleted, don't do anything
            }
            catch (SystemException $exc) {
                // if the view that was changed doesn't have an owner
            }
        }

        delete_records('watchlist_queue', 'usr', $viewuserdaterow->usr, 'view', $viewuserdaterow->view);
    }
}

/**
 * Get the people that have access to the view which the activity is related to
 * @param integer $view  The view ID
 * @return array The database array of people based on view access rules
 */
function activity_get_viewaccess_users($view) {
    require_once(get_config('docroot') . 'lib/group.php');
    $sql = "SELECT userlist.userid, usr.*, actpref.method, accpref.value AS lang,
              aic.value AS mnethostwwwroot, h.appname AS mnethostapp
                FROM (
                    SELECT friend.usr1 AS userid
                      FROM {view} view
                      JOIN {view_access} access ON (access.view = view.id AND access.accesstype = 'friends')
                      JOIN {usr_friend} friend ON (view.owner = friend.usr2 AND view.id = ?)
                    UNION
                    SELECT friend.usr2 AS userid
                      FROM {view} view
                      JOIN {view_access} access ON (access.view = view.id AND access.accesstype = 'friends')
                      JOIN {usr_friend} friend ON (view.owner = friend.usr1 AND view.id = ?)
                    UNION
                    SELECT access.usr AS userid
                      FROM {view_access} access
                     WHERE access.view = ?
                    UNION
                    SELECT members.member AS userid
                      FROM {view_access} access
                      JOIN {group} grp ON (access.group = grp.id AND grp.deleted = 0 AND access.view = ?)
                      JOIN {group_member} members ON (grp.id = members.group AND members.member <> CASE WHEN access.usr IS NULL THEN -1 ELSE access.usr END)
                     WHERE (access.role IS NULL OR access.role = members.role) AND
                      (grp.viewnotify = " . GROUP_ROLES_ALL . "
                       OR (grp.viewnotify = " . GROUP_ROLES_NONMEMBER . " AND (members.role = 'admin' OR members.role = 'tutor'))
                       OR (grp.viewnotify = " . GROUP_ROLES_ADMIN . " AND members.role = 'admin')
                      )
                ) AS userlist
                JOIN {usr} usr ON usr.id = userlist.userid
                LEFT JOIN {usr_activity_preference} actpref ON actpref.usr = usr.id
                LEFT JOIN {activity_type} acttype ON actpref.activity = acttype.id AND acttype.name = 'viewaccess'
                LEFT JOIN {usr_account_preference} accpref ON accpref.usr = usr.id AND accpref.field = 'lang'
                LEFT JOIN {auth_instance} ai ON ai.id = usr.authinstance
                LEFT OUTER JOIN {auth_instance_config} aic ON (aic.instance = ai.id AND aic.field = 'wwwroot')
                LEFT OUTER JOIN {host} h ON aic.value = h.wwwroot";
    $values = array($view, $view, $view, $view);
    if (!$u = get_records_sql_assoc($sql, $values)) {
        $u = array();
    }
    return $u;
}

/**
 * Return the minimum and maximum access times if they exist for the page
 * based on user getting access. To be used with view access notifications
 *
 * @param string $viewid ID of the view
 * @param string $userid ID of the user
 * @return array Min and max access dates
 */
function activity_get_viewaccess_user_dates($viewid, $userid) {
    if ($results = get_records_sql_array("
        SELECT MIN(startdate) AS mindate, MAX(stopdate) as maxdate FROM (
            SELECT startdate, stopdate FROM {view}
            WHERE id = ?
            UNION
            SELECT startdate, stopdate FROM {view_access}
            WHERE view = ? AND usr = ?
            UNION
            SELECT startdate, stopdate FROM {view_access} va
            JOIN {group_member} gm ON gm.group = va.group
            WHERE va.view = ? AND gm.member = ?
            UNION
            SELECT startdate, stopdate FROM {view_access} va
            JOIN {usr_institution} ui ON ui.institution = va.institution
            WHERE va.view = ? and ui.usr = ?
            UNION
            SELECT startdate, stopdate FROM {view_access}
            WHERE view = ? AND accesstype IN ('loggedin','public')
            UNION
            SELECT startdate, stopdate FROM {view_access}
            WHERE accesstype = 'friends' AND view = ?
            AND EXISTS (
                SELECT * FROM {usr_friend}
                WHERE (usr1 = (SELECT owner FROM {view} WHERE id = ?) AND usr2 = ?)
                OR (usr2 = (SELECT owner FROM {view} WHERE id = ?) AND usr1 = ?)
            )
        ) AS dates", array($viewid, $viewid, $userid, $viewid, $userid, $viewid, $userid, $viewid, $viewid, $viewid, $userid, $viewid, $userid))
    ) {
        return array('mindate' => $results[0]->mindate,
                     'maxdate' => $results[0]->maxdate);
    }
    return array('mindate' => null,
                 'maxdate' => null);
}

/**
 * Find a valid activity type record
 * @param mixed $activitytype  The type of activity we want to send the notification for
 * @param string|null $plugintype Find the activity type by plugin type
 * @param string|null $pluginname Find the activity type by plugin name
 * @throws SystemException
 * @return object A Database row object
 */
function activity_locate_typerecord($activitytype, $plugintype=null, $pluginname=null) {
    if (is_object($activitytype)) {
        return $activitytype;
    }
    if (is_numeric($activitytype)) {
        $at = get_record('activity_type', 'id', $activitytype);
    }
    else {
        if (empty($plugintype) && empty($pluginname)) {
            $at = get_record_select('activity_type',
                'name = ? AND plugintype IS NULL AND pluginname IS NULL',
                array($activitytype));
        }
        else {
            $at = get_record('activity_type', 'name', $activitytype, 'plugintype', $plugintype, 'pluginname', $pluginname);
        }
    }
    if (empty($at)) {
        throw new SystemException("Invalid activity type $activitytype");
    }
    return $at;
}

/**
 * To implement a new activity type, you must subclass this class. Your subclass
 * MUST at minimum include the following:
 *
 * 1. Override the __construct method with one which first calls parent::__construct
 *    and then populates $this->users with the list of recipients for this activity.
 *
 * 2. Implement the get_required_parameters method.
 */
abstract class ActivityType {

    /**
     * NOTE: Child classes MUST call the parent constructor, AND populate
     * $this->users with a list of user records which should receive the message!
     *
     * @param array $data The data needed to send the notification
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        $this->cron = $cron;
        $this->set_parameters($data);
        $this->ensure_parameters();
        $this->activityname = strtolower(substr(get_class($this), strlen('ActivityType')));
    }

    /**
     * This method should return an array which names the fields that must be present in the
     * $data that was passed to the class's constructor. It should include all necessary data
     * to determine the recipient(s) of the notification and to determine its content.
     *
     * @return array
     */
    abstract function get_required_parameters();

    /**
     * The number of users in a split chunk to notify
     */
    const USERCHUNK_SIZE = 1000;

    /**
     * Who any notifications about this activity should appear to come from
     * @var integer The ID of the person
     */
    protected $fromuser;

    /**
     * When sending notifications, should the email of the person sending it be
     * hidden? (Almost always yes, will cause the email to appear to come from
     * the 'noreply' address)
     * @var boolean
     */
    protected $hideemail = true;

    /**
     * The subject line of the message
     * @var string
     */
    protected $subject;

    /**
     * The body of the message
     * @var string
     */
    protected $message;

    /**
     * Language strings and parameters to build the subject / message with
     * @var array
     */
    protected $strings;

    /**
     * People to send the message to
     * @var array
     */
    protected $users = array();

    /**
     * A URL to display at the bottom of the message
     * @var string
     */
    protected $url;

    /**
     * Alternate text to display for the URL in HTML messages
     * @var string
     */
    protected $urltext;

    /**
     * The ID of the activity type
     * @var integer
     */
    protected $id;

    /**
     * The ID of the activity type
     * @var integer
     * @todo find out how it differs from $id
     */
    protected $type;

    /**
     * The partial class name without the 'ActivityType' prefix, eg Usermessage
     * @var string
     */
    protected $activityname;

    /**
     * @var boolean Whether this is being called by the cron job
     */
    protected $cron;

    /**
     * The last person to be notified for a particular queue item
     * when the activity_queue cron emails in bulk
     * @var integer
     */
    protected $last_processed_userid;

    /**
     * The queue item currently being processed via cron
     * @var integer
     */
    protected $activity_queue_id;

    /**
     * Override the normal message process and instead allow seperate HTML and plaintext email messages
     * @var boolean
     */
    protected $overridemessagecontents;

    /**
     * The parent message of a threadded message reply
     * @var integer
     */
    protected $parent;

    /**
     * The default notification method to use when sending the message to a person
     * @var string
     */
    protected $defaultmethod;

    /**
     * Get the ID of the ActivityType from database
     * @return integer
     */
    public function get_id() {
        if (!isset($this->id)) {
            $tmp = activity_locate_typerecord($this->get_type());
            $this->id = $tmp->id;
        }
        return $this->id;
    }

    /**
     * Get the default method to send the notification
     * @return string
     */
    public function get_default_method() {
        if (!isset($this->defaultmethod)) {
            $tmp = activity_locate_typerecord($this->get_id());
            $this->defaultmethod = $tmp->defaultmethod;
        }
        return $this->defaultmethod;
    }

    /**
     * Get the partial class name without the 'ActivityType' prefix, eg Usermessage
     * @return string
     */
    public function get_type() {
        $prefix = 'ActivityType';
        return strtolower(substr(get_class($this), strlen($prefix)));
    }

    /**
     * Check to see if any people will receive a notification
     * @return boolean
     */
    public function any_users() {
        return (is_array($this->users) && count($this->users) > 0);
    }

    /**
     * Fetch the people to be notified
     * @return array
     */
    public function get_users() {
        return $this->users;
    }

    /**
     * Set supplied data to the class properties
     * @param array $data  An associative array with keys matching properties of the class
     */
    private function set_parameters($data) {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Checks that we have the required properties set before trying to send messages
     * @throws ParamOutOfRangeException
     */
    private function ensure_parameters() {
        foreach ($this->get_required_parameters() as $param) {
            if (!isset($this->{$param})) {
                // Allow some string parameters to be specified in $this->strings
                if (!in_array($param, array('subject', 'message', 'urltext')) || empty($this->strings->{$param}->key)) {
                    throw new ParamOutOfRangeException(get_string('missingparam', 'activity', $param, $this->get_type()));
                }
            }
        }
    }

    /**
     * Turn ActivityType object into a stdClass object
     * @return object
     */
    public function to_stdclass() {
       return (object)get_object_vars($this);
    }

    /**
     * Get translated string for the person
     * This allows us to send email messages in the language the person prefers
     * @param object $user  A database user object
     * @param string $string The language string key
     * @return string  The translated language string value
     */
    public function get_string_for_user($user, $string) {
        if (empty($string) || empty($this->strings->{$string}->key)) {
            return;
        }
        $args = array_merge(
            array(
                $user->lang,
                $this->strings->{$string}->key,
                empty($this->strings->{$string}->section) ? 'mahara' : $this->strings->{$string}->section,
            ),
            empty($this->strings->{$string}->args) ? array() : $this->strings->{$string}->args
        );
        return call_user_func_array('get_string_from_language', $args);
    }

    /**
     * Optional string to use for the URL link text.
     * @param array $stringdef
     */
    public function add_urltext(array $stringdef) {
        $def = $stringdef;
        if (!is_object($this->strings)) {
            $this->strings = new stdClass();
        }
        $this->strings->urltext = (object) $def;
    }

    /**
     * Fetch the URL link text
     * @param object $user  A database user object
     * @return string
     */
    public function get_urltext($user) {
        if (empty($this->urltext)) {
            return $this->get_string_for_user($user, 'urltext');
        }
        return $this->urltext;
    }

    /**
     * Fetch the body message text
     * @param object $user  A database user object
     * @return string
     */
    public function get_message($user) {
        if (empty($this->message)) {
            return $this->get_string_for_user($user, 'message');
        }
        return $this->message;
    }

    /**
     * Fetch the subject line text
     * @param object $user  A database user object
     * @return string
     */
    public function get_subject($user) {
        if (empty($this->subject)) {
            return $this->get_string_for_user($user, 'subject');
        }
        return $this->subject;
    }

    /**
     * Rewrite $this->url with the ID of the internal notification record for this activity.
     * (Generally so that you can make a URL that sends the user to the Mahara inbox page
     * for this message.)
     *
     * @param int $internalid
     * @return boolean True if $this->url was updated, False if not.
     */
    protected function update_url($internalid) {
        return false;
    }

    /**
     * The process of sending an activity message to a person
     * @param object $user  A database user object
     * @return void
     */
    public function notify_user($user) {
        $changes = new stdClass();

        $userdata = $this->to_stdclass();
        // some stuff gets overridden by user specific stuff
        if (!empty($user->url)) {
            $userdata->url = $user->url;
        }
        if (empty($user->lang) || $user->lang == 'default') {
            $user->lang = get_user_language($user->id);
        }
        if (empty($user->method)) {
            // If method is not set then either the user has selected 'none' or their setting has not been set (so use default).
            if ($record = get_record('usr_activity_preference', 'usr', $user->id, 'activity', $this->get_id())) {
                $user->method = $record->method;
                if (empty($user->method)) {
                    // The user specified 'none' as their notification type.
                    return;
                }
            }
            else {
                $user->method = $this->get_default_method();
                if (empty($user->method)) {
                    // The default notification type is 'none' for this activity type.
                    return;
                }
            }
        }

        // always do internal
        foreach (PluginNotificationInternal::$userdata as &$p) {
            $function = 'get_' . $p;
            $userdata->$p = $this->$function($user);
        }

        $userdata->internalid = PluginNotificationInternal::notify_user($user, $userdata);
        if ($this->update_url($userdata->internalid)) {
            $changes->url = $userdata->url = $this->url;
        }

        if ($user->method != 'internal' || isset($changes->url)) {
            $changes->read = (int) ($user->method != 'internal');
            $changes->id = $userdata->internalid;
            update_record('notification_internal_activity', $changes);
        }

        if ($user->method != 'internal') {
            $method = $user->method;
            safe_require('notification', $method);
            $notificationclass = generate_class_name('notification', $method);
            $classvars = get_class_vars($notificationclass);
            if (!empty($classvars['userdata'])) {
                foreach ($classvars['userdata'] as &$p) {
                    $function = 'get_' . $p;
                    if (!isset($userdata->$p) && method_exists($this, $function)) {
                        $userdata->$p = $this->$function($user);
                    }
                }
            }
            try {
                call_static_method($notificationclass, 'notify_user', $user, $userdata);
            }
            catch (MaharaException $e) {
                static $badnotification = false;
                static $adminnotified = array();
                // We don't mind other notification methods failing, as it'll
                // go into the activity log as 'unread'
                $changes->read = 0;
                update_record('notification_internal_activity', $changes);
                if (!$badnotification && !($e instanceof EmailDisabledException || $e instanceof InvalidEmailException)) {
                    // Admins should probably know about the error, but to avoid sending too many similar notifications,
                    // save an initial prefix of the message being sent and throw away subsequent exceptions with the
                    // same prefix.  To cut down on spam, it's worth missing out on a few similar messages.
                    $k = substr($e, 0, 60);
                    if (!isset($adminnotified[$k])) {
                        $message = (object) array(
                            'users' => get_column('usr', 'id', 'admin', 1),
                            'subject' => get_string('adminnotificationerror1', 'activity'),
                            'message' => $e,
                        );
                        $adminnotified[$k] = 1;
                        $badnotification = true;
                        activity_occurred('maharamessage', $message);
                        $badnotification = false;
                    }
                }
            }
        }

        // The user's unread message count does not need to be updated from $changes->read
        // because of the db trigger on notification_internal_activity.
    }

    /**
     * Sound out notifications to $this->users.
     * Note that, although this has batching properties built into it with USERCHUNK_SIZE,
     * it's also recommended to update a bulk ActivityType's constructor to limit the total
     * number of records pulled from the database.
     * @return integer|void  Returns 0 for cron if successful
     */
    public function notify_users() {
        safe_require('notification', 'internal');
        $this->type = $this->get_id();

        if ($this->cron) {
            // Sort the list of users to notify by userid
            uasort($this->users, function($a, $b) {return $a->id > $b->id;});
            // Notify a chunk of users
            $num_processed_users = 0;
            $last_processed_userid = 0;
            foreach ($this->users as $user) {
                if ($this->last_processed_userid && ($user->id <= $this->last_processed_userid)) {
                    continue;
                }
                if ($num_processed_users < ActivityType::USERCHUNK_SIZE) {
                    // Immediately update the last_processed_userid in the activity_queue
                    // to prevent duplicated notifications
                    $last_processed_userid = $user->id;
                    update_record('activity_queue', array('last_processed_userid' => $last_processed_userid), array('id' => $this->activity_queue_id));
                    $this->notify_user($user);
                    $num_processed_users++;
                }
                else {
                    break;
                }
            }
            return $last_processed_userid;
        }
        else {
            while (!empty($this->users)) {
                $user = array_shift($this->users);
                $this->notify_user($user);
            }
        }
        return 0;
    }
}

/**
 * Abstract class for the activity types only available to administrators
 * When making new admin only activity types they should use this as parent class
 */
abstract class ActivityTypeAdmin extends ActivityType {

    /**
     * Activity class for sending messages to administators
     *
     * @param array $data The data needed to send the notification
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
        $this->users = activity_get_users($this->get_id(), null, null, true);
    }
}

/**
 * Contactus class for the contact form activity
 * This activity type is only available to administrators
 */
class ActivityTypeContactus extends ActivityTypeAdmin {

    /**
     * @var string Display name for the sender
     */
    protected $fromname;

    /**
     * @var string Email address of the sender
     */
    protected $fromemail;

    /**
     * @var boolean Whether to hide the email
     */
    protected $hideemail = false;

    /**
     * Activity class for sending the contact us form messages
     * @param array $data Parameters:
     *                    - message (string)
     *                    - subject (string) (optional)
     *                    - fromname (string)
     *                    - fromaddress (email address)
     *                    - fromuser (int) (if a logged in user)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
        if (!empty($this->fromuser)) {
            $this->url = profile_url($this->fromuser, false);
        }
        else {
            $this->customheaders = array(
                'Reply-to: ' . $this->fromname . ' <' . $this->fromemail . '>',
            );
        }
    }

    /**
     * Fetch the subject line text
     * @param object $user  A database user object
     * @return string
     */
    function get_subject($user) {
        return get_string_from_language($user->lang, 'newcontactus', 'activity');
    }

    /**
     * Fetch the body message text
     * @param object $user  A database user object
     * @return string
     */
    function get_message($user) {
        return get_string_from_language($user->lang, 'newcontactusfrom', 'activity') . ' ' . $this->fromname
            . ' <' . $this->fromemail .'>' . (isset($this->subject) ? ': ' . $this->subject : '')
            . "\n\n" . $this->message;
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('message', 'fromname', 'fromemail');
    }
}

/**
 * Objectionable class for the view objection form activity
 * This activity type is only available to administrators
 */
class ActivityTypeObjectionable extends ActivityTypeAdmin {

    /**
     * @var integer View ID
     */
    protected $view;

    /**
     * @var integer Arefact ID
     */
    protected $artefact;

    /**
     * @var integer User ID of person reporting issue
     */
    protected $reporter;

    /**
     * @var integer Unixtimestamp
     */

    protected $ctime;
    /**
     * @var integer User Id ofperson resolving issue
     */
    protected $review;

    /**
     * Activity class to send objectionable messages
     * @param array $data Parameters:
     *                    - message (string)
     *                    - view (int)
     *                    - artefact (int) (optional)
     *                    - reporter (int)
     *                    - ctime (int) (optional)
     *                    - review (int) (optional)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    function __construct($data, $cron=false) {
        parent::__construct($data, $cron);

        require_once('view.php');
        $this->view = new View($this->view);

        if (!empty($this->artefact)) {
            require_once(get_config('docroot') . 'artefact/lib.php');
            $this->artefact = artefact_instance_from_id($this->artefact);
        }
        // Notify institutional admins of the view owner
        $adminusers = array();
        if ($owner = $this->view->get('owner')) {
            if ($institutions = get_column('usr_institution', 'institution', 'usr', $owner)) {
                $adminusers = activity_get_users($this->get_id(), null, null, null, $institutions);
            }
        }
        if (isset($data->touser) && !empty($data->touser)) {
            // Notify user when admin updates objection
            $owneruser = activity_get_users($this->get_id(), array($data->touser));
            $this->users = array_merge($owneruser, $adminusers);
        }
        else if ($owner = $this->view->get('owner')) {
            if (!empty($adminusers)) {
                $this->users = $adminusers;
            }
        }

        if (empty($this->artefact)) {
            $this->url = $this->view->get_url(false, true) . '&objection=1';
        }
        else {
            $this->url = 'view/view.php?id=' . $this->view->get('id') . '&modal=1&artefact=' .  $this->artefact->get('id') . '&objection=1';
        }

        if (empty($this->strings->subject)) {
            $this->overridemessagecontents = true;
            $viewtitle = $this->view->get('title');
            $this->strings = new stdClass();
            if (empty($this->artefact)) {
                $this->strings->subject = (object) array(
                    'key'     => ($this->review ? 'objectionablereviewview' : 'objectionablecontentview'),
                    'section' => 'activity',
                    'args'    => array($viewtitle, display_default_name($this->reporter)),
                );
            }
            else {
                $title = $this->artefact->get('title');
                $this->strings->subject = (object) array(
                    'key'     => ($this->review ? 'objectionablereviewviewartefact' : 'objectionablecontentviewartefact'),
                    'section' => 'activity',
                    'args'    => array($viewtitle, $title, display_default_name($this->reporter)),
                );
            }
        }
    }

    /**
     * Fetch a Plain Text formatted message to send via email
     * @param object $user  A database user object
     * @todo This should be inherited from abstract class
     * @return string
     */
    public function get_emailmessage($user) {
        $reporterurl = profile_url($this->reporter);
        $ctime = strftime(get_string_from_language($user->lang, 'strftimedaydatetime'), $this->ctime);
        if (empty($this->artefact)) {
            $key = ($this->review ? 'objectionablereviewviewtext' : 'objectionablecontentviewtext');
            return get_string_from_language(
                $user->lang, $key, 'activity',
                $this->view->get('title'), display_default_name($this->reporter), $ctime,
                $this->message, $this->view->get_url(true, true) . "&objection=1", $reporterurl
            );
        }
        else {
            $key = ($this->review ? 'objectionablereviewviewartefacttext' : 'objectionablecontentviewartefacttext');
            return get_string_from_language(
                $user->lang, $key, 'activity',
                $this->view->get('title'), $this->artefact->get('title'), display_default_name($this->reporter), $ctime,
                $this->message, get_config('wwwroot') . "view/view.php?id=" . $this->view->get('id') . '&modal=1&artefact=' . $this->artefact->get('id') . "&objection=1", $reporterurl
            );
        }
    }

    /**
     * Fetch an HTML formatted message to send via email
     * @param object $user  A database user object
     * @todo This should be inherited from abstract class
     * @return string
     */
    public function get_htmlmessage($user) {
        $viewtitle = hsc($this->view->get('title'));
        $reportername = hsc(display_default_name($this->reporter));
        $reporterurl = profile_url($this->reporter);
        $ctime = strftime(get_string_from_language($user->lang, 'strftimedaydatetime'), $this->ctime);
        $message = format_whitespace($this->message);
        if (empty($this->artefact)) {
            $key = ($this->review ? 'objectionablereviewviewhtml' : 'objectionablecontentviewhtml');
            return get_string_from_language(
                $user->lang, $key, 'activity',
                $viewtitle, $reportername, $ctime,
                $message, $this->view->get_url(true, true) . "&objection=1", $viewtitle,
                $reporterurl, $reportername
            );
        }
        else {
            $key = ($this->review ? 'objectionablereviewviewartefacthtml' : 'objectionablecontentviewartefacthtml');
            return get_string_from_language(
                $user->lang, $key, 'activity',
                $viewtitle, hsc($this->artefact->get('title')), $reportername, $ctime,
                $message, get_config('wwwroot') . "view/view.php?id=" . $this->view->get('id') . '&modal=1&artefact=' . $this->artefact->get('id') . "&objection=1", hsc($this->artefact->get('title')),
                $reporterurl, $reportername
            );
        }
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('message', 'view', 'reporter');
    }

}
/**
 * VirusReport class for the uploading of files identified as potential viruses
 * This activity type is only available to administrators
 */
class ActivityTypeVirusRepeat extends ActivityTypeAdmin {

    /**
     * @var string username
     */
    protected $username;

    /**
     * @var string fullname
     */
    protected $fullname;

    /**
     * @var integer user ID
     */
    protected $userid;

    /**
     * Activity class for sending virus message to administators
     *
     * @param array $data The data needed to send the notification
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
    }

    /**
     * Fetch the subject line text
     * @param object $user  A database user object
     * @return string
     */
    public function get_subject($user) {
        $userstring = $this->username . ' (' . $this->fullname . ') (userid:' . $this->userid . ')' ;
        return get_string_from_language($user->lang, 'virusrepeatsubject', 'mahara', $userstring);
    }

    /**
     * Fetch the body message text
     * @param object $user  A database user object
     * @return string
     */
    public function get_message($user) {
        return get_string_from_language($user->lang, 'virusrepeatmessage');
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('username', 'fullname', 'userid');
    }
}

/**
 * VirusRelease class for the notification about potential virus file being dealt with
 * This activity type is only available to administrators
 */
class ActivityTypeVirusRelease extends ActivityTypeAdmin {

    /**
     * Activity class for sending virus message replies
     *
     * @param array $data The data needed to send the notification
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array();
    }
}

/**
 * Maharamessage class for the generic sitewise messages that Mahara needs to send
 * without needing their own activity type
 */
class ActivityTypeMaharamessage extends ActivityType {

    /**
     * The generic message class used for most messages
     * @param array $data Parameters:
     *                    - subject (string)
     *                    - message (string)
     *                    - users (list of user ids)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
        $includesuspendedusers = isset($data->includesuspendedusers) && $data->includesuspendedusers;
        $this->users = activity_get_users($this->get_id(), $this->users, null, false, array(), $includesuspendedusers);
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('message', 'subject', 'users');
    }
}

/**
 * Institutionmessage class for the institution specific messages that Mahara needs to send
 */
class ActivityTypeInstitutionmessage extends ActivityType {

    /**
     * @var string Type of messaage
     */
    protected $messagetype;

    /**
     * @var string Institution
     */
    protected $institution;

    /**
     * @var string username
     */
    protected $username;

    /**
     * @var string display name
     */
    protected $fullname;

    /**
     * Activity class for institution messages
     *
     * @param array $data The data needed to send the notification
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
        if ($this->messagetype == 'request') {
            $this->url = 'admin/users/institutionusers.php';
            $this->users = activity_get_users($this->get_id(), null, null, null,
                                              array($this->institution->name));
            $this->add_urltext(array('key' => 'institutionmembers', 'section' => 'admin'));
        }
        else if ($this->messagetype == 'invite') {
            $this->url = 'account/institutions.php';
            $this->users = activity_get_users($this->get_id(), $this->users);
            $this->add_urltext(array('key' => 'institutionmembership', 'section' => 'mahara'));
        }
    }

    /**
     * Fetch the language to send the message in
     * If the user has no choice set then use the institution's language
     *
     * @param object $user  A database user object
     */
    private function get_language($user) {
        $userlang = get_account_preference($user->id, 'lang');
        if ($userlang === 'default') {
            if (!isset($this->institution->language) || $this->institution->language === '' || $this->institution->language === 'default') {
                return get_config('lang');
            }
            else {
                return $this->institution->language;
            }
        }
        else {
            return $userlang;
        }
    }

    /**
     * Fetch the subject line text based on message type
     * @param object $user  A database user object
     * @return string
     */
    public function get_subject($user) {
        $lang = $this->get_language($user);
        if ($this->messagetype == 'request') {
            $userstring = $this->fullname . ' (' . $this->username . ')';
            return get_string_from_language($lang, 'institutionrequestsubject', 'activity', $userstring,
              $this->institution->displayname);
        }
        else if ($this->messagetype == 'invite') {
            return get_string_from_language($lang, 'institutioninvitesubject', 'activity',
              $this->institution->displayname);
        }
    }

    /**
     * Fetch the body message text based on message type
     * @param object $user  A database user object
     * @return string
     */
    public function get_message($user) {
        $lang = $this->get_language($user);
        if ($this->messagetype == 'request') {
            return $this->get_subject($user) .' '. get_string_from_language($lang, 'institutionrequestmessage', 'activity', $this->url);
        }
        else if ($this->messagetype == 'invite') {
            return $this->get_subject($user) .' '. get_string_from_language($lang, 'institutioninvitemessage', 'activity', $this->url);
        }
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('messagetype', 'institution');
    }
}

/**
 * Usermessage class for the person specific messages that Mahara needs to send
 * Messages that are sent from one person to another
 */
class ActivityTypeUsermessage extends ActivityType {

    /**
     * @var integer ID of person receiving the email
     */
    protected $userto;

    /**
     * @var integer ID of person receiving the email
     */
    protected $userfrom;

    /**
     * Activity class for messages direct to users
     * @param array $data Parameters:
     *                    - userto (int)
     *                    - userfrom (int)
     *                    - subject (string)
     *                    - message (string)
     *                    - parent (int)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
        if ($this->userfrom) {
            $this->fromuser = $this->userfrom;
        }
        $this->users = activity_get_users($this->get_id(), array($this->userto));
        $this->add_urltext(array(
            'key'     => 'Reply',
            'section' => 'group',
        ));
    }

    /**
     * Fetch the subject line text
     * @param object $user  A database user object
     * @return string
     */
    public function get_subject($user) {
        if (empty($this->subject)) {
            return get_string_from_language($user->lang, 'newusermessage', 'group',
                                            display_name($this->userfrom));
        }
        return $this->subject;
    }

    /**
     * Adjust the url for the message to use user/sendmessage.php to handle the reply
     *
     * @param integer $internalid ID of a notification_internal_activity row
     * @return boolean true
     */
    protected function update_url($internalid) {
        $this->url = 'user/sendmessage.php?id=' . $this->userfrom . '&replyto=' . $internalid . '&returnto=inbox';
        return true;
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('message', 'userto', 'userfrom');
    }

}

/**
 * Watchlist class for the messages sent relating to watching for changes in portfolios
 */
class ActivityTypeWatchlist extends ActivityType {

    /**
     * @var integer ID of the view
     */
    protected $view;

    /**
     * @var string|null Formatted name of the view author
     */
    protected $ownerinfo;

    /**
     * @var object View
     */
    protected $viewinfo;

    /**
     * Watchlist class for watchlist activity
     * @param array $data Parameters:
     *                    - view (int)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron) {
        parent::__construct($data, $cron);

        require_once('view.php');
        if ($this->viewinfo = new View($this->view)) {
            $this->ownerinfo = hsc($this->viewinfo->formatted_owner());
        }
        if (empty($this->ownerinfo)) {
            if (!empty($this->cron)) { // probably deleted already
                return;
            }
            throw new ViewNotFoundException(get_string('viewnotfound', 'error', $this->view));
        }
        $viewurl = $this->viewinfo->get_url(false);

        // mysql compatibility (sigh...)
        $casturl = 'CAST(? AS TEXT)';
        if (is_mysql()) {
            $casturl = '?';
        }
        $sql = 'SELECT u.*, wv.unsubscribetoken, p.method, ap.value AS lang, ' . $casturl . ' AS url
                    FROM {usr_watchlist_view} wv
                    JOIN {usr} u
                        ON wv.usr = u.id
                    LEFT JOIN {usr_activity_preference} p
                        ON p.usr = u.id
                    LEFT OUTER JOIN {usr_account_preference} ap
                        ON (ap.usr = u.id AND ap.field = \'lang\')
                    WHERE (p.activity = ? OR p.activity IS NULL)
                    AND wv.view = ?
               ';
        $this->users = get_records_sql_array(
            $sql,
            array($viewurl, $this->get_id(), $this->view)
        );

        // Remove the view from the watchlist of users who can no longer see it
        if ($this->users) {
            $userstodelete = array();
            foreach($this->users as $k => &$u) {
                if (!can_view_view($this->view, $u->id)) {
                    $userstodelete[] = $u->id;
                    unset($this->users[$k]);
                }
            }
            if ($userstodelete) {
                delete_records_select(
                    'usr_watchlist_view',
                    'view = ? AND usr IN (' . join(',', $userstodelete) . ')',
                    array($this->view)
                );
            }
        }

        $this->add_urltext(array('key' => 'View', 'section' => 'view'));
    }

    /**
     * Fetch the subject line text
     * @param object $user  A database user object
     * @return string
     */
    public function get_subject($user) {
        return get_string_from_language($user->lang, 'newwatchlistmessage', 'activity');
    }

    /**
     * Fetch the body message text
     * @param object $user  A database user object
     * @return string
     */
    public function get_message($user) {
        return get_string_from_language($user->lang, 'newwatchlistmessageview1', 'activity',
                                        $this->viewinfo->get('title'), $this->ownerinfo);
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('view');
    }
}

/**
 * Watchlistnotification class to deal with the settings of the watchlist block
 * Extending ActivityTypeWatchlist to reuse the functionality and structure
 */
class ActivityTypeWatchlistnotification extends ActivityTypeWatchlist{

    /**
     * @var integer view ID
     */
    protected $view;

    /**
     * @var array An array of block ids
     */
    protected $blocktitles = array();

    /**
     * @var integer user ID
     */
    protected $usr;

    /**
     * Watchlist notifications class
     * @param array $data Parameters:
     *                    - view (int)
     *                    - blocktitles (array: int)
     *                    - usr (int)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron) {
        parent::__construct($data, $cron);

        $this->blocktitles = $data->blocktitles;
        $this->usr = $data->usr;
        $this->unsubscribelink = get_config('wwwroot') . 'view/unsubscribe.php?a=watchlist&t=';
        $this->unsubscribetype = 'watchlist';
    }

    /**
     * override function get_message to add information about the changed
     * blockinstances
     *
     * @param object $user  A database user object
     * @return string
     */
    public function get_message($user) {
        $message = get_string_from_language($user->lang, 'newwatchlistmessageview1', 'activity',
                                        $this->viewinfo->get('title'), $this->ownerinfo);

        try {
            foreach ($this->blocktitles as $blocktitle) {
                $message .= "\n" . get_string_from_language($user->lang, 'blockinstancenotification', 'activity', $blocktitle);
            }
        }
        catch(Exception $exc) {
            var_log(var_export($exc, true));
        }

        return $message;
    }

    /**
     * overwrite get_type to obfuscate that we are not really an Activity_type
     */
    public function get_type() {
        return('watchlist');
    }
}

/**
 * ViewAccess class for the messages sent relating to being granted access to portfolios
 * This one only deals with new access and not the revocation of access
 */
class ActivityTypeViewAccess extends ActivityType {

    /**
     * @var integer view ID
     */
    protected $view;

    /**
     * @var array containing ids of users that had access before the change - this can be empty though
     */
    protected $oldusers;

    /**
     * @var array containing ids of all the views being changed - optional
     */
    protected $views;

    /**
     * @var string title of the view
     */
    private $title;

    /**
     * @var string formatted name of the author
     */
    private $ownername;

    /**
     * The activity class for saving / updating view access
     * @param array $data Parameters:
     *                    - view (int)
     *                    - oldusers (array of user IDs)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        parent::__construct($data, $cron);
        if (!$viewinfo = new View($this->view)) {
            if (!empty($this->cron)) { // probably deleted already
                return;
            }
            throw new ViewNotFoundException(get_string('viewnotfound', 'error', $this->view));
        }
        if ($this->views && $this->views[0] && $this->views[0]['collection_id']) {
            require_once('collection.php');
            if (!$collectioninfo = new Collection($this->views[0]['collection_id'])) {
                if (!empty($this->cron)) { // probably deleted already
                    return;
                }
                throw new ViewNotFoundException(get_string('collectionnotfound', 'error', $this->views[0]['collection_id']));
            }
        }

        // default url
        $this->url = 'view/sharedviews.php';
        // if we are dealing with one portfolio update url to go to that portfolio page
        if (!$this->views) {
            //we are dealing with a single page
            $this->url = get_config('wwwroot') . 'view/view.php?id=' . $this->view;
            $this->add_urltext(array('key' => 'Portfolio', 'section' => 'view'));
        }
        else {
            // check to see if it's just one collection
            if ($collectionids = array_column($this->views, 'collection_id')) {
                if (count(array_unique($collectionids)) === 1) {
                    if ($this->views[0]['collection_url']) {
                        $this->url = $this->views[0]['collection_url'];
                        $this->add_urltext(array('key' => 'Collection', 'section' => 'view'));
                    }
                }
            }
        }

        $this->users = array_diff_key(
            activity_get_viewaccess_users($this->view),
            $this->oldusers
        );
        if (!$viewinfo->get_collection()) {
            $this->title = $viewinfo->get('title');
        }
        $this->ownername = $viewinfo->formatted_owner();
        $this->overridemessagecontents = true;
    }

    /**
     * Fetch the subject line text based on the number of portfolio titles
     * @param object $user  A database user object
     * @return string
     */
    public function get_subject($user) {
        $subject = get_string('newaccessubjectdefault', 'activity');
        if ($titles = $this->get_view_titles_urls($user)) {
            //covers collection(s), page(s) and combination of both
            if ($this->ownername) {
                $subject = get_string('newaccesssubjectname', 'activity', count($titles), $this->ownername);
            }
            else {
                $subject = get_string('newaccesssubject', 'activity', count($titles));
            }
        }
        else {
            //dealing with a single page
            if ($this->ownername) {
                $subject = get_string('newaccesssubjectname', 'activity', 1, $this->ownername);
            }
            else {
                $subject = get_string('newaccesssubject', 'activity', 1);
            }
        }
        return $subject;
    }

    /**
     * Fetch message based on the access rules
     *
     * @param object $user  A database user object
     * @return string
     */
    public function get_view_access_message($user) {
        $accessdates = activity_get_viewaccess_user_dates($this->view, $user->id);
        $accessdatemessage = '';
        $fromdate = format_date(strtotime($accessdates['mindate']), 'strftimedate');
        $todate = format_date(strtotime($accessdates['maxdate']), 'strftimedate');
        if (!empty($accessdates['mindate']) && !empty($accessdates['maxdate'])) {
            $accessdatemessage .= get_string_from_language($user->lang, 'messageaccessfromto1', 'activity', $fromdate, $todate);
        }
        else if (!empty($accessdates['mindate'])) {
            $accessdatemessage .= get_string_from_language($user->lang, 'messageaccessfrom1', 'activity', $fromdate);
        }
        else if (!empty($accessdates['maxdate'])) {
            $accessdatemessage .= get_string_from_language($user->lang, 'messageaccessto1', 'activity', $todate);
        }
        else {
            $accessdatemessage = false;
        }
        return $accessdatemessage;
    }

    /**
     * Fetch the titles of all the views
     *
     * @param object $user  A database user object
     * @return array|false A nested array of titles and urls
     */
    public function get_view_titles_urls($user) {
        $items = array();
        if (!empty($this->views)) {
            //handle collection(s), page(s) and combination of both
            $views = $this->views;
            foreach ($views as $view) {
                if ($view['collection_id']) {
                    //collections
                    $url = $view['collection_url'];
                    if (get_config('emailexternalredirect')) {
                        $url = append_email_institution($user, $url);
                    }
                    $items[$view['collection_id']] = [
                        'name' => $view['collection_name'],
                        'url'  => $url,
                    ];
                }
                else {
                    //pages outside of collections
                    $url = get_config('wwwroot') . 'view/view.php?id=' . $view['id'];
                    if (get_config('emailexternalredirect')) {
                        $url = append_email_institution($user, $url);
                    }
                    $items[$view['id']] = [
                        'name' => $view['title'],
                        'url' => $url,
                    ];
               }
            }
            return $items;
        }
        return false;
    }

    /**
     * Internal function to get a formatted message based on template
     *
     * @param object $user  A database user object
     * @param string $template Name of the .tpl file
     * @return string Message body
     */
    public function _getmessage($user, $template) {
        $accessitems = array();
        if ($items = $this->get_view_titles_urls($user)) {
            $accessitems = $items;
        }
        else {
            //we are dealing with a single page
            $url = get_config('wwwroot') . 'view/view.php?id=' . $this->view;
            if (get_config('emailexternalredirect')) {
                $url = append_email_institution($user, $url);
            }
            $accessitems[$this->view] = [
                'name' => $this->title,
                'url' => $url,
            ];
        }
        $accessdatemessage = ($this->view && $user->id) ? $this->get_view_access_message($user) : null;
        $prefurl = get_config('wwwroot') . 'account/activity/preferences/index.php';
        if (get_config('emailexternalredirect')) {
            $prefurl = append_email_institution($user, $prefurl);
        }
        $sitename = get_config('sitename');

        $smarty = smarty_core();
        $smarty->assign('accessitems', $accessitems);
        $smarty->assign('accessdatemsg', $accessdatemessage . "\n");
        $smarty->assign('url', (get_config('emailexternalredirect') ? append_email_institution($user, $this->url) : $this->url));
        $smarty->assign('sitename', $sitename);
        $smarty->assign('prefurl', $prefurl);
        $messagebody = $smarty->fetch($template);

        return $messagebody;
    }

    /**
     * Fetch the body message text
     * @param object $user  A database user object
     * @return string
     */
    public function get_message($user) {
        return strip_tags($this->_getmessage($user, 'account/activity/accessinternal.tpl'));
    }

    /**
     * Fetch an Plain Text formatted message to send via email
     * @param object $user  A database user object
     * @todo This should be inherited from abstract class
     * @return string
     */
    public function get_emailmessage($user) {
        return strip_tags($this->_getmessage($user, 'account/activity/accessemail.tpl'));
    }

    /**
     * Fetch an HTML formatted message to send via email
     * @param object $user  A database user object
     * @todo This should be inherited from abstract class
     * @return string
     */
    public function get_htmlmessage($user) {
        return $this->_getmessage($user, 'account/activity/accessemail.tpl');
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('view', 'oldusers');
    }
}
/**
 * Extends ActivityType to handle the notification when removing
 * access from a view. The access needs to be a 1 to 1 share to user
 */
class ActivityTypeViewAccessRevoke extends ActivityType {

    protected $viewid;
    protected $string; // this can be empty though
    protected $fromid;
    protected $toid;
    protected $destinationuser;
    protected $originuser;
    protected $viewinfo;
    protected $message;

    /**
     * @param array $data Parameters:
     *                    - viewid (int)
     *                    - Message (Text)
     *                    - Fromid (int)
     *                    - toid (int)
     */
    public function __construct($data, $cron=false) {
        $this->message = $data->message;
        parent::__construct($data, $cron);
        if (!$this->viewinfo = new View($this->viewid)) {
            if (!empty($this->cron)) { // probably deleted already
                  return;
            }
            throw new ViewNotFoundException(get_string('viewnotfound', 'error', $this->viewid));
        }
        if (!$this->destinationuser = get_user($this->toid)) {
            if (!empty($this->cron)) { // probably deleted already
                  return;
            }
            throw new UserNotFoundException(get_string('usernotfound', 'error', $this->touser));
        }
        if (!$this->originuser = get_user($this->fromid)) {
            if (!empty($this->cron)) { // probably deleted already
                  return;
            }
            throw new UserNotFoundException(get_string('usernotfound', 'error', $this->fromid));
        }
        $this->url = 'view/share.php';
        $this->users = array($this->destinationuser);
        if ($this->viewinfo->get('collection')) {
            $this->viewtitle = $this->viewinfo->get('collection')->get('name');
        }
        else {
            $this->viewtitle = $this->viewinfo->display_title(true, false, false);
        }
        //Required for html emails to function.
        $this->overridemessagecontents = true;
    }

    public function _getmessage($user, $template) {
        $prefurl = get_config('wwwroot') . 'account/activity/preferences/index.php';
        if (get_config('emailexternalredirect')) {
            $prefurl = append_email_institution($user, $prefurl);
        }

        $sitename = get_config('sitename');
        $fullname = display_name($this->originuser, $user);
        $smarty = smarty_core();
        $smarty->assign('url', (get_config('emailexternalredirect') ? append_email_institution($user, $this->url) : $this->url));
        $smarty->assign('viewtitle', htmlspecialchars_decode($this->viewtitle)); //The htmlspecialcharacters encoding of the title and the message is done in the template.
        $smarty->assign('message', $this->message);
        $smarty->assign('fullname', $fullname);
        $smarty->assign('sitename', $sitename);
        $smarty->assign('prefurl', $prefurl);
        $smarty->assign('revokedbyowner',  $this->is_revoked_by_owner());
        $messagebody = $smarty->fetch($template);
        return $messagebody;
    }

    public function get_message($user) {
        return strip_tags($this->_getmessage($user, 'account/activity/accessrevokeinternal.tpl'));
    }

    public function get_emailmessage($user) {
        return strip_tags($this->_getmessage($user, 'account/activity/accessrevokeemail.tpl'));
    }

    public function get_htmlmessage($user) {
        return $this->_getmessage($user, 'account/activity/accessrevokeemailhtml.tpl');
    }

    public function get_subject($user) {
        // revoked by owner
        if ($this->is_revoked_by_owner()) {
            $subject = get_string(
                'ownerhasremovedaccesssubject',
                'collection',
                display_name($this->originuser, $user),
                hsc($this->viewtitle)
            );
        }
        else {
             // self revoked by other/verifier
            $subject = get_string(
                'userhasremovedaccesssubject',
                'collection',
                display_name($this->originuser, $user),
                hsc($this->viewtitle)
            );
        }
        return $subject;
    }

    public function get_required_parameters() {
        return array('viewid', 'message', 'fromid', 'toid');
    }

    function is_revoked_by_owner() {
        $portfolioowner = $this->viewinfo->get('owner');
        return $portfolioowner === $this->originuser->id;
    }
}


/**
 * GroupMessage class for the messages sent relating to groups and group roles
 */
class ActivityTypeGroupMessage extends ActivityType {

    /**
     * @var integer group ID
     */
    protected $group;

    /**
     * @var array group roles
     */
    protected $roles;

    /**
     * @var boolean Whether the group is deleted
     */
    protected $deletedgroup;

    /**
     * Activity for group messages
     * @param array $data Parameters:
     *                    - group (integer)
     *                    - roles (list of roles)
     *                    - deletedgroup (boolean)
     * @param boolean $cron Indicates whether this is being called by the cron job
     */
    public function __construct($data, $cron=false) {
        require_once('group.php');

        parent::__construct($data, $cron);
        $members = group_get_member_ids($this->group, isset($this->roles) ? $this->roles : null, $this->deletedgroup);
        if (!empty($members)) {
            $this->users = activity_get_users($this->get_id(), $members);
        }
    }

    /**
     * Get the minimum required data parameters for this activity type
     * @return array
     */
    public function get_required_parameters() {
        return array('group');
    }
}
/**
 * Plugin abstract class for adding activity types via a plugin
 * When making new activity types within your plugin they should use this as parent class
 */
abstract class ActivityTypePlugin extends ActivityType {

    /**
     * Fetch the plugin type, eg 'artefact'
     */
    abstract public function get_plugintype();

    /**
     * Fetch the plugin name, eg 'comment'
     */
    abstract public function get_pluginname();

    /**
     * Get the class name based on plugin type and name
     * @return string
     */
    public function get_type() {
        $prefix = 'ActivityType' . $this->get_plugintype() . $this->get_pluginname();
        return strtolower(substr(get_class($this), strlen($prefix)));
    }

    /**
     * Get the ID of the plugin type from database
     * @return integer
     */
    public function get_id() {
        if (!isset($this->id)) {
            $tmp = activity_locate_typerecord($this->get_type(), $this->get_plugintype(), $this->get_pluginname());
            $this->id = $tmp->id;
        }
        return $this->id;
    }
}

/**
 * Format the notification so that it displays ok in both inbox and email
 * @param string $message    The body message of the notification
 * @param string|null $type  The message type
 * @return string            The formatted message
 */
function format_notification_whitespace($message, $type=null) {
    $message = preg_replace('/<br( ?\/)?>/', '', $message);
    $message = preg_replace('/^(\s|&nbsp;|\xc2\xa0)*/', '', $message);
    // convert any htmlspecialchars back so we don't double escape as part of format_whitespace()
    $message = htmlspecialchars_decode($message);
    $message = format_whitespace($message);
    // @todo Sensibly distinguish html notifications, notifications where the full text
    // appears on another page and this is just an abbreviated preview, and text-only
    // notifications where the entire text must appear here because there's nowhere else
    // to see it.
    $replace = ($type == 'newpost' || $type == 'feedback') ? '<br>' : '<br><br>';
    return preg_replace('/(<br( ?\/)?>\s*){2,}/', $replace, $message);
}

/**
 * Get a table of elements that can be used to set notification settings for the specified user, or for the site defaults.
 *
 * @param object $user whose settings are being displayed or...
 * @param bool $sitedefaults true if the elements should be loaded from the site default settings.
 * @return array of elements suitable for adding to a pieforms form.
 */
function get_notification_settings_elements($user = null, $sitedefaults = false) {
    global $SESSION;

    if ($user == null && !$sitedefaults) {
        throw new SystemException("Function get_notification_settings_elements requires a user or sitedefaults must be true");
    }

    if ($sitedefaults || $user->get('admin')) {
        $activitytypes = get_records_array('activity_type', '', '', 'id');
    }
    else {
        $activitytypes = get_records_array('activity_type', 'admin', 0, 'id');
        $activitytypes = get_special_notifications($user, $activitytypes);
    }

    $notifications = plugins_installed('notification');

    $elements = array();

    $options = array();
    foreach ($notifications as $notification) {
        $options[$notification->name] = get_string('name', 'notification.' . $notification->name);
    }

    $maildisabledmsg = false;
    foreach ($activitytypes as $type) {
        // Find the default value.
        if ($sitedefaults) {
            $dv = $type->defaultmethod;
        }
        else {
            $dv = $user->get_activity_preference($type->id);
            if ($dv === false) {
                $dv = $type->defaultmethod;
            }
        }
        if (empty($dv)) {
            $dv = 'none';
        }

        // Create one maildisabled error message if applicable.
        if (!$sitedefaults && $dv == 'email' && !isset($maildisabledmsg) && get_account_preference($user->get('id'), 'maildisabled')) {
            $SESSION->add_error_msg(get_string('maildisableddescription', 'account', get_config('wwwroot') . 'account/index.php'), false);
            $maildisabledmsg = true;
        }

        // Calculate the key.
        if (empty($type->plugintype)) {
            $key = "activity_{$type->name}";
        }
        else {
            $key = "activity_{$type->name}_{$type->plugintype}_{$type->pluginname}";
        }

        // Find the row title and section.
        $rowtitle = $type->name;
        if (!empty($type->plugintype)) {
            $section = $type->plugintype . '.' . $type->pluginname;
        }
        else {
            $section = 'activity';
        }

        // Create the element.
        $elements[$key] = array(
            'defaultvalue' => $dv,
            'type' => 'select',
            'title' => get_string('type' . $rowtitle, $section),
            'options' => $options,
            'help' => true,
        );

        // Set up the help.
        $elements[$key]['helpformname'] = 'activityprefs';
        if (empty($type->plugintype)) {
            $elements[$key]['helpplugintype'] = 'core';
            $elements[$key]['helppluginname'] = 'account';
        }
        else {
            $elements[$key]['helpplugintype'] = $type->plugintype;
            $elements[$key]['helppluginname'] = $type->pluginname;
        }

        // Add the 'none' option if applicable.
        if ($type->allownonemethod) {
            $elements[$key]['options']['none'] = get_string('none');
        }
    }

    $title = array();
    foreach ($elements as $key => $row) {
      $title[$key] = $row['title'];
    }
    array_multisort($title, SORT_ASC, $elements);

    return $elements;
}

/**
 * Save the notification settings.
 *
 * @param array $values returned from submitting a pieforms form.
 * @param object $user whose settings are being updated or...
 * @param bool $sitedefaults true if the elements should be saved to the site default settings.
 */
function save_notification_settings($values, $user = null, $sitedefaults = false) {
    if ($user == null && !$sitedefaults) {
        throw new SystemException("Function save_notification_settings requires a user or sitedefaults must be true");
    }

    if ($sitedefaults || $user->get('admin')) {
        $activitytypes = get_records_array('activity_type');
    }
    else {
        $activitytypes = get_records_array('activity_type', 'admin', 0);
        $activitytypes = get_special_notifications($user, $activitytypes);
    }

    foreach ($activitytypes as $type) {
        if (empty($type->plugintype)) {
            $key = "activity_{$type->name}";
        }
        else {
            $key = "activity_{$type->name}_{$type->plugintype}_{$type->pluginname}";
        }
        $value = $values[$key] == 'none' ? null : $values[$key];
        if ($sitedefaults) {
            execute_sql("UPDATE {activity_type} SET defaultmethod = ? WHERE id = ?", array($value, $type->id));
        }
        else {
            $user->set_activity_preference($type->id, $value);
        }
    }
}

/**
 * Get special case activity types.
 * Currently checks if a non admin is an admin/moderator of a group and
 * adds that notification type to the array.
 *
 * @param object $user whose settings are being displayed
 * @param array  $activitytypes array of elements
 * @return array $activitytypes amended array of elements
 */
function get_special_notifications($user, $activitytypes) {
    if (empty($user)) {
        return $activitytypes;
    }
    // Check if the non-admin is a group admin/moderator in any of their groups
    if ($user->get('grouproles') !== null) {
        $groups = $user->get('grouproles');
        $allowreportpost = false;
        foreach ($groups as $group => $role) {
            if ($role == 'admin') {
                $allowreportpost = true;
                break;
            }
            else if ($moderator = get_record_sql("SELECT i.id
                FROM {interaction_forum_moderator} m, {interaction_instance} i
                WHERE i.id = m.forum AND i.group = ? AND i.deleted = 0 and m.user = ?", array($group, $user->get('id')))) {
                $allowreportpost = true;
                break;
            }
        }
        if ($allowreportpost) {
            // Add the reportpost option to the $activitytypes
            $reportpost = get_records_array('activity_type', 'name', 'reportpost', 'id');
            $activitytypes = array_merge($activitytypes, $reportpost);
        }
    }

    // If user is an institution admin, should receive objectionable material notifications
    if ($user->is_institutional_admin()) {
        $objectionable = get_records_array('activity_type', 'name', 'objectionable', 'id');
        $activitytypes = array_merge($activitytypes, $objectionable);
    }

    return $activitytypes;
}

/**
 * Append the authentication method ID to the URL in the email
 * if the authentication method for the person has external login
 * so that they get redirected to the external login page if not
 * currently logged into Mahara
 * @param object $user A database object of a usr row
 * @param string $url  A URL string to update
 * @return string An updated URL
 */
function append_email_institution($user, $url) {
    if (!isset($user->id) || (isset($user->id) && empty($user->id))) {
        return $url;
    }
    // Ignore auth methods 'internal' and 'ldap' as they login direct with login box
    $local = array('internal', 'ldap');
    if ($auth = get_field_sql("SELECT ai.id
                                FROM {usr} u
                                JOIN {auth_instance} ai ON ai.id = u.authinstance
                                AND ai.authname NOT IN (" . join(',',  array_map('db_quote', $local)) . ")
                                AND u.id = ?", array($user->id))) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'authid=' . $auth;
    }
    return $url;
}
