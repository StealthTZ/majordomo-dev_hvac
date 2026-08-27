<?php
/**
 * HVAC
 * @package project
 * @author Wizard <sergejey@gmail.com>
 * @copyright http://majordomo.smartliving.ru/ (c)
 * @version 0.2 (PHP 7.0 - 8.4 compatible)
 */
//
//
class dev_hvac extends module
{
    // module::__construct() only declares a part of the properties this module uses.
    // Declaring them here avoids "Creation of dynamic property" deprecations (PHP 8.2+).
    public $title;
    public $module_category;
    public $id;
    public $page;
    public $type;
    public $mac;
    public $keys;
    public $host;
    public $devtype;
    public $encryption;
    public $title_new;

    /** property name => setter method, per device family */
    private static $HANDLERS = array(
        'CH' => array(
            'temperature' => 'set_temp',
            'power' => 'set_power',
            'ac_mode' => 'set_ac_mode',
            'fan_speed' => 'set_fan_speed',
            'quiet' => 'set_quiet',
            'fan_direction' => 'set_fan_direction',
            'stepless_max' => 'set_stepless_max',
            'light' => 'set_light',
            'health' => 'set_health',
            'sleep' => 'set_sleep',
            'energy_save' => 'set_energy_save',
            'eco' => 'set_eco',
        ),
        'Gree' => array(
            'temperature' => 'set_temp',
            'power' => 'set_power',
            'ac_mode' => 'set_ac_mode',
            'fan_speed' => 'set_fan_speed',
            'quiet' => 'set_quiet',
            'fan_direction' => 'set_fan_direction',
            'fan_directionh' => 'set_fan_directionh',
            'light' => 'set_light',
            'health' => 'set_health',
            'sleep' => 'set_sleep',
            'energy_save' => 'set_energy_save',
            'turbo' => 'set_turbo',
            'air' => 'set_air',
            'blow' => 'set_blow',
            'stht' => 'set_stht',
        ),
    );

    /**
     * Module class constructor
     *
     * The PHP 4 style constructor (function dev_hvac()) only still worked because
     * module::__construct() calls it explicitly on PHP 8. Using __construct()
     * directly works on every supported PHP version.
     *
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        $this->name = "dev_hvac";
        $this->title = "HVAC";
        $this->module_category = "<#LANG_SECTION_DEVICES#>";
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->data_source)) {
            $p["data_source"] = $this->data_source;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        if (isset($this->page)) {
            $p["page"] = $this->page;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $mac;
        global $keys;
        global $host;
        global $title;
        global $devtype;
        global $encryption;
        global $type;
        global $view_mode;
        global $edit_mode;
        global $data_source;
        global $tab;
        global $title_new;

        if (isset($title)) {
            $this->title = $title;
        }
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($mac)) {
            $this->mac = $mac;
        }
        if (isset($keys)) {
            $this->keys = $keys;
        }
        if (isset($host)) {
            $this->host = $host;
        }
        if (isset($devtype)) {
            $this->devtype = $devtype;
        }
        if (isset($encryption)) {
            $this->encryption = $encryption;
        }
        if (isset($type)) {
            $this->type = $type;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($data_source)) {
            $this->data_source = $data_source;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
        if (isset($title_new)) {
            $this->title_new = $title_new;
        }
    }

    /**
     * Run
     *
     * @access public
     */
    function run()
    {
        global $session;
        $out = array();

        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }

        if (isset($this->owner) && is_object($this->owner)) {
            if (isset($this->owner->action)) {
                $out['PARENT_ACTION'] = $this->owner->action;
            }
            if (isset($this->owner->name)) {
                $out['PARENT_NAME'] = $this->owner->name;
            }
        }

        $out['VIEW_MODE'] = isset($this->view_mode) ? $this->view_mode : '';
        $out['EDIT_MODE'] = isset($this->edit_mode) ? $this->edit_mode : '';
        $out['MODE'] = isset($this->mode) ? $this->mode : '';
        $out['ACTION'] = isset($this->action) ? $this->action : '';
        $out['DATA_SOURCE'] = isset($this->data_source) ? $this->data_source : '';
        $out['TAB'] = isset($this->tab) ? $this->tab : '';

        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * BackEnd
     *
     * @access public
     */
    function admin(&$out)
    {
        $this->getConfig();

        $view_mode = isset($this->view_mode) ? $this->view_mode : '';
        $data_source = isset($this->data_source) ? $this->data_source : '';

        if ($view_mode == 'update_settings') {
            $this->saveConfig();
            $this->redirect("?");
        }
        if (isset($this->mode) && $this->mode == 'check_params') {
            $this->check_params();
        }
        // isset() on $_GET/$_POST keys - a plain !$_GET['x'] raises a warning on PHP 8
        if ($data_source != '' && empty($_GET['data_source']) && empty($_POST['data_source'])) {
            $out['SET_DATASOURCE'] = 1;
        }

        if ($data_source == 'dev_hvac_devices' || $data_source == '') {
            if ($view_mode == '' || $view_mode == 'search_dev_hvac_devices') {
                $this->search_dev_hvac_devices($out);
            }
            if ($view_mode == 'edit_dev_hvac_devices') {
                $this->edit_dev_hvac_devices($out, (int)$this->id);
            }
            if ($view_mode == 'delete_dev_hvac_devices') {
                $this->delete_dev_hvac_devices((int)$this->id);
                $this->redirect("?data_source=dev_hvac_devices");
            }
            if ($view_mode == 'hvac_devices_scan') {
                $this->hvac_devices_scan($out);
            }
        }
    }

    /**
     * FrontEnd
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
    }

    /**
     * dev_hvac_devices search
     * @access public
     */
    function search_dev_hvac_devices(&$out)
    {
        require(DIR_MODULES . $this->name . '/dev_hvac_devices_search.inc.php');
    }

    /**
     * hvac_devices_scan
     * @access public
     */
    function hvac_devices_scan(&$out)
    {
        require(DIR_MODULES . $this->name . '/hvac_devices_scan.inc.php');
    }

    /**
     * dev_hvac_devices edit/add
     * @access public
     */
    function edit_dev_hvac_devices(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/dev_hvac_devices_edit.inc.php');
    }

    /**
     * dev_hvac_devices delete record
     * @access public
     */
    function delete_dev_hvac_devices($id)
    {
        $id = (int)$id;
        if (!$id) {
            return;
        }

        $properties = SQLSelect("SELECT * FROM dev_hvac_commands WHERE DEVICE_ID=" . $id);
        if (is_array($properties)) {
            foreach ($properties as $property) {
                if (!empty($property['LINKED_OBJECT']) && !empty($property['LINKED_PROPERTY'])) {
                    removeLinkedProperty($property['LINKED_OBJECT'], $property['LINKED_PROPERTY'], $this->name);
                }
            }
        }

        SQLExec("DELETE FROM dev_hvac_devices WHERE ID=" . $id);
        SQLExec("DELETE FROM dev_hvac_commands WHERE DEVICE_ID=" . $id);
    }

    /**
     * Called by the core when a linked object property is changed.
     *
     * @return bool
     */
    function propertySetHandle($object, $property, $value)
    {
        $this->getConfig();
        include_once(DIR_MODULES . $this->name . '/hvac.class.php');

        $table = 'dev_hvac_commands';
        $properties = SQLSelect(
            "SELECT * FROM $table WHERE LINKED_OBJECT='" . DBSafe($object) . "'"
            . " AND LINKED_PROPERTY='" . DBSafe($property) . "'"
        );

        if (!is_array($properties) || !count($properties)) {
            return false;
        }

        $handled = false;

        foreach ($properties as $prop) {
            $device_id = (int)$prop['DEVICE_ID'];
            if (!$device_id) {
                continue;
            }

            $rec = SQLSelectOne("SELECT * FROM dev_hvac_devices WHERE ID=" . $device_id);
            if (!is_array($rec) || !isset($rec['ID'])) {
                continue;
            }

            $rm = hvac::CreateDevice(
                $rec['IP'],
                $rec['MAC'],
                $rec['DEVTYPE'],
                isset($rec['KEYS']) ? $rec['KEYS'] : '',
                isset($rec['ENCRYPTION']) ? $rec['ENCRYPTION'] : 'ECB'
            );

            if ($rm === null) {
                DebMes('dev_hvac: unsupported device type for ' . $rec['TITLE'], 'error');
                continue;
            }

            $family = isset($rec['TYPE']) ? $rec['TYPE'] : '';
            if (!isset(self::$HANDLERS[$family])) {
                continue;
            }

            $title = isset($prop['TITLE']) ? $prop['TITLE'] : '';
            if (!isset(self::$HANDLERS[$family][$title])) {
                continue;
            }

            $method = self::$HANDLERS[$family][$title];
            $rm->$method($value);

            $prop['VALUE'] = $value;
            SQLUpdate($table, $prop);
            $handled = true;
        }

        return $handled;
    }

    /**
     * Poll devices and publish their state.
     *
     * @param string $chtime ''    - every device (manual refresh from the UI)
     *                       'all' - every device that is not disabled
     *                       other - only devices with this polling interval
     */
    function check_params($chtime = '')
    {
        $this->getConfig();

        $chtime = (string)$chtime;

        if ($chtime === '') {
            $db_rec = SQLSelect("SELECT * FROM dev_hvac_devices");
        } elseif ($chtime === 'all') {
            $db_rec = SQLSelect("SELECT * FROM dev_hvac_devices WHERE CHTIME<>'none'");
        } else {
            $db_rec = SQLSelect("SELECT * FROM dev_hvac_devices WHERE CHTIME='" . DBSafe($chtime) . "'");
        }

        if (!is_array($db_rec) || !count($db_rec)) {
            return;
        }

        include_once(DIR_MODULES . $this->name . '/hvac.class.php');

        foreach ($db_rec as $rec) {
            $rm = hvac::CreateDevice(
                $rec['IP'],
                $rec['MAC'],
                $rec['DEVTYPE'],
                isset($rec['KEYS']) ? $rec['KEYS'] : '',
                isset($rec['ENCRYPTION']) ? $rec['ENCRYPTION'] : 'ECB'
            );

            if ($rm === null) {
                DebMes('dev_hvac: device ' . $rec['TITLE'] . ' has an unsupported type ' . $rec['DEVTYPE'], 'error');
                continue;
            }

            $response = $rm->get_status();

            if (!is_array($response) || !count($response)) {
                DebMes('dev_hvac: device ' . $rec['TITLE'] . ' is not available (' . $rm->lastError() . ')', 'error');
                continue;
            }

            foreach ($response as $key => $value) {
                $this->table_data_set($key, (int)$rec['ID'], $value);
            }

            $rec['UPDATED'] = date('Y-m-d H:i:s');
            SQLUpdate('dev_hvac_devices', $rec);
        }
    }

    /**
     * Store a single device property and push it to the linked object.
     */
    function table_data_set($prop, $dev_id, $val, $sg_val = null)
    {
        $table = 'dev_hvac_commands';
        $dev_id = (int)$dev_id;

        $record = SQLSelectOne(
            "SELECT * FROM $table WHERE TITLE='" . DBSafe($prop) . "' AND DEVICE_ID=" . $dev_id
        );

        // SQLSelectOne() returns NULL when nothing matched; count(NULL) is a
        // TypeError since PHP 8.0
        if (is_array($record) && isset($record['ID'])) {
            if ((string)$val === (string)$record['VALUE']) {
                return;
            }

            $record['VALUE'] = $val;
            SQLUpdate($table, $record);

            if (!empty($record['LINKED_OBJECT']) && !empty($record['LINKED_PROPERTY'])) {
                // exclude this module from the linked modules chain, otherwise a value
                // read from the device is immediately written back to it
                sg(
                    $record['LINKED_OBJECT'] . '.' . $record['LINKED_PROPERTY'],
                    is_null($sg_val) ? $val : $sg_val,
                    array($this->name => 1)
                );
            }
        } else {
            $record = array(
                'TITLE' => $prop,
                'DEVICE_ID' => $dev_id,
                'VALUE' => $val,
                'LINKED_OBJECT' => '',
                'LINKED_PROPERTY' => '',
                'LINKED_METHOD' => '',
            );
            SQLInsert($table, $record);
        }
    }

    /**
     * Install
     * @access private
     */
    function install($data = '')
    {
        parent::install();
    }

    /**
     * Uninstall
     * @access public
     */
    function uninstall()
    {
        SQLExec('DROP TABLE IF EXISTS dev_hvac_devices');
        SQLExec('DROP TABLE IF EXISTS dev_hvac_commands');
        parent::uninstall();
    }

    /**
     * dbInstall
     * @access private
     */
    function dbInstall($data = '')
    {
        /*
        dev_hvac_devices  - registered devices
        dev_hvac_commands - device properties and their links to objects
        */
        $data = <<<EOD
 dev_hvac_devices: ID int(10) unsigned NOT NULL auto_increment
 dev_hvac_devices: TYPE varchar(10) NOT NULL DEFAULT ''
 dev_hvac_devices: TITLE varchar(100) NOT NULL DEFAULT ''
 dev_hvac_devices: DEVTYPE varchar(10) NOT NULL DEFAULT ''
 dev_hvac_devices: IP varchar(45) NOT NULL DEFAULT ''
 dev_hvac_devices: MAC varchar(20) NOT NULL DEFAULT ''
 dev_hvac_devices: CHTIME varchar(10) NOT NULL DEFAULT ''
 dev_hvac_devices: KEYS varchar(128) NOT NULL DEFAULT ''
 dev_hvac_devices: ENCRYPTION varchar(10) NOT NULL DEFAULT 'ECB'
 dev_hvac_devices: UPDATED datetime
 dev_hvac_commands: ID int(10) unsigned NOT NULL auto_increment
 dev_hvac_commands: TITLE varchar(100) NOT NULL DEFAULT ''
 dev_hvac_commands: VALUE text
 dev_hvac_commands: DEVICE_ID int(10) NOT NULL DEFAULT '0'
 dev_hvac_commands: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 dev_hvac_commands: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 dev_hvac_commands: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 dev_hvac_commands: INDEX (DEVICE_ID)
 dev_hvac_commands: INDEX (LINKED_OBJECT)
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgSnVuIDI4LCAyMDE2IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
