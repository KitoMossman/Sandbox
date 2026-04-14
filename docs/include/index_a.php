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