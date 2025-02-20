#!/usr/bin/php
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
require_once 'multiroom.php';
require_once 'session.php';
require_once 'sql.php';

session_id(phpSession('get_sessionid'));
phpSession('open');

$option = isset($argv[1]) ? $argv[1] : '';

switch ($option) {
	case '-rx':
		if (isset($argv[2])) {
			rxOnOff($argv[2]);
			$status = '';
		} else {
			$status = rxStatus();
		}
		break;
	case '-tx':
		$status = txStatus();
		break;
	case '-all':
		$status = allStatus();
		break;
	case '-set-mpdvol':
		$rxStatusParts = explode(',', rxStatus());
		// rx, On/Off/Disabled/Unknown, volume, volume,mute_1/0, mastervol_opt_in_1/0, hostname
		if ($rxStatusParts[4] == '1') {
			sysCmd('/var/www/vol.sh ' . $argv[2] . (isset($argv[3]) ? ' ' . $argv[3] : ''));
		}
		$status = '';
		break;
	// This is used to set rx to 0dB when Airplay or Spotify connects to Sender
	case '-set-alsavol':
		if (isset($argv[2])) {
			if ($_SESSION['multiroom_rx'] == 'On') {
				setAlsavol($argv[2]);
			}
			$status = '';
		} else {
			$status = 'Missing option';
		}
		break;
	default:
		$status = 'Missing option';
		break;
}

if (phpSession('get_status') == PHP_SESSION_ACTIVE) {
	phpSession('close');
}

if ($status != '') {
	echo $status;
}
exit(0);

function rxOnOff($onoff) {
	if ($_SESSION['mpdmixer'] == 'hardware' || $_SESSION['mpdmixer'] == 'none') {
		phpSession('write', 'multiroom_rx', $onoff);
		$onoff == 'On' ? startMultiroomReceiver() : stopMultiroomReceiver();
	}
}

function rxStatus() {
	$result = sqlQuery("SELECT value FROM cfg_multiroom WHERE param = 'rx_mastervol_opt_in'", sqlConnect());
	$volume = $_SESSION['mpdmixer'] == 'none' ? '0dB' : ($_SESSION['mpdmixer'] == 'software' ? '?' : $_SESSION['volknob']);
	return
		'rx' . ',' . 						// Receiver
		$_SESSION['multiroom_rx'] . ',' . 	// Status: On/Off/Disabled/Unknown
		$volume . ',' .						// Volume
		$_SESSION['volmute'] . ',' . 		// Mute state: 1/0
		$result[0]['value'] . ',' . 		// Master volume opt-in: 1/0
		$_SESSION['hostname'];				// Hostname from System Config entry
}

function txStatus() {
	$volume = $_SESSION['mpdmixer'] == 'none' ? '0dB' : $_SESSION['volknob'];
	return 'tx' . ',' . $_SESSION['multiroom_tx'] . ',' . $volume . ',' . $_SESSION['volmute'];
}

function allStatus() {
	return rxStatus() . ',' . txStatus();
}

function setAlsavol($vol) {
	sysCmd('/var/www/command/util.sh set-alsavol "' . $_SESSION['amixname'] . '" ' . $vol);
}
