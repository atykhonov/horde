<?php
/**
 * IMP Base Class.
 *
 * Copyright 1999-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author   Chuck Hagenbuch <chuck@horde.org>
 * @author   Jon Parise <jon@horde.org>
 * @author   Michael Slusarz <slusarz@horde.org>
 * @category Horde
 * @license  http://www.fsf.org/copyleft/gpl.html GPL
 * @package  IMP
 */
class IMP
{
    /* Encrypt constants. */
    const ENCRYPT_NONE = 1;
    const PGP_ENCRYPT = 2;
    const PGP_SIGN = 3;
    const PGP_SIGNENC = 4;
    const SMIME_ENCRYPT = 5;
    const SMIME_SIGN = 6;
    const SMIME_SIGNENC = 7;
    const PGP_SYM_ENCRYPT = 8;
    const PGP_SYM_SIGNENC = 9;

    /* IMP Mailbox view constants. */
    const MAILBOX_START_FIRSTUNSEEN = 1;
    const MAILBOX_START_LASTUNSEEN = 2;
    const MAILBOX_START_FIRSTPAGE = 3;
    const MAILBOX_START_LASTPAGE = 4;

    /* Preferences constants. */
    const PREF_DEFAULT = "default\0";
    const PREF_NO_FOLDER = "nofolder\0";
    const PREF_VTRASH = "vtrash\0";

    /* Folder list actions. */
    const NOTEPAD_EDIT = "notepad\0";
    const TASKLIST_EDIT = "tasklist\0";

    /* Sorting constants. */
    const IMAP_SORT_DATE = 100;

    /**
     * Storage place for an altered version of the current URL.
     *
     * @var string
     */
    static public $newUrl = null;

    /**
     * The current active mailbox (may be search mailbox).
     *
     * @var string
     */
    static public $mailbox = '';

    /**
     * The real IMAP mailbox of the current index.
     *
     * @var string
     */
    static public $thismailbox = '';

    /**
     * The IMAP UID.
     *
     * @var integer
     */
    static public $uid = '';

    /**
     * displayFolder() cache.
     *
     * @var array
     */
    static private $_displaycache = array();

    /**
     * hideDeletedMsgs() cache.
     *
     * @var array
     */
    static private $_delhide = null;

    /**
     * prepareMenu() cache.
     *
     * @var array
     */
    static private $_menu = null;

    /**
     * Sidebar buffer.
     *
     * @var array
     */
    static private $_sidebar;

    /**
     * Returns the current view mode for IMP.
     *
     * @return string  Either 'dimp', 'imp', or 'mimp'.
     */
    static public function getViewMode()
    {
        return isset($_SESSION['imp']['view'])
            ? $_SESSION['imp']['view']
            : 'imp';
    }

    /**
     * Returns the plain text label that is displayed for the current mailbox,
     * replacing virtual search mailboxes with an appropriate description,
     * removing namespace and mailbox prefix information from what is shown to
     * the user, and passing the label through a user-defined hook.
     *
     * @param string $mbox  The mailbox to use for the label.
     *
     * @return string  The plain text label.
     */
    static public function getLabel($mbox)
    {
        $label = IMP_Search::isSearchMbox($mbox)
            ? $GLOBALS['injector']->getInstance('IMP_Search')->getLabel($mbox)
            : self::displayFolder($mbox);

        try {
            return Horde::callHook('mbox_label', array($mbox, $label), 'imp');
        } catch (Horde_Exception_HookNotSet $e) {
            return $label;
        }
    }

    /**
     * Adds a contact to the user defined address book.
     *
     * @param string $newAddress  The contact's email address.
     * @param string $newName     The contact's name.
     *
     * @return string  A link or message to show in the notification area.
     * @throws Horde_Exception
     */
    static public function addAddress($newAddress, $newName)
    {
        global $registry, $prefs;

        if (empty($newName)) {
            $newName = $newAddress;
        }

        $result = $registry->call('contacts/import', array(array('name' => $newName, 'email' => $newAddress), 'array', $prefs->getValue('add_source')));

        $escapeName = @htmlspecialchars($newName, ENT_COMPAT, $GLOBALS['registry']->getCharset());

        try {
            if ($contact_link = $registry->link('contacts/show', array('uid' => $result, 'source' => $prefs->getValue('add_source')))) {
                return Horde::link(Horde::url($contact_link), sprintf(_("Go to address book entry of \"%s\""), $newName)) . $escapeName . '</a>';
            }
        } catch (Horde_Exception $e) {}

        return $escapeName;
    }

    /**
     * Wrapper around IMP_Folder::flist() which generates the body of a
     * &lt;select&gt; form input from the generated folder list. The
     * &lt;select&gt; and &lt;/select&gt; tags are NOT included in the output
     * of this function.
     *
     * @param array $options  Optional parameters:
     * <pre>
     * 'abbrev' - (boolean) Abbreviate long mailbox names by replacing the
     *            middle of the name with '...'?
     *            DEFAULT: Yes
     * 'filter' - (array) An array of mailboxes to ignore.
     *            DEFAULT: Display all
     * 'heading' - (string) The label for an empty-value option at the top of
     *             the list.
     *             DEFAULT: ''
     * 'inc_notepads' - (boolean) Include user's editable notepads in list?
     *                   DEFAULT: No
     * 'inc_tasklists' - (boolean) Include user's editable tasklists in list?
     *                   DEFAULT: No
     * 'inc_vfolder' - (boolean) Include user's virtual folders in list?
     *                   DEFAULT: No
     * 'new_folder' - (boolean) Display an option to create a new folder?
     *                DEFAULT: No
     * 'selected' - (string) The mailbox to have selected by default.
     *             DEFAULT: None
     * </pre>
     *
     * @return string  A string containing <option> elements for each mailbox
     *                 in the list.
     */
    static public function flistSelect($options = array())
    {
        /* Don't filter here - since we are going to parse through every
         * member of the folder list below anyway, we can filter at that time.
         * This allows us the have a single cached value for the folder list
         * rather than a cached value for each different mailbox we may
         * visit. */
        $mailboxes = $GLOBALS['injector']->getInstance('IMP_Folder')->flist();

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);

        if (!empty($options['heading']) && (strlen($options['heading']) > 0)) {
            $t->set('heading', $options['heading']);
        }

        if (!empty($options['new_folder']) &&
            (!empty($GLOBALS['conf']['hooks']['permsdenied']) ||
             ($GLOBALS['injector']->getInstance('Horde_Perms')->hasAppPermission('create_folders') &&
              $GLOBALS['injector']->getInstance('Horde_Perms')->hasAppPermission('max_folders')))) {
            $t->set('new_mbox', true);
        }

        /* Add the list of mailboxes to the lists. */
        $filter = empty($options['filter'])
            ? array()
            : array_flip($options['filter']);
        $mbox_list = array();

        foreach ($mailboxes as $mbox) {
            if (!isset($filter[$mbox['val']])) {
                $label = (isset($options['abbrev']) && !$options['abbrev'])
                    ? $mbox['label']
                    : $mbox['abbrev'];

                $mbox_list[] = array(
                    'l' => $GLOBALS['injector']->getInstance('Horde_Text_Filter')->filter($label, 'space2html', array('encode' => true)),
                    'sel' => (!empty($options['selected']) && ($mbox['val'] === $options['selected'])),
                    'v' => self::formMbox($mbox['val'], true)
                );
            }
        }
        $t->set('mbox', $mbox_list);

        /* Add the list of virtual folders to the list. */
        if (!empty($options['inc_vfolder'])) {
            $imp_search = $GLOBALS['injector']->getInstance('IMP_Search');
            $vfolders = $imp_search->listQueries(IMP_Search::LIST_VFOLDER);
            if (!empty($vfolders)) {
                $vfolder_list = array();
                $vfolder_sel = $imp_search->searchMboxID();
                foreach ($vfolders as $id => $val) {
                    $vfolder_list[] = array(
                        'l' => $GLOBALS['injector']->getInstance('Horde_Text_Filter')->filter($val, 'space2html', array('encode' => true)),
                        'sel' => ($vfolder_sel == $id),
                        'v' => self::formMbox($imp_search->createSearchID($id), true)
                    );
                }
                $t->set('vfolder', $vfolder_list);
            }
        }

        /* Add the list of editable tasklists to the list. */
        if (!empty($options['inc_tasklists']) &&
            !empty($_SESSION['imp']['tasklistavail'])) {
            try {
                $tasklists = $GLOBALS['registry']->call('tasks/listTasklists', array(false, Horde_Perms::EDIT));

                if (count($tasklists)) {
                    $tasklist_list = array();
                    foreach ($tasklists as $id => $tasklist) {
                        $tasklist_list[] = array(
                            'l' => $GLOBALS['injector']->getInstance('Horde_Text_Filter')->filter($tasklist->get('name'), 'space2html', array('encode' => true)),
                            'v' => self::formMbox(self::TASKLIST_EDIT . $id, true)
                        );
                    }
                    $t->set('tasklist', $tasklist_list);
                }
            } catch (Horde_Exception $e) {}
        }

        /* Add the list of editable notepads to the list. */
        if (!empty($options['inc_notepads']) &&
            !empty($_SESSION['imp']['notepadavail'])) {
            try {
                $notepads = $GLOBALS['registry']->call('notes/listNotepads', array(false, Horde_Perms::EDIT));

                if (count($notepads)) {
                    $notepad_list[] = array();
                    foreach ($notepads as $id => $notepad) {
                        $notepad_list[] = array(
                            'l' => $GLOBALS['injector']->getInstance('Horde_Text_Filter')->filter($notepad->get('name'), 'space2html', array('encode' => true)),
                            'v' => self::formMbox(self::NOTEPAD_EDIT . $id, true)
                        );
                    }
                    $t->set('notepad', $notepad_list);
                }
            } catch (Horde_Exception $e) {}
        }

        return $t->fetch(IMP_TEMPLATES . '/imp/flist/flist.html');
    }

    /**
     * Checks for To:, Subject:, Cc:, and other compose window arguments and
     * pass back an associative array of those that are present.
     *
     * @return string  An associative array with compose arguments.
     */
    static public function getComposeArgs()
    {
        $args = array();
        $fields = array('to', 'cc', 'bcc', 'message', 'body', 'subject');

        foreach ($fields as $val) {
            if (($$val = Horde_Util::getFormData($val))) {
                $args[$val] = $$val;
            }
        }

        return self::_decodeMailto($args);
    }

    /**
     * Checks for mailto: prefix in the To field.
     *
     * @param array $args  A list of compose arguments.
     *
     * @return array  The array with the To: argument stripped of mailto:.
     */
    static protected function _decodeMailto($args)
    {
        if (isset($args['to']) && (strpos($args['to'], 'mailto:') === 0)) {
            $mailto = @parse_url($args['to']);
            if (is_array($mailto)) {
                $args['to'] = isset($mailto['path']) ? $mailto['path'] : '';
                if (!empty($mailto['query'])) {
                    parse_str($mailto['query'], $vals);
                    foreach ($fields as $val) {
                        if (isset($vals[$val])) {
                            $args[$val] = $vals[$val];
                        }
                    }
                }
            }
        }

        return $args;
    }

    /**
     * Prepares the arguments to use for composeLink().
     *
     * @param mixed $args   List of arguments to pass to compose.php. If this
     *                      is passed in as a string, it will be parsed as a
     *                      toaddress?subject=foo&cc=ccaddress (mailto-style)
     *                      string.
     * @param array $extra  Hash of extra, non-standard arguments to pass to
     *                      compose.php.
     *
     * @return array  The array of args to use for composeLink().
     */
    static public function composeLinkArgs($args = array(), $extra = array())
    {
        if (is_string($args)) {
            $string = $args;
            $args = array();
            if (($pos = strpos($string, '?')) !== false) {
                parse_str(substr($string, $pos + 1), $args);
                $args['to'] = substr($string, 0, $pos);
            } else {
                $args['to'] = $string;
            }
        }

        $args = self::_decodeMailto($args);

        /* Merge the two argument arrays. */
        return (is_array($extra) && !empty($extra))
            ? array_merge($args, $extra)
            : $args;
    }

    /**
     * Returns the appropriate link to call the message composition script.
     *
     * @param mixed $args       List of arguments to pass to compose script.
     *                          If this is passed in as a string, it will be
     *                          parsed as a toaddress?subject=foo&cc=ccaddress
     *                          (mailto-style) string.
     * @param array $extra      Hash of extra, non-standard arguments to pass
     *                          to compose script.
     * @param string $simplejs  Use simple JS (instead of Horde.popup() JS
     *                          function)?
     *
     * @return Horde_Url  The link to the message composition script.
     */
    static public function composeLink($args = array(), $extra = array(),
                                       $simplejs = false)
    {
        $args = self::composeLinkArgs($args, $extra);
        $view = self::getViewMode();

        if ($simplejs || ($view == 'dimp')) {
            $args['popup'] = 1;

            $url = Horde::applicationUrl(($view == 'dimp') ? 'compose-dimp.php' : 'compose.php')->setRaw(true)->add($args);
            $url->toStringCallback = array(__CLASS__, 'composeLinkSimpleCallback');
        } elseif (($view != 'mimp') &&
                  $GLOBALS['prefs']->getValue('compose_popup') &&
                  $GLOBALS['browser']->hasFeature('javascript')) {
            $url = Horde::applicationUrl('compose.php')->add($args);
            $url->toStringCallback = array(__CLASS__, 'composeLinkJsCallback');
        } else {
            $url = Horde::applicationUrl(($view == 'mimp') ? 'compose-mimp.php' : 'compose.php')->add($args);
        }

        return $url;
    }

    /**
     * Callback for Horde_Url when generating "simple" compose links. Simple
     * links don't require exterior javascript libraries.
     *
     * @param Horde_Url $url  URL object.
     *
     * @return string  URL string representation.
     */
    static public function composeLinkSimpleCallback($url)
    {
        return "javascript:void(window.open('" . strval($url) . "', '', 'width=820,height=610,status=1,scrollbars=yes,resizable=yes'));";
    }

    /**
     * Callback for Horde_Url when generating javascript compose links.
     *
     * @param Horde_Url $url  URL object.
     *
     * @return string  URL string representation.
     */
    static public function composeLinkJsCallback($url)
    {
        return 'javascript:' . Horde::popupJs(strval($url), array('urlencode' => true));
    }

    /**
     * If there is information available to tell us about a prefix in front of
     * mailbox names that shouldn't be displayed to the user, then use it to
     * strip that prefix out. Additionally, translate prefix text if this
     * is one of the folders with special meaning.
     *
     * @param string $folder        The folder name to display (UTF7-IMAP).
     * @param boolean $notranslate  Do not translate the folder prefix.
     *
     * @return string  The folder, with any prefix gone/translated.
     */
    static public function displayFolder($folder, $notranslate = false)
    {
        global $prefs;

        $cache = &self::$_displaycache;

        if (!$notranslate && isset($cache[$folder])) {
            return $cache[$folder];
        }

        $ns_info = $GLOBALS['injector']->getInstance('IMP_Imap')->getOb()->getNamespace($folder);
        $delimiter = is_null($ns_info) ? '' : $ns_info['delimiter'];

        /* Substitute any translated prefix text. */
        $sub_array = array(
            'INBOX' => _("Inbox"),
            $prefs->getValue('sent_mail_folder') => _("Sent"),
            $prefs->getValue('drafts_folder') => _("Drafts"),
            $prefs->getValue('trash_folder') => _("Trash"),
            $prefs->getValue('spam_folder') => _("Spam")
        );

        /* Strip namespace information. */
        if (!is_null($ns_info) &&
            !empty($ns_info['name']) &&
            ($ns_info['type'] == 'personal') &&
            substr($folder, 0, strlen($ns_info['name'])) == $ns_info['name']) {
            $out = substr($folder, strlen($ns_info['name']));
        } else {
            $out = $folder;
        }

        if ($notranslate) {
            return $out;
        }

        foreach ($sub_array as $key => $val) {
            if ((($key != 'INBOX') || ($folder == $out)) &&
                stripos($out, $key) === 0) {
                $len = strlen($key);
                if ((strlen($out) == $len) || ($out[$len] == $delimiter)) {
                    $out = substr_replace($out, Horde_String::convertCharset($val, $GLOBALS['registry']->getCharset(), 'UTF7-IMAP'), 0, $len);
                    break;
                }
            }
        }

        $cache[$folder] = Horde_String::convertCharset($out, 'UTF7-IMAP');

        return $cache[$folder];
    }

    /**
     * Filters a string, if requested.
     *
     * @param string $text  The text to filter.
     *
     * @return string  The filtered text (if requested).
     */
    static public function filterText($text)
    {
        if ($GLOBALS['prefs']->getValue('filtering') && strlen($text)) {
            return $GLOBALS['injector']->getInstance('Horde_Text_Filter')->filter($text, 'words', array(
                'replacement' => $GLOBALS['conf']['msgsettings']['filtering']['replacement'],
                'words_file' => $GLOBALS['conf']['msgsettings']['filtering']['words']
            ));
        }

        return $text;
    }

    /**
     * Build IMP's list of menu items.
     *
     * @return Horde_Menu  A Horde_Menu object.
     */
    static public function getMenu()
    {
        global $conf, $prefs, $registry;

        $menu_search_url = Horde::applicationUrl('search.php');
        $menu_mailbox_url = Horde::applicationUrl('mailbox.php');

        $spam_folder = self::folderPref($prefs->getValue('spam_folder'), true);

        $menu = new Horde_Menu();

        $menu->add(self::generateIMPUrl($menu_mailbox_url, 'INBOX'), _("_Inbox"), 'folders/inbox.png');

        if ($_SESSION['imp']['protocol'] != 'pop') {
            if ($prefs->getValue('use_trash') &&
                $prefs->getValue('empty_trash_menu')) {
                $mailbox = null;
                if ($prefs->getValue('use_vtrash')) {
                    $mailbox = $GLOBALS['injector']->getInstance('IMP_Search')->createSearchID($prefs->getValue('vtrash_id'));
                } else {
                    $trash_folder = self::folderPref($prefs->getValue('trash_folder'), true);
                    if (!is_null($trash_folder)) {
                        $mailbox = $trash_folder;
                    }
                }

                if (!empty($mailbox) &&
                    !$GLOBALS['injector']->getInstance('IMP_Imap')->getOb()->isReadOnly($mailbox)) {
                    $menu_trash_url = self::generateIMPUrl($menu_mailbox_url, $mailbox)->add(array('actionID' => 'empty_mailbox', 'mailbox_token' => Horde::getRequestToken('imp.mailbox')));
                    $menu->add($menu_trash_url, _("Empty _Trash"), 'empty_trash.png', null, null, 'return window.confirm(' . Horde_Serialize::serialize(_("Are you sure you wish to empty your trash folder?"), Horde_Serialize::JSON, $GLOBALS['registry']->getCharset()) . ')', '__noselection');
                }
            }

            if (!empty($spam_folder) &&
                $prefs->getValue('empty_spam_menu')) {
                $menu_spam_url = self::generateIMPUrl($menu_mailbox_url, $spam_folder)->add(array('actionID' => 'empty_mailbox', 'mailbox_token' => Horde::getRequestToken('imp.mailbox')));
                $menu->add($menu_spam_url, _("Empty _Spam"), 'empty_spam.png', null, null, 'return window.confirm(' . Horde_Serialize::serialize(_("Are you sure you wish to empty your trash folder?"), Horde_Serialize::JSON, $GLOBALS['registry']->getCharset()) . ')', '__noselection');
            }
        }

        if (self::canCompose()) {
            $menu->add(self::composeLink(array('mailbox' => self::$mailbox)), _("_New Message"), 'compose.png');
        }

        if ($conf['user']['allow_folders']) {
            $menu->add(Horde::applicationUrl('folders.php')->unique(), _("_Folders"), 'folders/folder.png');
        }

        if ($_SESSION['imp']['protocol'] != 'pop') {
            $menu->add($menu_search_url, _("_Search"), 'search.png');
        }

        if ($prefs->getValue('filter_menuitem')) {
            $menu->add(Horde::applicationUrl('filterprefs.php'), _("Fi_lters"), 'filters.png');
        }

        return $menu;
    }

    /**
     * Build IMP's list of menu items.
     */
    static public function prepareMenu()
    {
        if (isset(self::$_menu)) {
            return;
        }

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->set('forminput', Horde_Util::formInput());
        $t->set('use_folders', ($_SESSION['imp']['protocol'] != 'pop') && $GLOBALS['conf']['user']['allow_folders'], true);
        if ($t->get('use_folders')) {
            Horde::addScriptFile('imp.js', 'imp');
            $menu_view = $GLOBALS['prefs']->getValue('menu_view');
            $ak = $GLOBALS['prefs']->getValue('widget_accesskey')
                ? Horde::getAccessKey(_("Open Fo_lder"))
                : '';

            $t->set('ak', $ak);
            $t->set('flist', self::flistSelect(array('selected' => self::$mailbox, 'inc_vfolder' => true)));
            $t->set('flink', sprintf('%s%s<br />%s</a>', Horde::link('#'), ($menu_view != 'text') ? Horde::img('folders/open.png', _("Open Folder"), ($menu_view == 'icon') ? array('title' => _("Open Folder")) : array()) : '', ($menu_view != 'icon') ? Horde::highlightAccessKey(_("Open Fo_lder"), $ak) : ''));
        }
        $t->set('menu_string', self::getMenu()->render());

        self::$_menu = $t;

        /* Need to buffer sidebar output here, because it may add things like
         * cookies which need to be sent before output begins. */
        Horde::startBuffer();
        require HORDE_BASE . '/services/sidebar.php';
        self::$_sidebar = Horde::endBuffer();
    }

    /**
     * Outputs IMP's menu to the current output stream.
     */
    static public function menu()
    {
        self::prepareMenu();

        echo self::$_menu->fetch(IMP_TEMPLATES . '/imp/menu/menu.html') .
             self::$_sidebar;
    }

    /**
     * Outputs IMP's status/notification bar.
     */
    static public function status()
    {
        $GLOBALS['notification']->notify(array('listeners' => array('status', 'audio')));
    }

    /**
     * Outputs IMP's quota information.
     */
    static public function quota()
    {
        $quotadata = self::quotaData(true);
        if (!empty($quotadata)) {
            $t = $GLOBALS['injector']->createInstance('Horde_Template');
            $t->set('class', $quotadata['class']);
            $t->set('message', $quotadata['message']);
            echo $t->fetch(IMP_TEMPLATES . '/quota/quota.html');
        }
    }

    /**
     * Returns data needed to output quota.
     *
     * @param boolean $long  Output long messages?
     *
     * @return array  Array with these keys: class, message, percent.
     */
    static public function quotaData($long = true)
    {
        if (!isset($_SESSION['imp']['imap']['quota']) ||
            !is_array($_SESSION['imp']['imap']['quota'])) {
            return false;
        }

        try {
            $quotaDriver = $GLOBALS['injector']->getInstance('IMP_Quota');
            $quota = $quotaDriver->getQuota();
        } catch (IMP_Exception $e) {
            Horde::logMessage($e, 'ERR');
            return false;
        }

        if (empty($quota)) {
            return false;
        }

        $strings = $quotaDriver->getMessages();
        list($calc, $unit) = $quotaDriver->getUnit();
        $ret = array('percent' => 0);

        if ($quota['limit'] != 0) {
            $quota['usage'] = $quota['usage'] / $calc;
            $quota['limit'] = $quota['limit'] / $calc;
            $ret['percent'] = ($quota['usage'] * 100) / $quota['limit'];
            if ($ret['percent'] >= 90) {
                $ret['class'] = 'quotaalert';
            } elseif ($ret['percent'] >= 75) {
                $ret['class'] = 'quotawarn';
            } else {
                $ret['class'] = 'control';
            }

            $ret['message'] = $long
                ? sprintf($strings['long'], $quota['usage'], $unit, $quota['limit'], $unit, $ret['percent'])
                : sprintf($strings['short'], $ret['percent'], $quota['limit'], $unit);
            $ret['percent'] = sprintf("%.2f", $ret['percent']);
        } else {
            // Hide unlimited quota message?
            if (!empty($_SESSION['imp']['quota']['hide_when_unlimited'])) {
                return false;
            }

            $ret['class'] = 'control';
            if ($quota['usage'] != 0) {
                $quota['usage'] = $quota['usage'] / $calc;

                $ret['message'] = $long
                    ? sprintf($strings['nolimit_long'], $quota['usage'], $unit)
                    : sprintf($strings['nolimit_short'], $quota['usage'], $unit);
            } else {
                $ret['message'] = $long
                    ? sprintf(_("Quota status: NO LIMIT"))
                    : _("No limit");
            }
        }

        return $ret;
    }

    /**
     * Convert a preference value to/from the value stored in the preferences.
     *
     * To allow folders from the personal namespace to be stored without this
     * prefix for portability, we strip the personal namespace. To tell apart
     * folders from the personal and any empty namespace, we prefix folders
     * from the empty namespace with the delimiter.
     *
     * @param string $folder   The folder path.
     * @param boolean $append  True - convert from preference value.
     *                         False - convert to preference value.
     *
     * @return string  The folder name.
     */
    static public function folderPref($folder, $append)
    {
        $imp_imap = $GLOBALS['injector']->getInstance('IMP_Imap')->getOb();
        $def_ns = $imp_imap->defaultNamespace();
        $empty_ns = $imp_imap->getNamespace('');

        if ($append) {
            /* Converting from preference value. */
            if (!is_null($empty_ns) &&
                strpos($folder, $empty_ns['delimiter']) === 0) {
                /* Prefixed with delimiter => from empty namespace. */
                $folder = substr($folder, strlen($empty_ns['delimiter']));
            } elseif (($ns = $imp_imap->getNamespace($folder, true)) == null) {
                /* No namespace prefix => from personal namespace. */
                $folder = $def_ns['name'] . $folder;
            }
        } elseif (($ns = $imp_imap->getNamespace($folder)) !== null) {
            /* Converting to preference value. */
            if ($ns['name'] == $def_ns['name']) {
                /* From personal namespace => strip namespace. */
                $folder = substr($folder, strlen($def_ns['name']));
            } elseif ($ns['name'] == $empty_ns['name']) {
                /* From empty namespace => prefix with delimiter. */
                $folder = $empty_ns['delimiter'] . $folder;
            }
        }

        return $folder;
    }

    /**
     * Generates a URL with necessary mailbox/UID information.
     *
     * @param string|Horde_Url $page  Page name to link to.
     * @param string $mailbox         The base mailbox to use on the linked
     *                                page.
     * @param string $uid             The UID to use on the linked page.
     * @param string $tmailbox        The mailbox associated with $uid.
     * @param boolean $encode         Encode the argument separator?
     *
     * @return Horde_Url  URL to $page with any necessary mailbox information
     *                    added to the parameter list of the URL.
     */
    static public function generateIMPUrl($page, $mailbox, $uid = null,
                                          $tmailbox = null, $encode = true)
    {
        $url = ($page instanceof Horde_Url)
            ? clone $page
            : Horde::applicationUrl($page);

        return $url->add(self::getIMPMboxParameters($mailbox, $uid, $tmailbox))->setRaw(!$encode);
    }

    /**
     * Returns a list of parameters necessary to indicate current mailbox
     * status.
     *
     * @param string $mailbox   The mailbox to use on the linked page.
     * @param string $uid       The UID to use on the linked page.
     * @param string $tmailbox  The mailbox associated with $uid to use on
     *                          the linked page.
     *
     * @return array  The list of parameters needed to indicate the current
     *                mailbox status.
     */
    static public function getIMPMboxParameters($mailbox, $uid = null,
                                                $tmailbox = null)
    {
        $params = array('mailbox' => $mailbox);
        if (!is_null($uid)) {
            $params['uid'] = $uid;
            if ($mailbox != $tmailbox) {
                $params['thismailbox'] = $tmailbox;
            }
        }
        return $params;
    }

    /**
     * Determine whether we're hiding deleted messages.
     *
     * @param string $mbox    The current mailbox.
     * @param boolean $force  Force a redetermination of the return value
     *                        (return value is normally cached after the first
     *                        call).
     *
     * @return boolean  True if deleted messages should be hidden.
     */
    static public function hideDeletedMsgs($mbox, $force = false)
    {
        $delhide = &self::$_delhide;

        if (is_null($delhide) || $force) {
            if ($GLOBALS['prefs']->getValue('use_vtrash')) {
                $delhide = !$GLOBALS['injector']->getInstance('IMP_Search')->isVTrashFolder();
            } else {
                $sortpref = self::getSort();
                $delhide = ($GLOBALS['prefs']->getValue('delhide') &&
                            !$GLOBALS['prefs']->getValue('use_trash') &&
                            ($GLOBALS['injector']->getInstance('IMP_Search')->isSearchMbox($mbox) ||
                             ($sortpref['by'] != Horde_Imap_Client::SORT_THREAD)));
            }
        }

        return $delhide;
    }

    /**
     * Return a list of valid encrypt HTML option tags.
     *
     * @param string $default      The default encrypt option.
     * @param boolean $returnList  Whether to return a hash with options
     *                             instead of the options tag.
     *
     * @return string  The list of option tags.
     */
    static public function encryptList($default = null, $returnList = false)
    {
        if (is_null($default)) {
            $default = $GLOBALS['prefs']->getValue('default_encrypt');
        }

        $enc_opts = array(self::ENCRYPT_NONE => _("None"));
        $output = '';

        if (!empty($GLOBALS['conf']['gnupg']['path']) &&
            $GLOBALS['prefs']->getValue('use_pgp')) {
            $enc_opts += array(
                self::PGP_ENCRYPT => _("PGP Encrypt Message"),
                self::PGP_SIGN => _("PGP Sign Message"),
                self::PGP_SIGNENC => _("PGP Sign/Encrypt Message"),
                self::PGP_SYM_ENCRYPT => _("PGP Encrypt Message with passphrase"),
                self::PGP_SYM_SIGNENC => _("PGP Sign/Encrypt Message with passphrase")
            );
        }
        if ($GLOBALS['prefs']->getValue('use_smime')) {
            $enc_opts += array(
                self::SMIME_ENCRYPT => _("S/MIME Encrypt Message"),
                self::SMIME_SIGN => _("S/MIME Sign Message"),
                self::SMIME_SIGNENC => _("S/MIME Sign/Encrypt Message")
            );
        }

        if ($returnList) {
            return $enc_opts;
        }

        foreach ($enc_opts as $key => $val) {
             $output .= '<option value="' . $key . '"' . (($default == $key) ? ' selected="selected"' : '') . '>' . $val . "</option>\n";
        }

        return $output;
    }

    /**
     * Return the sorting preference for the current mailbox.
     *
     * @param string $mbox      The mailbox to use (defaults to current
     *                          mailbox in the session).
     * @param boolean $convert  Convert 'by' to a Horde_Imap_Client constant?
     *
     * @return array  An array with the following keys:
     * <pre>
     * 'by'  - (integer) Sort type.
     * 'dir' - (integer) Sort direction.
     * </pre>
     */
    static public function getSort($mbox = null, $convert = false)
    {
        global $prefs;

        if (is_null($mbox)) {
            $mbox = self::$mailbox;
        }

        $search_mbox = $GLOBALS['injector']->getInstance('IMP_Search')->isSearchMbox($mbox);
        $prefmbox = $search_mbox
            ? $mbox
            : self::folderPref($mbox, false);

        $sortpref = @unserialize($prefs->getValue('sortpref'));
        $entry = isset($sortpref[$prefmbox])
            ? $sortpref[$prefmbox]
            : array();

        if (!isset($entry['b'])) {
            $sortby = $prefs->getValue('sortby');
        }

        $ob = array(
            'by' => isset($entry['b']) ? $entry['b'] : $sortby,
            'dir' => isset($entry['d']) ? $entry['d'] : $prefs->getValue('sortdir'),
        );

        /* Restrict POP3 sorting to sequence only.  Although possible to
         * abstract other sorting methods, all other methods require a
         * download of all messages, which is too much overhead.*/
        if ($_SESSION['imp']['protocol'] == 'pop') {
            $ob['by'] = Horde_Imap_Client::SORT_SEQUENCE;
            return $ob;
        }

        switch ($ob['by']) {
        case Horde_Imap_Client::SORT_THREAD:
            /* Can't do threaded searches in search mailboxes. */
            if (!self::threadSortAvailable($mbox)) {
                $ob['by'] = self::IMAP_SORT_DATE;
            }
            break;

        case Horde_Imap_Client::SORT_FROM:
            /* If the preference is to sort by From Address, when we are
             * in the Drafts or Sent folders, sort by To Address. */
            if (self::isSpecialFolder($mbox)) {
                $ob['by'] = Horde_Imap_Client::SORT_TO;
            }
            break;

        case Horde_Imap_Client::SORT_TO:
            if (!self::isSpecialFolder($mbox)) {
                $ob['by'] = Horde_Imap_Client::SORT_FROM;
            }
            break;
        }

        if ($convert && ($ob['by'] == self::IMAP_SORT_DATE)) {
            $ob['by'] = $prefs->getValue('sortdate');
        }

        /* Sanity check: make sure we have some sort of sort value. */
        if (!$ob['by']) {
            $ob['by'] = Horde_Imap_Client::SORT_ARRIVAL;
        }

        return $ob;
    }

    /**
     * Determines if thread sorting is available.
     *
     * @param string $mbox  The mailbox to check.
     *
     * @return boolean  True if thread sort is available for this mailbox.
     */
    static public function threadSortAvailable($mbox)
    {
        /* Thread sort is always available for IMAP servers, since
         * Horde_Imap_Client_Socket has a built-in ORDEREDSUBJECT
         * implementation. We will always prefer REFERENCES, but will fallback
         * to ORDEREDSUBJECT if the server doesn't support THREAD sorting. */
        return ($_SESSION['imp']['protocol'] == 'imap') &&
               !$GLOBALS['injector']->getInstance('IMP_Search')->isSearchMbox($mbox) &&
               (!$GLOBALS['prefs']->getValue('use_trash') ||
                !$GLOBALS['prefs']->getValue('use_vtrash') ||
                $GLOBALS['injector']->getInstance('IMP_Search')->isVTrashFolder($mbox));
    }

    /**
     * Set the sorting preference for the current mailbox.
     *
     * @param integer $by      The sort type.
     * @param integer $dir     The sort direction.
     * @param string $mbox     The mailbox to use (defaults to current mailbox
     *                         in the session).
     * @param boolean $delete  Delete the entry?
     */
    static public function setSort($by = null, $dir = null, $mbox = null,
                                   $delete = false)
    {
        $entry = array();
        $sortpref = @unserialize($GLOBALS['prefs']->getValue('sortpref'));

        if (is_null($mbox)) {
            $mbox = self::$mailbox;
        }

        $prefmbox = $GLOBALS['injector']->getInstance('IMP_Search')->isSearchMbox($mbox)
            ? $mbox
            : self::folderPref($mbox, false);

        if ($delete) {
            unset($sortpref[$prefmbox]);
        } else {
            if (!is_null($by)) {
                $entry['b'] = $by;
            }
            if (!is_null($dir)) {
                $entry['d'] = $dir;
            }

            if (!empty($entry)) {
                $sortpref[$prefmbox] = isset($sortpref[$prefmbox])
                    ? array_merge($sortpref[$prefmbox], $entry)
                    : $entry;
            }
        }

        if ($delete || !empty($entry)) {
            $GLOBALS['prefs']->setValue('sortpref', serialize($sortpref));
        }
    }

    /**
     * Is $mbox a 'special' folder (e.g. 'drafts' or 'sent-mail' folder)?
     *
     * @param string $mbox  The mailbox to query.
     *
     * @return boolean  Is $mbox a 'special' folder?
     */
    static public function isSpecialFolder($mbox)
    {
        /* Get the identities. */
        $identity = $GLOBALS['injector']->getInstance('IMP_Identity');

        return (($mbox == self::folderPref($GLOBALS['prefs']->getValue('drafts_folder'), true)) || in_array($mbox, $identity->getAllSentmailFolders()));
    }

    /**
     * Sets mailbox/index information for current page load. This information
     * is accessible via IMP::$mailbox, IMP::$thismailbox, and IMP::$uid.
     *
     * @param boolean $mbox  Use this mailbox, instead of form data.
     */
    static public function setCurrentMailboxInfo($mbox = null)
    {
        if (is_null($mbox)) {
            $mbox = Horde_Util::getFormData('mailbox');
            self::$mailbox = empty($mbox) ? 'INBOX' : self::formMbox($mbox, false);
            self::$thismailbox = self::formMbox(Horde_Util::getFormData('thismailbox', $mbox), false);
            self::$uid = Horde_Util::getFormData('uid');
        } else {
            self::$mailbox = $mbox;
            self::$thismailbox = $mbox;
            self::$uid = null;
        }
    }

    /**
     * Return a selfURL that has had index/mailbox/actionID information
     * removed/altered based on an action that has occurred on the present
     * page.
     *
     * @return Horde_Url  The self URL.
     */
    static public function selfUrl()
    {
        return self::$newUrl
            ? self::$newUrl->copy()
            : Horde::selfUrl(true);
    }

    /**
     * Determine the status of composing.
     *
     * @return boolean  Is compose allowed?
     * @throws Horde_Exception
     */
    static public function canCompose()
    {
        try {
            return !Horde::callHook('disable_compose', array(), 'imp');
        } catch (Horde_Exception_HookNotSet $e) {
            return true;
        }
    }

    /**
     * Output configured alerts for newmail.
     *
     * @param mixed $var  Either an associative array with mailbox names as
     *                    the keys and the message count as the values or
     *                    an integer indicating the number of new messages
     *                    in the current mailbox.
     *
     * @param integer $msgs  The number of new messages.
     */
    static public function newmailAlerts($var)
    {
        if ($GLOBALS['prefs']->getValue('nav_popup')) {
            Horde::addInlineScript(array(
                self::_getNewMessagePopup($var)
            ), 'dom');
        }

        if ($sound = $GLOBALS['prefs']->getValue('nav_audio')) {
            $GLOBALS['notification']->push(Horde_Themes::img('audio/' . $sound), 'audio');
        }
    }

    /**
     * Outputs the necessary javascript code to display the new mail
     * notification message.
     *
     * @param mixed $var  See self::newmailAlerts().
     *
     * @return string  The javascript for the popup message.
     */
    static protected function _getNewMessagePopup($var)
    {
        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);
        if (is_array($var)) {
            if (empty($var)) {
                return;
            }
            $folders = array();
            foreach ($var as $mb => $nm) {
                $folders[] = array(
                    'name' => htmlspecialchars(self::displayFolder($mb)),
                    'new' => intval($nm),
                    'url' => self::generateIMPUrl('mailbox.php', $mb),
                );
            }
            $t->set('folders', $folders);

            if (($_SESSION['imp']['protocol'] != 'pop') &&
                $GLOBALS['prefs']->getValue('use_vinbox') &&
                ($vinbox_id = $GLOBALS['prefs']->getValue('vinbox_id'))) {
                $t->set('vinbox', Horde::link(self::generateIMPUrl('mailbox.php', $GLOBALS['injector']->getInstance('IMP_Search')->createSearchID($vinbox_id))));
            }
        } else {
            $t->set('msg', ($var == 1) ? _("You have 1 new message.") : sprintf(_("You have %s new messages."), $var));
        }
        $t_html = str_replace("\n", ' ', $t->fetch(IMP_TEMPLATES . '/newmsg/alert.html'));

        Horde::addScriptFile('effects.js', 'horde');
        Horde::addScriptFile('redbox.js', 'horde');

        return 'RedBox.overlay = false; RedBox.showHtml(\'' . addcslashes($t_html, "'/") . '\');';
    }

    /**
     * Determines parameters needed to do an address search
     *
     * @return array  An array with two keys: 'fields' and 'sources'.
     */
    static public function getAddressbookSearchParams()
    {
        $src = json_decode($GLOBALS['prefs']->getValue('search_sources'));
        if (empty($src)) {
            $src = array();
        }

        $fields = json_decode($GLOBALS['prefs']->getValue('search_fields'), true);
        if (empty($fields)) {
            $fields = array();
        }

        return array(
            'fields' => $fields,
            'sources' => $src
        );
    }

    /**
     * Converts a mailbox to/from a valid representation that can be used
     * in a form element.  Needed because null characters (used for various
     * internal non-IMAP mailbox representations) will not work in form
     * elements.
     *
     * @param string $mbox  The mailbox name.
     * @param boolean $to   Convert to the form representation?
     *
     * @return string  The converted mailbox.
     */
    static public function formMbox($mbox, $to)
    {
        return $to
            ? htmlspecialchars(rawurlencode($mbox), ENT_COMPAT, $GLOBALS['registry']->getCharset())
            : rawurldecode($mbox);
    }

}
