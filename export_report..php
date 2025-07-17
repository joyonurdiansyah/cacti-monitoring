<?php
// export_report.php - Fixed clean export system for Cacti monitoring data
include('./include/auth.php');
require_once('./lib/functions.php');

// Export configuration
define('EXPORT_EXCEL', 'excel');
define('EXPORT_PDF', 'pdf');

class CactiExportReport {
    private $config;
    
    public function __construct() {
        global $config;
        $this->config = $config;
    }
    
    /**
     * Get comprehensive monitoring data for all hosts
     */
    public function getMonitoringData() {
        $hosts = $this->getAllActiveHosts();
        $report_data = [];
        
        foreach ($hosts as $host) {
            $host_data = $this->getHostCompleteData($host['id']);
            $report_data[] = [
                'host_info' => $host,
                'daily' => $host_data['daily'],
                'weekly' => $host_data['weekly'],
                'monthly' => $host_data['monthly'],
                'interfaces' => $this->getHostInterfaces($host['id'])
            ];
        }
        
        return $report_data;
    }
    
    /**
     * Get all active hosts
     */
    private function getAllActiveHosts() {
        return db_fetch_assoc("
            SELECT id, hostname, description, status, disabled 
            FROM host 
            WHERE disabled = '' OR disabled = 'off'
            ORDER BY description
        ");
    }
    
    /**
     * Get complete host data for all periods
     */
    private function getHostCompleteData($host_id) {
        $periods = ['daily', 'weekly', 'monthly'];
        $data = [];
        
        foreach ($periods as $period) {
            $data[$period] = $this->calculateHostMetrics($host_id, $period);
        }
        
        return $data;
    }
    
    /**
     * Calculate comprehensive metrics for a host
     */
    private function calculateHostMetrics($host_id, $period) {
        $period_seconds = $this->getPeriodSeconds($period);
        $start_time = time() - $period_seconds;
        
        // Get host basic info
        $host = db_fetch_row_prepared("
            SELECT id, hostname, description, status, 
                    availability, cur_time, avg_time,   
                    total_polls, failed_polls,
                    status_fail_date, status_rec_date
            FROM host WHERE id = ?", [$host_id]);
        
        if (!$host) return null;
        
        // Calculate availability and response times
        $availability = $this->calculateAvailability($host, $period_seconds);
        $response_times = $this->calculateResponseTimes($host);
        $downtime_info = $this->calculateDowntime($host, $start_time);
        
        return [
            'availability_percent' => $availability,
            'max_response_time' => $response_times['max'],
            'avg_response_time' => $response_times['avg'],
            'min_response_time' => $response_times['min'],
            'total_downtime' => $downtime_info['total_seconds'],
            'downtime_incidents' => $downtime_info['incidents'],
            'status' => $host['status'],
            'polling_success_rate' => $this->calculatePollingSuccessRate($host)
        ];
    }
    
    /**
     * Get host network interfaces data
     */
    private function getHostInterfaces($host_id) {
        $interfaces = db_fetch_assoc_prepared("
            SELECT DISTINCT 
                dl.id as data_source_id,
                dt.name as template_name,
                dtr.data_source_name,
                dl.name_cache,
                dtr.rrd_maximum,
                dtr.rrd_minimum
            FROM data_local dl
            LEFT JOIN data_template dt ON dl.data_template_id = dt.id
            LEFT JOIN data_template_rrd dtr ON dl.id = dtr.local_data_id
            WHERE dl.host_id = ? 
            AND dt.name LIKE '%Interface%'
            ORDER BY dl.name_cache
        ", [$host_id]);
        
        $interface_data = [];
        foreach ($interfaces as $interface) {
            $traffic_data = $this->getInterfaceTrafficData($interface['data_source_id']);
            $interface_data[] = [
                'name' => $interface['name_cache'],
                'template' => $interface['template_name'],
                'max_in' => $traffic_data['max_in'],
                'avg_in' => $traffic_data['avg_in'],
                'max_out' => $traffic_data['max_out'],
                'avg_out' => $traffic_data['avg_out']
            ];
        }
        
        return $interface_data;
    }
    
    /**
     * Get interface traffic data from RRD files
     */
    private function getInterfaceTrafficData($data_source_id) {
        // This would normally query RRD files for actual traffic data
        // For now, returning sample structure
        return [
            'max_in' => 0,
            'avg_in' => 0,
            'max_out' => 0,
            'avg_out' => 0
        ];
    }
    
    /**
     * Generate Excel export
     */
    public function exportToExcel($data, $filename = null) {
        if (!$filename) {
            $filename = 'network_monitoring_report_' . date('Y-m-d_H-i-s') . '.csv';
        }
        
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        // Generate Excel content
        $excel_content = $this->generateExcelContent($data);
        echo $excel_content;
    }
    
    /**
     * Generate PDF export
     */
    public function exportToPDF($data, $filename = null) {
        if (!$filename) {
            $filename = 'network_monitoring_report_' . date('Y-m-d_H-i-s') . '.pdf';
        }
        
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Generate PDF content
        $pdf_content = $this->generatePDFContent($data);
        echo $pdf_content;
    }
    
    /**
     * Generate Excel content in CSV format (simple implementation)
     */
    private function generateExcelContent($data) {
        $output = '';
        
        // Header
        $output .= "Network Monitoring Report - " . date('Y-m-d H:i:s') . "\n\n";
        
        // Summary Section
        $output .= "SUMMARY\n";
        $output .= "Device Name,IP Address,Current Status,Daily Availability,Weekly Availability,Monthly Availability,Total Downtime (Hours)\n";
        
        foreach ($data as $host_data) {
            $host = $host_data['host_info'];
            $daily = $host_data['daily'];
            $weekly = $host_data['weekly'];
            $monthly = $host_data['monthly'];
            
            $output .= sprintf('"%s","%s","%s","%.2f%%","%.2f%%","%.2f%%","%.2f"' . "\n",
                $host['description'],
                $host['hostname'],
                $this->getStatusText($host['status']),
                $daily['availability_percent'],
                $weekly['availability_percent'],
                $monthly['availability_percent'],
                $monthly['total_downtime'] / 3600
            );
        }
        
        // Detailed Section
        $output .= "\n\nDETAILED METRICS\n";
        $output .= "Device,Period,Availability %,Max Response (ms),Avg Response (ms),Downtime (hours),Incidents\n";
        
        foreach ($data as $host_data) {
            $host = $host_data['host_info'];
            
            foreach (['daily', 'weekly', 'monthly'] as $period) {
                $metrics = $host_data[$period];
                $output .= sprintf('"%s","%s","%.2f","%.2f","%.2f","%.2f","%d"' . "\n",
                    $host['description'],
                    ucfirst($period),
                    $metrics['availability_percent'],
                    $metrics['max_response_time'],
                    $metrics['avg_response_time'],
                    $metrics['total_downtime'] / 3600,
                    $metrics['downtime_incidents']
                );
            }
        }
        
        // Interface Section
        $output .= "\n\nINTERFACE TRAFFIC\n";
        $output .= "Device,Interface,Max In (bps),Avg In (bps),Max Out (bps),Avg Out (bps)\n";
        
        foreach ($data as $host_data) {
            $host = $host_data['host_info'];
            
            foreach ($host_data['interfaces'] as $interface) {
                $output .= sprintf('"%s","%s","%.0f","%.0f","%.0f","%.0f"' . "\n",
                    $host['description'],
                    $interface['name'],
                    $interface['max_in'],
                    $interface['avg_in'],
                    $interface['max_out'],
                    $interface['avg_out']
                );
            }
        }
        
        return $output;
    }
    
    /**
     * Generate PDF content (HTML-based simple implementation)
     */
    private function generatePDFContent($data) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Network Monitoring Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; }
        .summary { margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .status-up { color: green; font-weight: bold; }
        .status-down { color: red; font-weight: bold; }
        .section-title { font-size: 18px; font-weight: bold; margin: 20px 0 10px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Network Monitoring Report</h1>
        <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
    </div>';
        
        // Summary table
        $html .= '<div class="section-title">Executive Summary</div>
        <table>
            <tr>
                <th>Device</th>
                <th>IP Address</th>
                <th>Status</th>
                <th>Daily Availability</th>
                <th>Weekly Availability</th>
                <th>Monthly Availability</th>
                <th>Monthly Downtime</th>
            </tr>';
        
        foreach ($data as $host_data) {
            $host = $host_data['host_info'];
            $daily = $host_data['daily'];
            $weekly = $host_data['weekly'];
            $monthly = $host_data['monthly'];
            
            $status_class = $host['status'] == 3 ? 'status-up' : 'status-down';
            
            $html .= '<tr>
                <td>' . htmlspecialchars($host['description']) . '</td>
                <td>' . htmlspecialchars($host['hostname']) . '</td>
                <td class="' . $status_class . '">' . $this->getStatusText($host['status']) . '</td>
                <td>' . number_format($daily['availability_percent'], 2) . '%</td>
                <td>' . number_format($weekly['availability_percent'], 2) . '%</td>
                <td>' . number_format($monthly['availability_percent'], 2) . '%</td>
                <td>' . $this->formatDuration($monthly['total_downtime']) . '</td>
            </tr>';
        }
        
        $html .= '</table>';
        
        // Detailed metrics
        $html .= '<div class="section-title">Detailed Performance Metrics</div>
        <table>
            <tr>
                <th>Device</th>
                <th>Period</th>
                <th>Availability</th>
                <th>Max Response Time</th>
                <th>Avg Response Time</th>
                <th>Downtime</th>
                <th>Incidents</th>
            </tr>';
        
        foreach ($data as $host_data) {
            $host = $host_data['host_info'];
            
            foreach (['daily', 'weekly', 'monthly'] as $period) {
                $metrics = $host_data[$period];
                $html .= '<tr>
                    <td>' . htmlspecialchars($host['description']) . '</td>
                    <td>' . ucfirst($period) . '</td>
                    <td>' . number_format($metrics['availability_percent'], 2) . '%</td>
                    <td>' . number_format($metrics['max_response_time'], 2) . ' ms</td>
                    <td>' . number_format($metrics['avg_response_time'], 2) . ' ms</td>
                    <td>' . $this->formatDuration($metrics['total_downtime']) . '</td>
                    <td>' . $metrics['downtime_incidents'] . '</td>
                </tr>';
            }
        }
        
        $html .= '</table></body></html>';
        
        return $html;
    }
    
    // Helper methods
    private function getPeriodSeconds($period) {
        $periods = [
            'daily' => 86400,
            'weekly' => 604800,
            'monthly' => 2592000
        ];
        return $periods[$period] ?? 86400;
    }
    
    private function calculateAvailability($host, $period_seconds) {
        if ($host['total_polls'] > 0) {
            $success_rate = (($host['total_polls'] - $host['failed_polls']) / $host['total_polls']) * 100;
            return max(0, min(100, $success_rate));
        }
        return 0;
    }
    
    private function calculateResponseTimes($host) {
        return [
            'max' => $host['cur_time'] * 1.5, // Estimated max
            'avg' => $host['avg_time'],
            'min' => $host['cur_time'] * 0.5   // Estimated min
        ];
    }
    
    private function calculateDowntime($host, $start_time) {
        $total_seconds = 0;
        $incidents = 0;
        
        if ($host['status_fail_date'] && $host['status_fail_date'] != '0000-00-00 00:00:00') {
            $fail_time = strtotime($host['status_fail_date']);
            if ($fail_time >= $start_time) {
                $recovery_time = $host['status_rec_date'] && $host['status_rec_date'] != '0000-00-00 00:00:00' 
                    ? strtotime($host['status_rec_date']) 
                    : time();
                $total_seconds = $recovery_time - $fail_time;
                $incidents = 1;
            }
        }
        
        return [
            'total_seconds' => $total_seconds,
            'incidents' => $incidents
        ];
    }
    
    private function calculatePollingSuccessRate($host) {
        if ($host['total_polls'] > 0) {
            return round((($host['total_polls'] - $host['failed_polls']) / $host['total_polls']) * 100, 2);
        }
        return 0;
    }
    
    private function getStatusText($status) {
        switch($status) {
            case 3: return 'UP';
            case 1: return 'DOWN';
            case 2: return 'RECOVERING';
            default: return 'UNKNOWN';
        }
    }
    
    private function formatDuration($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        } else if ($minutes > 0) {
            return $minutes . 'm';
        } else {
            return $seconds . 's';
        }
    }
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $exporter = new CactiExportReport();
    $data = $exporter->getMonitoringData();
    
    switch ($export_type) {
        case EXPORT_EXCEL:
            $exporter->exportToExcel($data);
            break;
        case EXPORT_PDF:
            $exporter->exportToPDF($data);
            break;
        default:
            echo "Invalid export type";
    }
    exit;
}

// Export buttons for your dashboard
function render_export_buttons() {
    ?>
    <div style="margin: 20px 0; text-align: center;">
        <a href="?export=excel" class="btn btn-primary" style="margin-right: 10px;">
            <i class="fa fa-file-excel"></i> Export to Excel
        </a>
        <a href="?export=pdf" class="btn btn-secondary">
            <i class="fa fa-file-pdf"></i> Export to PDF
        </a>
    </div>
    <?php
}
?>