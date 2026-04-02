<?php
// --- SICHERHEITS-KONFIGURATION (SUDOERS) ----------------------------------
/*
 DER FINALE "INGO-FIX" FÜR UBUNTU 24.04 & CLOUDPANEL:
 
 1. Klicke im Dashboard auf die 'Diagnose' Kachel (Stethoskop-Icon).
 2. Notiere den Namen hinter "PHP USER IST".
 3. Editiere die sudoers Datei auf dem Host: sudo visudo
 4. Füge ganz unten folgende Zeile ein (ersetze [USER] durch den Namen aus der Diagnose):
 
 [USER] ALL=(ALL) NOPASSWD: /usr/sbin/grub-reboot, /usr/bin/systemctl reboot, /usr/sbin/lxc, /usr/bin/systemctl, /usr/bin/docker, /usr/sbin/reboot, /usr/bin/journalctl, /usr/bin/lspci, /usr/bin/ls, /usr/sbin/nginx, /usr/bin/crontab
 
 5. Speichern (Strg+O, Enter, Strg+X). Danach fließen auch die Recovery-Logs live!
*/

// --- ZENTRALE KONFIGURATION -----------------------------------------------
$config = [
    'cloudpanel' => [
        'name' => 'CloudPanel',
        'url'  => 'https://sandbox.ma.ki', 
        'port' => 8443, 
        'user' => 'admin', 
        'pass' => 'IT123_maki'
    ],
    'lxd_web' => [
        'name' => 'LXD Web UI',
        'url'  => 'https://sandbox.ma.ki',
        'port' => 9443,
        'info' => 'Anmeldung über Browser-Zertifikat'
    ],
    'portainer' => [
        'name' => 'Portainer',
        'url'  => 'https://docker-sandbox.ma.ki', 
        'port' => 9443,
        // BYPASS MACVLAN: Pingt für den Status direkt die feste Bridge-IP an!
        'check_host' => '192.168.212.2', 
        'user' => 'admin', 
        'pass' => 'IT123456_maki'
    ],
    'host_ssh' => [
        'name' => 'Host System (Bare Metal)',
        'user' => 'web',
        'pass' => 'IT123_maki',
        'info' => 'Sudo Privilegien aktiv'
    ],
    'lxc_ssh' => [
        'name' => 'LXC Container (Docker Engine)',
        'user' => 'root',
        'pass' => 'IT123_maki',
        'info' => 'Direktzugriff als Root-User'
    ],
    'ttyd_host' => [
        'name' => 'Host Terminal',
        'url'  => 'https://web-cli.sandbox.ma.ki',
        'port' => 443
    ], 
    'ttyd_docker' => [
        'name' => 'Docker Terminal',
        'url'  => 'https://docker-cli.sandbox.ma.ki',
        'port' => 443,
        // BYPASS PROXY & MACVLAN: Pingt den direkten Daemon-Port über die Bridge
        'check_host' => '192.168.212.2',
        'check_port' => 7681 
    ],
    'recovery_grub_id' => '4', 
    'lxc_docker_name'  => 'docker-sandbox', 
    'lxc_snapshot'     => 'clean-state',
    'main_domain'      => 'sandbox.ma.ki'
];

/**
 * Hilfsfunktion zur Konstruktion der vollständigen URL mit Port
 */
function buildFullUrl($item) {
    if (!isset($item['url'])) return '';
    $port = isset($item['port']) ? ':' . $item['port'] : '';
    return $item['url'] . $port;
}

// --- LIVE STATS & SERVICE LOGIC -------------------------------------------
function checkServiceStatus($item) {
    $host = isset($item['check_host']) ? $item['check_host'] : parse_url($item['url'], PHP_URL_HOST);
    $port = isset($item['check_port']) ? $item['check_port'] : $item['port'];
    
    if (!$host) return false;
    
    // Timeout auf 0.8s gesetzt, falls die Bridge Latenz hat
    $connection = @fsockopen($host, $port, $errno, $errstr, 0.8);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}

function getLiveStats($config) {
    function readCpuStats() {
        $data = @file('/proc/stat');
        $cores = [];
        if($data) {
            foreach ($data as $line) {
                if (preg_match('/^cpu(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $matches)) {
                    $index = $matches[1];
                    $total = $matches[2] + $matches[3] + $matches[4] + $matches[5];
                    $idle = $matches[5];
                    $cores[$index] = ['total' => $total, 'idle' => $idle];
                }
            }
        }
        return $cores;
    }
    $stat1 = readCpuStats();
    usleep(100000); 
    $stat2 = readCpuStats();
    $cpu_usage = [];
    foreach ($stat1 as $i => $s1) {
        if(isset($stat2[$i])) {
            $diff_total = $stat2[$i]['total'] - $s1['total'];
            $diff_idle = $stat2[$i]['idle'] - $s1['idle'];
            $cpu_usage[] = ($diff_total == 0) ? 0 : round(100 * ($diff_total - $diff_idle) / $diff_total);
        }
    }
    $memInfo = @file_get_contents('/proc/meminfo');
    preg_match('/MemTotal:\s+(\d+)/', $memInfo, $totalMatches);
    preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $availMatches);
    $memTotal = $totalMatches[1] ?? 1;
    $memAvail = $availMatches[1] ?? 0;
    
    // --- CPU TEMPERATURE ---
    $temp_raw = @shell_exec("cat /sys/class/thermal/thermal_zone2/temp 2>/dev/null | head -n 1");
    $cpu_temp = is_numeric(trim((string)$temp_raw)) ? round(trim($temp_raw) / 1000) : '--';

    // --- AUTO-HEALER TELEMETRIE (LIVE) ---
    $broken_vhosts = [];
    $ls_output = shell_exec("sudo -n ls -1 /etc/nginx/sites-enabled | grep 'broken' 2>/dev/null");
    
    if (!empty($ls_output)) {
        $files = array_filter(explode("\n", trim($ls_output)));
        foreach ($files as $file) {
            $broken_vhosts[] = basename($file);
        }
    }
    $healer_active = (count($broken_vhosts) > 0);

    $services = [
        'cloudpanel'  => checkServiceStatus($config['cloudpanel']),
        'lxd'         => checkServiceStatus($config['lxd_web']),
        'portainer'   => checkServiceStatus($config['portainer']),
        'ttyd_host'   => checkServiceStatus($config['ttyd_host']),
        'ttyd_docker' => checkServiceStatus($config['ttyd_docker'])
    ];

    return [
        'cpu_cores' => $cpu_usage,
        'cpu_temp' => $cpu_temp,
        'ram_percent' => round((($memTotal - $memAvail) / $memTotal) * 100),
        'disk_percent' => round(((disk_total_space("/") - disk_free_space("/")) / disk_total_space("/")) * 100),
        'load' => number_format(sys_getloadavg()[0], 2),
        'services' => $services,
        'healer_active' => $healer_active,
        'broken_vhosts' => $broken_vhosts
    ];
}

// --- AJAX API HANDLER -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $output = "";

    switch ($action) {
        case 'get_live_stats':
            $stats = getLiveStats($config);
            
            // --- Micro-Check für das Frühwarnsystem ---
            $nginx_test_live = shell_exec("sudo -n /usr/sbin/nginx -t 2>&1");
            $stats['nginx_emerg'] = (strpos(strtolower($nginx_test_live), '[emerg]') !== false);
            
            echo json_encode($stats);
            exit;

        case 'system_reboot': 
            shell_exec('nohup sudo -n /usr/bin/systemctl reboot > /dev/null 2>&1 &'); 
            $output = "Systemneustart wird ausgeführt."; 
            break;

        case 'factory_reset':
            $grub_id = $config['recovery_grub_id'];
            $grub_res = shell_exec("sudo -n /usr/sbin/grub-reboot $grub_id 2>&1");
            if ($grub_res && strpos($grub_res, 'password is required') !== false) {
                $output = "FEHLER: Sudo-Berechtigung verweigert. Prüfen Sie den PHP-User!";
            } else {
                shell_exec("nohup sudo -n /usr/bin/systemctl reboot > /dev/null 2>&1 &");
                $output = "RESET AKTIV: Boot-Target sda2 gesetzt. System startet neu.";
            }
            break;

        case 'get_recovery_logs':
            $output = shell_exec("sudo -n /usr/bin/journalctl -p 3..4 -n 15 --no-pager 2>&1");
            if (strpos($output, 'password is required') !== false) {
                $output = "Status: Sudo-Rechte für journalctl fehlen noch.";
            }
            break;

        case 'diagnose':
            $user = trim(shell_exec("whoami"));
            
            // Hardware Info Gathering
            $os_info = trim(shell_exec("grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '\"'"));
            $kernel = trim(shell_exec("uname -r"));
            $cpu_model = trim(shell_exec("grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2"));
            $cpu_cores = trim(shell_exec("nproc"));
            $mem_total = round(intval(shell_exec("grep MemTotal /proc/meminfo | awk '{print $2}'")) / 1024 / 1024, 2) . " GB";
            $disk_total = round(disk_total_space("/") / 1024 / 1024 / 1024, 2) . " GB";
            
            // Mainboard Versuch (DMI)
            $board_vendor = trim(@file_get_contents('/sys/class/dmi/id/board_vendor') ?: '');
            $board_name = trim(@file_get_contents('/sys/class/dmi/id/board_name') ?: '');
            $hw_model = trim($board_vendor . " " . $board_name);
            
            if (empty($hw_model) || $hw_model === " ") {
                $hw_model = "Standard PC (oder Berechtigung fehlt)";
            }

            $out = "=== SYSTEM DEEP SCAN ===\n\n";
            $out .= "[ HARDWARE ]\n";
            $out .= "Board:    $hw_model\n";
            $out .= "CPU:      $cpu_model ($cpu_cores Threads)\n";
            $out .= "RAM:      $mem_total Total\n";
            $out .= "SSD (/):  $disk_total Total Kapazität\n\n";
            
            $out .= "[ SOFTWARE ]\n";
            $out .= "OS:       $os_info\n";
            $out .= "Kernel:   $kernel\n";
            $out .= "PHP User: $user\n\n";
            
            $out .= "[ SUDO CHECK ]\n";
            $out .= shell_exec("sudo -n -l 2>&1 | grep -E 'reboot|lxc|systemctl' || echo 'ACHTUNG: Keine Sudo-Rechte sichtbar!'");
            $out .= "\n";

            $out .= "[ ROOT CRONJOBS ]\n";
            $out .= shell_exec("sudo -n crontab -l || echo 'ACHTUNG: Keine Sudo-Rechte!'");
            $out .= "\n";

            $out .= "[ NGINX CONFIG CHECK ]\n";
            $nginx_test = shell_exec("sudo -n /usr/sbin/nginx -t 2>&1");
            
            if (strpos($nginx_test, 'password is required') !== false) {
                $out .= "FEHLER: Sudo-Rechte für /usr/sbin/nginx fehlen!\n";
            } else {
                $emerg_lines = [];
                if (!empty($nginx_test)) {
                    foreach (explode("\n", $nginx_test) as $line) {
                        if (strpos(strtolower($line), '[emerg]') !== false) {
                            $emerg_lines[] = trim($line);
                        }
                    }
                }
                
                if (count($emerg_lines) > 0) {
                    $out .= "GEFAHR: Konfiguration auf Datenträger ist defekt!\n";
                    $out .= "Nginx läuft aktuell nur aus dem RAM. Nächster Reload wird fehlschlagen:\n";
                    foreach ($emerg_lines as $emerg) {
                        $out .= " -> " . $emerg . "\n";
                    }
                } else {
                    $out .= "OK: Keine [emerg] Fehler. Konfiguration ist bereit für Reload.\n";
                }
            }
            
            $output = $out;
            break;

        default: 
            $output = "Aktion unbekannt.";
    }
    
    echo json_encode(['output' => $output]);
    exit;
}

$initialStats = getLiveStats($config);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infrastructure Hub | <?=gethostname()?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #020617; color: #f1f5f9; font-family: 'Inter', sans-serif; overflow: hidden; }
        .glass { background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.08); }
        .tab-btn { font-size: 0.875rem; font-weight: 700; transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.05em; }
        .tab-btn.active { background-color: #1e293b; color: #3b82f6; border-bottom: 4px solid #3b82f6; }
        .terminal-font { font-family: 'JetBrains Mono', monospace; }
        .progress-bar { transition: height 0.5s cubic-bezier(0.4, 0, 0.2, 1); } /* Added height transition logic */
        .danger-pattern { background: repeating-linear-gradient(45deg, #1e0000, #1e0000 15px, #2a0000 15px, #2a0000 30px); }
        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }
        .status-dot-online { animation: pulse-green 2s infinite; background-color: #22c55e; }
        .status-dot-offline { background-color: #ef4444; }
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #020617; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 5px; border: 2px solid #020617; }
        .case-sensitive { text-transform: none !important; }
        .log-line { border-bottom: 1px solid rgba(255,255,255,0.03); padding: 4px 0; }
        .log-error { color: #f87171; font-weight: bold; }
        .log-warn { color: #fbbf24; }
        
        /* Modal Transitions */
        .modal-enter { opacity: 0; transform: scale(0.95); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .modal-enter-active { opacity: 1; transform: scale(1); }
    </style>
</head>
<body class="flex flex-col h-screen text-slate-200 tracking-tight">

    <!-- Header -->
    <header class="h-20 bg-slate-900 border-b border-slate-800 flex items-center justify-between px-10 shrink-0 z-40 shadow-2xl relative">
        <div class="flex items-center gap-6">
            <div class="w-12 h-12 bg-blue-600 rounded-xl flex items-center justify-center font-bold text-xl shadow-lg">S</div>
            <div>
                <h1 class="text-white font-bold text-lg leading-tight">BARE METAL SERVER</h1>
                <div class="flex items-center gap-2 text-xs font-mono text-slate-500">
                    <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                    UBUNTU 24.04 • i5-3450 • 8GB RAM
                </div>
            </div>
        </div>
        <nav class="flex h-full items-center">
            <button onclick="switchTab('tab-dash')" class="tab-btn active h-full px-6 flex items-center border-b-4 border-transparent">Dashboard</button>
            <button onclick="switchTab('tab-term')" class="tab-btn h-full px-6 flex items-center border-b-4 border-transparent text-slate-400">Shells</button>
            <button onclick="switchTab('tab-recovery')" class="tab-btn h-full px-6 flex items-center border-b-4 border-transparent text-red-500 hover:bg-red-900/20">Recovery</button>
        </nav>
    </header>

    <!-- MAIN CONTENT -->
    <main class="flex-grow relative bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] overflow-hidden">
        
        <!-- DASHBOARD TAB -->
        <div id="tab-dash" class="tab-content h-full overflow-y-auto p-6">
            <div class="max-w-7xl mx-auto space-y-4">
                
                <!-- TOP METRICS GRID (CPU, RAM, DISK) -->
                <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                    
                    <!-- CPU Matrix -->
                    <div class="col-span-1 md:col-span-6 glass p-6 rounded-3xl flex flex-col justify-between relative overflow-hidden">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-slate-400 font-bold text-xs tracking-widest uppercase">CPU Processing</h3>
                                <div class="flex items-baseline gap-3 mt-1">
                                    <div class="text-3xl font-black text-white terminal-font"><span id="cpu-load"><?=$initialStats['load']?></span> <span class="text-sm text-slate-500 font-normal">Load Avg</span></div>
                                    <!-- CPU TEMP BADGE -->
                                    <div class="px-2 py-1 bg-slate-900/60 rounded-lg border border-white/5 flex items-center gap-1.5 shadow-inner">
                                        <svg class="w-3.5 h-3.5 text-slate-400" id="cpu-temp-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path></svg>
                                        <span id="cpu-temp" class="text-sm font-bold text-slate-300 terminal-font"><?=($initialStats['cpu_temp'] !== '--') ? $initialStats['cpu_temp'] . '°C' : 'N/A'?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="p-2 bg-blue-500/10 rounded-lg"><svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg></div>
                        </div>
                        <div class="grid grid-cols-4 gap-3 z-10 mt-4">
                            <?php foreach($initialStats['cpu_cores'] as $i => $usage): ?>
                            <div class="bg-slate-900/80 p-3 rounded-xl border border-slate-700/50 text-center flex flex-col items-center justify-end h-24 relative overflow-hidden group">
                                <div class="absolute bottom-0 left-0 w-full bg-blue-600/20 progress-bar z-0" id="cpu-bar-<?=$i?>" style="height: <?=$usage?>%"></div>
                                <span class="text-[10px] text-slate-500 font-bold uppercase z-10">Core <?=$i?></span>
                                <span class="text-lg font-black text-blue-400 terminal-font z-10 mt-1" id="cpu-val-<?=$i?>"><?=$usage?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- RAM -->
                    <div class="col-span-1 md:col-span-3 glass p-6 rounded-3xl flex flex-col justify-between items-center text-center">
                        <div class="w-full flex justify-between items-center mb-2">
                            <span class="text-slate-400 font-bold text-xs tracking-widest uppercase">Memory</span>
                            <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        </div>
                        <div class="relative w-32 h-32 flex items-center justify-center mt-2">
                            <svg class="w-full h-full transform -rotate-90" viewBox="0 0 128 128">
                                <circle cx="64" cy="64" r="58" fill="none" stroke="#1e293b" stroke-width="12"></circle>
                                <circle id="ram-circle" cx="64" cy="64" r="58" fill="none" stroke="#a855f7" stroke-width="12" stroke-dasharray="364.4" stroke-dashoffset="<?=364.4 - (364.4 * $initialStats['ram_percent'] / 100)?>" stroke-linecap="round" class="progress-bar"></circle>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-black text-white terminal-font" id="ram-val"><?=$initialStats['ram_percent']?>%</span>
                            </div>
                        </div>
                    </div>

                    <!-- DISK (FESTPLATTE) -->
                    <div class="col-span-1 md:col-span-3 glass p-6 rounded-3xl flex flex-col justify-between items-center text-center">
                        <div class="w-full flex justify-between items-center mb-2">
                            <span class="text-slate-400 font-bold text-xs tracking-widest uppercase">Storage</span>
                            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                        </div>
                        <div class="relative w-32 h-32 flex items-center justify-center mt-2">
                            <svg class="w-full h-full transform -rotate-90" viewBox="0 0 128 128">
                                <circle cx="64" cy="64" r="58" fill="none" stroke="#1e293b" stroke-width="12"></circle>
                                <circle id="disk-circle" cx="64" cy="64" r="58" fill="none" stroke="#10b981" stroke-width="12" stroke-dasharray="364.4" stroke-dashoffset="<?=364.4 - (364.4 * $initialStats['disk_percent'] / 100)?>" stroke-linecap="round" class="progress-bar"></circle>
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-2xl font-black text-white terminal-font" id="disk-val"><?=$initialStats['disk_percent']?>%</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- SERVER ARCHITECTURE BLOCK -->
                <div class="glass p-8 rounded-3xl relative">
                    <div class="absolute -top-3 left-8 bg-slate-900 px-3 py-1 text-slate-400 text-xs font-mono border border-slate-700 rounded-lg shadow-lg">
                        PHYSICAL MACHINE (Ubuntu 24.04)
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-2">
                        <!-- Partition Layout (Left) -->
                        <div class="col-span-1 space-y-4">
                            <div class="p-4 bg-red-900/20 border border-red-500/30 rounded-xl flex items-center justify-between">
                                <div>
                                    <div class="text-xs text-red-400 font-bold mb-1">/dev/sda2</div>
                                    <div class="text-sm font-black text-white tracking-widest uppercase">Recovery OS</div>
                                </div>
                                <span class="text-xs font-mono text-red-400 bg-red-500/10 px-3 py-1 rounded-lg">Minimal</span>
                            </div>
                            <div class="p-4 bg-green-900/20 border border-green-500/30 rounded-xl flex items-center justify-between ring-1 ring-green-500 shadow-[0_0_15px_rgba(34,197,94,0.15)]">
                                <div>
                                    <div class="text-xs text-green-400 font-bold mb-1">/dev/sda3</div>
                                    <div class="text-sm font-black text-white tracking-widest uppercase">Main System</div>
                                </div>
                                <span class="text-xs font-mono text-green-400 bg-green-500/10 px-3 py-1 rounded-lg">Active</span>
                            </div>
                        </div>

                        <!-- Host Services (Middle) -->
                        <div class="col-span-1 border-l border-slate-700/50 pl-8">
                            <div class="flex items-center gap-3 mb-4">
                                <span class="text-xs text-blue-400 font-black tracking-widest uppercase">Bare Metal Services</span>
                                <span class="h-px bg-slate-700/50 flex-grow"></span>
                            </div>
                            <div class="p-5 bg-blue-900/10 border border-blue-500/30 rounded-xl hover:bg-blue-900/20 transition cursor-pointer group shadow-lg" onclick="window.open('<?=buildFullUrl($config['cloudpanel'])?>', '_blank')">
                                <div class="flex justify-between items-start mb-3">
                                    <span class="text-blue-400 font-bold text-base group-hover:text-blue-300">CloudPanel</span>
                                    <span class="w-2.5 h-2.5 bg-green-500 rounded-full animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.6)]"></span>
                                </div>
                                <div class="text-xs text-slate-400 font-medium">Hosting & Web-Stack</div>
                                <div class="mt-4 text-xs font-mono bg-black/40 p-2 rounded-lg text-center text-blue-200 border border-white/5">Nginx / PHP / MySQL / NodeJS</div>
                            </div>
                        </div>

                        <!-- LXD Layer (Right) -->
                        <div class="col-span-1 border-l border-slate-700/50 pl-8">
                            <div class="flex items-center gap-3 mb-4">
                                <span class="text-xs text-amber-500 font-black tracking-widest uppercase">LXD Container</span>
                                <span class="h-px bg-slate-700/50 flex-grow"></span>
                            </div>
                            <div class="p-5 bg-cyan-900/10 border border-cyan-500/30 rounded-xl hover:bg-cyan-900/20 transition cursor-pointer group shadow-lg" onclick="window.open('<?=buildFullUrl($config['portainer'])?>', '_blank')">
                                <div class="flex justify-between items-start mb-3">
                                    <span class="text-cyan-400 font-bold text-base group-hover:text-cyan-300">docker-sandbox</span>
                                    <span class="w-2.5 h-2.5 bg-green-500 rounded-full animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.6)]"></span>
                                </div>
                                <div class="text-xs text-slate-400 font-medium">Sandbox Environment</div>
                                <div class="mt-4 text-xs font-mono bg-black/40 p-2 rounded-lg text-center text-cyan-200 border border-white/5">Docker-Engine + Portainer</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RULES & SERVICE INTERACTION -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 text-left">
                    <div class="glass p-8 rounded-3xl border-l-8 border-l-amber-500 bg-amber-500/10 shadow-lg flex flex-col justify-center text-left">
                        <span class="text-xs font-black text-amber-500 block mb-2 tracking-widest uppercase text-left">Routing Rule</span>
                        <h4 class="text-xl font-bold text-white mb-2 italic uppercase text-left">Subdomain Enforcement</h4>
                        <p class="text-sm text-slate-300 leading-relaxed uppercase text-[12px] text-left">
                            Webseiten benötigen zwingend eine Subdomain:<br>
                            <code class="text-lg text-amber-400 font-bold block mt-3 bg-black/40 p-2 rounded-lg border border-amber-500/20 lowercase tracking-normal">*.<?=$config['main_domain']?></code>
                        </p>
                    </div>

                    <!-- Interactive Status Grid -->
                    <div class="lg:col-span-2 glass p-6 rounded-3xl grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 shadow-lg uppercase font-bold text-left">
                        <?php
                        $serviceActions = [
                            'cloudpanel' => ['label' => 'CloudPanel', 'icon' => '🌐', 'action' => "window.open('" . buildFullUrl($config['cloudpanel']) . "', '_blank')"],
                            'lxd' => ['label' => 'LXD Web-UI', 'icon' => '📦', 'action' => "window.open('" . buildFullUrl($config['lxd_web']) . "', '_blank')"],
                            'portainer' => ['label' => 'Portainer', 'icon' => '🐳', 'action' => "window.open('" . buildFullUrl($config['portainer']) . "', '_blank')"],
                            'ttyd_host' => ['label' => 'Host Shell', 'icon' => '⌨', 'action' => "switchTab('tab-term'); showT('host')"],
                            'ttyd_docker' => ['label' => 'Docker Shell', 'icon' => '🐋', 'action' => "switchTab('tab-term'); showT('docker')"],
                            'system_load' => ['label' => 'Diagnose', 'icon' => '🩺', 'action' => "runAction('diagnose')"]
                        ];
                        foreach($serviceActions as $key => $item): ?>
                        <div id="tile-<?=$key?>" onclick="<?=$item['action']?>" class="relative bg-slate-900/40 p-5 rounded-2xl border border-white/5 flex items-center gap-4 group cursor-pointer hover:bg-slate-800 transition-all hover:scale-[1.02] active:scale-[0.98]">
                            
                            <!-- Healer Alert Indicator (Pulsing Dot auf Diagnose-Kachel) -->
                            <div id="kachel-healer-dot-<?=$key?>">
                                <?php if ($key === 'system_load' && $initialStats['healer_active']): ?>
                                    <span class="absolute -top-2 -right-2 flex h-4 w-4">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-4 w-4 bg-red-600 border border-slate-900"></span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div id="icon-<?=$key?>" class="w-12 h-12 rounded-xl bg-slate-800 flex items-center justify-center text-2xl group-hover:bg-blue-600 transition-colors shadow-inner"><?=$item['icon']?></div>
                            <div class="flex-grow text-left">
                                <span class="text-[10px] font-black text-slate-500 block tracking-wider group-hover:text-white transition-colors uppercase"><?=$item['label']?></span>
                                <div class="flex items-center gap-2 mt-1">
                                    <?php if ($key !== 'system_load'): ?>
                                        <div id="status-dot-<?=$key?>" class="w-2 h-2 rounded-full <?=$initialStats['services'][$key] ?? true ? 'status-dot-online' : 'status-dot-offline'?>"></div>
                                        <span id="status-text-<?=$key?>" class="text-[10px] font-bold tracking-tighter uppercase">
                                            <?=($initialStats['services'][$key] ?? true) ? 'Online' : 'Offline'?>
                                        </span>
                                    <?php else: ?>
                                        <span id="status-text-<?=$key?>" class="text-[10px] font-bold tracking-tighter uppercase <?=$initialStats['healer_active'] ? 'text-red-500' : 'text-slate-400'?>">
                                            <?=$initialStats['healer_active'] ? 'EINGRIFF!' : 'Scannen...'?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                <!-- CREDENTIALS GRID -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="glass p-6 rounded-2xl border-l-4 border-l-blue-500 bg-blue-500/5 text-left">
                        <span class="text-[10px] font-bold text-blue-400 block mb-4 tracking-widest uppercase text-left">CloudPanel</span>
                        <div class="text-sm terminal-font case-sensitive flex justify-between"><span class="text-slate-500 uppercase text-[10px]">User:</span> <?=$config['cloudpanel']['user']?></div>
                        <div class="text-sm terminal-font case-sensitive flex justify-between mt-1"><span class="text-slate-500 uppercase text-[10px]">Password:</span> <?=$config['cloudpanel']['pass']?></div>
                    </div>
                    <div class="glass p-6 rounded-2xl border-l-4 border-l-orange-500 bg-orange-500/5 text-left">
                        <span class="text-[10px] font-bold text-orange-400 block mb-4 tracking-widest uppercase text-left">Host Access</span>
                        <div class="text-sm terminal-font case-sensitive flex justify-between"><span class="text-slate-500 uppercase text-[10px]">User:</span> <span><?=$config['host_ssh']['user']?></span></div>
                        <div class="text-sm terminal-font case-sensitive flex justify-between mt-1"><span class="text-slate-500 uppercase text-[10px]">Password:</span> <span><?=$config['host_ssh']['pass']?></span></div>
                        <div class="text-[11px] text-slate-500 italic mt-3"><?=$config['host_ssh']['info']?></div>
                    </div>
                    <div class="glass p-6 rounded-2xl border-l-4 border-l-cyan-500 bg-cyan-500/5 text-left">
                        <span class="text-[10px] font-bold text-cyan-400 block mb-4 tracking-widest uppercase text-left">LXC Docker</span>
                        <div class="text-sm terminal-font case-sensitive flex justify-between"><span class="text-slate-500 uppercase text-[10px]">User:</span> <span><?=$config['lxc_ssh']['user']?></span></div>
                        <div class="text-sm terminal-font case-sensitive flex justify-between mt-1"><span class="text-slate-500 uppercase text-[10px]">Password:</span> <span><?=$config['lxc_ssh']['pass']?></span></div>
                        <div class="text-[11px] text-slate-500 italic mt-3"><?=$config['lxc_ssh']['info']?></div>
                    </div>
                    <div class="glass p-6 rounded-2xl border-l-4 border-l-purple-500 bg-purple-500/5 text-left">
                        <span class="text-[10px] font-bold text-purple-400 block mb-4 tracking-widest uppercase text-left">Portainer UI</span>
                        <div class="text-sm terminal-font case-sensitive flex justify-between"><span class="text-slate-500 uppercase text-[10px]">User:</span> <span><?=$config['portainer']['user']?></span></div>
                        <div class="text-sm terminal-font case-sensitive flex justify-between mt-1"><span class="text-slate-500 uppercase text-[10px]">Password:</span> <span><?=$config['portainer']['pass']?></span></div>
                    </div>
                </div>

                </div>
            </div>
        </div>

        <!-- TERMINALS TAB -->
        <div id="tab-term" class="tab-content hidden h-full p-8 flex flex-col gap-6 uppercase font-bold text-left">
            <div class="flex flex-col md:flex-row gap-4 justify-between items-start md:items-center bg-slate-900/50 p-4 rounded-2xl border border-slate-700/50 glass">
                <div class="flex gap-4">
                    <button onclick="showT('host')" class="px-8 py-3 bg-slate-800 rounded-xl text-sm font-black hover:bg-slate-700 transition border border-white/5 shadow-lg uppercase">Bare Metal Shell</button>
                    <button onclick="showT('docker')" class="px-8 py-3 bg-slate-800 rounded-xl text-sm font-black hover:bg-slate-700 transition border border-white/5 shadow-lg uppercase">LXC Container</button>
                </div>
                
                <div class="text-right flex flex-col items-end gap-2">
                    <span class="text-[10px] text-slate-500 normal-case italic flex items-center gap-1">
                        <svg class="w-3 h-3 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        iFrame bleibt schwarz? (Zertifikats-Isolation)
                    </span>
                    <div class="flex gap-3">
                        <a href="<?=buildFullUrl($config['ttyd_host'])?>" target="_blank" class="text-[11px] text-blue-400 hover:text-white transition flex items-center gap-1 bg-blue-500/10 px-3 py-1.5 rounded-lg border border-blue-500/20">
                            Host extern öffnen <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        </a>
                        <a href="<?=buildFullUrl($config['ttyd_docker'])?>" target="_blank" class="text-[11px] text-cyan-400 hover:text-white transition flex items-center gap-1 bg-cyan-500/10 px-3 py-1.5 rounded-lg border border-cyan-500/20">
                            LXC extern öffnen <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                        </a>
                    </div>
                </div>
            </div>

            <div id="t-host" class="term-v h-full flex flex-col"><iframe data-src="<?=buildFullUrl($config['ttyd_host'])?>" allow="fullscreen; clipboard-read; clipboard-write" class="flex-grow w-full rounded-3xl border border-slate-800 lazy-iframe bg-black shadow-2xl"></iframe></div>
            <div id="t-docker" class="term-v h-full hidden flex flex-col"><iframe data-src="<?=buildFullUrl($config['ttyd_docker'])?>" allow="fullscreen; clipboard-read; clipboard-write" class="flex-grow w-full rounded-3xl border border-slate-800 lazy-iframe bg-black shadow-2xl"></iframe></div>
        </div>

        <!-- RECOVERY TAB -->
        <div id="tab-recovery" class="tab-content hidden h-full overflow-y-auto p-12 danger-pattern uppercase font-bold text-center">
            <div class="max-w-6xl mx-auto space-y-10">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                    <div class="glass border-orange-900/50 p-10 rounded-[2.5rem] flex flex-col shadow-2xl text-center">
                        <div class="flex items-center justify-center gap-6 mb-8 text-orange-500 text-4xl">⚠️</div>
                        <h2 class="text-2xl font-black text-white tracking-tight mb-4 uppercase tracking-widest text-center">LXC Snapshot Reset</h2>
                        <p class="text-base text-slate-300 mb-8 flex-grow leading-relaxed normal-case text-center">Wiederherstellung: <code class="text-orange-400 tracking-normal"><?=$config['lxc_snapshot']?></code> für <?=$config['lxc_docker_name']?>.</p>
                        <label class="flex items-center gap-4 p-5 bg-black/40 rounded-2xl cursor-pointer hover:bg-black/60 transition border border-white/5"><input type="checkbox" id="confirmSoft" class="w-6 h-6 rounded bg-slate-900 border-slate-700 text-orange-600 focus:ring-orange-600"><span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Wiederherstellung bestätigen</span></label>
                        <button id="softBtn" disabled onclick="triggerSoftReset()" class="w-full py-6 bg-slate-800 text-slate-500 font-black rounded-2xl text-sm mt-6 shadow-xl uppercase tracking-widest text-center">Reset Container</button>
                    </div>
                    <div class="glass border-red-900/50 p-10 rounded-[2.5rem] flex flex-col shadow-2xl text-center">
                        <div class="flex items-center justify-center gap-6 mb-8 text-red-500 text-4xl">☢️</div>
                        <h2 class="text-2xl font-black text-white uppercase italic tracking-tighter mb-4 tracking-widest text-center">Bare Metal Factory Reset</h2>
                        <p class="text-base text-slate-300 mb-8 flex-grow leading-relaxed normal-case text-center">Formatiert sda3 (Hauptsystem) & startet sda2 (ID: <?=$config['recovery_grub_id']?>).</p>
                        <label class="flex items-center gap-4 p-5 bg-black/40 rounded-2xl cursor-pointer hover:bg-black/60 transition border border-white/5"><input type="checkbox" id="confirmHard" class="w-6 h-6 rounded bg-slate-900 border-slate-700 text-red-600 focus:ring-red-600"><span class="text-xs font-bold text-slate-400 underline tracking-widest font-bold">Totalverlust sda3 bestätigen</span></label>
                        <button id="hardBtn" disabled onclick="triggerFactoryReset()" class="w-full py-6 bg-slate-800 text-slate-500 font-black rounded-2xl text-sm mt-6 shadow-xl uppercase tracking-widest text-center">Hard Reset ausführen</button>
                    </div>
                </div>

                <div class="glass p-8 rounded-[2.5rem] border border-white/5 shadow-2xl text-left bg-black/40">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 rounded-full bg-red-500 animate-pulse"></div>
                            <span class="text-xs font-black uppercase tracking-widest text-slate-400">System Journal (Errors & Warnings)</span>
                        </div>
                        <button onclick="updateRecoveryLogs()" class="text-[10px] text-slate-600 hover:text-white uppercase font-bold transition-colors">Refresh Now</button>
                    </div>
                    <div id="recovery-logs" class="terminal-font text-[11px] leading-relaxed overflow-y-auto max-h-64 normal-case bg-black/20 p-4 rounded-xl border border-white/5 min-h-[100px]">
                        Initialisiere Log-Stream...
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- FOOTER -->
    <footer class="h-12 bg-slate-900 border-t border-slate-800 flex items-center justify-end px-10 shrink-0 z-40 shadow-inner relative">
        <span class="text-[11px] text-slate-500 terminal-font tracking-wider normal-case italic">
            Infrastructure Management &bull; Erstellt von <span class="text-white font-bold uppercase tracking-tight italic not-italic">Ingo Weber (ITSE2501)</span> und <span class="text-blue-400 font-extrabold uppercase not-italic">Gemini</span> &copy; 2026
        </span>
    </footer>

    <!-- DIAGNOSE MODAL (OVERLAY) -->
    <div id="diag-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-md z-[100] flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
        <div id="diag-modal-content" class="glass border border-slate-700 rounded-3xl w-full max-w-2xl shadow-[0_0_50px_rgba(0,0,0,0.8)] overflow-hidden transform scale-95 transition-transform duration-300 flex flex-col max-h-[90vh]">
            
            <!-- Modal Header -->
            <div class="p-6 border-b border-white/5 flex justify-between items-center bg-slate-900/50">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-blue-500/10 text-blue-400 rounded-lg"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg></div>
                    <h3 class="text-lg font-black text-white uppercase tracking-widest">System-Diagnose</h3>
                </div>
                <button onclick="closeModal()" class="text-slate-500 hover:text-white transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            
            <!-- Modal Body -->
            <div class="p-8 overflow-y-auto space-y-6 flex-grow">
                
                <!-- Healer Status Box -->
                <div id="modal-healer-status-box" class="p-5 rounded-2xl flex items-center justify-between border transition-all <?= $initialStats['healer_active'] ? 'bg-red-950/40 border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.2)]' : 'bg-slate-900/50 border-white/5' ?>">
                    <div class="flex items-center gap-4">
                        <div id="modal-healer-icon">
                            <?php if ($initialStats['healer_active']): ?>
                                <div class="p-3 bg-red-500/20 rounded-xl text-red-400 shadow-inner border border-red-500/20">
                                    <svg class="w-8 h-8 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                </div>
                            <?php else: ?>
                                <div class="p-3 bg-emerald-500/10 rounded-xl text-emerald-500 border border-emerald-500/10">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="text-left" id="modal-healer-text">
                            <h4 class="text-xs font-black text-white uppercase tracking-[0.2em]">Auto-Healer Status</h4>
                            <?php if ($initialStats['healer_active']): ?>
                                <p class="text-xs text-red-400 mt-1 font-bold uppercase">Isolierte Dateien gefunden! Manueller Eingriff nötig.</p>
                            <?php else: ?>
                                <p class="text-xs text-slate-500 mt-1 uppercase font-bold tracking-wider">System stabil. Keine Eingriffe verzeichnet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="text-right" id="modal-healer-list">
                        <?php if ($initialStats['healer_active']): ?>
                            <div class="flex flex-col gap-2">
                                <?php foreach ($initialStats['broken_vhosts'] as $broken_file): ?>
                                    <span class="text-[11px] terminal-font bg-red-500/20 text-red-300 px-3 py-1.5 rounded-lg border border-red-500/30 font-bold tracking-wider shadow-lg">
                                        <?= htmlspecialchars($broken_file) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span class="text-[10px] text-emerald-500 font-black uppercase tracking-[0.2em] bg-emerald-500/10 px-4 py-2 rounded-xl border border-emerald-500/20">Aktiv|Wachsam</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Hardware/Software Output -->
                <div class="bg-[#020617] rounded-2xl border border-white/5 p-6 shadow-inner relative">
                    <div class="absolute top-0 right-6 px-3 py-1 bg-slate-800 text-[9px] uppercase tracking-[0.3em] font-black text-slate-500 rounded-b-lg">Raw Output</div>
                    <pre id="diag-output" class="text-[11px] text-emerald-400 font-mono whitespace-pre-wrap leading-relaxed">System-Scan wird initialisiert...</pre>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="p-6 border-t border-white/5 bg-slate-900/50 flex justify-end">
                <button onclick="closeModal()" class="px-6 py-2.5 bg-slate-800 text-slate-300 font-bold rounded-xl text-sm hover:bg-slate-700 transition uppercase tracking-widest border border-white/5">Schließen</button>
            </div>
        </div>
    </div>

    <!-- MAIN LOGIC -->
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('.lazy-iframe').forEach(iframe => {
                iframe.src = iframe.getAttribute('data-src');
            });
        });

        function switchTab(id) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.getElementById(id).classList.remove('hidden');
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            const btn = document.querySelector(`button[onclick="switchTab('${id}')"]`);
            if(btn) btn.classList.add('active');
            
            if (id === 'tab-recovery') updateRecoveryLogs();
        }

        function showT(t) {
            document.querySelectorAll('.term-v').forEach(el => el.classList.add('hidden'));
            document.getElementById('t-' + t).classList.remove('hidden');
        }

        // Modal Logic
        function openModal() {
            const m = document.getElementById('diag-modal');
            const c = document.getElementById('diag-modal-content');
            m.classList.remove('hidden');
            setTimeout(() => {
                m.classList.remove('opacity-0');
                c.classList.remove('scale-95');
            }, 10);
        }

        function closeModal() {
            const m = document.getElementById('diag-modal');
            const c = document.getElementById('diag-modal-content');
            m.classList.add('opacity-0');
            c.classList.add('scale-95');
            setTimeout(() => { m.classList.add('hidden'); }, 300);
        }

        async function updateStats() {
            try {
                const fd = new FormData(); fd.append('action', 'get_live_stats');
                const res = await fetch(window.location.href, { method: 'POST', body: fd });
                const data = await res.json();

                // CPU Load Update
                data.cpu_cores.forEach((usage, i) => {
                    const bar = document.getElementById(`cpu-bar-${i}`);
                    const val = document.getElementById(`cpu-val-${i}`);
                    if(bar) bar.style.height = usage + '%'; // <--- HIER WAR DER FEHLER (width zu height korrigiert)
                    if(val) val.innerText = usage + '%';
                });

                // CPU Temperatur Update (Live Color Coding)
                const tempEl = document.getElementById('cpu-temp');
                const tempIcon = document.getElementById('cpu-temp-icon');
                if (tempEl && data.cpu_temp !== '--') {
                    tempEl.innerText = data.cpu_temp + '°C';
                    if (data.cpu_temp >= 80) {
                        tempEl.className = "text-sm font-bold text-red-500 terminal-font animate-pulse";
                        if(tempIcon) tempIcon.className = "w-3.5 h-3.5 text-red-500 animate-pulse";
                    } else if (data.cpu_temp >= 65) {
                        tempEl.className = "text-sm font-bold text-amber-500 terminal-font";
                        if(tempIcon) tempIcon.className = "w-3.5 h-3.5 text-amber-500";
                    } else {
                        tempEl.className = "text-sm font-bold text-emerald-400 terminal-font";
                        if(tempIcon) tempIcon.className = "w-3.5 h-3.5 text-emerald-400";
                    }
                }

                // RAM & Disk Update
                const cLen = 364.4;
                document.getElementById('ram-val').innerText = data.ram_percent + '%';
                document.getElementById('ram-circle').style.strokeDashoffset = cLen - (cLen * data.ram_percent / 100);
                document.getElementById('disk-val').innerText = data.disk_percent + '%';
                document.getElementById('disk-circle').style.strokeDashoffset = cLen - (cLen * data.disk_percent / 100);

                // Service Status Update
                for(const [key, isOnline] of Object.entries(data.services)) {
                    if (key === 'system_load') continue; 
                    const dot = document.getElementById(`status-dot-${key}`);
                    const txt = document.getElementById(`status-text-${key}`);
                    if(dot && txt) {
                        dot.className = "w-2 h-2 rounded-full " + (isOnline ? "status-dot-online" : "status-dot-offline");
                        txt.innerText = isOnline ? "Online" : "Offline";
                        txt.className = "text-[10px] font-bold uppercase tracking-tighter " + (isOnline ? "text-green-500" : "text-red-500");
                    }
                }

                // --- LIVE AUTO-HEALER & NGINX MODAL UPDATE ---
                const diagTile = document.getElementById('tile-system_load');
                const diagIcon = document.getElementById('icon-system_load');
                const diagTxt = document.getElementById('status-text-system_load');

                if (data.nginx_emerg) {
                    if(diagTile) {
                        diagTile.classList.add('animate-pulse', 'bg-red-900/30', 'border-red-500/50');
                        diagTile.classList.remove('bg-slate-900/40', 'border-white/5');
                    }
                    if(diagIcon) {
                        diagIcon.classList.add('text-red-500', 'bg-red-900/50');
                        diagIcon.classList.remove('text-slate-400', 'bg-slate-800');
                    }
                    if(diagTxt) { 
                        diagTxt.className = "text-[10px] font-bold tracking-tighter uppercase text-red-500"; 
                        diagTxt.innerText = "NGINX GEFAHR!"; 
                    }
                } else if (!data.healer_active) {
                    if(diagTile) {
                        diagTile.classList.remove('animate-pulse', 'bg-red-900/30', 'border-red-500/50');
                        diagTile.classList.add('bg-slate-900/40', 'border-white/5');
                    }
                    if(diagIcon) {
                        diagIcon.classList.remove('text-red-500', 'bg-red-900/50');
                        diagIcon.classList.add('text-slate-400', 'bg-slate-800');
                    }
                    if(diagTxt) { 
                        diagTxt.className = "text-[10px] font-bold tracking-tighter uppercase text-slate-400"; 
                        diagTxt.innerText = "Scannen..."; 
                    }
                }

                // Modal Fenster Update
                const diagDot = document.getElementById('kachel-healer-dot-system_load');
                const mBox = document.getElementById('modal-healer-status-box');
                const mIcon = document.getElementById('modal-healer-icon');
                const mText = document.getElementById('modal-healer-text');
                const mList = document.getElementById('modal-healer-list');

                if(data.healer_active) {
                    if(diagDot) diagDot.innerHTML = `<span class="absolute -top-2 -right-2 flex h-4 w-4"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"></span><span class="relative inline-flex rounded-full h-4 w-4 bg-red-600 border border-slate-900"></span></span>`;
                    
                    if(mBox) mBox.className = "p-5 rounded-2xl flex items-center justify-between border transition-all bg-red-950/40 border-red-500/50 shadow-[0_0_20px_rgba(239,68,68,0.2)]";
                    if(mIcon) mIcon.innerHTML = `<div class="p-3 bg-red-500/20 rounded-xl text-red-400 shadow-inner border border-red-500/20"><svg class="w-8 h-8 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div>`;
                    if(mText) mText.innerHTML = `<h4 class="text-xs font-black text-white uppercase tracking-[0.2em]">Auto-Healer Status</h4><p class="text-xs text-red-400 mt-1 font-bold uppercase">Isolierte Dateien gefunden! Manueller Eingriff nötig.</p>`;
                    if(mList) {
                        let html = '<div class="flex flex-col gap-2">';
                        data.broken_vhosts.forEach(file => { html += `<span class="text-[11px] terminal-font bg-red-500/20 text-red-300 px-3 py-1.5 rounded-lg border border-red-500/30 font-bold tracking-wider shadow-lg">${file}</span>`; });
                        html += '</div>';
                        mList.innerHTML = html;
                    }
                } else {
                    if(diagDot) diagDot.innerHTML = '';
                    
                    if(mBox) mBox.className = "p-5 rounded-2xl flex items-center justify-between border transition-all bg-slate-900/50 border-white/5";
                    if(mIcon) mIcon.innerHTML = `<div class="p-3 bg-emerald-500/10 rounded-xl text-emerald-500 border border-emerald-500/10"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>`;
                    if(mText) mText.innerHTML = `<h4 class="text-xs font-black text-white uppercase tracking-[0.2em]">Auto-Healer Status</h4><p class="text-xs text-slate-500 mt-1 uppercase font-bold tracking-wider">System stabil. Keine Eingriffe verzeichnet.</p>`;
                    if(mList) mList.innerHTML = `<span class="text-[10px] text-emerald-500 font-black uppercase tracking-[0.2em] bg-emerald-500/10 px-4 py-2 rounded-xl border border-emerald-500/20">Aktiv|Wachsam</span>`;
                }
            } catch (e) {}
        }

        async function updateRecoveryLogs() {
            const logBox = document.getElementById('recovery-logs');
            try {
                const fd = new FormData(); fd.append('action', 'get_recovery_logs');
                const res = await fetch(window.location.href, { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.output) {
                    const lines = data.output.split('\n');
                    logBox.innerHTML = lines.map(line => {
                        let colorClass = "";
                        if (line.toLowerCase().includes('err') || line.toLowerCase().includes('fail')) colorClass = "log-error";
                        else if (line.toLowerCase().includes('warn')) colorClass = "log-warn";
                        return `<div class="log-line ${colorClass}">${line}</div>`;
                    }).join('');
                    logBox.scrollTop = logBox.scrollHeight;
                }
            } catch (e) {
                logBox.innerText = "Fehler beim Laden der Logs.";
            }
        }

        async function runAction(action) {
            try {
                const fd = new FormData(); fd.append('action', action);
                const res = await fetch(window.location.href, { method: 'POST', body: fd });
                const data = await res.json();
                
                if(action === 'factory_reset' || action === 'system_reboot') {
                    if (data.output.includes("FEHLER")) {
                        alert(data.output);
                    } else {
                        document.body.innerHTML = "<div class='h-screen flex items-center justify-center bg-black text-red-600 font-mono text-center p-10 uppercase font-black text-center'><h1>" + data.output + "<br><br>Verbindung wird getrennt...</h1></div>";
                    }
                } else if(action === 'diagnose') {
                    document.getElementById('diag-output').innerText = data.output;
                    openModal();
                } else {
                    alert(data.output);
                }
            } catch (e) { alert("Fehler: Befehl konnte nicht an den Host übergeben werden."); }
        }

        const sC = document.getElementById('confirmSoft');
        const sB = document.getElementById('softBtn');
        sC?.addEventListener('change', (e) => {
            sB.disabled = !e.target.checked;
            sB.className = e.target.checked ? "w-full py-6 bg-orange-600 text-white font-black rounded-2xl uppercase tracking-[0.2em] text-sm mt-6 shadow-lg shadow-orange-600/30" : "w-full py-6 bg-slate-800 text-slate-500 font-black rounded-2xl uppercase tracking-[0.2em] text-sm mt-6";
        });

        const hC = document.getElementById('confirmHard');
        const hB = document.getElementById('hardBtn');
        hC?.addEventListener('change', (e) => {
            hB.disabled = !e.target.checked;
            hB.className = e.target.checked ? "w-full py-6 bg-red-600 text-white font-black rounded-2xl uppercase tracking-[0.2em] text-sm mt-6 shadow-xl shadow-red-900/50" : "w-full py-6 bg-slate-800 text-slate-500 font-black rounded-2xl uppercase tracking-[0.2em] text-sm mt-6";
        });

        function triggerSoftReset() { if(confirm("Snapshot jetzt einspielen?")) runAction('lxc_restore'); }
        function triggerFactoryReset() { if(confirm("LEZTE WARNUNG: SDA3 LÖSCHEN?")) runAction('factory_reset'); }

        setInterval(updateStats, 3000);
        setInterval(() => {
            if (!document.getElementById('tab-recovery').classList.contains('hidden')) {
                updateRecoveryLogs();
            }
        }, 3000);
    </script>
</body>
</html>