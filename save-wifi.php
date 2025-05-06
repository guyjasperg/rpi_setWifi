<?php
// save-wifi.php - Script to save WiFi credentials to NetworkManager
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
$hidden = isset($data['hidden']) && $data['hidden'] ? 'yes' : 'no';
$reboot = isset($data['reboot']) && $data['reboot'];

// Create NetworkManager connection
try {
    $uuid = uniqid("wifi-");
    $connection_name = "WiFi-" . preg_replace('/[^a-zA-Z0-9]/', '-', $ssid);
    
    // Build nmcli command
    if (!empty($password)) {
        // For secured networks
        $cmd = "sudo nmcli connection add type wifi ifname wlan0 con-name '$connection_name' autoconnect yes ssid '$ssid' wifi-sec.key-mgmt wpa-psk wifi-sec.psk '$password'";
    } else {
        // For open networks
        $cmd = "sudo nmcli connection add type wifi ifname wlan0 con-name '$connection_name' autoconnect yes ssid '$ssid'";
    }
    
    // Add hidden network setting if needed
    if ($hidden === 'yes') {
        $cmd .= " wifi.hidden $hidden";
    }
    
    // Delete existing connection with same name to avoid duplicates
    exec("sudo nmcli connection delete '$connection_name' 2>/dev/null");
    
    // Execute command to create connection
    exec($cmd, $output, $return_var);
    
    if ($return_var !== 0) {
        echo json_encode(['success' => false, 'message' => 'Failed to create NetworkManager connection']);
        exit;
    }
    
    // Try to connect to the new network immediately
    exec("sudo nmcli connection up '$connection_name'", $connect_output, $connect_return);
    
    // If reboot is requested, schedule a reboot
    if ($reboot) {
        // Use nohup to ensure the reboot happens even if the HTTP connection is closed
        exec('nohup sudo /bin/sh -c "sleep 5 && reboot" > /dev/null 2>&1 &');
    }
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>