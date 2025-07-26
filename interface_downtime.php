<?php
// --- CONFIG LOADER ---
$root_path = dirname(__FILE__);
if (file_exists("$root_path/include/config.php")) @include_once("$root_path/include/config.php");
if (file_exists("$root_path/include/global.php")) @include_once("$root_path/include/global.php");

// --- DB COMPAT/FALLBACK ---
function is_cacti_ready() {
    return function_exists('db_fetch_assoc') && function_exists('db_fetch_assoc_prepared');
}

// Jika fungsi db_* tidak tersedia, pakai koneksi manual
if (!is_cacti_ready()) {
    // Ganti dengan koneksi DB cacti-mu
    $CACTI_DB_HOST = 'localhost';
    $CACTI_DB_USER = 'root';
    $CACTI_DB_PASS = '';
    $CACTI_DB_NAME = 'cacti';
    $GLOBALS['___mysqli_conn'] = @new mysqli($CACTI_DB_HOST, $CACTI_DB_USER, $CACTI_DB_PASS, $CACTI_DB_NAME);

    function db_fetch_assoc($sql) {
        global $___mysqli_conn;
        $result = $___mysqli_conn->query($sql);
        if (!$result) return [];
        $arr = [];
        while ($row = $result->fetch_assoc()) $arr[] = $row;
        return $arr;
    }
    function db_fetch_assoc_prepared($sql, $params = []) {
        global $___mysqli_conn;
        $stmt = $___mysqli_conn->prepare($sql);
        if (!$stmt) return [];
        if (count($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) return [];
        $arr = [];
        while ($row = $result->fetch_assoc()) $arr[] = $row;
        $stmt->close();
        return $arr;
    }
}

// --- DATA FUNCTIONS ---
function get_all_hosts() {
    return db_fetch_assoc("SELECT id, hostname, description, status, disabled FROM host WHERE disabled='0' ORDER BY description ASC");
}
function get_interfaces_by_host($host_id) {
    return db_fetch_assoc_prepared("
        SELECT dl.id, dl.name AS interface_name, dtd.status, dtd.lastdown, dtd.lastup, dtd.status_last_error
        FROM data_local dl
        LEFT JOIN data_template_data dtd ON dl.id = dtd.local_data_id
        WHERE dl.host_id = ?
        ORDER BY dl.name ASC
    ", array($host_id));
}
function format_datetime($dt) {
    if (!$dt || $dt == '0000-00-00 00:00:00') return '-';
    return date('Y-m-d H:i:s', strtotime($dt));
}
function get_interface_stats($interfaces) {
    $total = count($interfaces); $down = 0;
    foreach($interfaces as $iface) if ($iface['status'] != 1) $down++;
    return array('total'=>$total, 'down'=>$down, 'up'=>$total-$down);
}

// --- DASHBOARD UI ---
function render_interface_downtime_dashboard() {
    $hosts = get_all_hosts();
    $all_interfaces = [];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Interface/Port Downtime Monitoring</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
body { background: #f6f8fa; font-family:sans-serif;}
.main-box { max-width:1250px; margin:30px auto 40px auto; background:#fff; border-radius:12px; box-shadow:0 6px 32px #0002; padding:34px 28px; }
h2 { color:#185998; margin-bottom:14px; font-size:2em;}
.summary-row { display:flex; gap:22px; margin: 0 0 16px 0; }
.summary-card { background:#f5fbff; border-radius:8px; padding:15px 30px 13px 19px; box-shadow:0 2px 8px #0001; font-size:17px;}
.summary-card strong { font-size:1.45em; color:#1e7da1;}
.table-container { overflow-x:auto; margin-bottom:10px;}
.interface-table { width:100%; border-collapse:collapse; min-width:730px;}
.interface-table th, .interface-table td { border:1px solid #e3e6ea; padding:7px 13px; font-size:13.2px;}
.interface-table th { background: #e9f3fa; color:#165887; font-weight:600;}
.interface-table td { background:#fcfdff;}
.status-badge { display:inline-block; padding:2px 11px 2px 9px; border-radius:16px; font-size:13px; font-weight:bold;}
.status-badge.up { background:#e9faee; color:#27a744;}
.status-badge.down { background:#fff1f1; color:#e02c2c;}
.search-box { margin-bottom:16px; }
.search-input { border:1.5px solid #b0d5ec; border-radius:6px; padding:7px 15px; min-width:330px; font-size:15px;}
.export-btn { background: linear-gradient(94deg, #289cf0, #00a5c8 75%); color: #fff; border:none; font-size:15px; padding:10px 26px; border-radius:7px; margin:0 7px; font-weight:600; cursor:pointer; box-shadow:0 2px 12px #2eb3e62c; }
.export-btn:hover { filter: brightness(1.12); }
.fadein { animation: fadein .7s; }
@keyframes fadein { 0%{opacity:0; transform:translateY(18px);} 100%{opacity:1; transform:translateY(0);} }
@media (max-width:900px){ .main-box{padding:20px 4px;} .summary-row{flex-direction:column;gap:7px;} }
</style>
</head>
<body>
<div class="main-box fadein">
    <h2><i class="fa fa-network-wired"></i> Interface/Port Downtime Monitoring</h2>
    <div style="color:#486c89; margin-bottom:20px;">
        <b>Status semua interface/network port</b> di setiap host.<br>
        Interface <b>DOWN</b> (meskipun host UP) akan tampil eksplisit.<br>
        <span style="color:#0c8abf;">Bisa search, filter, dan export Excel/CSV.</span>
    </div>
    <!-- Search/filter box & export -->
    <div class="search-box">
        <input class="search-input" id="searchInput" placeholder="Cari host, interface, status, atau error..." onkeyup="filterTable()">
        <button class="export-btn" onclick="exportTable('xlsx')"><i class="fa fa-file-excel"></i> Export Excel</button>
        <button class="export-btn" onclick="exportTable('csv')"><i class="fa fa-file-csv"></i> Export CSV</button>
    </div>
    <?php
    // Rekap total
    $total_interface = 0; $total_down = 0;
    foreach ($hosts as $host) {
        $ifaces = get_interfaces_by_host($host['id']);
        if (!$ifaces) continue;
        $stat = get_interface_stats($ifaces);
        $total_interface += $stat['total'];
        $total_down += $stat['down'];
        foreach ($ifaces as $row) {
            $row['host_name'] = $host['description'];
            $row['host_ip'] = $host['hostname'];
            $all_interfaces[] = $row;
        }
    }
    ?>
    <div class="summary-row">
        <div class="summary-card"><strong><?php echo count($hosts); ?></strong><br>Total Host</div>
        <div class="summary-card"><strong><?php echo $total_interface; ?></strong><br>Total Interface/Port</div>
        <div class="summary-card"><strong><?php echo $total_down; ?></strong><br>Interface DOWN</div>
        <div class="summary-card"><strong><?php echo $total_interface-$total_down; ?></strong><br>Interface UP</div>
    </div>
    <div class="table-container">
        <table class="interface-table" id="mainTable">
            <thead>
                <tr>
                    <th>Host</th>
                    <th>IP Address</th>
                    <th>Nama Interface / Port</th>
                    <th>Status</th>
                    <th>Waktu Terakhir Down</th>
                    <th>Waktu Terakhir Up</th>
                    <th>Pesan Error</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($all_interfaces as $row) {
                $status = $row['status'] == 1 ?
                    '<span class="status-badge up">UP</span>' :
                    '<span class="status-badge down">DOWN</span>';
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['host_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['host_ip']) . "</td>";
                echo "<td>" . htmlspecialchars($row['interface_name']) . "</td>";
                echo "<td>$status</td>";
                echo "<td>" . format_datetime($row['lastdown']) . "</td>";
                echo "<td>" . format_datetime($row['lastup']) . "</td>";
                echo "<td>" . ($row['status_last_error'] ? htmlspecialchars($row['status_last_error']) : '-') . "</td>";
                echo "</tr>";
            }
            ?>
            </tbody>
        </table>
    </div>
    <div style="color:#888;font-size:13px;margin-top:10px;">
        Export data Excel/CSV untuk rekap audit, SLA, atau troubleshooting.<br>
        <b>Tips:</b> Ketik di kolom pencarian untuk filter semua data secara real-time.
    </div>
</div>
<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
<script>
function filterTable() {
    var input = document.getElementById("searchInput");
    var filter = input.value.toLowerCase();
    var table = document.getElementById("mainTable");
    var trs = table.getElementsByTagName("tr");
    for (var i = 1; i < trs.length; i++) {
        var tr = trs[i];
        var txt = tr.textContent.toLowerCase();
        tr.style.display = txt.indexOf(filter) > -1 ? "" : "none";
    }
}
function exportTable(type) {
    var table = document.getElementById("mainTable");
    var wb = XLSX.utils.table_to_book(table, {sheet:"InterfaceDowntime"});
    if(type == 'csv') {
        XLSX.writeFile(wb, "interface_downtime_cacti.csv", {bookType:"csv"});
    } else {
        XLSX.writeFile(wb, "interface_downtime_cacti.xlsx", {bookType:"xlsx"});
    }
}
</script>
</body>
</html>
<?php
}

// ---- RUN ----
render_interface_downtime_dashboard();
?>
