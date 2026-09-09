<?php
/**
 * Interactive Google Drive Authorization Assistant
 *
 * This script runs in the CLI to guide the user through setting up
 * Google Drive API credentials, authorizing access, and generating
 * a persistent Refresh Token. It then saves them to backup_config.php.
 */

if (php_sapi_name() !== 'cli') {
    die("Error: This script must be run from the command line (CLI).\n");
}

echo "=================================================================\n";
echo "    GOOGLE DRIVE BACKUP AUTHORIZATION ASSISTANT                  \n";
echo "=================================================================\n\n";

echo "To upload backups to Google Drive, you need to set up OAuth credentials:\n";
echo "1. Go to Google Cloud Console (https://console.cloud.google.com/)\n";
echo "2. Create a new project (e.g., 'HMS Backups')\n";
echo "3. Search for 'Google Drive API' and ENABLE it.\n";
echo "4. Go to 'OAuth consent screen', select 'External' or 'Internal',\n";
echo "   configure the App Name, and add your email.\n";
echo "5. Publish the app (set status to 'In Production' to avoid expiration)\n";
echo "   and add your Google email under 'Test users' if in testing mode.\n";
echo "6. Go to 'Credentials' -> 'Create Credentials' -> 'OAuth client ID'.\n";
echo "7. Choose Application Type: 'Desktop App' (or 'Web Application' with\n";
echo "   redirect URI: https://developers.google.com/oauthplayground).\n";
echo "8. Copy the Client ID and Client Secret.\n\n";

// Choose which drive slot to authorize
echo "Choose Google Drive slot to authorize:\n";
echo "1. Google Drive 1 (Primary)\n";
echo "2. Google Drive 2 (Secondary)\n";
echo "Enter slot choice (1 or 2): ";
$slotChoice = trim(fgets(STDIN));
if ($slotChoice !== '1' && $slotChoice !== '2') {
    die("Error: Invalid slot choice. Must be 1 or 2.\n");
}
$slotKey = 'gdrive' . $slotChoice;
$slotName = $slotChoice === '1' ? 'Primary' : 'Secondary';

echo "\nConfiguring $slotName Google Drive ($slotKey)...\n\n";

// 1. Ask for Client ID
echo "Enter your Google Client ID: ";
$clientId = trim(fgets(STDIN));
if (empty($clientId)) {
    die("Error: Client ID cannot be empty.\n");
}

// 2. Ask for Client Secret
echo "Enter your Google Client Secret: ";
$clientSecret = trim(fgets(STDIN));
if (empty($clientSecret)) {
    die("Error: Client Secret cannot be empty.\n");
}

// 3. Generate Auth URL
// For desktop apps, the loopback redirect URI is usually localhost or urn:ietf:wg:oauth:2.0:oob
// Since Google deprecated urn:ietf:wg:oauth:2.0:oob, the standard redirect URI for desktop apps is:
// http://localhost (Google allows choosing a port automatically, or using localhost:port)
// Alternatively, we can use a custom redirect URI. But since we are running in the CLI, we can use
// a custom redirect URI of: https://developers.google.com/oauthplayground
// Or we can let them use the standard Google Desktop app redirect URI: http://localhost:8585
// Wait! The most robust, easiest way for CLI environments without a local web server running on that port
// is using redirect URI: http://localhost
// Let's use redirect URI: http://localhost
$redirectUri = 'http://localhost';

$authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/drive.file', // Access only files created/uploaded by this app
    'access_type'   => 'offline',
    'prompt'        => 'consent'
]);

echo "\n=================================================================\n";
echo "AUTHORIZATION STEP:\n";
echo "=================================================================\n";
echo "1. Copy and paste this URL into your web browser:\n\n";
echo "$authUrl\n\n";
echo "2. Log in with your Google account and grant the requested permissions.\n";
echo "3. After approving, your browser will redirect to a URL like:\n";
echo "   http://localhost/?code=4/0AdQt8qi...&scope=...\n";
echo "4. Copy the entire 'code' value from the browser's URL address bar.\n";
echo "=================================================================\n\n";

echo "Paste the authorization 'code' here: ";
$authCode = trim(fgets(STDIN));

if (empty($authCode)) {
    die("Error: Authorization code cannot be empty.\n");
}

// If the user pasted the entire URL by accident, parse out the code
if (strpos($authCode, 'code=') !== false) {
    parse_str(parse_url($authCode, PHP_URL_QUERY), $urlParams);
    if (isset($urlParams['code'])) {
        $authCode = $urlParams['code'];
    }
}

// URL-decode the code just in case
$authCode = urldecode($authCode);

echo "\nExchanging authorization code for access and refresh tokens...\n";

// Exchange authorization code for token
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'code'          => $authCode,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "Error: Failed to obtain tokens. HTTP Code: $httpCode\n";
    echo "Response: $response\n";
    die("Please verify your Client ID, Client Secret, and ensure the authorization code is fresh and correct.\n");
}

$tokenData = json_decode($response, true);
if (empty($tokenData['refresh_token'])) {
    echo "Warning: Google did not return a refresh token. This happens if you have already authorized this app.\n";
    echo "To fix this, go to Google Account Settings -> Security -> Third-party apps with account access,\n";
    echo "remove your app, and run this authorization script again.\n\n";
    echo "Enter existing Refresh Token (if you have one, or press Enter to retry setup): ";
    $refreshToken = trim(fgets(STDIN));
    if (empty($refreshToken)) {
        die("Authentication failed. No refresh token available.\n");
    }
} else {
    $refreshToken = $tokenData['refresh_token'];
    echo "Success! Obtained Refresh Token.\n";
}

// 4. Optional: Ask for Folder ID
echo "\nWould you like to specify a Google Drive Folder ID to upload backups into?\n";
echo "(If left blank, backups will be uploaded to the root of your Google Drive.)\n";
echo "Folder ID (press Enter to skip): ";
$folderId = trim(fgets(STDIN));

// 5. Update backup_config.php
$configPath = __DIR__ . '/backup_config.php';
if (!file_exists($configPath)) {
    die("Error: backup_config.php not found. Please restore the configuration template.\n");
}

// Load existing config
$config = include $configPath;
if (!is_array($config)) {
    $config = [];
}

// Initialize slots if not exist
if (!isset($config['gdrive1'])) {
    $config['gdrive1'] = ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'folder_id' => ''];
}
if (!isset($config['gdrive2'])) {
    $config['gdrive2'] = ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'refresh_token' => '', 'folder_id' => ''];
}

// Update settings for selected slot
$config[$slotKey]['enabled'] = true;
$config[$slotKey]['client_id'] = $clientId;
$config[$slotKey]['client_secret'] = $clientSecret;
$config[$slotKey]['refresh_token'] = $refreshToken;
$config[$slotKey]['folder_id'] = $folderId;

// Format the file content manually for a beautiful look and comments
$newConfigContent = "<?php\n" .
    "/**\n" .
    " * Automated Backup Configuration File\n" .
    " *\n" .
    " * Generated by authorize.php on " . date('Y-m-d H:i:s') . "\n" .
    " */\n\n" .
    "return [\n" .
    "    // --- System Executable Paths ---\n" .
    "    'php_bin'         => " . var_export($config['php_bin'] ?? 'C:\\\\xampp\\\\php\\\\php.exe', true) . ",\n" .
    "    'mysqldump_bin'   => " . var_export($config['mysqldump_bin'] ?? 'C:\\\\xampp\\\\mysql\\\\bin\\\\mysqldump.exe', true) . ",\n\n" .
    "    // --- Directory Settings ---\n" .
    "    'temp_dir'        => __DIR__ . '/temp/',\n" .
    "    'log_dir'         => __DIR__ . '/logs/',\n" .
    "    'log_file'        => __DIR__ . '/logs/backup.log',\n\n" .
    "    // --- Backup Naming ---\n" .
    "    'file_prefix'     => " . var_export($config['file_prefix'] ?? 'hms_backup_', true) . ",\n\n" .
    "    // --- Retention Settings ---\n" .
    "    'retention_days'      => " . var_export($config['retention_days'] ?? 30, true) . ",\n" .
    "    'min_backups_to_keep' => " . var_export($config['min_backups_to_keep'] ?? 5, true) . ",\n\n" .
    "    // --- Google Drive Integration Settings ---\n" .
    "    // Google Drive 1 (Primary)\n" .
    "    'gdrive1' => [\n" .
    "        'enabled'       => " . var_export($config['gdrive1']['enabled'], true) . ",\n" .
    "        'client_id'     => " . var_export($config['gdrive1']['client_id'], true) . ",\n" .
    "        'client_secret' => " . var_export($config['gdrive1']['client_secret'], true) . ",\n" .
    "        'refresh_token' => " . var_export($config['gdrive1']['refresh_token'], true) . ",\n" .
    "        'folder_id'     => " . var_export($config['gdrive1']['folder_id'], true) . ",\n" .
    "    ],\n\n" .
    "    // Google Drive 2 (Secondary)\n" .
    "    'gdrive2' => [\n" .
    "        'enabled'       => " . var_export($config['gdrive2']['enabled'], true) . ",\n" .
    "        'client_id'     => " . var_export($config['gdrive2']['client_id'], true) . ",\n" .
    "        'client_secret' => " . var_export($config['gdrive2']['client_secret'], true) . ",\n" .
    "        'refresh_token' => " . var_export($config['gdrive2']['refresh_token'], true) . ",\n" .
    "        'folder_id'     => " . var_export($config['gdrive2']['folder_id'], true) . ",\n" .
    "    ],\n" .
    "];\n";

if (file_put_contents($configPath, $newConfigContent) !== false) {
    echo "\n=================================================================\n";
    echo "SUCCESS: Configuration file updated and Google Drive ($slotName) enabled!\n";
    echo "=================================================================\n";
    echo "You are now ready to run your automated backups.\n";
    echo "Try running a test backup using the command:\n";
    echo "C:\\xampp\\php\\php.exe -f backup.php\n";
    echo "=================================================================\n\n";
} else {
    echo "Error: Failed to write to backup_config.php. Please check permissions.\n";
}
