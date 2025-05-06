<?php
// get-saved-password.php - Script to retrieve saved WiFi passwords
header('Content-Type: application/json');
// Add CORS headers to allow access from different origins
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Only GET method is allowed']);
    exit;
}

// Check if SSID parameter is provided
if (!isset($_GET['ssid']) || empty($_GET['ssid'])) {
    echo json_encode(['success' => false, 'message' => 'SSID parameter is required']);
    exit;
}

$ssid = $_GET['ssid'];
$passwordFile = '/etc/rpi-wifi-passwords.json';

// Function to get saved password for a specific SSID
function getSavedPassword($ssid, $file) {
    // Check if password file exists
    if (!file_exists($file)) {
        return null;
    }
    
    // Read the password file
    $jsonContent = file_get_contents($file);
    if (!$jsonContent) {
        return null;
    }
    
    // Parse JSON content
    $passwords = json_decode($jsonContent, true);
    if (!$passwords || !is_array($passwords)) {
        return null;
    }
    
    // Return the password if found
    return isset($passwords[$ssid]) ? $passwords[$ssid] : null;
}

// Get the password
$password = getSavedPassword($ssid, $passwordFile);

// Return the result
if ($password !== null) {
    echo json_encode(['success' => true, 'password' => $password]);
} else {
    echo json_encode(['success' => false, 'message' => 'No saved password found for this network']);
}
?>