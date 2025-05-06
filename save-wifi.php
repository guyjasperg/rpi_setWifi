<?php
// save-wifi.php - Script to save WiFi credentials to wpa_supplicant.conf and optionally reboot
header('Content-Type: application/json');
// Add CORS headers to allow access from different origins
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method is allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!isset($data['ssid']) || empty($data['ssid'])) {
    echo json_encode(['success' => false, 'message' => 'SSID is required']);
    exit;
}

// Get values from input
$ssid = $data['ssid'];
$password = isset($data['password']) ? $data['password'] : '';
$hidden = isset($data['hidden']) && $data['hidden'] ? 1 : 0;
$reboot = isset($data['reboot']) && $data['reboot'];

// Create wpa_supplicant configuration
function generateWpaConfig($ssid, $password, $hidden) {
    $config = "ctrl_interface=DIR=/var/run/wpa_supplicant GROUP=netdev\n";
    $config .= "update_config=1\n";
    $config .= "country=US\n\n"; // Change country code if needed
    
    $config .= "network={\n";
    $config .= "\tssid=\"" . addslashes($ssid) . "\"\n";
    
    if ($hidden) {
        $config .= "\tscan_ssid=1\n";
    }
    
    if (!empty($password)) {
        // Use WPA-PSK for networks with passwords
        $config .= "\tpsk=\"" . addslashes($password) . "\"\n";
        $config .= "\tkey_mgmt=WPA-PSK\n";
    } else {
        // For open networks
        $config .= "\tkey_mgmt=NONE\n";
    }
    
    $config .= "}\n";
    
    return $config;
}

// Save configuration to file
try {
    $config = generateWpaConfig($ssid, $password, $hidden);
    
    // Save to a temporary file first
    $tmp_file = tempnam('/tmp', 'wpa_');
    file_put_contents($tmp_file, $config);
    
    // Move to the actual location (requires sudo)
    exec("sudo cp $tmp_file /etc/wpa_supplicant/wpa_supplicant.conf", $output, $return_var);
    unlink($tmp_file); // Remove temporary file
    
    if ($return_var !== 0) {
        echo json_encode(['success' => false, 'message' => 'Failed to save configuration']);
        exit;
    }
    
    // If reboot is requested, schedule a reboot
    if ($reboot) {
        // Use nohup to ensure the reboot happens even if the HTTP connection is closed
        exec('nohup sudo /bin/sh -c "sleep 5 && reboot" > /dev/null 2>&1 &');
    } else {
        // Otherwise just restart the wireless interface
        exec('sudo wpa_cli -i wlan0 reconfigure', $output, $return_var);
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>