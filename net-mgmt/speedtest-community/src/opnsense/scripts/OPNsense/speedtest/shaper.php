#!/usr/local/bin/php
<?php

/*
 * Copyright (C) 2026 Blip9575
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

/*
 * Set the traffic shaper pipes of an interface from the speedtest results.
 *
 *   shaper.php set     take a percentile of the results of the last N days, scale by the
 *                      thresholds (and the DSL overhead of the upload's ACKs) and
 *                      update the pipes when they differ by the minimum change
 *   shaper.php create  create pipes, queues and rules on the interface for the
 *                      directions that have none yet
 *   shaper.php sync    when the interface address changed (newwanip hook), update
 *                      the rules that exclude the previous address; set runs it too
 *   shaper.php mss     MSS clamping status of the interface
 *   shaper.php setmss  set the interface MSS to its MTU (clamping to MTU - 40)
 *   shaper.php rules   second step of create (run by create itself): a rule can
 *                      only refer to a queue that the process saw when it loaded
 *                      the model, so the rules are added by a fresh process
 *
 * Pipes are found through the shaper rules on the interface (in: download,
 * out: upload, a queue counts as its pipe), else by the names below.
 */

require_once('script/load_phalcon.php');
require_once('config.inc');
require_once('interfaces.inc');
require_once('util.inc');

use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Speedtest\Speedtest;
use OPNsense\TrafficShaper\TrafficShaper;

const CSV_FILE = '/usr/local/opnsense/scripts/OPNsense/speedtest/speedtest.csv';
const LOCK_FILE = '/tmp/speedtest_shaper.lock';
// CoDel default target (ms)
const CODEL_TARGET_MS = 5;
// link framing, bytes per packet on top of the IP packet: Ethernet header for a plain
// link; DSL per RFC 2684 (ATM, LLC/SNAP bridged incl. AAL5 trailer) or PTM, plus PPPoE
const ETHERNET_HEADER = 14;
const DSL_OVERHEAD = ['atm' => 32, 'ptm' => 22];
const PPPOE_OVERHEAD = 8;
// TCP/IP headers of a full packet and the size of a TCP ACK (with timestamps)
const TCPIP_HEADERS = 40;
const ACK_BYTES = 52;

// speedtest.csv columns
const CSV_TIMESTAMP = 0;
const CSV_DOWNLOAD = 5;
const CSV_UPLOAD = 6;

// per direction: rule direction, csv column and the names used when creating
const DIRECTIONS = [
    'download' => [
        'rule' => 'in',
        'column' => CSV_DOWNLOAD,
        'pipe' => 'WAN_download_p',
        'queue' => 'WAN_download_q',
        'rulename' => 'WAN to LAN',
    ],
    'upload' => [
        'rule' => 'out',
        'column' => CSV_UPLOAD,
        'pipe' => 'WAN_upload_p',
        'queue' => 'WAN_upload_q',
        'rulename' => 'LAN to WAN',
    ],
];

function finish($result)
{
    $msg = $result['message'] ?? $result['status'];
    // reopen: saving the config switches the syslog identity
    openlog('speedtest', LOG_ODELAY, LOG_USER);
    syslog($result['status'] == 'error' ? LOG_ERR : LOG_NOTICE, 'shaper: ' . $msg);
    echo json_encode($result) . PHP_EOL;
    exit(0);
}

/**
 * download and upload (Mbit/s) of the results of the last $days days: the given
 * percentile of each (0: slowest, 50: median), linear between neighbouring results
 */
function speedtest_estimate($days, $percentile)
{
    // the csv timestamp is the UTC time read as local time, compare in the same terms
    $since = time() - (int)date('Z') - $days * 86400;
    $rows = [];
    if (($fh = @fopen(CSV_FILE, 'r')) !== false) {
        fgetcsv($fh, 0, ',', '"', '\\'); // header
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (isset($row[CSV_UPLOAD]) && is_numeric($row[CSV_TIMESTAMP]) && $row[CSV_TIMESTAMP] >= $since &&
                is_numeric($row[CSV_DOWNLOAD]) && is_numeric($row[CSV_UPLOAD]) &&
                $row[CSV_DOWNLOAD] > 0 && $row[CSV_UPLOAD] > 0) {
                $rows[] = $row;
            }
        }
        fclose($fh);
    }
    if (count($rows) == 0) {
        return null;
    }
    $estimate = ['probes' => count($rows)];
    foreach (DIRECTIONS as $dir => $props) {
        $values = array_map('floatval', array_column($rows, $props['column']));
        sort($values);
        $pos = ($percentile / 100) * (count($values) - 1);
        $low = (int)floor($pos);
        $high = min($low + 1, count($values) - 1);
        $estimate[$dir] = $values[$low] + ($values[$high] - $values[$low]) * ($pos - $low);
    }
    return $estimate;
}

/**
 * the link behind the interface: its MTU (as configured now, e.g. 1492 for PPPoE or 1500
 * with RFC 4638 mini jumbo frames), PPPoE or not, and the DSL type set in the settings
 */
function wan_link($interface, $dsltype)
{
    $realif = get_real_interface($interface);
    $details = legacy_interface_details($realif);
    $link = [
        'mtu' => !empty($details['mtu']) ? (int)$details['mtu'] : 1500,
        'pppoe' => strpos($realif, 'pppoe') === 0,
        'dsl' => isset(DSL_OVERHEAD[$dsltype]) ? $dsltype : '',
    ];
    $link['overhead'] = $link['dsl'] != '' ?
        DSL_OVERHEAD[$link['dsl']] + ($link['pppoe'] ? PPPOE_OVERHEAD : 0) : ETHERNET_HEADER;
    return $link;
}

/**
 * bytes on the line for an IP packet of $bytes: ATM sends 48 bytes in each 53 byte cell
 * (the last one padded), PTM adds 1 byte per 64
 */
function wire_bytes($bytes, $link)
{
    $bytes += $link['overhead'];
    if ($link['dsl'] == 'atm') {
        return (int)ceil($bytes / 48) * 53;
    } elseif ($link['dsl'] == 'ptm') {
        return $bytes * 65 / 64;
    }
    return $bytes;
}

/**
 * upload bandwidth (Kbit/s) for a DSL line: the speedtest measures full packets, while the
 * download's ACKs take much more of the line than the shaper counts for them (ATM: a 52
 * byte ACK takes 106 bytes); reserve the line for one ACK per two full packets at the
 * download pipe's rate and give the rest to full packets. Never below half of $kbit.
 */
function dsl_upload($kbit, $download_kbit, $link)
{
    $full = wire_bytes($link['mtu'], $link);
    // line capacity the result stands for: goodput of full packets -> bytes on the line
    $line = $kbit * $full / ($link['mtu'] - TCPIP_HEADERS);
    $acks = $download_kbit * 1000 / (2 * $link['mtu'] * 8);
    $ack_line = $acks * wire_bytes(ACK_BYTES, $link) * 8 / 1000;
    $bulk = max(0, $line - $ack_line) * $link['mtu'] / $full;
    return max($kbit / 2, $bulk + $acks * ACK_BYTES * 8 / 1000);
}

/**
 * pipes per direction: through the rules on the interface, else by name
 * @return array direction => list of pipe uuids
 */
function discover_pipes($shaper, $interface, $by_name = true)
{
    $queue_pipe = [];
    foreach ($shaper->queues->queue->iterateItems() as $uuid => $queue) {
        $queue_pipe[$uuid] = (string)$queue->pipe;
    }
    $pipes = [];
    foreach ($shaper->pipes->pipe->iterateItems() as $uuid => $pipe) {
        $pipes[$uuid] = (string)$pipe->description;
    }
    $found = [];
    foreach (DIRECTIONS as $dir => $props) {
        $found[$dir] = [];
    }
    foreach ($shaper->rules->rule->iterateItems() as $rule) {
        if ((string)$rule->enabled != '1' || (string)$rule->interface != $interface) {
            continue;
        }
        $target = (string)$rule->target;
        $pipe = isset($pipes[$target]) ? $target : ($queue_pipe[$target] ?? null);
        if ($pipe === null) {
            continue;
        }
        foreach (DIRECTIONS as $dir => $props) {
            // a rule without direction matches both
            if (in_array((string)$rule->direction, ['', $props['rule']]) && !in_array($pipe, $found[$dir])) {
                $found[$dir][] = $pipe;
            }
        }
    }
    foreach (DIRECTIONS as $dir => $props) {
        if (empty($found[$dir]) && $by_name) {
            $named = array_keys($pipes, $props['pipe'], true);
            if (count($named) == 1) {
                $found[$dir] = $named;
            }
        }
    }
    return $found;
}

function pipe_kbit($pipe)
{
    $factor = ['bit' => 0.001, 'Kbit' => 1, 'Mbit' => 1000, 'Gbit' => 1000000];
    return (int)round((float)(string)$pipe->bandwidth * ($factor[(string)$pipe->bandwidthMetric] ?? 1));
}

/**
 * pipe setting for a bandwidth: whole Mbit/s from 10 Mbit/s up, else Kbit/s;
 * rounded down so the pipe stays at or below the threshold
 */
function pipe_target($kbit)
{
    if ($kbit >= 10000) {
        $target = ['bandwidth' => (int)floor($kbit / 1000), 'metric' => 'Mbit'];
        $target['kbit'] = $target['bandwidth'] * 1000;
    } else {
        $target = ['bandwidth' => max(1, (int)floor($kbit)), 'metric' => 'Kbit'];
        $target['kbit'] = $target['bandwidth'];
    }
    return $target;
}

/**
 * CoDel target of a pipe that uses CoDel, for its new bandwidth: on a slow pipe one
 * full-size packet (as sent on the link) takes longer than the default target (5 ms), so the target
 * becomes that time; a target above the default goes back to it once the pipe is
 * fast enough, a lower one is left alone
 * @return string addition to the message, empty when the target is unchanged
 */
function codel_target($pipe, $kbit, $link)
{
    if ((string)$pipe->scheduler != 'fq_codel' && (string)$pipe->codel_enable != '1') {
        return '';
    }
    $ms = (int)ceil(wire_bytes($link['mtu'], $link) * 8 / $kbit);
    $current = (string)$pipe->codel_target;
    if ($ms > CODEL_TARGET_MS) {
        $new = (string)$ms;
    } elseif ($current !== '' && (int)$current > CODEL_TARGET_MS) {
        $new = '';
    } else {
        return '';
    }
    if ($new === $current) {
        return '';
    }
    $pipe->codel_target = $new;
    return $new === '' ? ', CoDel target back to default' : sprintf(', CoDel target %d ms', $ms);
}

function shaper_apply()
{
    $backend = new Backend();
    $backend->configdRun('template reload OPNsense/Shaper');
    $backend->configdRun('template reload OPNsense/IPFW');
    foreach (['shaper reload', 'ipfw reload'] as $cmd) {
        $ret = trim($backend->configdRun($cmd));
        if ($ret != 'OK') {
            return "$cmd: $ret";
        }
    }
    return null;
}

/**
 * validate and write the shaper model to the config (not applied yet)
 */
function save_shaper($shaper)
{
    $errors = [];
    foreach ($shaper->performValidation() as $msg) {
        $errors[] = $msg->getField() . ': ' . $msg->getMessage();
    }
    if (!empty($errors)) {
        return implode(', ', $errors);
    }
    $shaper->serializeToConfig();
    Config::getInstance()->save();
    return null;
}

/**
 * uuid of the item in $items with this description, null when there is none
 * (or more than one) or it does not match $filter
 */
/**
 * The rules exclude the interface address, so the firewall's own traffic and
 * with it the speedtest is not shaped. Keep that exclusion current: replace the
 * previously recorded address in the rules on the interface by the current one.
 */
function sync_exclusion($mdl, $interface)
{
    $current = get_interface_ip($interface);
    $stored = (string)$mdl->shaper->address;
    if (empty($current)) {
        return ['status' => 'unchanged', 'message' => "interface $interface has no IPv4 address, exclusion not checked"];
    }
    if ($stored == $current) {
        return ['status' => 'unchanged', 'message' => "excluded address $current is current"];
    }
    $updated = 0;
    if ($stored != '') {
        $shaper = new TrafficShaper();
        foreach ($shaper->rules->rule->iterateItems() as $rule) {
            if ((string)$rule->interface != $interface) {
                continue;
            }
            foreach (['destination' => 'in', 'source' => 'out'] as $field => $direction) {
                $not = $field . '_not';
                if (
                    in_array((string)$rule->direction, ['', $direction]) &&
                    (string)$rule->$not == '1' && (string)$rule->$field == $stored
                ) {
                    $rule->$field = $current;
                    $updated++;
                }
            }
        }
        if ($updated > 0) {
            $err = save_shaper($shaper) ?? shaper_apply();
            if ($err !== null) {
                return ['status' => 'error', 'message' => $err];
            }
        }
    }
    $mdl->shaper->address = $current;
    $mdl->serializeToConfig();
    Config::getInstance()->save();
    return [
        'status' => $updated > 0 ? 'updated' : 'unchanged',
        'message' => $stored == '' ? "excluded address recorded as $current" :
            sprintf('excluded address changed from %s to %s in %d rule(s)', $stored, $current, $updated),
    ];
}

function ordinal($n)
{
    $suffix = in_array($n % 100, [11, 12, 13]) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

function find_named($items, $description, $filter = null)
{
    $match = [];
    foreach ($items->iterateItems() as $uuid => $item) {
        if ((string)$item->description == $description && ($filter === null || $filter($item))) {
            $match[] = $uuid;
        }
    }
    return count($match) == 1 ? $match[0] : null;
}

/**
 * MSS clamping of the interface: the interface "MSS" setting is an MTU, the firewall
 * clamps TCP to it minus 40 (IPv4); needed when the MTU is below 1500 (PPPoE)
 */
function mss_status($interface)
{
    $link = wan_link($interface, '');
    $mss = (string)(Config::getInstance()->object()->interfaces->$interface->mss ?? '');
    $result = ['interface' => $interface, 'mtu' => $link['mtu'], 'mss' => $mss];
    if (is_numeric($mss) && (int)$mss <= $link['mtu']) {
        $result['status'] = 'on';
        $result['message'] = sprintf('on (TCP MSS %d, MTU %d)', (int)$mss - TCPIP_HEADERS, $link['mtu']);
    } elseif ($link['mtu'] >= 1500 && !is_numeric($mss)) {
        $result['status'] = 'unneeded';
        $result['message'] = sprintf('not needed (MTU %d)', $link['mtu']);
    } else {
        $result['status'] = 'missing';
        $result['message'] = is_numeric($mss) ?
            sprintf('too high: MSS setting %d is above the MTU %d', (int)$mss, $link['mtu']) :
            sprintf('missing (MTU %d)', $link['mtu']);
    }
    return $result;
}

$action = $argv[1] ?? '';
if (!in_array($action, ['set', 'create', 'rules', 'sync', 'mss', 'setmss'])) {
    echo json_encode(['status' => 'error', 'message' => 'usage: shaper.php set|create|sync|mss|setmss']) . PHP_EOL;
    exit(0);
}

openlog('speedtest', LOG_ODELAY, LOG_USER);
$lock = fopen(LOCK_FILE, 'a+');
if ($action != 'rules' && !flock($lock, LOCK_EX | LOCK_NB)) {
    finish(['status' => 'error', 'message' => 'another shaper update is running']);
}

$mdl = new Speedtest();
$settings = $mdl->shaper;
$interface = (string)$settings->interface;
if ($action == 'sync') {
    finish(sync_exclusion($mdl, $interface));
}
if ($action == 'mss') {
    echo json_encode(mss_status($interface)) . PHP_EOL;
    exit(0);
}
if ($action == 'setmss') {
    $status = mss_status($interface);
    if ($status['status'] != 'missing') {
        finish(['status' => 'unchanged', 'message' => 'MSS clamping ' . $status['message']]);
    }
    Config::getInstance()->object()->interfaces->$interface->mss = (string)$status['mtu'];
    Config::getInstance()->save();
    (new Backend())->configdRun('filter reload');
    finish(['status' => 'updated', 'message' => sprintf('MSS clamping set on %s: MSS setting %d (TCP MSS %d)',
        $interface, $status['mtu'], $status['mtu'] - TCPIP_HEADERS)]);
}
if ($action == 'set' && (string)$settings->enabled != '1') {
    finish(['status' => 'disabled', 'message' => 'setting the shaper bandwidth is disabled']);
}
// in case an address change was missed, the pipes are set after the exclusion is current
$sync = $action == 'set' ? sync_exclusion($mdl, $interface) : null;
if ($sync !== null && $sync['status'] == 'error') {
    finish($sync);
}

$days = (int)(string)$settings->days;
$percentile = (int)(string)$settings->percentile;
$thresholds = ['download' => (int)(string)$settings->threshold_down, 'upload' => (int)(string)$settings->threshold_up];
$link = wan_link($interface, (string)$settings->dsltype);
$avg = speedtest_estimate($days, $percentile);
if ($avg === null) {
    finish(['status' => 'error', 'message' => sprintf('no speedtest results of the last %d day(s)', $days)]);
}
$result = ['status' => 'unchanged', 'interface' => $interface, 'days' => $days, 'probes' => $avg['probes'],
    'percentile' => $percentile, 'thresholds' => $thresholds, 'link' => $link];
$kbit = [];
foreach (DIRECTIONS as $dir => $props) {
    $kbit[$dir] = $avg[$dir] * 1000 * $thresholds[$dir] / 100;
}
if ($link['dsl'] != '') {
    $kbit['upload'] = dsl_upload($kbit['upload'], pipe_target($kbit['download'])['kbit'], $link);
}
$targets = [];
foreach (DIRECTIONS as $dir => $props) {
    $targets[$dir] = pipe_target($kbit[$dir]);
    $result[$dir] = ['estimate_mbit' => round($avg[$dir], 2), 'target_mbit' => $targets[$dir]['kbit'] / 1000];
}
$basis = sprintf(' (%s of %d results of the last %d day(s), download x %d%%, upload x %d%%%s)',
    $percentile == 50 ? 'median' : ordinal($percentile) . ' percentile', $avg['probes'], $days,
    $thresholds['download'], $thresholds['upload'],
    $link['dsl'] != '' ? sprintf(', %s%s MTU %d', strtoupper($link['dsl']), $link['pppoe'] ? ' PPPoE' : '', $link['mtu']) : '');

$shaper = new TrafficShaper();
$found = discover_pipes($shaper, $interface);
$changed = false;

if ($action == 'set') {
    $minchange = (int)(string)$settings->minchange;
    $msgs = [];
    foreach (DIRECTIONS as $dir => $props) {
        if (count($found[$dir]) != 1) {
            finish(['status' => 'error', 'message' => sprintf(
                count($found[$dir]) == 0 ?
                    'no %s pipe found for interface %s, use "Create shaper"' :
                    'more than one %s pipe found for interface %s, nothing changed',
                $dir,
                $interface
            )] + $result);
        }
    }
    foreach (DIRECTIONS as $dir => $props) {
        $pipe = $shaper->getNodeByReference('pipes.pipe.' . $found[$dir][0]);
        $current = pipe_kbit($pipe);
        $result[$dir]['pipe'] = sprintf('%s (%s)', $pipe->description, $pipe->number);
        $result[$dir]['current_mbit'] = $current / 1000;
        $target = $targets[$dir]['kbit'];
        $diff = $current > 0 ? abs($target - $current) * 100 / $current : 100;
        // a pipe in bit/s or Kbit/s moves to Mbit/s once its target is in Mbit/s, also inside the
        // minimum change; never back, so the unit doesn't flip around 10 Mbit/s
        $to_mbit = $targets[$dir]['metric'] == 'Mbit' && in_array((string)$pipe->bandwidthMetric, ['bit', 'Kbit']);
        if (($target != $current && $diff >= $minchange) || $to_mbit) {
            $pipe->bandwidth = (string)$targets[$dir]['bandwidth'];
            $pipe->bandwidthMetric = $targets[$dir]['metric'];
            $result[$dir]['action'] = 'updated';
            $msgs[] = sprintf('%s %s: %.2f to %.2f Mbit/s', $dir, $pipe->description, $current / 1000, $target / 1000) .
                codel_target($pipe, $target, $link);
            $changed = true;
        } else {
            $result[$dir]['action'] = 'unchanged';
            $msgs[] = sprintf('%s %s: %.2f Mbit/s kept (target %.2f, change %.1f%%, minimum %d%%)', $dir, $pipe->description, $current / 1000, $target / 1000, $diff, $minchange);
        }
    }
    $result['message'] = implode('; ', $msgs) . $basis;
    if ($sync['status'] == 'updated') {
        $result['message'] .= '; ' . $sync['message'];
    }
    if ($changed) {
        $err = save_shaper($shaper) ?? shaper_apply();
        if ($err !== null) {
            finish(['status' => 'error', 'message' => $err] + $result);
        }
        $result['status'] = 'updated';
    }
} else {
    // only directions without rules on the interface; a pipe or queue left
    // by an earlier, unfinished run is reused by name
    $missing = array_keys(array_filter(discover_pipes($shaper, $interface, false), function ($pipes) {
        return empty($pipes);
    }));
    if (empty($missing)) {
        finish(['status' => 'exists', 'message' => "shaper rules already exist for interface $interface"] + $result);
    }
    $address = get_interface_ip($interface);
    if (empty($address)) {
        // the rules exclude the firewall's own address, so the speedtest itself is not shaped
        finish(['status' => 'error', 'message' => "interface $interface has no IPv4 address, nothing created"] + $result);
    }

    if ($action == 'create') {
        // pipes and queues, then the rules in a fresh process (see "rules" above)
        $msgs = [];
        foreach ($missing as $dir) {
            $props = DIRECTIONS[$dir];
            $pipe_uuid = find_named($shaper->pipes->pipe, $props['pipe']);
            if ($pipe_uuid === null) {
                $pipe = $shaper->pipes->pipe->Add();
                $pipe->number = $shaper->newPipeNumber();
                $pipe->bandwidth = (string)$targets[$dir]['bandwidth'];
                $pipe->bandwidthMetric = $targets[$dir]['metric'];
                $pipe->scheduler = 'fq_codel';
                $pipe->codel_ecn_enable = '1';
                $pipe->description = $props['pipe'];
                $pipe->origin = 'TrafficShaper';
                $pipe_uuid = $pipe->getAttribute('uuid');
                $msgs[] = sprintf('pipe %s (%.2f Mbit/s%s)', $props['pipe'], $targets[$dir]['kbit'] / 1000,
                    codel_target($pipe, $targets[$dir]['kbit'], $link));
            }
            $queue_uuid = find_named($shaper->queues->queue, $props['queue'], function ($queue) use ($pipe_uuid) {
                return (string)$queue->pipe == $pipe_uuid;
            });
            if ($queue_uuid === null) {
                $queue = $shaper->queues->queue->Add();
                $queue->number = $shaper->newQueueNumber();
                $queue->pipe = $pipe_uuid;
                $queue->weight = '100';
                $queue->description = $props['queue'];
                $queue->origin = 'TrafficShaper';
                $msgs[] = 'queue ' . $props['queue'];
            }
        }
        if (!empty($msgs)) {
            $err = save_shaper($shaper);
            if ($err !== null) {
                finish(['status' => 'error', 'message' => $err] + $result);
            }
        }
        $rules = json_decode(shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' rules'), true) ??
            ['status' => 'error', 'message' => 'adding the rules failed'];
        $msgs = array_merge($msgs, $rules['created'] ?? []);
        $result['status'] = $rules['status'];
        $result['message'] = $rules['status'] == 'created' ?
            'created ' . implode(', ', $msgs) . " on $interface, excluding $address" :
            ($rules['message'] ?? $rules['status']) . (empty($msgs) ? '' : ' (created: ' . implode(', ', $msgs) . ')');
        foreach ($missing as $dir) {
            $result[$dir]['action'] = $rules['status'] == 'created' ? 'created' : 'incomplete';
            $result[$dir]['pipe'] = DIRECTIONS[$dir]['pipe'];
        }
    } else {
        $created = [];
        $sequence = (int)$shaper->getMaxRuleSequence();
        foreach ($missing as $dir) {
            $props = DIRECTIONS[$dir];
            $pipe_uuid = find_named($shaper->pipes->pipe, $props['pipe']);
            $queue_uuid = $pipe_uuid === null ? null :
                find_named($shaper->queues->queue, $props['queue'], function ($queue) use ($pipe_uuid) {
                    return (string)$queue->pipe == $pipe_uuid;
                });
            if ($queue_uuid === null) {
                finish(['status' => 'error', 'message' => "queue {$props['queue']} not found, no rules added"]);
            }
            $rule = $shaper->rules->rule->Add();
            $rule->sequence = (string)(++$sequence);
            $rule->interface = $interface;
            $rule->proto = 'ip';
            $rule->direction = $props['rule'];
            if ($props['rule'] == 'in') {
                $rule->destination = $address;
                $rule->destination_not = '1';
            } else {
                $rule->source = $address;
                $rule->source_not = '1';
            }
            $rule->target = $queue_uuid;
            $rule->description = $props['rulename'];
            $rule->origin = 'TrafficShaper';
            $created[] = sprintf('rule "%s"', $props['rulename']);
        }
        $err = save_shaper($shaper) ?? shaper_apply();
        if ($err !== null) {
            finish(['status' => 'error', 'message' => $err]);
        }
        // remember the excluded address, so sync can follow a change (saved here, as the
        // config of the create process predates these rules)
        $mdl->shaper->address = $address;
        $mdl->serializeToConfig();
        Config::getInstance()->save();
        $result = ['status' => 'created', 'message' => 'created ' . implode(', ', $created), 'created' => $created];
    }
}

finish($result);
