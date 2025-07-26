<?php
include('./include/auth.php');
include_once('./include/config.php');

header('Content-Type: application/json');

// Validasi param
if (!isset($_GET['host_id'])) {
    echo json_encode(['error' => 'host_id missing']);
    exit;
}
$host_id = intval($_GET['host_id']);
$period = isset($_GET['period']) ? $_GET['period'] : 'daily';

// --- COPY FUNGSI/FLOW DARI DASHBOARD ---
function format_period_date_range($period) {
    $now = time();
    $periods = array(
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
        'yearly' => 31536000
    );
    $period_seconds = isset($periods[$period]) ? $periods[$period] : 86400;
    $start_time = $now - $period_seconds;
    $start_date = date('d M Y', $start_time);
    $end_date = date('d M Y', $now);
    if ($start_date === $end_date) {
        return $start_date;
    }
    return $start_date . ' - ' . $end_date;
}
function get_host_status_text($status) {
    switch($status) {
        case 0: return 'Unknown';
        case 1: return 'Down';
        case 2: return 'Recovering';
        case 3: return 'Up';
        default: return 'Unknown';
    }
}
function get_host_availability_data($host_id, $period = 'daily') {
    global $config;
    $now = time();
    $periods = array(
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
        'yearly' => 31536000
    );
    $period_seconds = isset($periods[$period]) ? $periods[$period] : 86400;
    $start_time = $now - $period_seconds;

    $date_range = format_period_date_range($period);

    $current_host = db_fetch_row_prepared("
        SELECT id, hostname, description, status, status_event_count, 
                status_fail_date, status_rec_date, snmp_community, snmp_version,
                availability, cur_time, avg_time, total_polls, failed_polls
        FROM host 
        WHERE id = ?", array($host_id));
    if (!$current_host) {
        return [
            'error' => 'Host not found',
        ];
    }

    $total_downtime = 0;
    $downtime_incidents = [];
    $availability_percent = 0;
    if ($current_host['total_polls'] > 0) {
        $failed_polls = $current_host['failed_polls'];
        $total_polls = $current_host['total_polls'];
        $availability_percent = (($total_polls - $failed_polls) / $total_polls) * 100;
        $polling_interval = 300;
        $estimated_downtime = $failed_polls * $polling_interval;
        if ($period_seconds > 0 && $availability_percent < 100) {
            $total_downtime = $period_seconds * (1 - ($availability_percent / 100));
        } else {
            $total_downtime = $estimated_downtime;
        }
    }
    if ($current_host['status_fail_date'] && $current_host['status_fail_date'] != '0000-00-00 00:00:00') {
        $fail_time = strtotime($current_host['status_fail_date']);
        $rec_time = $current_host['status_rec_date'] && $current_host['status_rec_date'] != '0000-00-00 00:00:00' 
                ? strtotime($current_host['status_rec_date']) 
                : $now;
        if ($fail_time >= $start_time) {
            $downtime_duration = $rec_time - $fail_time;
            $downtime_incidents[] = [
                'start_time' => date('Y-m-d H:i:s', $fail_time),
                'end_time' => $current_host['status'] == 3 ? date('Y-m-d H:i:s', $rec_time) : 'ongoing',
                'duration' => $downtime_duration,
                'status' => get_host_status_text($current_host['status']),
                'event_count' => $current_host['status_event_count']
            ];
        }
    }
    if ($current_host['status'] != 3) {
        $current_downtime_start = $current_host['status_fail_date'] && $current_host['status_fail_date'] != '0000-00-00 00:00:00'
                                ? strtotime($current_host['status_fail_date'])
                                : $start_time;
        $current_downtime = $now - $current_downtime_start;
        $downtime_incidents[] = [
            'start_time' => date('Y-m-d H:i:s', $current_downtime_start),
            'end_time' => 'ongoing',
            'duration' => $current_downtime,
            'status' => get_host_status_text($current_host['status']),
            'event_count' => $current_host['status_event_count']
        ];
        $total_downtime += $current_downtime;
    }
    $uptime_seconds = $period_seconds - $total_downtime;
    $availability_percent = max(0, min(100, ($uptime_seconds / $period_seconds) * 100));

    return [
        'host_id' => $current_host['id'],
        'hostname' => $current_host['hostname'],
        'description' => $current_host['description'],
        'status' => $current_host['status'],
        'status_text' => get_host_status_text($current_host['status']),
        'availability_percent' => round($availability_percent, 2),
        'total_polls' => $current_host['total_polls'],
        'failed_polls' => $current_host['failed_polls'],
        'polling_success_rate' => $current_host['total_polls'] > 0 ? 
            round((($current_host['total_polls'] - $current_host['failed_polls']) / $current_host['total_polls']) * 100, 2) : 0,
        'response_time' => $current_host['cur_time'],
        'avg_response_time' => $current_host['avg_time'],
        'downtime_seconds' => $total_downtime,
        'downtime_incidents' => count($downtime_incidents),
        'downtime_details' => $downtime_incidents,
        'period' => $period,
        'date_range' => $date_range,
    ];
}

echo json_encode(get_host_availability_data($host_id, $period));
exit;
?>
