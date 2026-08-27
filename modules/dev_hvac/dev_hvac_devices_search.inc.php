<?php
/*
* @version 0.2
*/
global $session;

if (isset($this->owner) && is_object($this->owner) && isset($this->owner->name) && $this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$out['CYCLERUN'] = ((time() - (int)gg('cycle_dev_hvacRun')) < 15) ? 1 : 0;

$sortby_dev_hvac_devices = "ID DESC";
$out['SORTBY'] = $sortby_dev_hvac_devices;

// SEARCH RESULTS
$res = SQLSelect("SELECT * FROM dev_hvac_devices ORDER BY " . $sortby_dev_hvac_devices);

if (is_array($res) && isset($res[0]['ID'])) {
    $total = count($res);
    for ($i = 0; $i < $total; $i++) {
        $updated = isset($res[$i]['UPDATED']) ? (string)$res[$i]['UPDATED'] : '';
        if ($updated === '' || strpos($updated, '0000-00-00') === 0) {
            $res[$i]['UPDATED'] = '';
            continue;
        }
        $tmp = explode(' ', $updated);
        $res[$i]['UPDATED'] = fromDBDate($tmp[0]) . (isset($tmp[1]) ? ' ' . $tmp[1] : '');
    }
    $out['RESULT'] = $res;
}
