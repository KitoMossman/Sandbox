#!/bin/bash
# Nginx Watchdog & Auto-Healer V2 (Closed-Loop Edition)
# Prüft, ob Nginx läuft. Wenn nicht, wird die Konfiguration getestet.
# Neu: Testet isolierte (.broken) Dateien auf erfolgreiche manuelle Reparatur.
# Regeneriert komplette Verzeichnisstrukturen (Superset-Methode NodeJS/PHP) oder isoliert fehlerhafte vHosts.
# Manueller Aufruf: ./nginx-healer.sh --force (oder -f)

# --- PARAMETER CHECK (MANUAL OVERRIDE) ---
FORCE_RUN=0
if [[ "$1" == "--force" ]] || [[ "$1" == "-f" ]]; then
    FORCE_RUN=1
    echo "Nginx Auto-Heal: Manueller Override aktiv."
    logger -p syslog.info "Nginx Auto-Heal: Skript wurde manuell mit --force gestartet."
fi

# --- STUFE 0: AUTO-RESTORE (Closed-Loop System) ---
# Prüft bei JEDEM Durchlauf, ob der User im CloudPanel seine Fehler behoben hat.
BROKEN_FILES=$(ls -1 /etc/nginx/sites-enabled/*.broken 2>/dev/null)

if [ -n "$BROKEN_FILES" ]; then
    if [ $FORCE_RUN -eq 1 ]; then echo "Prüfe isolierte (.broken) Dateien auf Reparatur..."; fi
    
    for b_file in $BROKEN_FILES; do
        ORIG_FILE="${b_file%.broken}"
        
        # Datei testweise wieder scharfschalten
        mv "$b_file" "$ORIG_FILE"
        
        # Trockenübung: Ist die Konfiguration jetzt valide?
        if nginx -t >/dev/null 2>&1; then
            logger -p syslog.info "Nginx Auto-Heal: vHost erfolgreich repariert und reintegriert: $ORIG_FILE"
            if [ $FORCE_RUN -eq 1 ]; then echo "-> ERFOLG: $ORIG_FILE ist wieder fehlerfrei und aktiv."; fi
            
            # Nginx direkt anweisen, die neue, fehlerfreie Config in den RAM zu laden
            systemctl reload nginx
        else
            # Reparatur war erfolglos (User hat den Fehler noch nicht behoben) -> Wieder isolieren
            mv "$ORIG_FILE" "$b_file"
            if [ $FORCE_RUN -eq 1 ]; then echo "-> FEHLGESCHLAGEN: $b_file ist immer noch defekt. Bleibt isoliert."; fi
        fi
    done
fi

# --- HAUPT-BEDINGUNG (Watchdog) ---
# Startet, wenn Nginx tot ist ODER wenn das Skript manuell erzwungen wird
if [ $FORCE_RUN -eq 1 ] || ! systemctl is-active --quiet nginx; then
    
    # Konfiguration testen und Ausgabe abfangen
    TEST_OUTPUT=$(nginx -t 2>&1)
    
    if echo "$TEST_OUTPUT" | grep -q "failed"; then
        
        if [ $FORCE_RUN -eq 1 ]; then echo "Fehlerhafte Konfiguration erkannt. Starte Heilungsprozess..."; fi
        
        # --- STUFE 1: PRE-EMPTIVE AUTO-KORREKTUR (Full Tree Regeneration - Superset) ---
        MISSING_PATH=$(echo "$TEST_OUTPUT" | grep -oP 'open\(\) "\K[^"]+')
        
        if [ -n "$MISSING_PATH" ] && echo "$TEST_OUTPUT" | grep -q "No such file or directory"; then
            
            MISSING_DIR=$(dirname "$MISSING_PATH")
            MISSING_USER=$(echo "$MISSING_PATH" | awk -F/ '{print $3}') # Extrahiert z.B. kito01
            
            if [ -n "$MISSING_USER" ] && [[ "$MISSING_PATH" == /home/* ]]; then
                
                # DAS FINALE SUPERSET-ARRAY: Kombination aus NodeJS und PHP/Varnish
                declare -a REQUIRED_DIRS=(
                    "/home/$MISSING_USER/backups/databases"
                    "/home/$MISSING_USER/htdocs"
                    "/home/$MISSING_USER/logs/nginx"
                    "/home/$MISSING_USER/logs/php"
                    "/home/$MISSING_USER/logs/varnish-cache"
                    "/home/$MISSING_USER/tmp"
                    "/home/$MISSING_USER/.nvm"
                    "/home/$MISSING_USER/.ssh"
                    "/home/$MISSING_USER/.varnish-cache"
                )
                
                # 1. Spezifischen Ordner anlegen (Sicherheitsnetz)
                mkdir -p "$MISSING_DIR"
                
                # 2. Komplette Superset-Struktur wiederherstellen
                for dir in "${REQUIRED_DIRS[@]}"; do
                    if [ ! -d "$dir" ]; then
                        mkdir -p "$dir"
                    fi
                done
                
                # 3. Berechtigungen (chown) rekursiv und für versteckte Ordner fixen
                chown "$MISSING_USER":"$MISSING_USER" "/home/$MISSING_USER" 2>/dev/null
                
                # Standard- und Hidden-Ordner der Root-Ebene durchiterieren
                for bdir in backups htdocs logs tmp .nvm .ssh .varnish-cache; do
                    if [ -d "/home/$MISSING_USER/$bdir" ]; then
                        chown -R "$MISSING_USER":"$MISSING_USER" "/home/$MISSING_USER/$bdir" 2>/dev/null
                    fi
                done
                
                logger -p syslog.warn "Nginx Auto-Heal: Benutzer-Verzeichnisstruktur (Superset) für '$MISSING_USER' regeneriert."
                if [ $FORCE_RUN -eq 1 ]; then echo "-> Verzeichnisstruktur für '$MISSING_USER' wiederhergestellt."; fi
            else
                mkdir -p "$MISSING_DIR"
                logger -p syslog.warn "Nginx Auto-Heal: System-Verzeichnis '$MISSING_DIR' neu erstellt."
                if [ $FORCE_RUN -eq 1 ]; then echo "-> Verzeichnis '$MISSING_DIR' wiederhergestellt."; fi
            fi
            
            # Nginx erneut testen, um zu sehen, ob das mkdir das Problem gelöst hat
            TEST_OUTPUT=$(nginx -t 2>&1)
        fi

        # --- STUFE 2: ISOLATION (Wenn Auto-Korrektur nicht gereicht hat) ---
        if echo "$TEST_OUTPUT" | grep -q "failed"; then
            
            BROKEN_FILE=$(echo "$TEST_OUTPUT" | grep -oP 'in /etc/nginx/[^:]+' | head -1 | sed 's/^in //')
            
            if [ -z "$BROKEN_FILE" ]; then
                if [ -n "$MISSING_PATH" ]; then
                    BROKEN_FILE=$(grep -rl "$MISSING_PATH" /etc/nginx/sites-enabled/ 2>/dev/null | head -1)
                    if [ -z "$BROKEN_FILE" ] && [ -n "$MISSING_USER" ]; then
                        BROKEN_FILE=$(grep -rl "/home/$MISSING_USER/" /etc/nginx/sites-enabled/ 2>/dev/null | head -1)
                    fi
                fi
            fi

            if [ -n "$BROKEN_FILE" ] && [ -f "$BROKEN_FILE" ]; then
                mv "$BROKEN_FILE" "${BROKEN_FILE}.broken"
                logger -p syslog.err "Nginx Auto-Heal: vHost isoliert. Datei '$BROKEN_FILE' wurde deaktiviert."
                if [ $FORCE_RUN -eq 1 ]; then echo "-> vHost isoliert: $BROKEN_FILE wurde deaktiviert."; fi
            else
                logger -p syslog.err "Nginx Auto-Heal: Konfiguration defekt. Konnte fehlerhafte Datei nicht isolieren."
                if [ $FORCE_RUN -eq 1 ]; then echo "-> KRITISCH: Konnte fehlerhafte Datei nicht isolieren!"; fi
            fi
        fi
    else
        if [ $FORCE_RUN -eq 1 ]; then echo "Konfiguration ist (wieder) fehlerfrei. Keine weitere Heilung nötig."; fi
    fi
    
    # --- FINALES RESTART/RELOAD PROTOKOLL ---
    logger -p syslog.info "Nginx Auto-Heal: Wende Systemstatus an (Reload/Restart)..."
    
    # Nutzt reload-or-restart: Macht einen sanften Reload, wenn Nginx läuft (keine Downtime!), oder einen Start, wenn er tot ist.
    systemctl reload-or-restart nginx
    
    if systemctl is-active --quiet nginx; then
        logger -p syslog.info "Nginx Auto-Heal: Nginx läuft erfolgreich. System online."
        if [ $FORCE_RUN -eq 1 ]; then echo "DONE: Nginx läuft erfolgreich."; fi
    else
        logger -p syslog.err "Nginx Auto-Heal: Start/Reload fehlgeschlagen. Manueller Eingriff erforderlich."
        if [ $FORCE_RUN -eq 1 ]; then echo "FEHLER: Nginx konnte nicht gestartet/geladen werden."; fi
    fi
else
    # Auskommentieren, wenn die Testphase vorbei ist! (Vermeidet Log-Spam im Syslog)
    # logger -p syslog.debug "Nginx Auto-Heal: Nginx läuft fehlerfrei. Nichts zu tun."
    if [ $FORCE_RUN -eq 1 ]; then echo "Nginx Auto-Heal: Nginx läuft fehlerfrei. Ich habe nichts zu tun."; fi
fi