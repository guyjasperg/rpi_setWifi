<?php
// save-wifi.php - Script to save WiFi credentials to NetworkManager and store passwords
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
$saveCredentials = isset($data['saveCredentials']) ? $data['saveCredentials'] : true;

// Define the file to store WiFi passwords
$passwordFile = '/etc/rpi-wifi-passwords.json';

// Create NetworkManager connection
try {
    // Check if NetworkManager is installed and running
    exec("which nmcli", $which_output, $which_return);
    if ($which_return !== 0) {
        echo json_encode(['success' => false, 'message' => 'NetworkManager (nmcli) not found']);
        exit;
    }
    
    // Properly escape values for shell commands
    $ssid_escaped = escapeshellarg($ssid);
    $password_escaped = escapeshellarg($password);
    $connection_name = "WiFi-" . preg_replace('/[^a-zA-Z0-9]/', '-', $ssid);
    $connection_name_escaped = escapeshellarg($connection_name);
    
    // Delete existing connection with same name to avoid duplicates
    exec("sudo nmcli connection delete $connection_name_escaped 2>/dev/null");
    
    // Build the nmcli command
    if (!empty($password)) {
        // For secured networks
        $cmd = "sudo nmcli connection add type wifi ifname wlan0 con-name $connection_name_escaped autoconnect yes ssid $ssid_escaped";
        $cmd .= " wifi-sec.key-mgmt wpa-psk wifi-sec.psk $password_escaped";
    } else {
        // For open networks
        $cmd = "sudo nmcli connection add type wifi ifname wlan0 con-name $connection_name_escaped autoconnect yes ssid $ssid_escaped";
    }
    
    // Add hidden network setting if needed
    if ($hidden === 'yes') {
        $cmd .= " wifi.hidden yes";
    }
    
    // Execute command with full output capture
    exec($cmd . " 2>&1", $output, $return_var);
    $output_text = implode("\n", $output);
    
    if ($return_var !== 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to create NetworkManager connection: ' . $output_text
        ]);
        exit;
    }
    
    // Save the password if requested
    if ($saveCredentials && !empty($password)) {
        saveWifiPassword($ssid, $password, $passwordFile);
    }
    
    // Do not connect immediately - changes will be applied after reboot
    $connect_output_text = "WiFi configuration saved. Changes will be applied after reboot.";
    $connect_return = 0;
    
    // If reboot is requested, schedule a reboot
    if ($reboot) {
        // Use nohup to ensure the reboot happens even if the HTTP connection is closed
        exec('nohup sudo /bin/sh -c "sleep 5 && reboot" > /dev/null 2>&1 &');
    }
    
    echo json_encode([
        'success' => true,
        'details' => [
            'connection_name' => $connection_name,
            'connection_result' => $connect_output_text,
            'password_saved' => $saveCredentials && !empty($password),
            'message' => $reboot ? 
                'WiFi configuration saved. Your Raspberry Pi will reboot to apply changes.' : 
                'WiFi configuration saved. Changes will be applied after next reboot.'
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Save WiFi password to the password storage file
 * 
 * @param string $ssid The SSID of the network
 * @param string $password The password to save
 * @param string $file The file path to store passwords
 * @return bool Success status
 */
function saveWifiPassword($ssid, $password, $file) {
    // Read existing passwords
    $passwords = [];
    if (file_exists($file)) {
        $jsonContent = file_get_contents($file);
        if ($jsonContent) {
            $passwords = json_decode($jsonContent, true) ?: [];
        }
    }
    
    // Update password for this SSID
    $passwords[$ssid] = $password;
    
    // Create directory if it doesn't exist
    $directory = dirname($file);
    if (!is_dir($directory)) {
        // If file is directly in /etc, we don't need to create the directory
        if ($directory !== '/etc') {
            mkdir($directory, 0755, true);
        }
    }
    
    // Write back to file
    $success = file_put_contents($file, json_encode($passwords, JSON_PRETTY_PRINT));
    
    // Set proper permissions for security
    if ($success) {
        chmod($file, 0600); // Only readable/writable by owner
        exec("sudo chown root:root " . escapeshellarg($file));
    }
    
    return $success !== false;
}
?>