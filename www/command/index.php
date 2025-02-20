<?php
/**
 * moOde audio player (C) 2014 Tim Curtis
 * http://moodeaudio.org
 *
 * tsunamp player ui (C) 2013 Andrea Coiutti & Simone De Gregori
 * http://www.tsunamp.com
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
require_once 'mpd.php';

if (isset($_GET['cmd']) && empty($_GET['cmd'])) {
	echo 'Command missing';
} else if (stripos($_GET['cmd'], '.sh') === false && stripos($_GET['cmd'], '.php') === false) {
	// MPD commands
	if (false === ($sock = openMpdSock('localhost', 6600))) {
		workerLog('command/index.php: Connection to MPD failed');
	} else {
		sendMpdCmd($sock, $_GET['cmd']);
		$result = readMpdResp($sock);
		closeMpdSock($sock);
		//echo $result;
	}
} else {
	// PHP and BASH commands
    if (preg_match('/^[A-Za-z0-9 _.-]+$/', $_GET['cmd'])) {
		if (substr_count($_GET['cmd'], '.') > 1) {
			echo 'Invalid string'; // Reject directory traversal ../
		} else if (stripos($_GET['cmd'], 'vol.sh') !== false) {
			$result = sysCmd('/var/www/' . $_GET['cmd']);
			echo $result[0];
        } else if (stripos($_GET['cmd'], 'libupd-submit.php') !== false) {
			$result = sysCmd('/var/www/' . $_GET['cmd']);
			echo 'Library update submitted';
        } else if (stripos($_GET['cmd'], 'trx-status.php') !== false) {
			$result = sysCmd('/var/www/command/' . $_GET['cmd']);
			echo $result[0];
        } else if (stripos($_GET['cmd'], 'coverview.php') !== false) {
			$result = sysCmd('/var/www/command/' . $_GET['cmd']);
			echo $result[0];
        } else {
            echo 'Unknown command';
        }
    } else {
    	echo 'Invalid string';
    }
}
