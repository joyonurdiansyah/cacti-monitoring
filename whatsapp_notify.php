<?php
/*
+-------------------------------------------------------------------------+
| WhatsApp Notification Script for Cacti                                 |
| Monitors device and interface status using Fonnte API                  |
+-------------------------------------------------------------------------+
 */

// Konfigurasi Database Cacti
$db_config = [
    'host' => 'localhost',
    'dbname' => 'cacti',
    'username' => 'root',
    'password' => 'password'
];

// Konfigurasi WhatsApp Fonnte
$whatsapp_config = [
    'api_url' => 'https://api.fonnte.com/send',
    'token' => 'YOUR_FONNTE_TOKEN_HERE',
    'target' => '6281234567890', // Nomor tujuan WhatsApp
    'group_target' => '', // Kosongkan jika tidak menggunakan grup
];

// Konfigurasi Notifikasi
$notification_config = [
    'check_devices' => true,
    'check_interfaces' => true,
    'max_devices_per_message' => 10,
    'max_interfaces_per_message' => 15,
    'send_summary' => true,
    'use_emoji' => true
];

/**
 * Fungsi untuk mengirim pesan WhatsApp via Fonnte
 */
function sendWhatsAppMessage($message, $target = null) {
    global $whatsapp_config;
    
    $target = $target ?: $whatsapp_config['target'];
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $whatsapp_config['api_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array(
            'target' => $target,
            'message' => $message,
        ),
        CURLOPT_HTTPHEADER => array(
            'Authorization: ' . $whatsapp_config['token']
        ),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ));
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        error_log("WhatsApp API Error: " . $error);
        return false;
    }
    
    if ($http_code !== 200) {
        error_log("WhatsApp API HTTP Error: " . $http_code . " - " . $response);
        return false;
    }
    
    return json_decode($response, true);
}

/**
 * Fungsi untuk mendapatkan koneksi database
 */
function getDatabaseConnection() {
    global $db_config;
    
    try {
        $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        exit("Database connection failed\n");
    }
}

/**
 * Fungsi untuk mengecek device yang down
 */
function checkDownDevices($db) {
    // Status di Cacti:
    // 0 = Unknown, 1 = Down, 2 = Recovering, 3 = Up
    $query = "
        SELECT 
            h.id,
            h.hostname,
            h.description,
            h.status,
            h.status_last_error,
            h.status_fail_date,
            h.last_updated,
            CASE 
                WHEN h.status = 0 THEN 'Unknown'
                WHEN h.status = 1 THEN 'Down'
                WHEN h.status = 2 THEN 'Recovering'
                WHEN h.status = 3 THEN 'Up'
                ELSE 'Unknown'
            END as status_text
        FROM host h
        WHERE h.disabled = ''
        AND h.deleted = ''
        AND h.status IN (0, 1, 2)
        ORDER BY h.status ASC, h.hostname ASC
    ";
    
    return $db->query($query)->fetchAll();
}

/**
 * Fungsi untuk mengecek interface yang down
 */
function checkDownInterfaces($db) {
    $query = "
        SELECT 
            h.hostname,
            h.description as host_description,
            hi.ifName,
            hi.ifAlias,
            hi.ifDescr,
            hi.ifOperStatus,
            hi.ifAdminStatus,
            hi.last_up_time,
            hi.last_down_time,
            CASE 
                WHEN hi.ifOperStatus = 1 THEN 'Up'
                WHEN hi.ifOperStatus = 2 THEN 'Down'
                WHEN hi.ifOperStatus = 3 THEN 'Testing'
                WHEN hi.ifOperStatus = 4 THEN 'Unknown'
                WHEN hi.ifOperStatus = 5 THEN 'Dormant'
                WHEN hi.ifOperStatus = 6 THEN 'NotPresent'
                WHEN hi.ifOperStatus = 7 THEN 'LowerLayerDown'
                ELSE 'Unknown'
            END as oper_status_text,
            CASE 
                WHEN hi.ifAdminStatus = 1 THEN 'Up'
                WHEN hi.ifAdminStatus = 2 THEN 'Down'
                WHEN hi.ifAdminStatus = 3 THEN 'Testing'
                ELSE 'Unknown'
            END as admin_status_text
        FROM host h
        INNER JOIN host_template ht ON h.host_template_id = ht.id
        INNER JOIN host_snmp_cache hsc ON h.id = hsc.host_id
        INNER JOIN host_interface hi ON h.id = hi.host_id
        WHERE h.disabled = ''
        AND h.deleted = ''
        AND h.status = 3
        AND hi.ifOperStatus = 2
        AND hi.ifAdminStatus = 1
        AND hi.ifName != ''
        AND hi.ifName NOT LIKE '%loopback%'
        AND hi.ifName NOT LIKE '%null%'
        ORDER BY h.hostname ASC, hi.ifName ASC
    ";
    
    return $db->query($query)->fetchAll();
}

/**
 * Fungsi untuk format pesan device down
 */
function formatDeviceMessage($devices) {
    global $notification_config;
    
    if (empty($devices)) {
        return null;
    }
    
    $emoji = $notification_config['use_emoji'];
    $messages = [];
    $chunks = array_chunk($devices, $notification_config['max_devices_per_message']);
    
    foreach ($chunks as $chunk) {
        $message = ($emoji ? "🚨" : "") . " *DEVICE DOWN ALERT*\n";
        $message .= "Waktu: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($chunk as $device) {
            $status_emoji = '';
            if ($emoji) {
                switch ($device['status']) {
                    case 0: $status_emoji = '❓'; break;
                    case 1: $status_emoji = '🔴'; break;
                    case 2: $status_emoji = '🟡'; break;
                    default: $status_emoji = '❓'; break;
                }
            }
            
            $message .= "{$status_emoji} *{$device['hostname']}*\n";
            if (!empty($device['description'])) {
                $message .= "   Desc: {$device['description']}\n";
            }
            $message .= "   Status: {$device['status_text']}\n";
            if (!empty($device['status_last_error'])) {
                $message .= "   Error: {$device['status_last_error']}\n";
            }
            if ($device['status_fail_date'] != '0000-00-00 00:00:00') {
                $message .= "   Down Since: {$device['status_fail_date']}\n";
            }
            $message .= "\n";
        }
        
        $messages[] = $message;
    }
    
    return $messages;
}

/**
 * Fungsi untuk format pesan interface down
 */
function formatInterfaceMessage($interfaces) {
    global $notification_config;
    
    if (empty($interfaces)) {
        return null;
    }
    
    $emoji = $notification_config['use_emoji'];
    $messages = [];
    $chunks = array_chunk($interfaces, $notification_config['max_interfaces_per_message']);
    
    foreach ($chunks as $chunk) {
        $message = ($emoji ? "🔌" : "") . " *INTERFACE DOWN ALERT*\n";
        $message .= "Waktu: " . date('Y-m-d H:i:s') . "\n\n";
        
        $current_host = '';
        foreach ($chunk as $interface) {
            if ($current_host != $interface['hostname']) {
                $current_host = $interface['hostname'];
                $message .= ($emoji ? "🖥️" : "") . " *{$current_host}*\n";
            }
            
            $interface_name = $interface['ifName'] ?: $interface['ifDescr'];
            $message .= ($emoji ? "  🔴" : "  -") . " {$interface_name}\n";
            
            if (!empty($interface['ifAlias'])) {
                $message .= "     Alias: {$interface['ifAlias']}\n";
            }
            
            $message .= "     Oper: {$interface['oper_status_text']} | Admin: {$interface['admin_status_text']}\n";
            
            if ($interface['last_down_time'] != '0000-00-00 00:00:00') {
                $message .= "     Down Since: {$interface['last_down_time']}\n";
            }
            $message .= "\n";
        }
        
        $messages[] = $message;
    }
    
    return $messages;
}

/**
 * Fungsi untuk membuat summary message
 */
function createSummaryMessage($device_count, $interface_count) {
    global $notification_config;
    
    if ($device_count == 0 && $interface_count == 0) {
        return ($notification_config['use_emoji'] ? "✅" : "") . " *CACTI MONITORING*\nSemua device dan interface dalam kondisi normal.\nWaktu: " . date('Y-m-d H:i:s');
    }
    
    $emoji = $notification_config['use_emoji'];
    $message = ($emoji ? "📊" : "") . " *CACTI MONITORING SUMMARY*\n";
    $message .= "Waktu: " . date('Y-m-d H:i:s') . "\n\n";
    
    if ($device_count > 0) {
        $message .= ($emoji ? "🚨" : "") . " Device Down: {$device_count}\n";
    }
    
    if ($interface_count > 0) {
        $message .= ($emoji ? "🔌" : "") . " Interface Down: {$interface_count}\n";
    }
    
    if ($device_count == 0 && $interface_count == 0) {
        $message .= ($emoji ? "✅" : "") . " Semua sistem normal\n";
    }
    
    return $message;
}

/**
 * Main execution
 */
try {
    echo "Starting Cacti WhatsApp Notification Check...\n";
    
    // Koneksi ke database
    $db = getDatabaseConnection();
    echo "Database connected successfully\n";
    
    $down_devices = [];
    $down_interfaces = [];
    
    // Cek device down
    if ($notification_config['check_devices']) {
        echo "Checking for down devices...\n";
        $down_devices = checkDownDevices($db);
        echo "Found " . count($down_devices) . " down devices\n";
    }
    
    // Cek interface down
    if ($notification_config['check_interfaces']) {
        echo "Checking for down interfaces...\n";
        $down_interfaces = checkDownInterfaces($db);
        echo "Found " . count($down_interfaces) . " down interfaces\n";
    }
    
    $messages_sent = 0;
    
    // Kirim notifikasi device down
    if (!empty($down_devices)) {
        $device_messages = formatDeviceMessage($down_devices);
        foreach ($device_messages as $message) {
            $result = sendWhatsAppMessage($message);
            if ($result) {
                echo "Device down message sent successfully\n";
                $messages_sent++;
                sleep(1); // Delay untuk menghindari rate limit
            } else {
                echo "Failed to send device down message\n";
            }
        }
    }
    
    // Kirim notifikasi interface down
    if (!empty($down_interfaces)) {
        $interface_messages = formatInterfaceMessage($down_interfaces);
        foreach ($interface_messages as $message) {
            $result = sendWhatsAppMessage($message);
            if ($result) {
                echo "Interface down message sent successfully\n";
                $messages_sent++;
                sleep(1); // Delay untuk menghindari rate limit
            } else {
                echo "Failed to send interface down message\n";
            }
        }
    }
    
    // Kirim summary jika diaktifkan
    if ($notification_config['send_summary'] && (count($down_devices) > 0 || count($down_interfaces) > 0)) {
        $summary_message = createSummaryMessage(count($down_devices), count($down_interfaces));
        $result = sendWhatsAppMessage($summary_message);
        if ($result) {
            echo "Summary message sent successfully\n";
            $messages_sent++;
        } else {
            echo "Failed to send summary message\n";
        }
    }
    
    echo "Notification check completed. Messages sent: {$messages_sent}\n";
    
} catch (Exception $e) {
    error_log("WhatsApp Notification Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>