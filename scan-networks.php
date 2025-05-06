<?php
// scan-networks.php - Script to scan for available WiFi networks on Raspberry Pi
header('Content-Type: application/json');
// Add CORS headers to allow access from different origins
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Function to scan for WiFi networks
function scanWifiNetworks() {
    $networks = array();
    
    // Use iwlist to scan for available networks
    exec('sudo iwlist wlan0 scan 2>&1', $output, $return_var);
    
    if ($return_var !== 0) {
        // Try alternative interface names if wlan0 fails
        exec('sudo iwlist wlan1 scan 2>&1', $output, $return_var);
        
        if ($return_var !== 0) {
            // If both fail, return empty array with error message
            error_log("WiFi scan failed with error code: $return_var");
            error_log("Output: " . implode("\n", $output));
            return $networks;
        }
    }
    
    // Process the output to extract network information
    $current_network = null;
    
    foreach ($output as $line) {
        $line = trim($line);
        
        // Start of a new cell indicates a new network
        if (strpos($line, 'Cell') === 0) {
            // Save previous network if we have one
            if ($current_network && isset($current_network['ssid']) && $current_network['ssid'] !== '') {
                $networks[] = $current_network;
            }
            
            // Start a new network
            $current_network = array('ssid' => '', 'signal' => '');
        }
        
        // Extract SSID
        if (preg_match('/ESSID:"(.+)"/', $line, $matches)) {
            if ($current_network) {
                $current_network['ssid'] = $matches[1];
            }
        }
        
        // Extract signal level
        if (preg_match('/Signal level=(.+) dBm/', $line, $matches)) {
            if ($current_network) {
                $current_network['signal'] = $matches[1];
            }
        }
    }
    
    // Don't forget to add the last network
    if ($current_network && isset($current_network['ssid']) && $current_network['ssid'] !== '') {
        $networks[] = $current_network;
    }
    
    // Sort networks by signal strength (strongest first)
    usort($networks, function ($a, $b) {
        return intval($b['signal']) <=> intval($a['signal']);
    });
    
    return $networks;
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