<?php 
include('./include/auth.php'); 
top_header(); 

api_plugin_hook('console_before'); 

function render_external_links($style = 'FRONT') { 
    global $config;     
    $consoles = db_fetch_assoc_prepared('SELECT id, contentfile 
        FROM external_links 
        WHERE enabled = "on" AND style = ?', array($style)); 
    if (cacti_sizeof($consoles)) { 
        foreach($consoles as $page) { 
            if (is_realm_allowed($page['id']+10000)) { 
                if (preg_match('/^((((ht|f)tp(s?))\:\/\/){1}\S+)/i', $page['contentfile'])) { 
                    print '<iframe class="content" src="' . $page['contentfile'] . '" frameborder="0"></iframe>'; 
                } else { 
                    print '<div id="content">'; 
                    $file = $config['base_path'] . "/include/content/" . $page['contentfile'];                     
                    if (file_exists($file)) { 
                        include_once($file); 
                    } else { 
                        print '<h1>The file \'' . $page['contentfile'] . '\' does not exist!!</h1>'; 
                    } 
                    print '</div>'; 
                } 
            } 
        } 
    } 
} 

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
    
    // If it's the same day, show only one date
    if ($start_date === $end_date) {
        return $start_date;
    }
    
    return $start_date . ' - ' . $end_date;
}

function get_host_downtime_details($host_id, $period = 'daily') {
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
    
    // Add date range information
    $date_range = format_period_date_range($period);
    
    $current_host = db_fetch_row_prepared("
        SELECT id, hostname, description, status, status_event_count, 
                status_fail_date, status_rec_date, snmp_community, snmp_version,
                availability, cur_time, avg_time, total_polls, failed_polls
        FROM host 
        WHERE id = ?", array($host_id));
    if (!$current_host) {
        return array(
            'total_downtime_seconds' => 0,
            'downtime_incidents' => 0,
            'downtime_details' => array(),
            'period_seconds' => $period_seconds,
            'current_status' => 0,
            'date_range' => $date_range,
            'period_name' => ucfirst($period)
        );
    }
    
    $total_downtime = 0;
    $downtime_incidents = array();
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
            $downtime_incidents[] = array(
                'start_time' => date('Y-m-d H:i:s', $fail_time),
                'end_time' => $current_host['status'] == 3 ? date('Y-m-d H:i:s', $rec_time) : 'ongoing',
                'duration' => $downtime_duration,
                'status' => $current_host['status'],
                'event_count' => $current_host['status_event_count']
            );
        }
    }
    if ($current_host['status'] != 3) {
        $current_downtime_start = $current_host['status_fail_date'] && $current_host['status_fail_date'] != '0000-00-00 00:00:00'
                                ? strtotime($current_host['status_fail_date'])
                                : $start_time;
        $current_downtime = $now - $current_downtime_start;
        $downtime_incidents[] = array(
            'start_time' => date('Y-m-d H:i:s', $current_downtime_start),
            'end_time' => 'ongoing',
            'duration' => $current_downtime,
            'status' => $current_host['status'],
            'event_count' => $current_host['status_event_count']
        );
        $total_downtime += $current_downtime;
    }
    return array(
        'total_downtime_seconds' => $total_downtime,
        'downtime_incidents' => count($downtime_incidents),
        'downtime_details' => $downtime_incidents,
        'period_seconds' => $period_seconds,
        'current_status' => $current_host['status'],
        'availability_calculated' => $availability_percent,
        'total_polls' => $current_host['total_polls'],
        'failed_polls' => $current_host['failed_polls'],
        'response_time' => $current_host['cur_time'],
        'avg_response_time' => $current_host['avg_time'],
        'host_info' => $current_host,
        'date_range' => $date_range,
        'period_name' => ucfirst($period)
    );
}

function get_host_availability_data($host_id, $period = 'daily') {
    $downtime_info = get_host_downtime_details($host_id, $period);
    $availability_percent = 0;
    if ($downtime_info['period_seconds'] > 0) {
        $uptime_seconds = $downtime_info['period_seconds'] - $downtime_info['total_downtime_seconds'];
        $availability_percent = max(0, min(100, ($uptime_seconds / $downtime_info['period_seconds']) * 100));
    }
    if ($downtime_info['availability_calculated'] > 0) {
        $availability_percent = $downtime_info['availability_calculated'];
    }
    return array(
        'host' => $downtime_info['host_info'],
        'availability_percent' => round($availability_percent, 2),
        'downtime_seconds' => $downtime_info['total_downtime_seconds'],
        'downtime_incidents' => $downtime_info['downtime_incidents'],
        'downtime_details' => $downtime_info['downtime_details'],
        'status' => $downtime_info['current_status'],
        'response_time' => $downtime_info['response_time'],
        'avg_response_time' => $downtime_info['avg_response_time'],
        'total_polls' => $downtime_info['total_polls'],
        'failed_polls' => $downtime_info['failed_polls'],
        'polling_success_rate' => $downtime_info['total_polls'] > 0 ? 
            round((($downtime_info['total_polls'] - $downtime_info['failed_polls']) / $downtime_info['total_polls']) * 100, 2) : 0,
        'date_range' => $downtime_info['date_range'],
        'period_name' => $downtime_info['period_name']
    );
}

function get_host_availability_data_all_periods($host_id){
    $periods = array('daily', 'weekly', 'monthly', 'yearly');
    $result = array();
    foreach($periods as $p){
        $result[$p] = get_host_availability_data($host_id, $p);
    }
    return $result;
}

function format_downtime_duration($seconds) {
    $seconds = (int)$seconds;
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . ' hari';
    if ($hours > 0) $parts[] = $hours . ' jam';
    if ($minutes > 0) $parts[] = $minutes . ' menit';
    if ($secs > 0 || empty($parts)) $parts[] = $secs . ' detik';
    return implode(' ', $parts);
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

function get_all_hosts() {
    return db_fetch_assoc('SELECT id, hostname, description, status, disabled FROM host ORDER BY description');
}

function render_availability_dashboard_accordion() {
    global $config;
    $period = isset($_GET['period']) ? $_GET['period'] : 'daily';
    $hosts = get_all_hosts();

    $hosts_data = array();
    $total_hosts = 0; $up_hosts = 0; $down_hosts = 0; $disabled_hosts = 0; $total_availability = 0;
    
    foreach($hosts as $h) {
        if ($h['disabled'] == 'on') {
            $disabled_hosts++;
            continue;
        }
        $periods = get_host_availability_data_all_periods($h['id']);
        $d = $periods['daily'];
        $hosts_data[] = array('host' => $h, 'periods' => $periods);
        $total_hosts++;
        if ($d['status'] == 3) {
            $up_hosts++;
        } else {
            $down_hosts++;
        }
        $total_availability += $d['availability_percent'];
    }
    $avg_availability = $total_hosts > 0 ? round($total_availability / $total_hosts, 2) : 0;
    ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<link rel="stylesheet" href="dashboard.css">
<div class="dashboard-container">
    <div class="dashboard-header">
        <h2><i class="fa fa-tachometer-alt"></i> Network Monitoring Dashboard</h2>
        <p>Real-time availability monitoring and downtime tracking - Cacti Version 1.2.15</p>
    </div>
    <!-- <div class="period-selector">
        <a href="?period=daily" class="<?php echo $period == 'daily' ? 'active' : ''; ?>"><i class="fa fa-calendar-day"></i> Daily</a>
        <a href="?period=weekly" class="<?php echo $period == 'weekly' ? 'active' : ''; ?>"><i class="fa fa-calendar-week"></i> Weekly</a>
        <a href="?period=monthly" class="<?php echo $period == 'monthly' ? 'active' : ''; ?>"><i class="fa fa-calendar-alt"></i> Monthly</a>
        <a href="?period=yearly" class="<?php echo $period == 'yearly' ? 'active' : ''; ?>"><i class="fa fa-calendar"></i> Yearly</a>
    </div> -->
    <div style="margin-bottom: 20px;">
    <button id="exportCsvBtn" onclick="exportToCSV()" style="
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(40, 167, 69, 0.4)'" 
        onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(40, 167, 69, 0.3)'"
        onmousedown="this.style.transform='translateY(0)'"
        onmouseup="this.style.transform='translateY(-2px)'">
            <i class="fa fa-download" style="font-size: 16px;"></i>
            Export to CSV
        </button>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value"><?php echo $total_hosts; ?></div><div class="stat-label">Total Devices</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $up_hosts; ?></div><div class="stat-label">Online Devices</div></div>
        <div class="stat-card <?php echo $down_hosts > 0 ? 'danger' : ''; ?>"><div class="stat-value"><?php echo $down_hosts; ?></div><div class="stat-label">Offline Devices</div></div>
        <div class="stat-card info"><div class="stat-value"><?php echo $disabled_hosts; ?></div><div class="stat-label">Disabled Devices</div></div>
        <div class="stat-card <?php echo $avg_availability < 95 ? 'warning' : ''; ?>"><div class="stat-value"><?php echo $avg_availability; ?>%</div><div class="stat-label">Average Availability</div></div>
    </div>
    <div class="filter-container" style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <input type="text" id="searchHost" placeholder="🔍 Search Device Name or IP Address..." style="padding: 8px 12px; border-radius: 6px; border: 1px solid #ccc; min-width: 280px;">
        <select id="statusFilter" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #ccc;">
            <option value="">All Status</option>
            <option value="Up">Up</option>
            <option value="Down">Down</option>
            <option value="Recovering">Recovering</option>
            <option value="Unknown">Unknown</option>
        </select>
        <select id="availabilityFilter" style="padding: 8px 12px; border-radius: 6px; border: 1px solid #ccc;">
            <option value="">All Availability</option>
            <option value="99">>= 99%</option>
            <option value="95">>= 95%</option>
            <option value="90">>= 90%</option>
            <option value="0">< 90%</option>
        </select>
        <button onclick="clearAllFilters()" style="
            padding: 8px 16px; 
            border-radius: 6px; 
            border: 1px solid #6c757d; 
            background: #6c757d; 
            color: white; 
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        " onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
            <i class="fa fa-times"></i> Clear Filters
        </button>
    </div>

    <div id="accordion-dashboard">
    <?php foreach($hosts_data as $row): 
        $host_id = $row['host']['id'];
        $desc = htmlspecialchars($row['host']['description']);
        $ip = htmlspecialchars($row['host']['hostname']);
        $ps = $row['periods'];
        $current_period_data = $ps[$period];
        $status_class = $current_period_data['status'] == 3 ? 'status-up' : 'status-down';
        $status_text = get_host_status_text($current_period_data['status']);
        $status_icon = $current_period_data['status'] == 3 ? 'fa-check-circle' : 'fa-times-circle';
    ?>
        <div class="accordion" id="device_<?php echo $host_id; ?>"
            data-host="<?php echo $desc; ?>"
            data-ip="<?php echo $ip; ?>"
            data-availability="<?php echo $current_period_data['availability_percent']; ?>"
            data-downtime="<?php echo format_downtime_duration($current_period_data['downtime_seconds']); ?>"
            data-incidents="<?php echo $current_period_data['downtime_incidents']; ?>"
            data-status="<?php echo get_host_status_text($current_period_data['status']); ?>"
        >

            <div class="accordion-header" onclick="toggleAccordion(this)">
                <span>
                    <i class="fa fa-server"></i> 
                    <?php echo $desc; ?> 
                    <small style="color:#666;">(<?php echo $ip; ?>)</small>
                </span>
                <span>
                    <span class="status-badge <?php echo $status_class; ?>">
                        <i class="fa <?php echo $status_icon; ?>"></i> <?php echo $status_text; ?>
                    </span>
                    <i class="fa fa-chevron-down"></i>
                </span>
            </div>
            <div class="accordion-content">
                <div class="device-info-grid">
                    <div class="info-card">
                        <h4><i class="fa fa-chart-pie"></i> Status Periode <?php echo $current_period_data['period_name']; ?></h4>
                        <div class="period-info" style="background: #e9ecef; padding: 8px; border-radius: 4px; margin-bottom: 15px;">
                            <strong>Periode:</strong> <?php echo $current_period_data['date_range']; ?>
                        </div>
                        <div class="chart-container">
                            <canvas id="pie_<?php echo $host_id; ?>_<?php echo $period; ?>" width="120" height="120"></canvas>
                        </div>
                        <div style="text-align: center; margin-top: 10px;">
                            <strong>Availability: <?php echo $current_period_data['availability_percent']; ?>%</strong>
                        </div>
                        <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px;">
                            <h5><i class="fa fa-info-circle"></i> Current Status Detail</h5>
                            <div><strong>Status:</strong> <?php echo get_host_status_text($current_period_data['status']); ?></div>
                            <div><strong>Response Time:</strong> <?php echo round($current_period_data['response_time'], 2); ?> ms</div>
                            <div><strong>Avg Response Time:</strong> <?php echo round($current_period_data['avg_response_time'], 2); ?> ms</div>
                            <div><strong>Total Polls:</strong> <?php echo $current_period_data['total_polls']; ?></div>
                            <div><strong>Failed Polls:</strong> <?php echo $current_period_data['failed_polls']; ?></div>
                            <div><strong>Polling Success Rate:</strong> <?php echo $current_period_data['polling_success_rate']; ?>%</div>
                        </div>
                        
                        <!-- Updated downtime info section with date range -->
                        <div class="downtime-info <?php echo $current_period_data['downtime_seconds'] == 0 ? 'no-downtime' : ''; ?>">
                            <div><strong>Periode Monitoring:</strong> <?php echo $current_period_data['date_range']; ?></div>
                            <div><strong>Total Downtime:</strong> <?php echo format_downtime_duration($current_period_data['downtime_seconds']); ?></div>
                            <div><strong>Jumlah Incidents:</strong> <?php echo $current_period_data['downtime_incidents']; ?></div>
                            <?php if ($current_period_data['downtime_seconds'] > 0 && $current_period_data['downtime_incidents'] > 0): ?>
                            <div><strong>Rata-rata per incident:</strong> <?php echo format_downtime_duration($current_period_data['downtime_seconds'] / $current_period_data['downtime_incidents']); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($current_period_data['downtime_details'])): ?>
                        <div class="downtime-details">
                            <h5><i class="fa fa-exclamation-triangle"></i> Detail Downtime Incidents (<?php echo $current_period_data['date_range']; ?>):</h5>
                            <?php foreach($current_period_data['downtime_details'] as $incident): ?>
                            <div class="downtime-incident">
                                <div><strong>Mulai:</strong> <?php echo $incident['start_time']; ?></div>
                                <div><strong>Selesai:</strong> <?php echo $incident['end_time']; ?></div>
                                <div><strong>Durasi:</strong> <?php echo format_downtime_duration($incident['duration']); ?></div>
                                <div><strong>Status:</strong> <?php echo get_host_status_text($incident['status']); ?></div>
                                <?php if (isset($incident['event_count'])): ?>
                                <div><strong>Event Count:</strong> <?php echo $incident['event_count']; ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="traffic-preview">
                        <h4><i class="fa fa-signal"></i> Traffic Chart Preview</h4>
                        <?php
                        $graphs = db_fetch_assoc_prepared("
                            SELECT gl.id, gl.title_cache, gt.name as template_name
                            FROM graph_local gl
                            LEFT JOIN graph_templates gt ON gl.graph_template_id = gt.id
                            WHERE gl.host_id = ? AND gl.title_cache != ''
                            ORDER BY gl.title_cache LIMIT 10
                        ", array($host_id));
                        if (empty($graphs)) {
                            $graphs = db_fetch_assoc_prepared("
                                SELECT DISTINCT gl.id, 
                                CONCAT(dt.name, ' - ', dtr.data_source_name) as title_cache,
                                'Data Source' as template_name
                                FROM graph_local gl
                                LEFT JOIN graph_templates_item gti ON gl.id = gti.local_graph_id
                                LEFT JOIN data_template_rrd dtr ON gti.task_item_id = dtr.id
                                LEFT JOIN data_local dl ON dtr.local_data_id = dl.id
                                LEFT JOIN data_template dt ON dl.data_template_id = dt.id
                                WHERE gl.host_id = ? AND dt.name IS NOT NULL
                                ORDER BY dt.name LIMIT 10", 
                                array($host_id)
                            );
                        }
                        if (empty($graphs)) {
                            echo "<p style='color: #666; text-align: center; margin: 20px 0;'>No graphs available for this device</p>";
                        } else {
                            echo '<div class="traffic-grid">';
                            foreach($graphs as $graph) {
                                $graph_id = $graph['id'];
                                $graph_title = !empty($graph['title_cache']) ? htmlspecialchars($graph['title_cache']) : htmlspecialchars($desc);
                                $template_name = !empty($graph['template_name']) ? htmlspecialchars($graph['template_name']) : 'Unknown';
                                echo '<div class="traffic-item">';
                                echo "<div style='font-size:0.9em; margin-bottom:5px; font-weight:500;'>$graph_title</div>";
                                echo "<div style='font-size:0.8em; color:#666; margin-bottom:8px;'>Template: $template_name</div>";
                                echo "<a href='graph.php?action=view&local_graph_id={$graph_id}' target='_blank'><img src='graph_image.php?local_graph_id={$graph_id}&rra_id=0' alt='$graph_title' style='max-width:100%; height:auto;'></a>";
                                echo '</div>';
                            }
                            echo '</div>';
                        }
                        ?>
                    </div>
                </div>
                <hr style="margin: 20px 0;">
                <h4><i class="fa fa-history"></i> Historical Availability</h4>
                <div class="period-charts">
                    <?php foreach(array('daily', 'weekly', 'monthly', 'yearly') as $p): ?>
                    <div class="period-chart">
                        <h5><?php echo ucfirst($p); ?></h5>
                        <div class="period-date-range" style="font-size: 0.8em; color: #666; margin-bottom: 8px;">
                            <?php echo $ps[$p]['date_range']; ?>
                        </div>
                        <div class="chart-container">
                            <canvas id="pie_<?php echo $host_id; ?>_<?php echo $p; ?>" width="80" height="80"></canvas>
                        </div>
                        <div style="font-size: 0.9em; margin-top: 10px;">
                            <strong><?php echo $ps[$p]['availability_percent']; ?>%</strong><br>
                            <small>Down: <?php echo format_downtime_duration($ps[$p]['downtime_seconds']); ?></small><br>
                            <small>Incidents: <?php echo $ps[$p]['downtime_incidents']; ?></small><br>
                            <small>Polls: <?php echo $ps[$p]['total_polls']; ?>/<?php echo $ps[$p]['failed_polls']; ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<script>
function toggleAccordion(header) {
    var accordion = header.parentElement;
    var wasActive = accordion.classList.contains("active");
    var allAccordions = document.querySelectorAll('.accordion');
    allAccordions.forEach(function(acc) {
        acc.classList.remove("active");
        var icon = acc.querySelector('.fa-chevron-down, .fa-chevron-up');
        if (icon) {
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    });
    if (!wasActive) {
        accordion.classList.add("active");
        var icon = header.querySelector('.fa-chevron-down, .fa-chevron-up');
        if (icon) {
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        }
    }
}
function renderAllCharts() {
    <?php foreach($hosts_data as $row):
        $host_id = $row['host']['id'];
        $ps = $row['periods'];
        foreach(array('daily','weekly','monthly','yearly') as $p):
            $percent = isset($ps[$p]['availability_percent']) && is_numeric($ps[$p]['availability_percent'])
                ? $ps[$p]['availability_percent'] : 0;
            $down = 100 - $percent;
    ?>
    (function() {
        var canvasId = "pie_<?php echo $host_id . '_' . $p; ?>";
        var el = document.getElementById(canvasId);
        if (!el) return;
        var percent = <?php echo $percent; ?>;
        var down = <?php echo $down; ?>;
        var totalPolls = <?php echo isset($ps[$p]['total_polls']) ? (int)$ps[$p]['total_polls'] : 0; ?>;
        if (totalPolls === 0 || isNaN(percent) || isNaN(down) || percent < 0 || down < 0 || (percent === 0 && down === 0)) {
            el.parentNode.innerHTML = '<div style="color:#aaa;font-size:13px;text-align:center;margin-top:30px">No Data</div>';
            return;
        }
        var upColor = percent >= 99 ? '#28a745' : (percent >= 95 ? '#ffc107' : '#fd7e14');
        var downColor = '#dc3545';
        new Chart(el, {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [percent, down],
                    backgroundColor: [upColor, downColor],
                    borderWidth: 2,
                    borderColor: '#fff'
                }],
                labels: ['Up Time', 'Down Time']
            },
            options: {
                cutout: '65%',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var label = context.label || '';
                                var value = context.parsed || 0;
                                return label + ': ' + value.toFixed(1) + '%';
                            }
                        }
                    }
                }
            }
        });
    })();
    <?php endforeach; endforeach; ?>
}
document.addEventListener("DOMContentLoaded", function() {
    renderAllCharts();
});
if (document.getElementById('menu_main_console')) {
    document.getElementById('menu_main_console').addEventListener('click', function() {
        setTimeout(function() {
            renderAllCharts();
        }, 100);
    });
}

// Fallback function untuk CSV export jika XLSX gagal
// CSV export function
// Enhanced CSV export function with detailed downtime information
function exportToCSV() {
    try {
        var csvContent = [];
        
        // Enhanced headers with more detailed information
        var headers = [
            "Device Name", 
            "IP Address", 
            "Period Type",
            "Period Date Range",
            "Current Status",
            "Availability (%)",
            "Total Downtime Duration",
            "Downtime Incidents Count",
            "Response Time (ms)",
            "Avg Response Time (ms)",
            "Total Polls",
            "Failed Polls",
            "Polling Success Rate (%)",
            "Downtime Start DateTime",
            "Downtime End DateTime", 
            "Downtime Duration Detail",
            "Incident Status",
            "Event Count"
        ];
        
        csvContent.push(headers.join(","));
        
        // Get all accordion elements
        var accordions = document.querySelectorAll('.accordion');
        
        accordions.forEach(function(acc) {
            // Get basic device information
            var deviceName = (acc.getAttribute('data-host') || '').replace(/"/g, '""');
            var ipAddress = (acc.getAttribute('data-ip') || '').replace(/"/g, '""');
            var currentStatus = (acc.getAttribute('data-status') || '').replace(/"/g, '""');
            var availability = (acc.getAttribute('data-availability') || '').replace(/"/g, '""');
            var totalDowntime = (acc.getAttribute('data-downtime') || '').replace(/"/g, '""');
            var incidentsCount = (acc.getAttribute('data-incidents') || '').replace(/"/g, '""');
            
            // Extract detailed information from the accordion content
            var content = acc.querySelector('.accordion-content');
            if (content) {
                // Get period information for all periods (daily, weekly, monthly, yearly)
                var periodCharts = content.querySelectorAll('.period-chart');
                
                // If no period charts, get current period info
                if (periodCharts.length === 0) {
                    // Get current period data from the main info card
                    var infoCard = content.querySelector('.info-card');
                    if (infoCard) {
                        var periodInfo = extractPeriodInfo(infoCard, 'current');
                        var downtimeDetails = extractDowntimeDetails(content);
                        
                        if (downtimeDetails.length > 0) {
                            // Create row for each downtime incident
                            downtimeDetails.forEach(function(incident) {
                                addCSVRow(csvContent, deviceName, ipAddress, periodInfo, incident);
                            });
                        } else {
                            // No downtime incidents, add single row with basic info
                            var emptyIncident = {
                                startTime: 'No Downtime',
                                endTime: 'No Downtime',
                                duration: '0 detik',
                                status: currentStatus,
                                eventCount: '0'
                            };
                            addCSVRow(csvContent, deviceName, ipAddress, periodInfo, emptyIncident);
                        }
                    }
                } else {
                    // Process each period (daily, weekly, monthly, yearly)
                    periodCharts.forEach(function(chart) {
                        var periodType = chart.querySelector('h5').textContent.trim();
                        var periodDateRange = chart.querySelector('.period-date-range');
                        var dateRange = periodDateRange ? periodDateRange.textContent.trim() : 'Unknown';
                        
                        var periodInfo = {
                            type: periodType,
                            dateRange: dateRange,
                            availability: extractTextFromElement(chart, 'strong'),
                            downtime: extractDowntimeFromChart(chart),
                            incidents: extractIncidentsFromChart(chart),
                            polls: extractPollsFromChart(chart),
                            responseTime: 'N/A',
                            avgResponseTime: 'N/A',
                            successRate: 'N/A'
                        };
                        
                        // For historical periods, we might not have detailed downtime info
                        // so we add a summary row
                        var summaryIncident = {
                            startTime: 'Period Summary',
                            endTime: 'Period Summary', 
                            duration: periodInfo.downtime,
                            status: currentStatus,
                            eventCount: periodInfo.incidents
                        };
                        
                        addCSVRow(csvContent, deviceName, ipAddress, periodInfo, summaryIncident);
                    });
                }
                
                // Also add current detailed status if available
                var currentStatusDetail = content.querySelector('.info-card');
                if (currentStatusDetail) {
                    var currentPeriodInfo = extractCurrentPeriodDetailedInfo(currentStatusDetail);
                    var downtimeDetails = extractDowntimeDetails(content);
                    
                    if (downtimeDetails.length > 0) {
                        downtimeDetails.forEach(function(incident) {
                            addCSVRow(csvContent, deviceName, ipAddress, currentPeriodInfo, incident);
                        });
                    }
                }
            }
        });
        
        // Create and download CSV
        var csvString = csvContent.join("\n");
        var blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        
        // Generate filename with current date and time
        var now = new Date();
        var filename = 'cacti_detailed_monitoring_' + 
                    now.getFullYear() + 
                    ('0' + (now.getMonth() + 1)).slice(-2) + 
                    ('0' + now.getDate()).slice(-2) + '_' +
                    ('0' + now.getHours()).slice(-2) + 
                    ('0' + now.getMinutes()).slice(-2) + 
                    '.csv';
        
        // Create download link
        if (window.navigator && window.navigator.msSaveOrOpenBlob) {
            // For IE
            window.navigator.msSaveOrOpenBlob(blob, filename);
        } else {
            var link = document.createElement('a');
            if (link.download !== undefined) {
                var url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // Show success message
                showExportMessage('Detailed CSV file exported successfully: ' + filename, 'success');
            }
        }
        
    } catch (error) {
        console.error('Enhanced CSV Export error:', error);
        showExportMessage('Failed to export detailed CSV file: ' + error.message, 'error');
    }
}

// Helper function to add CSV row
function addCSVRow(csvContent, deviceName, ipAddress, periodInfo, incident) {
    var row = [
        '"' + deviceName + '"',
        '"' + ipAddress + '"',
        '"' + (periodInfo.type || 'Current') + '"',
        '"' + (periodInfo.dateRange || 'N/A') + '"',
        '"' + (incident.status || 'Unknown') + '"',
        '"' + (periodInfo.availability || 'N/A') + '"',
        '"' + (periodInfo.downtime || 'N/A') + '"',
        '"' + (periodInfo.incidents || 'N/A') + '"',
        '"' + (periodInfo.responseTime || 'N/A') + '"',
        '"' + (periodInfo.avgResponseTime || 'N/A') + '"',
        '"' + (periodInfo.polls || 'N/A') + '"',
        '"' + (periodInfo.failedPolls || 'N/A') + '"',
        '"' + (periodInfo.successRate || 'N/A') + '"',
        '"' + (incident.startTime || 'N/A') + '"',
        '"' + (incident.endTime || 'N/A') + '"',
        '"' + (incident.duration || 'N/A') + '"',
        '"' + (incident.status || 'N/A') + '"',
        '"' + (incident.eventCount || 'N/A') + '"'
    ];
    
    csvContent.push(row.join(","));
}

// Helper function to extract current period detailed information
function extractCurrentPeriodDetailedInfo(infoCard) {
    var periodInfo = {};
    
    // Extract period type and date range
    var periodElement = infoCard.querySelector('h4');
    if (periodElement) {
        periodInfo.type = periodElement.textContent.replace(/.*Status Periode\s+/, '').trim();
    }
    
    var periodDateElement = infoCard.querySelector('.period-info');
    if (periodDateElement) {
        periodInfo.dateRange = periodDateElement.textContent.replace('Periode:', '').trim();
    }
    
    // Extract availability
    var availabilityElement = infoCard.querySelector('strong');
    if (availabilityElement && availabilityElement.textContent.includes('Availability:')) {
        periodInfo.availability = availabilityElement.textContent.replace('Availability:', '').trim();
    }
    
    // Extract detailed status information
    var statusDetails = infoCard.querySelectorAll('div');
    statusDetails.forEach(function(detail) {
        var text = detail.textContent;
        if (text.includes('Response Time:')) {
            periodInfo.responseTime = text.replace('Response Time:', '').trim();
        } else if (text.includes('Avg Response Time:')) {
            periodInfo.avgResponseTime = text.replace('Avg Response Time:', '').trim();
        } else if (text.includes('Total Polls:')) {
            periodInfo.polls = text.replace('Total Polls:', '').trim();
        } else if (text.includes('Failed Polls:')) {
            periodInfo.failedPolls = text.replace('Failed Polls:', '').trim();
        } else if (text.includes('Polling Success Rate:')) {
            periodInfo.successRate = text.replace('Polling Success Rate:', '').trim();
        } else if (text.includes('Total Downtime:')) {
            periodInfo.downtime = text.replace('Total Downtime:', '').trim();
        } else if (text.includes('Jumlah Incidents:')) {
            periodInfo.incidents = text.replace('Jumlah Incidents:', '').trim();
        }
    });
    
    return periodInfo;
}

// Helper function to extract downtime details
function extractDowntimeDetails(content) {
    var downtimeDetails = [];
    var downtimeIncidents = content.querySelectorAll('.downtime-incident');
    
    downtimeIncidents.forEach(function(incident) {
        var detail = {};
        var details = incident.querySelectorAll('div');
        
        details.forEach(function(detailDiv) {
            var text = detailDiv.textContent;
            if (text.includes('Mulai:')) {
                detail.startTime = text.replace('Mulai:', '').trim();
            } else if (text.includes('Selesai:')) {
                detail.endTime = text.replace('Selesai:', '').trim();
            } else if (text.includes('Durasi:')) {
                detail.duration = text.replace('Durasi:', '').trim();
            } else if (text.includes('Status:')) {
                detail.status = text.replace('Status:', '').trim();
            } else if (text.includes('Event Count:')) {
                detail.eventCount = text.replace('Event Count:', '').trim();
            }
        });
        
        downtimeDetails.push(detail);
    });
    
    return downtimeDetails;
}

// Helper function to extract text from element
function extractTextFromElement(parent, selector) {
    var element = parent.querySelector(selector);
    return element ? element.textContent.trim() : 'N/A';
}

// Helper function to extract downtime from chart
function extractDowntimeFromChart(chart) {
    var smalls = chart.querySelectorAll('small');
    for (var i = 0; i < smalls.length; i++) {
        if (smalls[i].textContent.includes('Down:')) {
            return smalls[i].textContent.replace('Down:', '').trim();
        }
    }
    return 'N/A';
}

// Helper function to extract incidents from chart
function extractIncidentsFromChart(chart) {
    var smalls = chart.querySelectorAll('small');
    for (var i = 0; i < smalls.length; i++) {
        if (smalls[i].textContent.includes('Incidents:')) {
            return smalls[i].textContent.replace('Incidents:', '').trim();
        }
    }
    return 'N/A';
}

// Helper function to extract polls from chart
function extractPollsFromChart(chart) {
    var smalls = chart.querySelectorAll('small');
    for (var i = 0; i < smalls.length; i++) {
        if (smalls[i].textContent.includes('Polls:')) {
            return smalls[i].textContent.replace('Polls:', '').trim();
        }
    }
    return 'N/A';
}

// Helper function to extract period info
function extractPeriodInfo(infoCard, type) {
    return {
        type: type,
        dateRange: 'Current Period',
        availability: 'N/A',
        downtime: 'N/A',
        incidents: 'N/A',
        polls: 'N/A',
        failedPolls: 'N/A',
        responseTime: 'N/A',
        avgResponseTime: 'N/A',
        successRate: 'N/A'
    };
}

// Function to show export messages (keeping the original)
function showExportMessage(message, type) {
    var messageDiv = document.createElement('div');
    messageDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 15px 20px;
        border-radius: 5px;
        color: white;
        font-weight: bold;
        z-index: 9999;
        max-width: 300px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transition: all 0.3s ease;
    `;
    
    if (type === 'success') {
        messageDiv.style.backgroundColor = '#28a745';
        messageDiv.innerHTML = '<i class="fa fa-check-circle" style="margin-right: 8px;"></i>' + message;
    } else {
        messageDiv.style.backgroundColor = '#dc3545';
        messageDiv.innerHTML = '<i class="fa fa-exclamation-triangle" style="margin-right: 8px;"></i>' + message;
    }
    
    document.body.appendChild(messageDiv);
    
    // Auto remove after 5 seconds
    setTimeout(function() {
        messageDiv.style.opacity = '0';
        messageDiv.style.transform = 'translateX(100%)';
        setTimeout(function() {
            if (messageDiv.parentNode) {
                document.body.removeChild(messageDiv);
            }
        }, 300);
    }, 5000);
    
    // Remove on click
    messageDiv.onclick = function() {
        messageDiv.style.opacity = '0';
        messageDiv.style.transform = 'translateX(100%)';
        setTimeout(function() {
            if (messageDiv.parentNode) {
                document.body.removeChild(messageDiv);
            }
        }, 300);
    };
}

// Filtering function - REPLACE the existing filterAccordion function
function filterAccordion() {
    const searchText = document.getElementById('searchHost').value.toLowerCase().trim();
    const statusVal = document.getElementById('statusFilter').value.trim();
    const availVal = document.getElementById('availabilityFilter').value.trim();

    document.querySelectorAll('.accordion').forEach(acc => {
        const host = (acc.getAttribute('data-host') || '').toLowerCase();
        const ip = (acc.getAttribute('data-ip') || '').toLowerCase();
        const status = (acc.getAttribute('data-status') || '').trim();
        const availability = parseFloat(acc.getAttribute('data-availability')) || 0;

        let match = true;

        // Filter by search text (search in both hostname and IP)
        if (searchText) {
            const searchMatch = host.includes(searchText) || ip.includes(searchText);
            if (!searchMatch) match = false;
        }

        // Filter by status (empty means show all)
        if (statusVal && statusVal !== '' && status !== statusVal) {
            match = false;
        }

        // Filter by availability (empty means show all)
        if (availVal && availVal !== '') {
            if (availVal === '99' && availability < 99) match = false;
            else if (availVal === '95' && availability < 95) match = false;
            else if (availVal === '90' && availability < 90) match = false;
            else if (availVal === '0' && availability >= 90) match = false;
        }

        acc.style.display = match ? 'block' : 'none';
    });

    // Update results count
    updateFilterResults();
}

// Add this new function to show filter results count
function updateFilterResults() {
    const totalDevices = document.querySelectorAll('.accordion').length;
    const visibleDevices = document.querySelectorAll('.accordion[style="display: block"], .accordion:not([style*="display: none"])').length;
    
    let resultDiv = document.getElementById('filter-results');
    if (!resultDiv) {
        resultDiv = document.createElement('div');
        resultDiv.id = 'filter-results';
        resultDiv.style.cssText = `
            margin: 10px 0;
            padding: 8px 12px;
            background: #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            color: #495057;
        `;
        
        const filterContainer = document.querySelector('.filter-container');
        filterContainer.parentNode.insertBefore(resultDiv, filterContainer.nextSibling);
    }
    
    if (visibleDevices === totalDevices) {
        resultDiv.innerHTML = `<i class="fa fa-info-circle"></i> Showing all ${totalDevices} devices`;
    } else {
        resultDiv.innerHTML = `<i class="fa fa-filter"></i> Showing ${visibleDevices} of ${totalDevices} devices`;
    }
}

// Clear all filters function
function clearAllFilters() {
    document.getElementById('searchHost').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('availabilityFilter').value = '';
    filterAccordion();
}

// REPLACE the existing DOMContentLoaded event listener for filters
document.addEventListener('DOMContentLoaded', function() {
    // Attach filter listeners
    ['searchHost', 'statusFilter', 'availabilityFilter'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', filterAccordion);
            element.addEventListener('change', filterAccordion);
            element.addEventListener('keyup', filterAccordion);
        }
    });
    
    // Initial filter results display
    setTimeout(updateFilterResults, 100);
});

</script>
<?php
}

render_external_links('FRONTTOP'); 
if (read_config_option('hide_console') != 'on') { 
?> 
<table class='cactiTable'> 
    <tr class='tableRow'> 
        <td class='textAreaNotes top left'> 
            <?php print __('You are now logged into <a href="%s"><b>Cacti</b></a>. You can follow these basic steps to get started.', 'about.php');?> 
            <ul> 
                <li><?php print __('<a href="%s">Create devices</a> for network', 'host.php');?></li> 
                <li><?php print __('<a href="%s">Create graphs</a> for your new devices', 'graphs_new.php');?></li> 
                <li><?php print __('<a href="%s">View</a> your new graphs', $config['url_path'] . 'graph_view.php');?></li> 
            </ul> 
        </td> 
        <td class='textAreaNotes top right'> 
            <strong><?php print get_cacti_version_text();?></strong> 
        </td> 
    </tr> 
    <?php if (isset($config['poller_id']) && $config['poller_id'] > 1) {?> 
    <tr class='tableRow'><td colspan='2'><hr></td></tr> 
    <tr class='tableRow'> 
        <td colspan='2'> 
            <strong><?php print __('Remote Data Collector Status:');?></strong> 
            <?php print '<i>' . (isset($config['connection']) && $config['connection'] == 'online' ? __('Online') : (isset($config['connection']) && $config['connection'] == 'recovery' ? __('Recovery') : __('Offline'))) . '</i>';?> 
        </td> 
    </tr> 
    <?php } ?> 
</table> 
<?php 
} 
render_availability_dashboard_accordion();
render_external_links('FRONT'); 
bottom_footer();
?>
