<?php
// scan-networks.php - Script to scan for available WiFi networks
header('Content-Type: application/json');
// Add CORS headers to allow access from different origins
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * Scan for WiFi networks using NetworkManager
 * 
 * @return array Array of networks with SSID and signal strength
 */
function scanNetworks() {
    $networks = [];
    
    // Check if NetworkManager is installed
    exec("which nmcli", $which_output, $which_return);
    if ($which_return !== 0) {
        // Fallback to iwlist if nmcli is not available
        return scanNetworksWithIwlist();
    }
    
    // Use NetworkManager to scan for networks
    exec("sudo nmcli -t -f SSID,SIGNAL device wifi list", $output, $return_var);
    
    if ($return_var !== 0) {
        return scanNetworksWithIwlist(); // Fallback to iwlist
    }
    
    foreach ($output as $line) {
        // Skip empty lines
        if (empty(trim($line))) continue;
        
        // Parse the output (format: SSID:SIGNAL)
        $parts = explode(':', $line, 2);
        if (count($parts) == 2) {
            $ssid = trim($parts[0]);
            $signal = trim($parts[1]);
            
            // Skip empty SSIDs or duplicates
            if (!empty($ssid) && !isNetworkInList($networks, $ssid)) {
                $networks[] = [
                    'ssid' => $ssid,
                    'signal' => $signal
                ];
            }
        }
    }
    
    // Sort networks by signal strength (highest first)
    usort($networks, function($a, $b) {
        return intval($b['signal']) - intval($a['signal']);
    });
    
    return $networks;
}

/**
 * Fallback function to scan with iwlist
 * 
 * @return array Array of networks with SSID and signal strength
 */
function scanNetworksWithIwlist() {
    $networks = [];
    
    // Use iwlist to scan for networks
    exec("sudo iwlist wlan0 scan | grep -E 'ESSID|Quality'", $output, $return_var);
    
    if ($return_var !== 0) {
        return []; // Return empty array if scan fails
    }
    
    $current_ssid = null;
    $current_signal = null;
    
    foreach ($output as $line) {
        if (strpos($line, 'ESSID:') !== false) {
            // If we have data from previous network, add it
            if ($current_ssid !== null && !isNetworkInList($networks, $current_ssid)) {
                $networks[] = [
                    'ssid' => $current_ssid,
                    'signal' => $current_signal ?: 'Unknown'
                ];
            }
            
            // Extract SSID (removing quotes)
            preg_match('/ESSID:"([^"]*)"/', $line, $matches);
            $current_ssid = isset($matches[1]) ? $matches[1] : '';
            $current_signal = null;
        } elseif (strpos($line, 'Quality=') !== false) {
            // Extract signal quality
            preg_match('/Quality=(\d+)\/(\d+)/', $line, $quality_matches);
            if (isset($quality_matches[1]) && isset($quality_matches[2])) {
                // Convert to percentage
                $current_signal = round(($quality_matches[1] / $quality_matches[2]) * 100);
            } else {
                // Try to extract signal level in dBm
                preg_match('/Signal level=(-\d+) dBm/', $line, $level_matches);
                if (isset($level_matches[1])) {
                    $current_signal = $level_matches[1]; // dBm value
                }
            }
        }
    }
    
    // Add the last network if any
    if ($current_ssid !== null && !isNetworkInList($networks, $current_ssid)) {
        $networks[] = [
            'ssid' => $current_ssid,
            'signal' => $current_signal ?: 'Unknown'
        ];
    }
    
    // Sort networks by signal strength (highest first)
    usort($networks, function($a, $b) {
        $signal_a = is_numeric($a['signal']) ? intval($a['signal']) : -100;
        $signal_b = is_numeric($b['signal']) ? intval($b['signal']) : -100;
        return $signal_b - $signal_a;
    });
    
    return $networks;
}

/**
 * Check if a network is already in the list
 * 
 * @param array $networks List of networks
 * @param string $ssid SSID to check
 * @return bool True if network exists in list
 */
function isNetworkInList($networks, $ssid) {
    foreach ($networks as $network) {
        if ($network['ssid'] === $ssid) {
            return true;
        }
    }
    return false;
}

// Scan networks and return as JSON
$networks = scanNetworks();
echo json_encode($networks);
?>