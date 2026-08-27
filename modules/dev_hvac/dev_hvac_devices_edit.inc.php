<?php
/*
* @version 0.2
*/
if (isset($this->owner) && is_object($this->owner) && isset($this->owner->name) && $this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'dev_hvac_devices';
$id = (int)$id;

$rec = $id ? SQLSelectOne("SELECT * FROM $table_name WHERE ID=" . $id) : null;
if (!is_array($rec)) {
    $rec = array();
}

include_once(DIR_MODULES . $this->name . '/hvac.class.php');

$ok = 1;
$updated_hours = '';
$updated_minutes = '';

if ($this->mode == 'add_from_scan') {
    // pre-fill the form with the data found by the network scan, nothing is saved yet
    global $type, $title, $ip, $devtype, $mac, $keys, $keys64, $encryption;

    if (isset($keys64) && $keys64 != '') {
        $padded = strtr($keys64, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded !== false) {
            $keys = $decoded;
        }
    }

    $rec['TYPE'] = isset($type) ? $type : '';
    $rec['TITLE'] = isset($title) ? $title : '';
    $rec['IP'] = isset($ip) ? $ip : '';
    $rec['DEVTYPE'] = isset($devtype) ? $devtype : '';
    $rec['MAC'] = isset($mac) ? $mac : '';
    $rec['KEYS'] = isset($keys) ? $keys : '';
    $rec['ENCRYPTION'] = isset($encryption) && $encryption != '' ? $encryption : 'ECB';
    $rec['CHTIME'] = '1m';

    if ($rec['TITLE'] == '') {
        $rec['TITLE'] = trim($rec['TYPE'] . ' ' . $rec['IP']);
    }
}

if ($this->mode == 'update') {
    $ok = 1;

    if ($this->tab == '') {
        global $type, $title, $ip, $devtype, $mac, $keys, $encryption, $chtime;
        global $updated_date, $updated_minutes, $updated_hours;

        $rec['TYPE'] = isset($type) ? $type : '';
        $rec['TITLE'] = isset($title) ? trim($title) : '';
        if ($rec['TITLE'] == '') {
            $out['ERR_TITLE'] = 1;
            $ok = 0;
        }
        $rec['IP'] = isset($ip) ? trim($ip) : '';
        $rec['DEVTYPE'] = isset($devtype) ? trim($devtype) : '';
        $rec['MAC'] = isset($mac) ? trim($mac) : '';
        $rec['KEYS'] = isset($keys) ? trim($keys) : '';
        $rec['ENCRYPTION'] = (isset($encryption) && strtoupper($encryption) == 'GCM') ? 'GCM' : 'ECB';
        $rec['CHTIME'] = isset($chtime) ? $chtime : '';

        if (isset($updated_date) && $updated_date != '') {
            $rec['UPDATED'] = toDBDate($updated_date) . ' '
                . (isset($updated_hours) ? $updated_hours : '00') . ':'
                . (isset($updated_minutes) ? $updated_minutes : '00') . ':00';
        }
    }

    // UPDATING RECORD
    if ($ok) {
        if (!empty($rec['ID'])) {
            SQLUpdate($table_name, $rec);
        } else {
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec);
            $id = (int)$rec['ID'];
        }
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
    }
}

// step: default
if ($this->tab == '') {
    if (!empty($rec['UPDATED']) && strpos((string)$rec['UPDATED'], '0000-00-00') !== 0) {
        $tmp = explode(' ', $rec['UPDATED']);
        $out['UPDATED_DATE'] = fromDBDate($tmp[0]);
        if (isset($tmp[1])) {
            $tmp2 = explode(':', $tmp[1]);
            $updated_hours = isset($tmp2[0]) ? $tmp2[0] : '';
            $updated_minutes = isset($tmp2[1]) ? $tmp2[1] : '';
        }
    }

    for ($i = 0; $i < 60; $i++) {
        $option_title = ($i < 10) ? "0$i" : (string)$i;
        if ($option_title == $updated_minutes) {
            $out['UPDATED_MINUTES'][] = array('TITLE' => $option_title, 'SELECTED' => 1);
        } else {
            $out['UPDATED_MINUTES'][] = array('TITLE' => $option_title);
        }
    }
    for ($i = 0; $i < 24; $i++) {
        $option_title = ($i < 10) ? "0$i" : (string)$i;
        if ($option_title == $updated_hours) {
            $out['UPDATED_HOURS'][] = array('TITLE' => $option_title, 'SELECTED' => 1);
        } else {
            $out['UPDATED_HOURS'][] = array('TITLE' => $option_title);
        }
    }

    $out['ENCRYPTION_ECB_SELECTED'] = (!isset($rec['ENCRYPTION']) || $rec['ENCRYPTION'] != 'GCM') ? 1 : 0;
    $out['ENCRYPTION_GCM_SELECTED'] = (isset($rec['ENCRYPTION']) && $rec['ENCRYPTION'] == 'GCM') ? 1 : 0;
}

if ($this->tab == 'data' || $this->tab == 'data_usage') {
    $this->getConfig();

    $device_id = (int)(isset($rec['ID']) ? $rec['ID'] : 0);

    global $delete_id;
    if (!empty($delete_id)) {
        $doomed = SQLSelectOne("SELECT * FROM dev_hvac_commands WHERE ID=" . (int)$delete_id);
        if (is_array($doomed) && !empty($doomed['LINKED_OBJECT']) && !empty($doomed['LINKED_PROPERTY'])) {
            removeLinkedProperty($doomed['LINKED_OBJECT'], $doomed['LINKED_PROPERTY'], $this->name);
        }
        SQLExec("DELETE FROM dev_hvac_commands WHERE ID=" . (int)$delete_id);
    }

    // adding a new property has to happen *outside* of the update loop: it used to
    // be inside it, which inserted one copy per existing row and nothing at all
    // when the device had no properties yet
    if ($this->mode == 'update') {
        global $title_new;
        if (isset($title_new) && trim($title_new) != '' && $device_id) {
            $prop = array('TITLE' => trim($title_new), 'DEVICE_ID' => $device_id, 'VALUE' => '');
            SQLInsert('dev_hvac_commands', $prop);
        }
    }

    global $sort_by_name;
    $order = !empty($sort_by_name) ? 'TITLE' : 'ID';
    $properties = SQLSelect("SELECT * FROM dev_hvac_commands WHERE DEVICE_ID=" . $device_id . " ORDER BY " . $order);
    if (!is_array($properties)) {
        $properties = array();
    }

    paging($properties, 20, $out);

    $total = count($properties);
    for ($i = 0; $i < $total; $i++) {
        if ($this->mode == 'update') {
            $prop_id = (int)$properties[$i]['ID'];

            // remember the previous link *before* overwriting it, otherwise the
            // comparison below can never be true and the old link is never released
            $old_linked_object = $properties[$i]['LINKED_OBJECT'];
            $old_linked_property = $properties[$i]['LINKED_PROPERTY'];

            global ${'title' . $prop_id};
            global ${'value' . $prop_id};
            global ${'linked_object' . $prop_id};
            global ${'linked_property' . $prop_id};
            global ${'linked_method' . $prop_id};

            if (isset(${'title' . $prop_id})) {
                $properties[$i]['TITLE'] = trim(${'title' . $prop_id});
            }
            if (isset(${'value' . $prop_id})) {
                $properties[$i]['VALUE'] = trim(${'value' . $prop_id});
            }
            if (isset(${'linked_object' . $prop_id})) {
                $properties[$i]['LINKED_OBJECT'] = trim(${'linked_object' . $prop_id});
            }
            if (isset(${'linked_property' . $prop_id})) {
                $properties[$i]['LINKED_PROPERTY'] = trim(${'linked_property' . $prop_id});
            }
            // LINKED_METHOD is present in the form and in the table, but the previous
            // version never read it back, so it could not be saved
            if (isset(${'linked_method' . $prop_id})) {
                $properties[$i]['LINKED_METHOD'] = trim(${'linked_method' . $prop_id});
            }

            SQLUpdate('dev_hvac_commands', $properties[$i]);

            if ($old_linked_object != '' &&
                ($old_linked_object != $properties[$i]['LINKED_OBJECT'] || $old_linked_property != $properties[$i]['LINKED_PROPERTY'])
            ) {
                removeLinkedProperty($old_linked_object, $old_linked_property, $this->name);
            }
        }

        if ($properties[$i]['LINKED_OBJECT'] && $properties[$i]['LINKED_PROPERTY']) {
            addLinkedProperty($properties[$i]['LINKED_OBJECT'], $properties[$i]['LINKED_PROPERTY'], $this->name);
        }

        $properties[$i]['DEVTYPE'] = isset($rec['TYPE']) ? $rec['TYPE'] : '';
    }

    $out['PROPERTIES'] = $properties;
}

if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars((string)$v);
        }
    }
}
outHash($rec, $out);
