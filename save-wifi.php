<?php
// save-wifi.php - Script to save WiFi credentials to NetworkManager and store passwords
header('Content-Type: application/json');
// Add CORS headers to allow access from different origins
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Define log file
define('LOG_FILE', '/var/log/wifi-setup.log');

// Logging function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    // Use sudo to ensure we can write to the log file
    exec("echo " . escapeshellarg($logEntry) . " | sudo tee -a " . escapeshellarg(LOG_FILE) . " > /dev/null 2>&1");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    logMessage("Received OPTIONS request");
    exit(0);
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Only POST method is allowed';
    logMessage("Error: $error");
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!isset($data['ssid']) || empty($data['ssid'])) {
    $error = 'SSID is required';
    logMessage("Error: $error");
    echo json_encode(['success' => false, 'message' => $error]);
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

logMessage("Processing WiFi config for SSID: $ssid, Hidden: $hidden, Reboot: " . ($reboot ? 'yes' : 'no') . ", SaveCredentials: " . ($saveCredentials ? 'yes' : 'no'));

// Create NetworkManager connection
try {
    // Check if NetworkManager is installed and running
    exec("which nmcli", $which_output, $which_return);
    if ($which_return !== 0) {
        $error = 'NetworkManager (nmcli) not found';
        logMessage("Error: $error");
        echo json_encode(['success' => false, 'message' => $error]);
        exit;
    }
    
    // Log current connections before modifications
    exec("sudo nmcli -t -f NAME,TYPE,AUTOCONNECT,AUTOCONNECT-PRIORITY connection show", $initial_connections, $initial_return);
    logMessage("Initial connections:\n" . implode("\n", $initial_connections));
    
    // Properly escape values for shell commands
    $ssid_escaped = escapeshellarg($ssid);
    $password_escaped = escapeshellarg($password);
    $connection_name = "WiFi-" . preg_replace('/[^a-zA-Z0-9]/', '-', $ssid);
    $connection_name_escaped = escapeshellarg($connection_name);
    
    // Delete existing connection with same name to avoid duplicates
    exec("sudo nmcli connection delete $connection_name_escaped 2>&1", $delete_output, $delete_return);
    logMessage("Delete existing connection '$connection_name': " . implode("\n", $delete_output));
    
    // Disable autoconnect for all other WiFi connections
    exec("sudo nmcli -t -f NAME,TYPE connection show", $all_connections, $conn_return);
    foreach ($all_connections as $conn) {
        $parts = explode(':', $conn);
        $conn_name = $parts[0];
        $conn_type = $parts[1] ?? '';
        if ($conn_name !== $connection_name && ($conn_type === '802-11-wireless' || strpos($conn_name, 'WiFi-') === 0 || $conn_name === 'preconfigured')) {
            $conn_name_escaped = escapeshellarg($conn_name);
            exec("sudo nmcli connection modify $conn_name_escaped connection.autoconnect no 2>&1", $modify_output, $modify_return);
            logMessage("Disabled autoconnect for '$conn_name' (type: $conn_type): " . implode("\n", $modify_output) . ", Return code: $modify_return");
        }
    }
    
    // Build the nmcli command
    if (!empty($password)) {
        // For secured networks
        $cmd = "sudo nmcli connection add type wifi ifname wlan0 con-name $connection_name_escaped autoconnect yes ssid $ssid_escaped";
        $cmd .= " wifi-sec.key-mgmt wpa-psk wifi-sec.psk $password_escaped";
        $cmd .= " connection.autoconnect-priority 100"; // High priority
    } else {
        // For open networks
        $cmd = "sudo nmcli connection add type wifi ifname wlan0 con-name $connection_name_escaped autoconnect yes ssid $ssid_escaped";
        $cmd .= " connection.autoconnect-priority 100"; // High priority
    }
    
    // Add hidden network setting if needed
    if ($hidden === 'yes') {
        $cmd .= " wifi.hidden yes";
    }
    
    // Execute command with full output capture
    exec($cmd . " 2>&1", $output, $return_var);
    $output_text = implode("\n", $output);
    logMessage("Executing nmcli command: $cmd");
    logMessage("nmcli output: $output_text, Return code: $return_var");
    
    if ($return_var !== 0) {
        $error = 'Failed to create NetworkManager connection: ' . $output_text;
        logMessage("Error: $error");
        echo json_encode([
            'success' => false, 
            'message' => $error
        ]);
        exit;
    }
    
    // Verify the connection was added
    exec("sudo nmcli connection show $connection_name_escaped 2>&1", $verify_output, $verify_return);
    logMessage("Verify connection '$connection_name': " . implode("\n", $verify_output));
    if ($verify_return !== 0) {
        $error = 'Failed to verify NetworkManager connection: ' . implode("\n", $verify_output);
        logMessage("Error: $error");
        echo json_encode([
            'success' => false, 
            'message' => $error
        ]);
        exit;
    }
    
    // Attempt to activate the connection
    $connect_output_text = "";
    exec("sudo nmcli connection up $connection_name_escaped 2>&1", $up_output, $up_return);
    $connect_output_text = implode("\n", $up_output);
    logMessage("Activating connection '$connection_name': $connect_output_text, Return code: $up_return");
    if ($up_return !== 0) {
        $connect_output_text = "Connection will be attempted on reboot due to activation failure: $connect_output_text";
        logMessage("Warning: Failed to activate connection immediately");
    }
    
    // Log final connection state
    exec("sudo nmcli -t -f NAME,TYPE,AUTOCONNECT,AUTOCONNECT-PRIORITY connection show", $final_connections, $final_return);
    logMessage("Final connections:\n" . implode("\n", $final_connections));
    exec("sudo nmcli -t -f DEVICE,STATE,CON-NAME device status 2>&1", $status_output, $status_return);
    logMessage("Final device status: " . implode("\n", $status_output));
    
    // Save the password if requested
    $password_saved = false;
    if ($saveCredentials && !empty($password)) {
        $password_saved = saveWifiPassword($ssid, $password, $passwordFile);
        logMessage("Password save for SSID '$ssid': " . ($password_saved ? 'Success' : 'Failed'));
    }
    
    // If reboot is requested, schedule a reboot
    if ($reboot) {
        // Use nohup to ensure the reboot happens even if the HTTP connection is closed
        exec('nohup sudo /bin/sh -c "sleep 5 && reboot" > /dev/null 2>&1 &');
        logMessage("Scheduled reboot in 5 seconds");
    }
    
    echo json_encode([
        'success' => true,
        'details' => [
            'connection_name' => $connection_name,
            'connection_result' => $output_text,
            'connect_result' => $connect_output_text,
            'password_saved' => $password_saved,
            'message' => $reboot ? 
                'WiFi configuration saved. Your Raspberry Pi will reboot to apply changes.' : 
                'WiFi configuration saved and activated. If not connected, changes will apply after reboot.'
        ]
    ]);
} catch (Exception $e) {
    $error = 'Exception: ' . $e->getMessage();
    logMessage("Error: $error");
    echo json_encode(['success' => false, 'message' => $error]);
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
        exec("sudo cat " . escapeshellarg($file) . " 2>&1", $read_output, $read_return);
        if ($read_return === 0) {
            $jsonContent = implode("\n", $read_output);
            $passwords = json_decode($jsonContent, true) ?: [];
            logMessage("Read existing passwords from $file: " . ($jsonContent ? 'Success' : 'Empty'));
        } else {
            logMessage("Failed to read $file: " . implode("\n", $read_output));
        }
    } else {
        logMessage("Password file $file does not exist, will attempt to create");
    }
    
    // Update password for this SSID
    $passwords[$ssid] = $password;
    
    // Create directory if it doesn't exist
    $directory = dirname($file);
    if (!is_dir($directory)) {
        // If file is directly in /etc, we don't need to create the directory
        if ($directory !== '/etc') {
            exec("sudo mkdir -p " . escapeshellarg($directory) . " 2>&1", $mkdir_output, $mkdir_return);
            logMessage("Creating directory $directory: " . implode("\n", $mkdir_output) . ", Return code: $mkdir_return");
            if ($mkdir_return !== 0) {
                logMessage("Failed to create directory $directory");
                return false;
            }
        }
    }
    
    // Write back to file using sudo
    $jsonData = json_encode($passwords, JSON_PRETTY_PRINT);
    $tempFile = tempnam(sys_get_temp_dir(), 'wifi');
    file_put_contents($tempFile, $jsonData);
    exec("sudo mv " . escapeshellarg($tempFile) . " " . escapeshellarg($file) . " 2>&1", $mv_output, $mv_return);
    logMessage("Writing passwords to $file: " . implode("\n", $mv_output) . ", Return code: $mv_return");
    
    // Set proper permissions for security
    if ($mv_return === 0) {
        exec("sudo chmod 0600 " . escapeshellarg($file) . " 2>&1", $chmod_output, $chmod_return);
        exec("sudo chown root:root " . escapeshellarg($file) . " 2>&1", $chown_output, $chown_return);
        logMessage("Set permissions on $file: chmod Return code: $chmod_return, chown Return code: $chown_return");
        return true;
    }
    
    return false;
}
?>