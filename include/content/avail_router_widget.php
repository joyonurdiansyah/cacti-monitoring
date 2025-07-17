    <?php
    // --- SETTING ---
    $community = "public"; 
    $routers = [
        "192.168.221.253" => "Router-AS",
        "192.168.200.200" => "ether12-indihome",
    ];
    $ifIndex = 2; // Ganti dengan index sesuai interface utama

    // --- HITUNG AVAILABILITY ---
    function get_if_status($ip, $community, $ifIndex) {
        $oid = "1.3.6.1.2.1.2.2.1.8.$ifIndex";
        $result = @snmpget($ip, $community, $oid);
        if ($result && strpos($result, 'INTEGER: 1') !== false) return 1;
        if ($result && strpos($result, 'INTEGER: 2') !== false) return 0;
        return null;
    }

    echo "<h3>Availability Uptime Router</h3>";
    echo "<table class='cactiTable'>";
    echo "<tr><th>Router/IP</th><th>Status</th><th>Last Checked</th></tr>";

    foreach ($routers as $ip => $name) {
        $status = get_if_status($ip, $community, $ifIndex);
        $icon = $status === 1 ? "🟢 UP" : ($status === 0 ? "🔴 DOWN" : "❓ Error");
        echo "<tr>
                <td>$name ($ip)</td>
                <td>$icon</td>
                <td>" . date("Y-m-d H:i:s") . "</td>
            </tr>";
    }
    echo "</table>";
    ?>