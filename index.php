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
            'current_status' => 0
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
        'host_info' => $current_host
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
            round((($downtime_info['total_polls'] - $downtime_info['failed_polls']) / $downtime_info['total_polls']) * 100, 2) : 0
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
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value"><?php echo $total_hosts; ?></div><div class="stat-label">Total Devices</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $up_hosts; ?></div><div class="stat-label">Online Devices</div></div>
        <div class="stat-card <?php echo $down_hosts > 0 ? 'danger' : ''; ?>"><div class="stat-value"><?php echo $down_hosts; ?></div><div class="stat-label">Offline Devices</div></div>
        <div class="stat-card info"><div class="stat-value"><?php echo $disabled_hosts; ?></div><div class="stat-label">Disabled Devices</div></div>
        <div class="stat-card <?php echo $avg_availability < 95 ? 'warning' : ''; ?>"><div class="stat-value"><?php echo $avg_availability; ?>%</div><div class="stat-label">Average Availability</div></div>
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
        <div class="accordion" id="device_<?php echo $host_id; ?>">
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
                        <h4><i class="fa fa-chart-pie"></i> Status Periode <?php echo ucfirst($period); ?></h4>
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
                        <div class="downtime-info <?php echo $current_period_data['downtime_seconds'] == 0 ? 'no-downtime' : ''; ?>">
                            <div><strong>Total Downtime:</strong> <?php echo format_downtime_duration($current_period_data['downtime_seconds']); ?></div>
                            <div><strong>Jumlah Incidents:</strong> <?php echo $current_period_data['downtime_incidents']; ?></div>
                            <?php if ($current_period_data['downtime_seconds'] > 0 && $current_period_data['downtime_incidents'] > 0): ?>
                            <div><strong>Rata-rata per incident:</strong> <?php echo format_downtime_duration($current_period_data['downtime_seconds'] / $current_period_data['downtime_incidents']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($current_period_data['downtime_details'])): ?>
                        <div class="downtime-details">
                            <h5><i class="fa fa-exclamation-triangle"></i> Detail Downtime Incidents:</h5>
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
