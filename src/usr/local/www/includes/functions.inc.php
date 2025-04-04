<?php
/*
 * functions.inc.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2013-2025 Rubicon Communications, LLC (Netgate)
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

if (Connection_Aborted()) {
	exit;
}

require_once("config.inc");
require_once("pfsense-utils.inc");

function get_stats($sitems = array()) {
	$sitems = is_array($sitems) ? $sitems : [];
	$stats['cpu'] = (!in_array('cpu_usage', $sitems)) ? cpu_usage() : '|';
	$stats['mem'] = (!in_array('memory_usage', $sitems)) ? mem_usage() : '';
	$stats['uptime'] = (!in_array('uptime', $sitems)) ? get_uptime() : '';
	$stats['states'] = (!in_array('state_table_size', $sitems)) ? get_pfstate() : '';
	$stats['temp'] = (!in_array('temperature', $sitems)) ? get_temp() : '';
	$stats['datetime'] = (!in_array('current_datetime', $sitems)) ? update_date_time() : '';
	$stats['cpufreq'] = (!in_array('cpu_type', $sitems)) ? get_cpufreq() : '';
	$stats['load_average'] = (!in_array('load_average', $sitems)) ? get_load_average() : '';
	if (!in_array('mbuf_usage', $sitems)) {
		get_mbuf($stats['mbuf'], $stats['mbufpercent']);
	} else {
		$stats['mbuf'] = '';
		$stats['mbufpercent'] = '';
	}
	$stats['statepercent'] = (!in_array('state_table_size', $sitems)) ? get_pfstate(true) : '';
	$stats = join("|", $stats);
	return $stats;
}

function get_uptime() {
	$uptime = get_uptime_sec();

	if (intval($uptime) == 0) {
		return;
	}

	$updays = (int)($uptime / 86400);
	$uptime %= 86400;
	$uphours = (int)($uptime / 3600);
	$uptime %= 3600;
	$upmins = (int)($uptime / 60);
	$uptime %= 60;
	$upsecs = (int)($uptime);

	$uptimestr = "";
	if ($updays > 1) {
		$uptimestr .= "$updays Days ";
	} else if ($updays > 0) {
		$uptimestr .= "1 Day ";
	}

	if ($uphours > 1) {
		$hours = "s";
	}

	if ($upmins > 1) {
		$minutes = "s";
	}

	if ($upsecs > 1) {
		$seconds = "s";
	}

	$uptimestr .= sprintf("%02d Hour$hours %02d Minute$minutes %02d Second$seconds", $uphours, $upmins, $upsecs);
	return $uptimestr;
}

// Returns the current total ticks and user ticks. The dashboard widget calculates the load from that
function cpu_usage() {

	$diff = array('user', 'nice', 'sys', 'intr', 'idle');
	$cpuTicks = array_combine($diff, explode(" ", get_single_sysctl('kern.cp_time')));

	return array_sum($cpuTicks) . "|" . $cpuTicks['idle'];
}

function get_pfstate($percent=false) {
	$matches = "";
	$maxstates = (config_get_path('system/maximumstates', 0) > 0) ? config_get_path('system/maximumstates') : pfsense_default_state_size();
	$curentries = `/sbin/pfctl -si |grep current`;
	if (preg_match("/([0-9]+)/", $curentries, $matches)) {
		$curentries = $matches[1];
	}
	if (!is_numeric($curentries)) {
		$curentries = 0;
	}
	if ($percent) {
		if (intval($maxstates) > 0) {
			return round(($curentries / $maxstates) * 100, 0);
		} else {
			return "NA";
		}
	} else {
		return $curentries . "/" . $maxstates;
	}
}

function has_temp() {
	/* no known temp monitors available at present */

	/* should only reach here if there is no hardware monitor */
	return false;
}

function get_hwtype() {
	return;
}

function get_mbuf(&$mbuf, &$mbufpercent) {
	$mbufs_output=trim(`/usr/bin/netstat -mb | /usr/bin/grep "mbuf clusters in use" | /usr/bin/awk '{ print $1 }'`);
	list($mbufs_current, $mbufs_cache, $mbufs_total, $mbufs_max) = explode("/", $mbufs_output);
	$mbuf = "{$mbufs_total}/{$mbufs_max}";
	$mbufpercent = ($mbufs_max > 0) ? round(($mbufs_total / $mbufs_max) * 100, 0) : "NA";
}

function get_temp() {
	$temp_out = get_single_sysctl("dev.cpu.0.temperature");
	if ($temp_out == "") {
		$temp_out = get_single_sysctl("hw.acpi.thermal.tz0.temperature");
	}

	// Remove 'C' from the end and spaces
	$temp_out = trim(rtrim($temp_out, 'C'));

	if ($temp_out[0] == '-') {
		return '';
	}

	return $temp_out;
}

/* Get mounted filesystems and usage. Do not display entries for virtual filesystems (e.g. devfs, nullfs, unionfs) */
function get_mounted_filesystems() {
	$mout = "";
	$filesystems = array();
	exec("/bin/df -Tht ufs,zfs,tmpfs,cd9660 | /usr/bin/awk '{print $1, $2, $3, $6, $7;}'", $mout);

	/* Get rid of the header */
	array_shift($mout);
	foreach ($mout as $fs) {
		$f = array();
		list($f['device'], $f['type'], $f['total_size'], $f['percent_used'], $f['mountpoint']) = explode(' ', $fs);

		/* We dont' want the trailing % sign. */
		$f['percent_used'] = trim($f['percent_used'], '%');

		$filesystems[] = $f;
	}
	return $filesystems;
}

function disk_usage($slice = '/') {
	$dfout = "";
	exec("/bin/df -h {$slice} | /usr/bin/tail -n 1 | /usr/bin/awk '{ print $5 }' | /usr/bin/cut -d '%' -f 1", $dfout);
	$diskusage = trim($dfout[0]);

	return $diskusage;
}

function swap_usage() {
	exec("/usr/sbin/swapinfo | /usr/bin/tail -1", $swap_info);
	$swap_used = "";
	foreach ($swap_info as $line) {
		if (preg_match('/(\d+)%$/', $line, $matches)) {
			$swap_used = $matches[1];
			break;
		}
	}

	return $swap_used;
}

function mem_usage() {
	$memUsage = "NA";
	$totalMem = (int) get_single_sysctl("vm.stats.vm.v_page_count");
	if (is_numeric($totalMem) and ($totalMem > 0)) {
		/* Include inactive and laundry with free memory since they
		 * could be freed under pressure. */
		$inactiveMem = (int) get_single_sysctl("vm.stats.vm.v_inactive_count");
		$laundryMem = (int) get_single_sysctl("vm.stats.vm.v_laundry_count");
		$freeMem = (int) get_single_sysctl("vm.stats.vm.v_free_count");
		if (is_numeric($inactiveMem) &&
		    is_numeric($laundryMem) &&
		    is_numeric($freeMem)) {
			$usedMem = $totalMem - ($inactiveMem + $laundryMem + $freeMem);
			$memUsage = round(($usedMem * 100) / $totalMem, 0);
		}
	}
	return $memUsage;
}

function update_date_time() {
	$datetime = date("D M j G:i:s T Y");
	return $datetime;
}

function get_cpufreq() {
	$cpufreqs = "";
	$out = "";
	$cpufreqs = explode(" ", get_single_sysctl('dev.cpu.0.freq_levels'));
	$maxfreq = explode("/", $cpufreqs[0]);
	$maxfreq = $maxfreq[0];
	$curfreq = "";
	$curfreq = get_single_sysctl('dev.cpu.0.freq');
	if (($curfreq > 0) && ($curfreq != $maxfreq)) {
		$out = "Current: {$curfreq} MHz, Max: {$maxfreq} MHz";
	}
	return $out;
}

define("INTEL_C2000_IQIA_PHYS", "0x1f188086");
define("INTEL_200XX_QAT", "0x18ee8086");
define("INTEL_200XX_QAT_VF", "0x18ef8086");
define("INTEL_C3K_QAT", "0x19e28086");
define("INTEL_C3K_QAT_VF", "0x19e38086");
define("INTEL_C620_QAT", "0x37c88086");
define("INTEL_C620_QAT_VF", "0x37c98086");
define("INTEL_XEOND_QAT", "0x6f548086");
define("INTEL_XEOND_QAT_VF", "0x6f558086");
define("INTEL_DH895XCC_QAT", "0x04358086");
define("INTEL_DH895XCC_QAT_VF", "0x04438086");
define("INTEL_4XXX_QAT", "0x49408086");
define("INTEL_4XXX_QAT_VF", "0x49418086");
define("INTEL_401XX_QAT", "0x49428086");
define("INTEL_401XX_QAT_VF", "0x49438086");
define("AESNI_ALGS", "AES-CBC,AES-CCM,AES-GCM,AES-ICM,AES-XTS");
define("AESNI_ALGS_SHA", "SHA1,SHA256");
define("QAT_ALGS", "AES-CBC,AES-CCM,AES-GCM,AES-ICM,AES-XTS,SHA1,SHA256,SHA384,SHA512");

function crypto_accel_new($name = "", $algs = "") {
	return (array("name" => $name, "present" => false, "enabled" => false, "algs" => explode(",", $algs)));
}

function crypto_accel_init() {
	$machine = get_single_sysctl('hw.machine');

	/* Defaults */
	$crypto = array();
	$crypto["accel"] = array();

	switch ($machine) {
	case 'amd64':
		$crypto["accel"][] = crypto_accel_new("AESNI", AESNI_ALGS);
		$crypto["accel"][] = crypto_accel_new("QAT", QAT_ALGS);
		break;
	}

	return ($crypto);
}

function crypto_accel_set_flags($crypto, $name, $present = false, $enabled = false) {

	foreach ($crypto["accel"] as &$accel) {
		if ($accel["name"] != $name) {
			continue;
		}
		$accel["present"] = $present;
		$accel["enabled"] = $enabled;
	}

	return ($crypto);
}

function crypto_accel_get($crypto, $name, $key) {

	foreach ($crypto["accel"] as $accel) {
		if ($accel["name"] != $name) {
			continue;
		}
                return ($accel[$key]);
	}

	return ("");
}

function crypto_accel_set_algs($crypto, $name, $algs) {

	foreach ($crypto["accel"] as &$accel) {
		if ($accel["name"] != $name) {
			continue;
		}
		$a = explode(",", $algs);
		$m = array_merge($accel["algs"], $a);
		$accel["algs"] = array_unique($m, SORT_STRING);
	}

	return ($crypto);
}

function crypto_accel_get_algs($crypto) {
	$algs = [];

	foreach ($crypto['accel'] as $accel) {
		/* skip these... */
		if (!$accel["present"] || !$accel["enabled"]) {
			continue;
		}
		/* $algs is indexed by number, array_merge will automatically
		 * append values for entries with duplicate numerical keys so no
		 * need to manually avoid duplicate index values here. */
		$algs = array_unique(array_merge($algs, $accel['algs']), SORT_STRING);
	}

	/* return early */
	if (empty($algs)) {
		return (gettext('Inactive'));
	}

	/* Sort values, ignoring keys */
	sort($algs, SORT_STRING);

	/* format and return the algs string */
	return (implode(', ', $algs));
}

function get_cpu_crypto_support() {
	global $g;
	$machine = get_single_sysctl('hw.machine');
	$QATIDS = [
		INTEL_C2000_IQIA_PHYS, INTEL_200XX_QAT, INTEL_200XX_QAT_VF,
		INTEL_C3K_QAT, INTEL_C3K_QAT_VF, INTEL_C620_QAT, INTEL_C620_QAT_VF,
		INTEL_XEOND_QAT, INTEL_XEOND_QAT_VF, INTEL_DH895XCC_QAT, INTEL_DH895XCC_QAT_VF,
		INTEL_4XXX_QAT, INTEL_4XXX_QAT_VF, INTEL_401XX_QAT, INTEL_401XX_QAT_VF
	];

	/* Defaults */
	$crypto = crypto_accel_init();

	switch ($machine) {
	case 'amd64':
		$fd = @fopen("{$g['varlog_path']}/dmesg.boot", "r");
		while ($fd && !feof($fd)) {
			$dmesgl = fgets($fd);
			if (preg_match("/^  Features2.*AESNI/", $dmesgl, $matches)) {
				$crypto = crypto_accel_set_flags($crypto, "AESNI", true, (is_module_loaded('aesni')) ? true : false);
			}
			if (preg_match("/^  Structured Extended Features.*SHA/", $dmesgl, $matches)) {
				$crypto = crypto_accel_set_algs($crypto, "AESNI", AESNI_ALGS_SHA);
			}
		}
		if ($fd) {
			fclose($fd);
		}
		exec("/usr/sbin/pciconf -l -l | /usr/bin/awk '{ printf \"%s|0x%s%s\\n\", $1, $6, $5 }'", $pciids);
		if (isset($pciids) && is_array($pciids)) {
			foreach ($pciids as $pciid) {
				list($devid, $pid) = explode('|', $pciid, 2);
				if (in_array($pid, $QATIDS)) {
					list($devname, $busloc) = explode('@', $devid);
					$active = ((substr($devname, 0, 3) == 'qat') && is_module_loaded('qat'));
					$crypto = crypto_accel_set_flags($crypto, "QAT", true, $active);
					break;
				}
			}
		}
		break;
	}

	return ($crypto);
}

function get_cpu_crypto_string($crypto) {
	$machine = get_single_sysctl('hw.machine');
	$string = '';

	switch ($machine) {
	case 'amd64':
		$string = 'AES-NI CPU Crypto: ';
		if ($crypto && crypto_accel_get($crypto, 'AESNI', 'present')) {
			$string .= 'Yes ';
			$string .= crypto_accel_get($crypto, 'AESNI', 'enabled') ? '(active)' : '(inactive)';
		} else {
			$string .= 'No';
		}
		$string .= "<br>\n";
		$string .= 'QAT Crypto: ';
		if ($crypto && crypto_accel_get($crypto, 'QAT', 'present')) {
			$string .= 'Yes ';
			$string .= crypto_accel_get($crypto, 'QAT', 'enabled') ? '(active)' : '(inactive)';
		} else {
			$string .= 'No';
		}
		break;
	}

	if (strlen($string) == 0) {
		$string = 'CPU Crypto: None/Unknown Platform';
	}

	return ($string);
}

function get_cpu_count($show_detail = false) {
	$cpucount = get_single_sysctl('kern.smp.cpus');

	if ($show_detail) {
		$cpudetail = "";
		exec("/usr/bin/grep 'FreeBSD/SMP:.*package' /var/log/dmesg.boot | /usr/bin/cut -f2- -d' '", $cpudetail);
		if (!empty($cpudetail[0]))  {
			$cpucount = $cpudetail[0];
		}
	}
	return $cpucount;
}

function get_load_average() {
	$load_average = "";
	exec("/usr/bin/uptime | /usr/bin/sed 's/^.*: //'", $load_average);
	return $load_average[0];
}

function get_interfacestats() {
	//build interface list for widget use
	$ifdescrs = get_configured_interface_list();

	$array_in_packets = array();
	$array_out_packets = array();
	$array_in_bytes = array();
	$array_out_bytes = array();
	$array_in_errors = array();
	$array_out_errors = array();
	$array_collisions = array();
	$array_interrupt = array();
	$new_data = "";

	//build data arrays
	foreach ($ifdescrs as $ifdescr => $ifname) {
		$ifinfo = get_interface_info($ifdescr);
		$new_data .= "{$ifinfo['inpkts']},";
		$new_data .= "{$ifinfo['outpkts']},";
		$new_data .= format_bytes($ifinfo['inbytes']) . ",";
		$new_data .= format_bytes($ifinfo['outbytes']) . ",";
		if (isset($ifinfo['inerrs'])) {
			$new_data .= "{$ifinfo['inerrs']},";
			$new_data .= "{$ifinfo['outerrs']},";
		} else {
			$new_data .= "0,";
			$new_data .= "0,";
		}
		if (isset($ifinfo['collisions'])) {
			$new_data .= htmlspecialchars($ifinfo['collisions']) . ",";
		} else {
			$new_data .= "0,";
		}
	}//end for

	return $new_data;
}

function get_interfacestatus() {
	$data = "";

	//build interface list for widget use
	$ifdescrs = get_configured_interface_with_descr();

	foreach ($ifdescrs as $ifdescr => $ifname) {
		$ifinfo = get_interface_info($ifdescr);
		$data .= $ifname . "^";
		if ($ifinfo['status'] == "up" || $ifinfo['status'] == "associated") {
			$data .= "up";
		} else if ($ifinfo['status'] == "no carrier") {
			$data .= "down";
		} else if ($ifinfo['status'] == "down") {
			$data .= "block";
		}
		$data .= "^";
		if ($ifinfo['ipaddr']) {
			$data .= "<strong>" . htmlspecialchars($ifinfo['ipaddr']) . "</strong>";
		}
		$data .= "^";
		if ($ifinfo['ipaddrv6']) {
			$data .= "<strong>" . htmlspecialchars($ifinfo['ipaddrv6']) . "</strong>";
		}
		$data .= "^";
		if ($ifinfo['status'] != "down") {
			$data .= htmlspecialchars($ifinfo['media']);
		}

		$data .= "~";

	}
	return $data;
}

