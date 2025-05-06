<?php
// scan-networks.php - Script to scan for available WiFi networks on Raspberry Pi
header('Content-Type: application/json');

// Function to scan for WiFi networks
function scanWifiNetworks() {
    $networks = array();
    
    // Use iwlist to scan for available networks
    exec('sudo iwlist wlan0 scan', $output, $return_var);
    
    if ($return_var !== 0) {
        // Try alternative interface names if wlan0 fails
        exec('sudo iwlist wlan1 scan', $output, $return_var);
        
        if ($return_var !== 0) {
            // If both fail, return empty array
            return $networks;
        }
    }
    
    // Process the output to extract network information
    $ssid = '';
    $signal = '';
    
    foreach ($output as $line) {
        $line = trim($line);
        
        // Extract SSID
        if (preg_match('/ESSID:"(.+)"/', $line, $matches)) {
            $ssid = $matches[1];
            
            // If we have both SSID and signal, add to networks
            if ($ssid !== '' && $signal !== '') {
                $networks[] = array(
                    'ssid' => $ssid,
                    'signal' => $signal
                );
                $signal = '';
            }
        }
        
        // Extract signal level
        if (preg_match('/Signal level=(.+) dBm/', $line, $matches)) {
            $signal = $matches[1];
        }
    }
    
    // Sort networks by signal strength (strongest first)
    usort($networks, function ($a, $b) {
        return $b['signal'] <=> $a['signal'];
    });
    
    return $networks;
}

// Get the networks
$networks = scanWifiNetworks();

// Output as JSON
echo json_encode($networks);
?>