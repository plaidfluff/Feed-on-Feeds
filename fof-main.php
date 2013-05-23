<?php
/*
 * This file is part of FEED ON FEEDS - http://feedonfeeds.com/
 *
 * fof-main.php - initializes FoF, and contains functions used from other scripts
 *
 *
 * Copyright (C) 2004-2007 Stephen Minutillo
 * steve@minutillo.com - http://minutillo.com/steve/
 *
 * Distributed under the GPL - see LICENSE
 *
 */

/*
    globals controlling behavior:
        $fof_no_login -- does not force auth redirect if true
        $fof_installer -- forces auth to admin, changes log file

        $fof_logall -- forces logging on
        $fof_tracelog -- enables ridiculous logging for debugging
*/

/* quiet warnings, default to UTC */
date_default_timezone_set('UTC');

fof_repair_drain_bamage();

if ( (@include_once('fof-config.php')) === false ) {
    echo "You will first need to create a fof-config.php file.  Please copy fof-config-sample.php to fof-config.php and then update the values to match your database settings.\n";
    die();
}

require_once('fof-asset.php');
require_once('fof-db.php');
require_once('classes/fof-prefs.php');

fof_db_connect();

if (empty($fof_installer)) {
    if (empty($fof_no_login)) {
        require_user();
    } else {
        $fof_user_id = 1;
    }

    $fof_prefs_obj =& FoF_Prefs::instance();

    ob_start();
    fof_init_plugins();
    ob_end_clean();
}

require_once('autoloader.php');
require_once('simplepie/SimplePie.php');

function fof_set_content_type($type='text/html')
{
    static $set;
    if ( ! $set)
    {
        header("Content-Type: $type; charset=utf-8");
        $set = true;
    }
}

function fof_log($message, $topic='debug')
{
    global $fof_prefs_obj;
    global $fof_installer;
    global $fof_logall;
    static $log;

    if ( (empty($fof_prefs_obj) || empty($fof_prefs_obj->admin_prefs['logging']))
    &&  empty($fof_logall)
    &&  empty($fof_installer) )
        return;

    if ( ! isset($log)) {
        $log_path = (defined('FOF_DATA_PATH') ? FOF_DATA_PATH : '.');
        $log_file = (empty($fof_installer) ? 'fof.log' : 'fof-install.log');
        $log = @fopen(implode(DIRECTORY_SEPARATOR, array($log_path, $log_file)), 'a');
    }

    if ( ! $log)
        return;

    $message = str_replace ("\n", "\\n", $message);
    $message = str_replace ("\r", "\\r", $message);

    fwrite($log, date('r') . " [$topic] $message\n");
}

/* deeper log, for debugging */
function fof_trace($message=NULL) {
    global $fof_tracelog;

    if (empty($fof_tracelog))
        return;

    $bt = debug_backtrace();
    $frame = $bt[1];
    $first_frame = end($bt);

    $args = array();
    foreach ($frame['args'] as $k=>$v)
        $args[] = var_export($v,true);

    $where = basename($first_frame['file']) . '>' . basename($frame['file']) . ':' . $frame['line'] . ':' . $frame['function'] . '(' . implode(', ', $args) . ')';

    if (isset($message))
        $where .= ': ' . $message;
    fof_log($where, 'trace');
}

function fof_authenticate($user_name, $user_password_hash)
{
    global $fof_user_name;

    if (fof_db_authenticate_hash($user_name, $user_password_hash))
    {
        $user_login_expire_time = time() + (60 * 60 * 24 * 365 * 10); /* 10 years */
        setcookie('user_name', $fof_user_name, $user_login_expire_time);
        setcookie('user_password_hash', $user_password_hash, $user_login_expire_time);
        return true;
    }

    return false;
}

function require_user()
{
    global $fof_user_id, $fof_user_name, $fof_user_level;

    if (defined('FOF_AUTH_EXTERNAL') && ! empty($_SERVER['REMOTE_USER'])) {
        $user_row = fof_db_get_user($_SERVER['REMOTE_USER']);
        if (empty($user_row)) {
            fof_log('user \'' . $_SERVER['REMOTE_USER'] . '\' not indexed', 'auth');

            if (defined('FOF_AUTH_EXTERNAL_ADD')) {
                $result = fof_db_add_user($_SERVER['REMOTE_USER'], 'unused password' . $_SERVER['UNIQUE_ID']);
                $user_row = fof_db_get_user($_SERVER['REMOTE_USER']);
                fof_log('added new index for user \'' . $_SERVER['REMOTE_USER'] . '\'', 'auth');
            }
        }
        if ( ! empty($user_row)) {
            $fof_user_id = $user_row['user_id'];
            $fof_user_name = $user_row['user_name'];
            $fof_user_level = $user_row['user_level'];

            fof_log('user \'' . $_SERVER['REMOTE_USER'] . '\' established', 'auth');
            return true;
        }
        if (defined('FOF_AUTH_EXTERNAL_ONLY'))
            return false;
    }

    if ( ! isset($_COOKIE['user_name']) || ! isset($_COOKIE['user_password_hash']))
    {
        header('Location: login.php');
        exit();
    }

    $user_name = $_COOKIE['user_name'];
    $user_password_hash = $_COOKIE['user_password_hash'];

    if ( ! fof_authenticate($user_name, $user_password_hash))
    {
        header('Location: login.php');
        exit();
    }
}

function fof_logout()
{
    setcookie('user_name', '', time());
    setcookie('user_password_hash', '', time());
}

function fof_current_user()
{
    global $fof_user_id;

    return $fof_user_id;
}

function fof_username()
{
    global $fof_user_name;

    return $fof_user_name;
}

function fof_prefs()
{
    $p =& FoF_Prefs::instance();
    return $p->prefs;
}

function fof_is_admin()
{
    global $fof_user_level;

    return $fof_user_level == 'admin';
}

function fof_get_tags($user_id)
{
    $tags = array();

    $counts = fof_db_get_tag_unread($user_id);
    $statement = fof_db_get_tags($user_id);
    while ( ($row = fof_db_get_row($statement)) !== false )
    {
        if(isset($counts[$row['tag_id']]))
          $row['unread'] = $counts[$row['tag_id']];
        else
          $row['unread'] = 0;

        $tags[] = $row;
    }

    return $tags;
}

function fof_get_item_tags($user_id, $item_id)
{
    $result = fof_db_get_item_tags($user_id, $item_id);

    $tags = array();

    while ( ($row = fof_db_get_row($result)) !== false )
    {
        $tags[] = $row['tag_name'];
    }

    return $tags;
}

function fof_tag_feed($user_id, $feed_id, $tag)
{
    $tag_id = fof_db_get_tag_by_name($tag);
    if($tag_id == NULL)
    {
        $tag_id = fof_db_create_tag($tag);
    }

    $result = fof_db_get_items($user_id, $feed_id, $what='all', NULL, NULL);

    $items = array();
    foreach($result as $r)
    {
        $items[] = $r['item_id'];
    }

    fof_db_tag_items($user_id, $tag_id, $items);

    fof_db_tag_feed($user_id, $feed_id, $tag_id);
}

function fof_untag_feed($user_id, $feed_id, $tag)
{
    $tag_id = fof_db_get_tag_by_name($tag);
    if($tag_id == NULL)
    {
        $tag_id = fof_db_create_tag($tag);
    }

    $result = fof_db_get_items($user_id, $feed_id, $what="all", NULL, NULL);

    $items = array();
    foreach($result as $r)
    {
        $items[] = $r['item_id'];
    }

    fof_db_untag_items($user_id, $tag_id, $items);

    fof_db_untag_feed($user_id, $feed_id, $tag_id);
}

function fof_tag_item($user_id, $item_id, $tag)
{
    $tags = is_array($tag) ? $tag : array($tag);

    foreach($tags as $tag)
    {
        // remove tag, if it starts with '-'
        if ( $tag{0} == '-' )
        {
            fof_untag_item($user_id, $item_id, substr($tag, 1));
            continue;
        }

        $tag_id = fof_db_get_tag_by_name($tag);
        if($tag_id == NULL)
        {
            $tag_id = fof_db_create_tag($tag);
        }

        fof_db_tag_items($user_id, $tag_id, $item_id);
    }
}

function fof_untag_item($user_id, $item_id, $tag)
{
    $tag_id = fof_db_get_tag_by_name($tag);
    fof_db_untag_items($user_id, $tag_id, $item_id);
}

function fof_untag($user_id, $tag)
{
    $tag_id = fof_db_get_tag_by_name($tag);

    $result = fof_db_get_items($user_id, $feed_id, $tag, NULL, NULL);

    $items = array();
    foreach($result as $r)
    {
        $items[] = $r['item_id'];
    }

    fof_db_untag_items($user_id, $tag_id, $items);
}

function fof_nice_time_stamp($age)
{
    $age = time() - $age;

    if ($age == 0)
        return array(
            'never',
            '&infin;'
        );

    $days = floor($age / 60 / 60 / 24);
    if ($days > 365)
        return array(
            floor($days / 365) . ' year' . (floor($days / 365) == 1 ? '' : 's') . ' ago',
            floor($days / 365) . 'y'
        );
    else if ($days > 7)
        return array(
            floor($days / 7) . ' week' . (floor($days / 7) == 1 ? '' : 's') . ' ago',
            floor($days / 7) . 'w'
        );
    else if ($days)
        return array(
            $days . ' day' . ($days == 1 ? '' : 's') . ' ago',
            $days . 'd'
        );

    $hours = $age / 60 / 60 % 24;
    if ($hours)
        return array(
            $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago',
            $hours . 'h'
        );

    $minutes = $age / 60 % 60;
    if ($minutes)
        return array(
            $minutes . ' minute' . ($minutes == 1 ? '' : 's') . ' ago',
            $minutes . 'm'
        );

    $seconds = $age % 60;
    if ($seconds)
        return array(
            $seconds . ' second' . ($seconds == 1 ? '' : 's') . ' ago',
            $seconds . 's'
        );

    return array(
        'never',
        '&infin;'
    );
}

/* returns an array representing a user's view of a feed */
function fof_get_feed($user_id, $feed_id) {
    /* should verify user_id is subscribed to feed */

    $feed = fof_db_get_feed_by_id($feed_id);

    if ( ! empty($feed['alt_image']))
        $feed['feed_image'] = $feed['alt_image'];

    if ( ! empty($feed['subscription_prefs'])) {
        $feed['prefs'] = unserialize($feed['subscription_prefs']);
        unset($feed['subscription_prefs']);
    }
    if (empty($feed['prefs']))
        $feed['prefs'] = array('tags' => array());
    if (empty($feed['prefs']['tags']))
        $feed['prefs']['tags'] = array();
    $feed['tags'] = array();
    $tagmap = fof_db_get_tag_id_map();
    foreach ($feed['prefs']['tags'] as $tagid) {
        $feed['tags'][] = $tagmap[$tagid];
    }
    unset($tagmap);

    $feed['feed_items'] = 0;
    $feed['feed_read'] = 0;
    $statement = fof_db_get_item_count($user_id, 'all', $feed_id);
    while ( ($row = fof_db_get_row($statement)) !== false ) {
        $feed['feed_items'] += $row['count'];
        $feed['feed_read'] += $row['count'];
    }

    $feed['feed_unread'] = 0;
    $statement = fof_db_get_item_count($user_id, 'unread', $feed_id);
    while ( ($row = fof_db_get_row($statement)) !== false ) {
        $feed['feed_unread'] += $row['count'];
    }

    $feed['feed_starred'] = 0;
    $statement = fof_db_get_item_count($user_id, 'starred', $feed_id);
    while ( ($row = fof_db_get_row($statement)) !== false ) {
        $feed['feed_starred'] += $row['count'];
    }

    $feed['feed_tagged'] = 0;
    $statement = fof_db_get_item_count($user_id, 'tagged', $feed_id);
    while ( ($row = fof_db_get_row($statement)) !== false ) {
        $feed['feed_tagged'] += $row['count'];
    }

    $feed['feed_age'] = $feed['feed_cache_date'];
    list($feed['agestr'], $feed['agestrabbr']) = fof_nice_time_stamp($feed['feed_cache_date']);

    $feed['max_date'] = 0;
    $feed['lateststr'] = '';
    $feed['lateststrabbr'] = '';
    $statement = fof_db_get_latest_item_age($user_id);
    while ( ($row = fof_db_get_row($statement)) !== false) {
        if ($row['id'] == $feed['feed_id']) {
            $feed['max_date'] = $row['max_date'];
            list($feed['lateststr'], $feed['lateststrabbr']) = fof_nice_time_stamp($row['max_date']);
        }
    }

    return $feed;
}

function fof_get_feeds($user_id, $order = 'feed_title', $direction = 'asc')
{
    $feeds = array();

    $result = fof_db_get_subscriptions($user_id);

    $i = 0;
    while ( ($row = fof_db_get_row($result)) !== false )
    {
        $feeds[$i]['feed_id'] = $row['feed_id'];
        $feeds[$i]['feed_url'] = $row['feed_url'];
        $feeds[$i]['feed_title'] = $row['feed_title'];
        $feeds[$i]['feed_link'] = $row['feed_link'];
        $feeds[$i]['feed_description'] = $row['feed_description'];
        $feeds[$i]['feed_image'] = empty($row['alt_image']) ? $row['feed_image'] : $row['alt_image'];
        $feeds[$i]['alt_image'] = $row['alt_image'];

        $feeds[$i]['prefs'] = unserialize($row['subscription_prefs']);
        if ( empty($feeds[$i]['prefs']) )
            $feeds[$i]['prefs'] = array();
        if ( empty($feeds[$i]['prefs']['tags']) )
            $feeds[$i]['prefs']['tags'] = array();

        $feeds[$i]['tags'] = array();

        $feeds[$i]['max_date'] = 0;
        $feeds[$i]['feed_age'] = $row['feed_cache_date'];
        list($agestr, $agestrabbr) = fof_nice_time_stamp($row['feed_cache_date']);
        $feeds[$i]['agestr'] = $agestr;
        $feeds[$i]['agestrabbr'] = $agestrabbr;
        $feeds[$i]['lateststr'] = '';
        $feeds[$i]['lateststrabbr'] = '';

        $feeds[$i]['feed_items'] = 0;
        $feeds[$i]['feed_read'] = 0;
        $feeds[$i]['feed_unread'] = 0;
        $feeds[$i]['feed_starred'] = 0;
        $feeds[$i]['feed_tagged'] = 0;

        $i++;
    }

    /* I guess convert tag_ids in feed prefs to tag names on feed */
    $tags = fof_db_get_tag_id_map();
    for ($i=0; $i<count($feeds); $i++)
    {
        foreach($feeds[$i]['prefs']['tags'] as $tag)
        {
            $feeds[$i]['tags'][] = $tags[$tag];
        }
    }

    /* tally up all items */
    $result = fof_db_get_item_count($user_id);
    while ( ($row = fof_db_get_row($result)) !== false )
    {
        for($i=0; $i<count($feeds); $i++)
        {
            if($feeds[$i]['feed_id'] == $row['id'])
            {
                $feeds[$i]['feed_items'] += $row['count'];
                $feeds[$i]['feed_read'] += $row['count'];
            }
        }
    }

    /* tally up unread items */
    $result = fof_db_get_item_count($user_id, 'unread');
    while ( ($row = fof_db_get_row($result)) !== false )
    {
        for($i=0; $i<count($feeds); $i++)
        {
            if($feeds[$i]['feed_id'] == $row['id'])
            {
                $feeds[$i]['feed_unread'] += $row['count'];
            }
        }
    }

    /* tally up starred items */
    $result = fof_db_get_item_count($user_id, 'starred');
    while ( ($row = fof_db_get_row($result)) !== false )
    {
        for($i=0; $i<count($feeds); $i++)
        {
            if($feeds[$i]['feed_id'] == $row['id'])
            {
                $feeds[$i]['feed_starred'] += $row['count'];
            }
        }
    }

    /* tally up tags which aren't system-tags */
    $result = fof_db_get_item_count($user_id, 'tagged');
    while ( ($row = fof_db_get_row($result)) !== false )
    {
        for($i=0; $i<count($feeds); $i++)
        {
            if($feeds[$i]['feed_id'] == $row['id'])
            {
                $feeds[$i]['feed_tagged'] += $row['count'];
            }
        }
    }

    /* find most recent item for each feed */
    $result = fof_db_get_latest_item_age($user_id);
    while ( ($row = fof_db_get_row($result)) !== false )
    {
        for($i=0; $i<count($feeds); $i++)
        {
            if($feeds[$i]['feed_id'] == $row['id'])
            {
                $feeds[$i]['max_date'] = $row['max_date'];
                list($agestr, $agestrabbr) = fof_nice_time_stamp($row['max_date']);
                $feeds[$i]['lateststr'] = $agestr;
                $feeds[$i]['lateststrabbr'] = $agestrabbr;
            }
        }
    }

    return fof_multi_sort($feeds, $order, $direction != 'asc');
}

/* describe what is being displayed */
function fof_view_title($feed=NULL, $what='unread', $when=NULL, $start=NULL, $limit=NULL, $search=NULL, $itemcount = 0)
{
    $prefs = fof_prefs();
    $title = 'feed on feeds - showing';

    if ($itemcount) {
        if (is_numeric($start))
        {
            if ( ! is_numeric($limit))
                $limit = $prefs['howmany'];
            if ($start || $limit < $itemcount) {
                $end = $start + $limit;
                if ($end > $itemcount)
                    $end = $itemcount;
                $start += 1;
                if ($start != $end)
                    $title .= " $start to $end of ";
            }
        }

        $title .= " $itemcount";
    }
    $title .= ' item' . ($itemcount != 1 ? 's' : '');

    if ( ! empty($feed)) {
        $r = fof_db_get_feed_by_id($feed);
        $title .= ' in \'' . htmlentities($r['feed_title']) . '\'';
    }

    if (empty($what))
        $what = 'unread';

    if ($what != 'all') {
        $tags = explode(' ', $what);
        $last_tag = array_pop($tags);
        if ( ! empty($last_tag)) {
            $title .= ' tagged';
            if ( ! empty($tags)) {
                $title .= ' ' . implode(', ', $tags) . (count($tags) > 1 ? ',' : '') . ' and';
            }
            $title .= ' ' . $last_tag;
        }
    }

    if ( ! empty($when))
        $title .= ' from ' . $when ;

    if (isset($search)) {
        $title .= " <a href='javascript:toggle_highlight()'>matching <i class='highlight'>$search</i></a>";
    }

    return $title;
}

function fof_get_items($user_id, $feed=NULL, $what="unread", $when=NULL, $start=NULL, $limit=NULL, $order="desc", $search=NULL)
{
    global $fof_item_filters;

    $items = fof_db_get_items($user_id, $feed, $what, $when, $start, $limit, $order, $search);

    for($i=0; $i<count($items); $i++)
    {
        foreach($fof_item_filters as $filter)
        {
            $items[$i]['item_content'] = $filter($items[$i]['item_content']);
        }
    }

    return $items;
}

function fof_get_item($user_id, $item_id)
{
    global $fof_item_filters;

    $item = fof_db_get_item($user_id, $item_id);

    foreach($fof_item_filters as $filter)
    {
        $item['item_content'] = $filter($item['item_content']);
    }

    return $item;
}

function fof_delete_subscription($user_id, $feed_id)
{
    fof_db_delete_subscription($user_id, $feed_id);

    if (fof_db_get_subscribed_users_count($feed_id) == 0) {
        fof_db_delete_feed($feed_id);
    }
}

/* simple wrapper to construct a safe GET-style URL from location and variables */
function fof_url($path='.', $query_variables=array(), $fragment=NULL) {
    $url = $path;
    $qv = array();
    foreach ($query_variables as $k => $v)
        if ( ! empty($v))
            $qv[] = urlencode($k) . '=' . urlencode($v);

    if ( ! empty($qv) )
        $url .= '?' . implode('&', $qv);

    if ( ! empty($fragment) )
        $url .= '#' . urlencode($fragment);

    return $url;
}

function fof_get_nav_links($feed=NULL, $what='new', $when=NULL, $start=NULL, $limit=NULL, $search=NULL, $itemcount=9999)
{
    $prefs = fof_prefs();
    $navlinks = '';

    $qv = array('feed' => $feed,
                'what' => $what,
                'when' => $when,
                'search' => $search,
                'howmany' => $limit);

    if( ! empty($when)) {
        $begin = strtotime(($when == 'today') ? fof_todays_date() : $when);

        $tomorrow = date( "Y/m/d", $begin + (24 * 60 * 60) );
        $yesterday = date( "Y/m/d", $begin - (24 * 60 * 60) );

        $navlinks .= '<a href="' . fof_url('.', array_merge($qv, array('when' => $yesterday))) . '">[&laquo; ' . $yesterday . ']</a>';

        if ($when != "today") {
            $navlinks .= ' <a href="' . fof_url('.', array_merge($qv, array('when' => 'today'))) . '">[today]</a>';
            $navlinks .= ' <a href="' . fof_url('.', array_merge($qv, array('when' => 'tomorrow'))) . '">[' . $tomorrow . '&raquo;]</a>';
        }
    }

    if (is_numeric($start)) {
        if ( ! is_numeric($limit)) {
            $limit = isset($prefs['howmany']) ? $prefs['howmany'] : NULL;
            $qv['howmany'] = $limit;
        }

        if ($itemcount <= $limit)
            return '';

        $earlier = $start + $limit;
        $later = $start - $limit;

        $qv['how'] = 'paged';

        if($itemcount > $earlier) {
            $navlinks .= ' <a href="' . fof_url('.', array_merge($qv, array('which' => $earlier))) . '">[&laquo; previous ' . $limit . ']</a>';
            $navlinks .= ' <a href="' . fof_url('.', array_merge($qv, array('how' => 'unpaged'))) . '">[all-at-once]</a>';
        }

        if($later >= 0) {
            $navlinks .= ' <a href="' . fof_url('.', array_merge($qv, array('which' => $start))) . '">[current items]</a>';
            $navlinks .= ' <a href="' . fof_url('.', array_merge($qv, array('which' => $later))) . '">[next ' . $limit . ' &raquo;]</a>';
        }
    }

    return $navlinks;
}

function fof_render_feed_link($row)
{
    $p =& FoF_Prefs::instance();

    $link = $row['feed_link'];
    $description = htmlentities($row['feed_description']);
    $title = htmlentities($row['feed_title']);
    $url = $row['feed_url'];

    if ($title == "[no title]")
        $title = $link;
    if ($title == "[no link]")
        $title = $url;

    $s = "<b><a href=\"$link\" title=\"$description\"";
    if ($p->get('item_target'))
        $s .= " target=\"_blank\"";
    $s .= ">$title</a></b> ";
    $s .= "<a href=\"$url\">(rss)</a>";

    return $s;
}

function fof_opml_to_array($opml)
{
    $rx = "/xmlurl=\"(.*?)\"/mi";

    if (preg_match_all($rx, $opml, $m))
    {
        for($i = 0; $i < count($m[0]) ; $i++)
        {
            $r[] = $m[1][$i];
        }
    }

    return $r;
}

function fof_prepare_url($url)
{
    if (substr($url, 0, 7) == 'feed://')
        $url = substr($url, 7);

    if (substr($url, 0, 7) != 'http://'
    &&  substr($url, 0, 8) != 'https://')
        $url = 'http://' . $url;

    return $url;
}


function fof_subscribe($user_id, $url, $unread='today') {
    fof_trace();

    $url = trim($url);
    if (empty($url))
        return "<span style=\"color:red\">Error: <b>cannot subscribe to nothing</b> (empty url?)</span><br>\n";

    /* ensure url at least has a reasonable protocol */
    $url = fof_prepare_url($url);

    $feed = fof_db_get_feed_by_url($url);
    if (empty($feed)) {
        /* raw url does not exist, try cooking it with simplepie */
        if ( ($rss = fof_parse($url)) === false
        ||   isset($rss->error) ) {
            $rss_error = (isset($rss) && isset($rss->error)) ? $rss->error : '';
            return "<span style=\"color:red\">Error: <b>Failed to subscribe to '$url'</b>"
                   . (!empty($rss_error) ? ": $rss_error</span> <span><a href=\"http://feedvalidator.org/check?url=" . urlencode($url) . "\">try to validate it?</a>" : "")
                   . "</span><br>\n";
        }

        $self = $rss->get_link(0, 'self');
        $url = html_entity_decode( ($self ? $self : $rss->subscribe_url()), ENT_QUOTES);

        $feed = fof_db_get_feed_by_url($url);
        if (empty($feed)) {
            /* cooked url does not exist, add it */
            $new_feed_id = fof_db_add_feed($url, $rss->get_title(), $rss->get_link(), $rss->get_description() );
            if (empty($new_feed_id))
                return "<span style=\"color:red\">Error: <b>Failed to subscribe to '$url'</b></span<br>\n";
            $feed = fof_db_get_feed_by_id($new_feed_id);
            /* assert(!empty($feed)) */
        }
    }

    if (fof_db_is_subscribed($user_id, $url)) {
        return "<span>You are already subscribed to '" . fof_render_feed_link($feed) . "'.</span><br>\n";
    }

    /* subscribe to the feed */
    fof_db_add_subscription($user_id, $feed['feed_id']);

    /* set tags for user on any existing items */
    fof_apply_plugin_tags($feed['feed_id'], NULL, $user_id);

    /* update the feed */
    list($n, $err) = fof_update_feed($new_feed_id);
    if ( ! empty($err))
        return "<span style=\"color:red\">$err</span><br>\n";

    /* and set requested existing items unread */
    if ($unread != 'no')
        fof_db_mark_feed_unread($user_id, $feed['feed_id'], $unread);

    return "<span style=\"color:green\"><b>Subscribed to '" . fof_render_feed_link($feed) . "'.</b></span><br>\n";
}


function fof_mark_item_unread($feed_id, $id)
{
    $result = fof_db_get_subscribed_users($feed_id);
    $users = array();
    while (($user_id = fof_db_get_row($result, 'user_id')) !== false) {
        $users[] = $user_id;
    }
    fof_db_mark_item_unread($users, $id);
}

function fof_parse($url)
{
    if (empty($url)) {
        fof_log("got empty url");
        return false;
    }

    $p =& FoF_Prefs::instance();
    $admin_prefs = $p->admin_prefs;

    $pie = new SimplePie();
    $pie->set_cache_location(dirname(__FILE__).'/cache');
    $pie->set_cache_duration($admin_prefs["manualtimeout"] * 60);
    $pie->set_feed_url($url);
    $pie->remove_div(true);
    $pie->init();

    if ( $pie -> error() )
    {
        if ( ($data = file_get_contents($url)) === false) {
            fof_log("failed to fetch '$url'");
            return false;
        }

        $data = preg_replace ( '~.*<\?xml~sim', '<?xml', $data );

        #file_put_contents ('/tmp/text.xml',$data);

        unset ( $pie );

        $pie = new SimplePie();
        $pie->set_cache_location(dirname(__FILE__).'/cache');
        $pie->set_cache_duration($admin_prefs["manualtimeout"] * 60);
        $pie->remove_div(true);

        $pie -> set_raw_data ( $data );
        $pie -> init();
    }

    return $pie;
}

/* XXX: why is fof_subscription_to_tags a global? */
function fof_apply_tags($feed_id, $item_id)
{
    global $fof_subscription_to_tags;

    if ( ! isset($fof_subscription_to_tags)) {
        $fof_subscription_to_tags = fof_db_get_subscription_to_tags();
    }

    fof_trace("subs_to_tags:" . var_export($fof_subscription_to_tags, TRUE));

    if (isset($fof_subscription_to_tags[$feed_id])) {
        $feed_subs = $fof_subscription_to_tags[$feed_id];
        if (is_array($feed_subs) ) {
            foreach ($feed_subs as $user_id => $tags) {
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        fof_db_tag_items($user_id, $tag, $item_id);
                    }
                }
            }
        }
    }
}

/* returns array of number of items added, and status message to display */
function fof_update_feed($id)
{
    global $fof_item_prefilters;
    static $blacklist = null;
    static $admin_prefs = null;

    $ids = array();

    if ($blacklist === null) {
        $p =& FoF_Prefs::instance();
        $admin_prefs = $p->admin_prefs;
        $blacklist = preg_split('/(\r\n|\r|\n)/', isset($admin_prefs['blacklist']) ? $admin_prefs['blacklist'] : NULL, -1, PREG_SPLIT_NO_EMPTY);
    }

    if (empty($id))
        return array(0, '');

    $feed = fof_db_get_feed_by_id($id);
    if (empty($feed)) {
        fof_log("no such feed '$id'");
        return array(0, "Error: <b>no such feed '$id'</b>");
    }

    fof_log("updating feed_id:$id url:'" . $feed['feed_url'] . "'");

    fof_db_feed_mark_attempted_cache($id);

    if ( ($rss = fof_parse($feed['feed_url'])) === false
    ||   isset($rss->error) ) {
        $rss_error = (isset($rss) && isset($rss->error)) ? $rss->error : 'unknown error';
        fof_log("feed update failed feed_id:$id url:'" . $feed['feed_url'] . "': " . $rss_error);
        return array(0, "Error: <b>failed to parse feed '" . $feed['feed_url'] . "'</b>: $rss_error");
    }

    /* if an alt image is set, don't try to update image from feed */
    if ( ! empty($feed['alt_image'])) {
        $feed_image = $feed['alt_image'];
        $feed_image_cache_date = 0;
    } else {
        $feed_image = $feed['feed_image'];
        $feed_image_cache_date = $feed['feed_image_cache_date'];

        // cached favicon older than a week?
        if ( $feed [ 'feed_image_cache_date' ] < time() - ( 7*24*60*60 ) ) {
            $feed_image = fof_get_favicon($feed['feed_link']);
            $feed_image_cache_date = time();
        }
    }

    $feed_title = $rss->get_title();
    $feed_link = $rss->get_link();
    $feed_description = $rss->get_description();

    /* set the feed's current information */
    fof_db_feed_update_metadata($id, $feed_title, $feed_link, $feed_description, $feed_image, $feed_image_cache_date);

    $feed_id = $feed['feed_id'];
    $n = 0;

    // Set up the dynamic updates here, so we can include would-be-purged items
    $purgedUpdTimes = array();
    $count_Added = 0;

    if ($rss->get_items()) {
        foreach ($rss->get_items() as $item) {
            $title = $item->get_title();

            foreach ($blacklist as $bl)
                if (stristr($title, $bl) !== false)
                    continue 2;

            $link = $item->get_permalink();
            $content = $item->get_content();
            $date = $item->get_date('U');

            if (empty($date))
                $date = time();

            // don't fetch entries older than the purge limit
            if ( ! $date ) {
                $date = time();
            } elseif ( ! empty($admin_prefs['purge'])
            &&   $date <= ( time() - $admin_prefs['purge'] * 24 * 3600 ) ) {
                $purgedUpdTimes[] = $date;
                continue;
            }

            /* check if item already known */
            $item_id = $item->get_id();
            $id = fof_db_find_item($feed_id, $item_id);

            if ($id == NULL) {
                $n++;

                foreach ($fof_item_prefilters as $filter)
                    list($link, $title, $content) = $filter($item, $link, $title, $content);

                $id = fof_db_add_item($feed_id, $item_id, $link, $title, $content, time(), $date, $date);
                fof_apply_tags($feed_id, $id);
                $count_Added++;

                $republished = false;

                if ( ! $republished) {
                    fof_mark_item_unread($feed_id, $id);
                }

                fof_apply_plugin_tags($feed_id, $id, NULL);
            } else {
                fof_db_update_item($feed_id, $item_id, $link, time());
            }

            $ids[] = $id;
        }
    }

    unset($rss);

    if ( ! empty($admin_prefs['dynupdates']) )
    {
        // Determine the average time between items, to determine the next update time

        $count = 0;
        $lastTime = 0;
        $totalDelta = 0.0;
        $totalDeltaSquare = 0.0;

        // Accumulate the times for the pre-purged items
        sort ($purgedUpdTimes, SORT_NUMERIC);
        foreach ($purgedUpdTimes as $time) {
            if ($count > 0) {
                $delta = $time - $lastTime;
                $totalDelta += $delta;
                $totalDeltaSquare += $delta*$delta;
            }
            $lastTime = $time;
            $count++;
        }

        // Accumulate the times for the stored items
        $result = fof_db_items_updated_list($feed_id);
        while ($row = fof_db_get_row($result)) {
            if ($count > 0) {
                $delta = (float)($row['item_updated'] - $lastTime);
                $totalDelta += $delta;
                $totalDeltaSquare += $delta*$delta;
            }
            $count++;
            $lastTime = $row['item_updated'];
        }

        // Accumulate the time for 'now' (to give the window something to grow on)
        $delta = time() - $lastTime;
        if ($delta > 0 && $count > 0) {
            $totalDelta += $delta;
            $totalDeltaSquare += $delta*$delta;
            $count++;
        }

        $mean = 0;
        if ($count > 0) {
            $mean = $totalDelta/$count;
        }
        $stdev = 0;
        if ($count > 1) {
            $stdev = sqrt(($count*$totalDeltaSquare - $totalDelta*$totalDelta)
                          /($count * ($count - 1)));
        }
       
        // This algorithm is rife with fiddling, and I really need to generate metrics to test the efficacy
        $now = time();
        $nextInterval = $mean + $stdev*2/($count + 1);
        $nextTime = min(max($lastTime + $nextInterval, $now + $stdev),
                        $now + 86400/2);
       
	$lastInterval = $now - $lastTime; 
        fof_log($feed['feed_title'] . ": Next feed update in "
                . ($nextTime - $now) . " seconds;"
                . " count=$count t=$totalDelta t2=$totalDeltaSquare"
                . " mean=$mean stdev=$stdev");
        if ($count_Added > 0) {
                // In a perfect world, we want both of these numbers to be low
                fof_log("DYNUPDATE_ADD $feed_id count $count_Added overstep $lastInterval");
        } else {
                fof_log("DYNUPDATE_NONE $feed_id since $lastInterval");
        }
        fof_db_feed_cache_set($feed_id, (int)round($nextTime));
    }

    // optionally purge old items -  if 'purge' is set we delete items that are not
    // unread or starred, not currently in the feed or within sizeof(feed) items
    // of being in the feed, and are over 'purge' many days old

    $ndelete = 0;
    $delete = array();

    if ( ! empty($admin_prefs['purge']) )
    {
        $purge = $admin_prefs [ 'purge' ];

        fof_log('purge is ' . $purge);

        $result = fof_db_items_purge_list($feed_id, $purge);

        while($row = fof_db_get_row($result)) {
            $delete[] = $row['item_id'];
        }

        fof_db_items_delete($delete);
    }

    if ( ! empty($admin_prefs['match_similarity'])) {
        $threshold = $admin_prefs['match_similarity'];

        $result = fof_db_items_duplicate_list();

        while ( $row = fof_db_get_row ( $result ) )
        {
            $similarity = 0;

            similar_text ( $row [ 'c1' ], $row [ 'c2' ], $similarity );

            if ( $similarity > $threshold )
                $delete[] = $row [ 'item_id' ];
        }
    }

    if (count( $delete )) {
        fof_db_items_delete($delete);
        fof_db_tag_delete($delete);
    }

    $ndelete += count ( $delete );

    fof_db_feed_mark_cached($feed_id);

    $log = "feed update complete, $n new items, $ndelete items purged";
    if($admin_prefs['purge'] == "")
    {
        $log .= " (purging disabled)";
    }
    fof_log($log, "update");

    return array($n, "");
}

/*  for all users subscribed to feed_id (or the specified user_id)
    and for all items in feed_id (or the specified item_id)
    and for each plugin active for each user
    run the plugin filter on the item data
    and set the resulting tags for the user and item
*/
function fof_apply_plugin_tags($feed_id, $item_id = NULL, $user_id = NULL)
{
    $users = array();

    if($user_id)
    {
        $users[] = $user_id;
    }
    else
    {
        $result = fof_db_get_subscribed_users($feed_id);
        while (($row = fof_db_get_row($result)) !== false) {
            $users[] = $row['user_id'];
        }
    }

    $items = array();
    if($item_id)
    {
        $items[] = fof_db_get_item($user_id, $item_id);
    }
    else
    {
        $result = fof_db_get_items($user_id, $feed_id, $what="all", NULL, NULL);

        foreach($result as $r)
        {
            $items[] = $r;
        }
    }

    $userdata = fof_db_get_users();

    foreach($users as $user)
    {
        fof_log("tagging for $user");

        global $fof_tag_prefilters;
        foreach($fof_tag_prefilters as $plugin => $filter)
        {
            fof_log("considering $plugin $filter");

            if ( empty( $userdata[$user]['prefs']['plugin_' . $plugin] ) )
            {
                foreach($items as $item)
                {
                    $tags = $filter($item['item_link'], $item['item_title'], $item['item_content']);
                    fof_tag_item($user, $item['item_id'], $tags);
                }
            }
        }
    }
}

function fof_init_plugins()
{
    global $fof_item_filters, $fof_item_prefilters, $fof_tag_prefilters, $fof_plugin_prefs;

    $fof_item_filters = array();
    $fof_item_prefilters = array();
    $fof_plugin_prefs = array();
    $fof_tag_prefilters = array();

    $p =& FoF_Prefs::instance();

    $dirlist = opendir(FOF_DIR . "/plugins");
    while($file=readdir($dirlist))
    {
        if(preg_match('/\.php$/',$file) && !$p->get('plugin_' . substr($file, 0, -4)))
        {
            fof_log("including " . $file);
            include(FOF_DIR . "/plugins/" . $file);
        }
    }

    closedir();
}

function fof_add_tag_prefilter($plugin, $function)
{
    global $fof_tag_prefilters;

    $fof_tag_prefilters[$plugin] = $function;
}

function fof_add_item_filter($function, $order=null)
{
    global $fof_item_filters;

    if(is_int($order))
      $fof_item_filters[$order] = $function;
    else
      $fof_item_filters[] = $function;

    ksort($fof_item_filters);
}

function fof_add_item_prefilter($function)
{
    global $fof_item_prefilters;

    $fof_item_prefilters[] = $function;
}

function fof_add_pref($name, $key, $type="string")
{
    global $fof_plugin_prefs;

    $fof_plugin_prefs[] = array($name, $key, $type);
}

function fof_add_item_widget($function)
{
    global $fof_item_widgets;

    $fof_item_widgets[] = $function;
}

function fof_get_widgets($item)
{
    global $fof_item_widgets;

    $widgets = array();

    if (is_array($fof_item_widgets)) {
        foreach($fof_item_widgets as $widget) {
            $w = $widget($item);
            if($w) $widgets[] = $w;
        }
    }

    return $widgets;
}

function fof_get_plugin_prefs()
{
    global $fof_plugin_prefs;

    return $fof_plugin_prefs;
}

function fof_multi_sort($tab,$key,$rev)
{
    $compare = create_function('$a,$b',
        '$la = strtolower($a[\'' . $key . '\']);' .
        '$lb = strtolower($b[\'' . $key . '\']);' .
        'if ($la == $lb) {' .
            'return 0;' .
        '} else if ($la ' . (($rev) ? '>' : '<') . ' $lb) {' .
            'return -1;' .
        '} else {' .
            'return 1;' .
        '}');
    usort($tab, $compare);
    return $tab;
}

function fof_todays_date()
{
    $prefs = fof_prefs();
    $offset = isset($prefs['tzoffset']) ? $prefs['tzoffset'] : 0;

    return gmdate( "Y/m/d", time() + ($offset * 60 * 60) );
}

function fof_repair_drain_bamage()
{
    if (ini_get('register_globals')) foreach($_REQUEST as $k=>$v) { unset($GLOBALS[$k]); }

    // thanks to submitter of http://bugs.php.net/bug.php?id=39859
    if (get_magic_quotes_gpc()) {
        function undoMagicQuotes($array, $topLevel=true) {
            $newArray = array();
            foreach($array as $key => $value) {
                if (!$topLevel) {
                    $key = stripslashes($key);
                }
                if (is_array($value)) {
                    $newArray[$key] = undoMagicQuotes($value, false);
                }
                else {
                    $newArray[$key] = stripslashes($value);
                }
            }
            return $newArray;
        }
        $_GET = undoMagicQuotes($_GET);
        $_POST = undoMagicQuotes($_POST);
        $_COOKIE = undoMagicQuotes($_COOKIE);
        $_REQUEST = undoMagicQuotes($_REQUEST);
    }
}

// grab a favicon from $url and cache it
function fof_get_favicon ( $url )
{
    $request = 'http://fvicon.com/' . urlencode($url) . '?format=gif&width=16&height=16&canAudit=false';

    $reflector = new ReflectionClass('SimplePie_File');
    $sp = $reflector->newInstanceArgs(array($request));

    if ( $sp -> success )
    {
        /* XXX: seems like this ought to check the result somehow... */

        $data = $sp -> body;
        $path = join(DIRECTORY_SEPARATOR, array('cache', md5($data) . '.png'));
        file_put_contents(join(DIRECTORY_SEPARATOR, array(dirname(__FILE__), $path)), $data);
    } else {
        $path = join(DIRECTORY_SEPARATOR, array('image', 'feed-icon.png'));
    }

    return $path;
}

/* generate the contents of a tr element from a feed db row*/
function fof_render_feed_row($f) {
    global $fof_asset;
    global $fof_prefs_obj;

    $out = '';

    /* provide some reasonable fallbacks when things aren't set */
    $link = $f['feed_link'] == '[no link]' ? $f['feed_url'] : $f['feed_link'];
    $title = $f['feed_title'] == '[no title]' ? $link : $f['feed_title'];
    $title_json = htmlentities(json_encode($title), ENT_QUOTES);

    /* show default icon if none exists, or we don't want per-feed icons */
    $image = (empty($f['feed_image']) || ! $fof_prefs_obj->get('favicons')) ? $fof_asset['feed_icon'] : $f['feed_image'];

    $unread = empty($f['feed_unread']) ? 0 : $f['feed_unread'];
    $items = empty($f['feed_items']) ? 0 : $f['feed_items'];
    $starred = empty($f['feed_starred']) ? 0 : $f['feed_starred'];
    $tagged = empty($f['feed_tagged']) ? 0 : $f['feed_tagged'];
    $feed_view_unread_url = fof_url('.', array('feed' => $f['feed_id'], 'how' => 'paged'));
    $feed_view_all_url = fof_url('.', array('feed' => $f['feed_id'], 'how' => 'paged', 'what' => 'all'));
    $feed_unsubscribe_url = fof_url('delete.php', array('feed' => $f['feed_id']));
    $feed_update_url = fof_url('update.php', array('feed' => $f['feed_id']));

    switch ($fof_prefs_obj->get('sidebar_style')) {
        case 'simple': /* feed_url feed_unread feed_title */
            $out .= '	<td class="source"><a href="' . $f['feed_url'] . '" title="feed"><img class="feed-icon" src="' . $image . '" /</a></td>' . "\n";

            $out .= '	<td class="unread">' . (empty($unread) ? '' : $unread) . '</td>';

            $out .= '	<td class="title"><a href="' . ($unread ? $feed_view_unread_url : $feed_view_all_url) . '">' . $title . '</a></td>' . "\n";

            /* controls */
            $out .= '	<td class="controls"><a href="' . $feed_unsubscribe_url . '" title="delete" onclick="return sb_unsub_conf(' . $title_json . ');">[x]</a></td>' . "\n";
            break;

        case 'fancy': /* feed_url max_date feed_unread feed_title */
            $out .= '	<td class="source"><a href="' . $link . '" title="site"' . ($fof_prefs_obj->get('item_target') ? ' target="_blank"' : '') . '><img class="feed-icon" src="' . $image . '" /></a></td>' . "\n";

            $out .= '	<td class="latest"><span title="' . $f['lateststr'] . '" id="' . $f['feed_id'] . '-lateststr">' . $f['lateststrabbr'] . '</span></td>' . "\n";

            $out .= '	<td class="unread"><span class="nowrap" id="' . $f['feed_id'] . '-items">';
            if ($unread)
                $out .= '<a class="unread" title="' . $unread . ' unread items" href="' . $feed_view_unread_url . '">' . $unread . '</a>';
            $out .= '</span></td>' . "\n";

            $out .= '	<td class="title"><a href="' . ($unread ? $feed_view_unread_url : $feed_view_all_url) . '" title="' . ($unread ? ($unread . ' new of ') : '') . $items . ' total items">' . $title . '</a></td>' . "\n";

            /* controls */
            $out .= '	<td class="controls">';
            $out .=   '<ul class="feedmenu">';
            $out .=     '<li>';
            $out .=       '<a href="#" title="feed controls">&Delta;</a>';
            $out .=       '<ul>';
            $out .=         '<li><a href="#" title="last update ' . $f['agestr']. '" onclick="return sb_update_feed(' . $f['feed_id'] . ');">Update Feed</a></li>';
            $out .=         '<li><a href="#" title="mark all as read" onclick="return sb_readall_feed(' . $f['feed_id']. ')">Mark all items as read</a></li>';
            $out .=         '<li><a href="' . $feed_view_all_url . '" title="' . $items . ' total items">View all items</a></li>';
            $out .=         '<li><a href="' . $link . '" title="home page"' . ($fof_prefs_obj->get('item_target') ? ' target="_blank"' : '') . '>Feed Source Site</a></li>';
            $out .=         '<li><a href="' . $feed_unsubscribe_url . '" title="unsubscribe" onclick="return sb_unsub_conf(' . $title_json . ');">Unsubscribe from feed</a></li>';
            $out .=       '</ul>';
            $out .=     '</li>';
            $out .=   '</ul>';
            $out .= '</td>' . "\n";
            break;

        default: /* feed_age max_date feed_unread feed_url feed_title */
            $out .= '	<td class="updated"><span title="' . $f['agestr'] . '" id="' . $f['feed_id'] . '-agestr">' . $f['agestrabbr'] . '</span></td>' . "\n";

            $out .= '	<td class="latest"><span title="' . $f['lateststr'] . '" id="' . $f['feed_id'] . '-lateststr">' . $f['lateststrabbr'] . '</span></td>' . "\n";

            $out .= '	<td style="unread" class="nowrap" id="' . $f['feed_id'] . '-items">';
            if ($unread)
                $out .= '<a class="unread" title="new items" href="' . $feed_view_unread_url . '">' . $unread . '</a>/';
            $out .= '<a href="' . $feed_view_all_url . '" title="all items, ' . $starred . ' starred, ' . $tagged . ' tagged">' . $items . '</a>';
            $out .= '</td>' . "\n";

            $out .= '	<td class="source"><a href="' . $f['feed_url'] . '"><img class="feed-icon" src="' . $image .'" ></a>' . "\n";

            $out .= '	<td class="title"><a href="' . $link . '" title="home page"' . ($fof_prefs_obj->get('item_target') ? ' target="_blank"' : '') . '><b>' . $title . '</b></a></td>' . "\n";

            /* controls */
            $out .= '	<td class="controls"><span class="nowrap">';
            $out .= ' <a href="' . $feed_update_url . '" title="update">u</a>';
            $out .= ' <a href="#" title="mark all read" onclick="return sb_read_conf(' . $title_json . ', ' . $f['feed_id'] . ');">m</a>';
            $out .= ' <a href="' . $feed_unsubscribe_url . '" title="delete" onclick="return sb_unsub_conf(' . $title_json . ');">d</a>';
            $out .= '</span></td>' . "\n";
            break;
    }

    return $out;
}

?>
