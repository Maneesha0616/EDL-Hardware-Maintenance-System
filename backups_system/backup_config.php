<?php
/**
 * Automated Backup Configuration File
 * Generated/Updated by Admin Dashboard on 2026-06-02 11:55:07
 */

return [
    // --- System Executable Paths ---
    'php_bin' => 'C:\\xampp\\php\\php.exe',
    'mysqldump_bin' => 'C:\\xampp\\mysql\\bin\\mysqldump.exe',

    // --- Directory Settings ---
    'temp_dir' => __DIR__ . '/temp/',
    'log_dir' => __DIR__ . '/logs/',
    'log_file' => __DIR__ . '/logs/backup.log',

    // --- Backup Naming ---
    'file_prefix' => 'hms_backup_',

    // --- Retention Settings ---
    'retention_days' => 30,
    'min_backups_to_keep' => 5,

    // --- Google Drive Integration Settings ---
    // Google Drive 1 (Primary)
    'gdrive1' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '',
        'refresh_token' => '',
        'folder_id' => '',
    ],

    // Google Drive 2 (Secondary)
    'gdrive2' => [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
        'refresh_token' => '',
        'folder_id' => '',
    ],
];
