<?php
/* 
 * mdspy - A web interface to show mdadm status
 * Copyright (c) 2026, Dragos GALALAE (gala_dragos@yahoo.com)
 * Licensed under the MIT License.
 */

function getRaidCapacity($md)
{
    $path = "/sys/block/$md/size";

    if (!file_exists($path)) {
        return 'Unknown';
    }

    $sectors = (int)trim(@file_get_contents($path));

    if ($sectors <= 0) {
        return 'Unknown';
    }

    $bytes = $sectors * 512;

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return sprintf('%.2f %s', $bytes, $units[$i]);
}

function parseMdstat()
{
    $mdstat = @file_get_contents('/proc/mdstat');

    if ($mdstat === false) {
        return [];
    }

    $lines = explode("\n", $mdstat);
    $arrays = [];

    for ($i = 0; $i < count($lines); $i++) {

        $line = trim($lines[$i]);

        if (!preg_match('/^(md\d+)\s*:\s*(.+)$/', $line, $m)) {
            continue;
        }

        $arrayName = $m[1];
        $details = $m[2];

        $type = 'unknown';

        if (preg_match('/(raid\d+)/i', $details, $typeMatch)) {
            $type = strtolower($typeMatch[1]);
        }

        preg_match_all('/([a-zA-Z0-9_\-\/]+)\[\d+\]/', $details, $devMatch);

        $devices = array_unique($devMatch[1]);

        $activeDevices = count($devices);
        $totalDevices = $activeDevices;

        $health = 'OK';
        $healthClass = 'green';
        $percent = null;

        $block = '';

        $j = $i + 1;

        while (
            $j < count($lines)
            && trim($lines[$j]) !== ''
            && !preg_match('/^md\d+\s*:/', trim($lines[$j]))
        ) {
            $block .= ' ' . trim($lines[$j]);
            $j++;
        }

        if (preg_match('/\[(\d+)\/(\d+)\]/', $block, $cnt)) {
            $totalDevices = (int)$cnt[1];
            $activeDevices = (int)$cnt[2];
        }

        if (
            preg_match('/recovery\s*=\s*([\d\.]+)%/i', $block, $rec) ||
            preg_match('/resync\s*=\s*([\d\.]+)%/i', $block, $rec) ||
            preg_match('/reshape\s*=\s*([\d\.]+)%/i', $block, $rec)
        ) {
            $health = 'REBUILDING';
            $healthClass = 'yellow';
            $percent = $rec[1];
        } elseif ($activeDevices < $totalDevices) {
            $health = 'DEGRADED';
            $healthClass = 'red';
        }

        $arrays[] = [
            'name' => $arrayName,
            'type' => $type,
            'capacity' => getRaidCapacity($arrayName),
            'active' => $activeDevices,
            'total' => $totalDevices,
            'devices' => $devices,
            'health' => $health,
            'healthClass' => $healthClass,
            'percent' => $percent
        ];

        $i = $j - 1;
    }

    return $arrays;
}

$arrays = parseMdstat();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="refresh" content="30">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Linux RAID Status</title>

<style>

body {
    margin: 20px;
    font-family: Arial, Helvetica, sans-serif;
    background: #f5f6f8;
    color: #333;
}

h1 {
    margin-top: 0;
    margin-bottom: 20px;
}

.info {
    margin-bottom: 15px;
    color: #666;
    font-size: 13px;
}

.refresh-control {
    margin-bottom: 15px;
    padding: 10px;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
}

.refresh-control label {
    font-weight: bold;
    margin-right: 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
}

thead {
    background: #2f4050;
    color: #fff;
}

th,
td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e5e5e5;
}

tbody tr:hover {
    background: #fafafa;
}

.status {
    font-size: 22px;
    font-weight: bold;
    cursor: help;
}

.green {
    color: #28a745;
}

.red {
    color: #dc3545;
}

.yellow {
    color: #ffc107;
}

.devices {
    cursor: help;
}

.capacity {
    white-space: nowrap;
}

.footer {
    margin-top: 15px;
    color: #888;
    font-size: 12px;
}

</style>
</head>
<body>

<h1>Linux RAID Status</h1>

<div class="refresh-control">
    <label for="refreshDropdown">Auto refresh:</label>
    <select id="refreshDropdown">
        <option value="0">Off</option>
        <option value="5">5 seconds</option>
        <option value="10">10 seconds</option>
        <option value="15">15 seconds</option>
        <option value="30" selected>30 seconds</option>
        <option value="45">45 seconds</option>
        <option value="60">60 seconds</option>
        <option value="90">90 seconds</option>
        <option value="120">120 seconds</option>
    </select>
</div>

<script>
document.getElementById('refreshDropdown').addEventListener('change', function() {
    var interval = this.value;
    var metaRefresh = document.querySelector('meta[http-equiv="refresh"]');
    if (metaRefresh) {
        metaRefresh.setAttribute('content', interval);
    }
});
</script>

<table>
    <thead>
        <tr>
            <th>Array</th>
            <th>Type</th>
            <th>Capacity</th>
            <th>Devices</th>
            <th>Health</th>
        </tr>
    </thead>
    <tbody>

<?php if (empty($arrays)): ?>

<tr>
    <td colspan="5">
        No RAID arrays detected.
    </td>
</tr>

<?php else: ?>

<?php foreach ($arrays as $array): ?>

<?php

$title = $array['health'];

if (
    $array['health'] === 'REBUILDING'
    && $array['percent'] !== null
) {
    $title .= ' (' . $array['percent'] . '%)';
}

$icon = match ($array['health']) {
    'OK' => '✔',
    'DEGRADED' => '✖',
    'REBUILDING' => '🔄',
    default => '?'
};

?>

<tr>

    <td>
        <?= htmlspecialchars($array['name']) ?>
    </td>

    <td>
        <?= htmlspecialchars($array['type']) ?>
    </td>

    <td class="capacity">
        <?= htmlspecialchars($array['capacity']) ?>
    </td>

    <td>

        <span
            class="devices"
            title="<?= htmlspecialchars(implode(', ', $array['devices'])) ?>"
        >
            <?= $array['active'] ?>/<?= $array['total'] ?>
        </span>

    </td>

    <td>

        <span
            class="status <?= $array['healthClass'] ?>"
            title="<?= htmlspecialchars($title) ?>"
        >
            <?= $icon ?>
        </span>

    </td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

    </tbody>
</table>

<div class="footer">
    Client IP: <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?>
</div>

</body>
</html>