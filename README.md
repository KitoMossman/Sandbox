# Sandbox

Dieses Repository dient als Sandbox und Sammlung verschiedener Konfigurationsdateien, Skripte und Snippets für Systemadministration, Containerisierung und Web-Hosting.

## 📂 Struktur

Die Dateien sind nach Themen und Anwendungsbereichen in folgende Verzeichnisse unterteilt:

### `lxc/`
Konfigurationsdateien für **LXC/LXD** Container.
- Enthält Standardprofile (`default-profil.yaml`)
- Docker-spezifische Sandbox-Konfigurationen (`docker-sandbox.yaml`)
- Netzwerk-Setups (`networks-lxdbr0.yaml`)

### `sda2/`
Ressourcen für **Systemwiederherstellung und Backups**.
- Skripte und Konfigurationen für `partclone` (`sda2-befehl-partclone.txt`)
- Ein Systemd-Service und automatisiertes Skript für einen "Factory Reset" (`sda2-factory-reset.service`, `sda2-factory-restore.sh`)

### `sda3/`
Verschiedene **System-Tools, Cronjobs und Überwachungsskripte**.
- Custom GRUB-Konfiguration (`40_custom.grub.txt`)
- Ein `nginx-autohealer.sh` Skript zur automatischen Fehlerbehebung von Nginx
- Konfigurationen für Web-Terminals via `ttyd` (`ttyd-docker.txt`, `ttyd-web.txt`)

### `vHost-ReverseProxy-cPanel/`
Vorlagen und Konfigurationen für **Virtual Hosts und Reverse Proxys**.
- Speziell abgestimmt für cPanel Umgebungen
- Reverse Proxy Setups für Web-CLIs und Docker (`docker-cli.sandbox.vhost.txt`, `web-cli.sandbox.vhost.txt`)

### `webroot/`
Grundlegende **Web-Dateien**.
- Enthält typische Einstiegsdateien wie `index.php` und `style.css` für Test- oder Startseiten.

## 🚀 Verwendungszweck

Dieses Projekt fungiert primär als Ablageort und Testumgebung (Sandbox) für nützliche DevOps- und Sysadmin-Skripte. Es bietet eine kleine Wissensdatenbank und fertige Vorlagen für:
- LXC/LXD Container-Management
- Nginx Reverse Proxy Setups
- Automatisierte System-Images und Wiederherstellung
- Dienst-Überwachung (Auto-Healing)
