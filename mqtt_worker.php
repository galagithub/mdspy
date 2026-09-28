<?php
// MQTT background worker: publishes mdstat status at regular intervals
// Configuration via environment variables
$mqttEnabled   = getenv('MQTT_ENABLED') !== false && strtolower(getenv('MQTT_ENABLED')) === 'true';
$mqttHost      = getenv('MQTT_HOST') ?: 'mqtt';
$mqttPort      = (int)(getenv('MQTT_PORT') ?: 1883);
$mqttTopic     = getenv('MQTT_TOPIC') ?: 'mdspy/status';
$mqttUsername  = getenv('MQTT_USERNAME') ?: '';
$mqttPassword  = getenv('MQTT_PASSWORD') ?: '';
$refreshSec    = (int)(getenv('MQTT_REFRESH') ?: 30); // default 30 seconds

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

    $lines   = explode("\n", $mdstat);
    $arrays  = [];

    for ($i = 0; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (!preg_match('/^(md\d+)\s*:\s*(.+)$/', $line, $m)) {
            continue;
        }
        $arrayName = $m[1];
        $details   = $m[2];
        $type      = 'unknown';
        if (preg_match('/(raid\d+)/i', $details, $typeMatch)) {
            $type = strtolower($typeMatch[1]);
        }
        preg_match_all('/([a-zA-Z0-9_\-\/]+)\[\d+\]/', $details, $devMatch);
        $devices = array_unique($devMatch[1]);

        $activeDevices = count($devices);
        $totalDevices  = $activeDevices;
        $health        = 'OK';
        $healthClass   = 'green';
        $percent       = null;
        $block         = '';

        $j = $i + 1;
        while ($j < count($lines) && trim($lines[$j]) !== '' && !preg_match('/^md\d+\s*:/', trim($lines[$j]))) {
            $block .= ' ' . trim($lines[$j]);
            $j++;
        }

        if (preg_match('/\[(\d+)\/(\d+)\]/', $block, $cnt)) {
            $totalDevices  = (int)$cnt[1];
            $activeDevices = (int)$cnt[2];
        }

        if (
            preg_match('/recovery\s*=\s*([\d\.]+)%/i', $block, $rec) ||
            preg_match('/resync\s*=\s*([\d\.]+)%/i', $block, $rec) ||
            preg_match('/reshape\s*=\s*([\d\.]+)%/i', $block, $rec)
        ) {
            $health      = 'REBUILDING';
            $healthClass = 'yellow';
            $percent     = $rec[1];
        } elseif ($activeDevices < $totalDevices) {
            $health      = 'DEGRADED';
            $healthClass = 'red';
        }

        $arrays[] = [
            'name'         => $arrayName,
            'type'         => $type,
            'capacity'     => getRaidCapacity($arrayName),
            'active'       => $activeDevices,
            'total'        => $totalDevices,
            'devices'      => $devices,
            'health'       => $health,
            'healthClass'  => $healthClass,
            'percent'      => $percent,
        ];

        $i = $j - 1;
    }

    return $arrays;
}

// Main loop
while (true) {
    if ($mqttEnabled && class_exists('Mosquitto\Client')) {
        $arrays = parseMdstat();
        try {
            $mqtt = new Mosquitto\Client();
            $mqtt->connect($mqttHost, $mqttPort, 60);
            if (!empty($mqttUsername) && !empty($mqttPassword)) {
                $mqtt->username_pw_set($mqttUsername, $mqttPassword);
            }
            $payload = json_encode($arrays);
            $mqtt->publish($mqttTopic, $payload);
            $mqtt->disconnect();
        } catch (Exception $e) {
            // Silently ignore errors – we do not want the worker to stop
        }
    }
    sleep($refreshSec);
}
?>