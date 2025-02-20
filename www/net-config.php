<?php
/**
 * moOde audio player (C) 2014 Tim Curtis
 * http://moodeaudio.org
 *
 * This Program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3, or (at your option)
 * any later version.
 *
 * This Program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

set_include_path('/var/www/inc');
require_once 'common.php';
require_once 'network.php';
require_once 'session.php';
require_once 'sql.php';

$dbh = sqlConnect();
phpSession('open');

// Get current settings: [0] = eth0, [1] = wlan0, [2] = apd0
$cfg_network = sqlQuery('SELECT * FROM cfg_network', $dbh);

// Reset eth0 and wlan0 to defaults
if (isset($_POST['reset']) && $_POST['reset'] == 1) {
	// eth0
	$value = array('method' => 'dhcp', 'ipaddr' => '', 'netmask' => '', 'gateway' => '', 'pridns' => '', 'secdns' => '', 'wlanssid' => '',
		'wlansec' => '', 'wlanpwd' => '', 'wlan_psk' => '', 'wlan_country' => '', 'wlan_channel' => '');
	sqlUpdate('cfg_network', $dbh, 'eth0', $value);

	// wlan0
	$value['wlanssid'] = 'None (activates AP mode)';
	$value['wlansec'] = 'wpa';
	$value['wlan_country'] = $cfg_network[1]['wlan_country']; // Preserve country code
	sqlUpdate('cfg_network', $dbh, 'wlan0', $value);

	submitJob('netcfg', '', 'Network config reset', 'Restart required');
}

// Update interfaces
if (isset($_POST['save']) && $_POST['save'] == 1) {
	// eth0
	$value = array('method' => $_POST['eth0method'], 'ipaddr' => $_POST['eth0ipaddr'], 'netmask' => $_POST['eth0netmask'],
		'gateway' => $_POST['eth0gateway'], 'pridns' => $_POST['eth0pridns'], 'secdns' => $_POST['eth0secdns'], 'wlanssid' => '',
		'wlansec' => '', 'wlanpwd' => '', 'wlan_psk' => '', 'wlan_country' => '', 'wlan_channel' => '');
	sqlUpdate('cfg_network', $dbh, 'eth0', $value);

	// wlan0
	$method = (empty($_POST['wlan0ssid']) || $_POST['wlan0ssid'] == 'None (activates AP mode)') ? 'dhcp' : $_POST['wlan0method'];

	if ($_POST['wlan0ssid'] != $cfg_network[1]['wlanssid'] || $_POST['wlan0pwd'] != $cfg_network[1]['wlan_psk']) {
		$psk = genWpaPSK($_POST['wlan0ssid'], $_POST['wlan0pwd']);
	} else {
		$psk = $cfg_network[1]['wlan_psk']; // Existing
	}

	// Update cfg_network
	$value = array('method' => $method, 'ipaddr' => $_POST['wlan0ipaddr'], 'netmask' => $_POST['wlan0netmask'],
		'gateway' => $_POST['wlan0gateway'], 'pridns' => $_POST['wlan0pridns'], 'secdns' => $_POST['wlan0secdns'],
		'wlanssid' => $_POST['wlan0ssid'], 'wlansec' => $_POST['wlan0sec'], 'wlanpwd' => $psk, 'wlan_psk' => $psk,
		'wlan_country' => $_POST['wlan0country'], 'wlan_channel' => '');
	sqlUpdate('cfg_network', $dbh, 'wlan0', $value);

	// Add/update cfg_ssid
	if ($_POST['wlan0ssid'] != 'None (activates AP mode)') {
		$result = sqlQuery("SELECT * FROM cfg_ssid WHERE ssid='" . $_POST['wlan0ssid'] . "'", $dbh);
		if ($result === true) {
			// Add
			$values =
				"'"	. SQLite3::escapeString($_POST['wlan0ssid']) . "'," .
				"'" . $_POST['wlan0sec'] . "'," .
				"'" . $psk . "'";
			$result = sqlQuery('INSERT INTO cfg_ssid VALUES (NULL,' . $values . ')', $dbh);
		} else {
			// Update
			$result = sqlQuery("UPDATE cfg_ssid SET " .
				"ssid='" . SQLite3::escapeString($_POST['wlan0ssid']) . "'," .
				"sec='" . $_POST['wlan0sec'] . "'," .
				"psk='" . $psk . "' " .
				"where id='" . $result[0]['id'] . "'" , $dbh);
		}
	}

	// apd0
	if ($_POST['wlan0apdssid'] != $cfg_network[2]['wlanssid'] || $_POST['wlan0apdpwd'] != $cfg_network[2]['wlan_psk']) {
		$psk = genWpaPSK($_POST['wlan0apdssid'], $_POST['wlan0apdpwd']);
	} else {
		$psk = $cfg_network[2]['wlan_psk']; // Existing
	}

	$value = array('method' => '', 'ipaddr' => '', 'netmask' => '', 'gateway' => '', 'pridns' => '', 'secdns' => '',
		'wlanssid' => $_POST['wlan0apdssid'], 'wlansec' => '', 'wlanpwd' => $psk, 'wlan_psk' => $psk,
		'wlan_country' => '', 'wlan_channel' => $_POST['wlan0apdchan']);
	sqlUpdate('cfg_network', $dbh, 'apd0', $value);

	submitJob('netcfg', '', 'Changes saved', 'Restart required');
}

// Update saved networks
if (isset($_POST['update-saved-networks']) && $_POST['update-saved-networks'] == 1) {
	$cfg_ssid = sqlQuery("SELECT * FROM cfg_ssid WHERE ssid != '" . $cfg_network[1]['wlanssid'] . "'", $dbh);
	if ($cfg_ssid !== true) {
		$item_deleted = false;
		for ($i = 0; $i < count($cfg_ssid); $i++) {
			$_post_ssid = 'cfg-ssid-' . $cfg_ssid[$i]['id'];
			if (isset($_POST[$_post_ssid]) && $_POST[$_post_ssid] == 'on') {
				$result = sqlQuery("DELETE FROM cfg_ssid WHERE id = '" . $cfg_ssid[$i]['id'] . "'", $dbh);
				$item_deleted = true;
			}
		}

		if ($item_deleted) {
			submitJob('netcfg', '', 'Update applied', 'Restart required');
		}
	}
}

phpSession('close');

//
// Populate form fields
//

// Get updated settings: [0] = eth0, [1] = wlan0, [2] = apd0
$cfg_network = sqlQuery('SELECT * FROM cfg_network', $dbh);

// List saved networks excluding the currently configured SSID
$cfg_ssid = sqlQuery("SELECT * FROM cfg_ssid WHERE ssid != '" . $cfg_network[1]['wlanssid'] . "'", $dbh);
if ($cfg_ssid === true) {
	$_saved_networks = '<p style="text-align:center;">There are no saved networks</p>';
} else {
	for ($i=0; $i < count($cfg_ssid); $i++) {
		$_saved_networks .= '<div class="control-group">';
		$_saved_networks .= '<label class="control-label">' . $cfg_ssid[$i]['ssid'] . '</label>';
		$_saved_networks .= '<div class="controls">';
		$_saved_networks .= '<input name="cfg-ssid-' . $cfg_ssid[$i]['id'] . '" class="checkbox-ctl saved-ssid-modal-delete" type="checkbox"><em>Delete</em>';
		$_saved_networks .= '</div>';
		$_saved_networks .= '</div>';
	}
}

// ETH0
$_eth0method .= "<option value=\"dhcp\" "   . ($cfg_network[0]['method'] == 'dhcp' ? 'selected' : '') . " >DHCP</option>\n";
$_eth0method .= "<option value=\"static\" " . ($cfg_network[0]['method'] == 'static' ? 'selected' : '') . " >STATIC</option>\n";

// Display ipaddr or message
$ipaddr = sysCmd("ip addr list eth0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1");
$_eth0currentip = empty($ipaddr[0]) ? 'Not in use' : $ipaddr[0];

// Static IP
$_eth0ipaddr = $cfg_network[0]['ipaddr'];
//$_eth0netmask = $cfg_network[0]['netmask'];
$_eth0netmask .= "<option value=\"24\" " . ($cfg_network[0]['netmask'] == '24' ? 'selected' : '') . " >255.255.255.0</option>\n";
$_eth0netmask .= "<option value=\"16\" " . ($cfg_network[0]['netmask'] == '16' ? 'selected' : '') . " >255.255.0.0</option>\n";
$_eth0gateway = $cfg_network[0]['gateway'];
$_eth0pridns = $cfg_network[0]['pridns'];
$_eth0secdns = $cfg_network[0]['secdns'];

// WLAN0
$_wlan0method .= "<option value=\"dhcp\" " . ($cfg_network[1]['method'] == 'dhcp' ? 'selected' : '') . " >DHCP</option>\n";
$_wlan0method .= "<option value=\"static\" " . ($cfg_network[1]['method'] == 'static' ? 'selected' : '') . " >STATIC</option>\n";

// Get ipaddr if any
$ipaddr = sysCmd("ip addr list wlan0 |grep \"inet \" |cut -d' ' -f6|cut -d/ -f1");

// Get link quality and signal level
if (!empty($ipaddr[0])) {
	$signal = sysCmd('iwconfig wlan0 | grep -i quality');
	$array = explode('=', $signal[0]);
	$qual = explode('/', $array[1]);
	$quality = round((100 * $qual[0]) / $qual[1]);
	$lev = explode('/', $array[2]);
	$level = strpos($lev[0], 'dBm') !== false ? $lev[0] : $lev[0] . '%';
}

// Determine message to display
if ($_SESSION['apactivated'] == true) {
	$_wlan0currentip = empty($ipaddr[0]) ? 'Unable to activate AP mode' : $ipaddr[0] . ' - AP mode active';
} else {
	$_wlan0currentip = empty($ipaddr[0]) ? 'Not in use' : $ipaddr[0] . ' - quality ' . $quality . '%, level ' . $level;
}

// SSID, scanner, security protocol, password
if (isset($_POST['scan']) && $_POST['scan'] == '1') {
	$result = sysCmd("iwlist wlan0 scan | grep ESSID | sed 's/ESSID://; s/\"//g'"); // Do twice to improve results
	$result = sysCmd("iwlist wlan0 scan | grep ESSID | sed 's/ESSID://; s/\"//g'");
	$array = array();
	$array[0] = 'None (activates AP mode)';
	$ssidList = array_merge($array, $result);

	foreach ($ssidList as $ssid) {
		$ssid = trim($ssid);
		// Additional filtering
		if (!empty($ssid) && false === strpos($ssid, '\x')) {
			$selected = ($cfg_network[1]['wlanssid'] == $ssid) ? 'selected' : '';
			$_wlan0ssid .= sprintf('<option value="%s" %s>%s</option>\n', $ssid, $selected, $ssid);
		}
	}
} else {
	if (isset($_POST['manualssid']) && $_POST['manualssid'] == '1') {
		$_wlan0ssid = sprintf('<option value="%s" %s>%s</option>\n', 'None (activates AP mode)', '', 'None (activates AP mode)');
		$_wlan0ssid .= sprintf('<option value="%s" %s>%s</option>\n', $_POST['wlan0otherssid'], 'selected', htmlentities($_POST['wlan0otherssid']));
	} else if ($cfg_network[1]['wlanssid'] == 'None (activates AP mode)') {
		$_wlan0ssid .= sprintf('<option value="%s" %s>%s</option>\n', $cfg_network[1]['wlanssid'], 'selected', $cfg_network[1]['wlanssid']);
	} else {
		$_wlan0ssid = sprintf('<option value="%s" %s>%s</option>\n', 'None (activates AP mode)', '', 'None (activates AP mode)');
		$_wlan0ssid .= sprintf('<option value="%s" %s>%s</option>\n', $cfg_network[1]['wlanssid'], 'selected', htmlentities($cfg_network[1]['wlanssid']));
	}
}
$_wlan0sec .= "<option value=\"wpa\"" . ($cfg_network[1]['wlansec'] == 'wpa' ? 'selected' : '') . ">WPA/WPA2 Personal</option>\n";
$_wlan0sec .= "<option value=\"none\"" . ($cfg_network[1]['wlansec'] == 'none' ? 'selected' : '') . ">No security</option>\n";
$_wlan0pwd = $cfg_network[1]['wlanpwd']; // old: htmlentities($cfg_network[1]['wlanpwd'])

// WiFi country code
$zonelist = sysCmd("cat /usr/share/zoneinfo/iso3166.tab | tail -n +26 | tr '\t' ','");
$zonelist_sorted = array();
for ($i = 0; $i < count($zonelist); $i++) {
	$country = explode(',', $zonelist[$i]);
	if ($country[1] == 'Britain (UK)') {$country[1] = 'United Kingdom (UK)';}
	$zonelist_sorted[$i] = $country[1] . ',' . $country[0];
}
sort($zonelist_sorted);
foreach ($zonelist_sorted as $zone) {
	$country = explode(',', $zone);
	$selected = ($country[1] == $cfg_network[1]['wlan_country']) ? 'selected' : '';
	$_wlan0country .= sprintf('<option value="%s" %s>%s</option>\n', $country[1], $selected, $country[0]);
}

// Static ip
$_wlan0ipaddr = $cfg_network[1]['ipaddr'];
//$_wlan0netmask = $cfg_network[1]['netmask'];
$_wlan0netmask .= "<option value=\"24\" "   . ($cfg_network[1]['netmask'] == '24' ? 'selected' : '') . " >255.255.255.0</option>\n";
$_wlan0netmask .= "<option value=\"16\" " . ($cfg_network[1]['netmask'] == '16' ? 'selected' : '') . " >255.255.0.0</option>\n";
$_wlan0gateway = $cfg_network[1]['gateway'];
$_wlan0pridns = $cfg_network[1]['pridns'];
$_wlan0secdns = $cfg_network[1]['secdns'];

// Access point
$_wlan0apdssid = $cfg_network[2]['wlanssid'];
$_wlan0apdchan = $cfg_network[2]['wlan_channel'];
$_wlan0apdpwd = $cfg_network[2]['wlanpwd'];

waitWorker(1, 'net-config');

$tpl = "net-config.html";
$section = basename(__FILE__, '.php');
storeBackLink($section, $tpl);

include('header.php');
eval("echoTemplate(\"" . getTemplate("templates/$tpl") . "\");");
include('footer.php');
