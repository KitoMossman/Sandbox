!/bin/bash

# 1. Trigger-Check
if ! grep -q "factory-reset" /proc/cmdline; then
    exit 0
fi

# 2. UI-Vorbereitung (Ausgabe auf Konsole)
exec > /dev/tty1 2>&1
clear
echo "===================================================="
echo "          FACTORY RESET AKTIVIERT                  "
echo "===================================================="
echo ""

# 3. Variablen
IMAGE="/root/clean-state.img.gz"
TARGET="/dev/sda3"

# 4. Validierung
if [ ! -f "$IMAGE" ]; then
    echo "[FEHLER] Quelldatei $IMAGE nicht gefunden!"
    sleep 10
    reboot
fi

echo "[1/2] Bereite Zielpartition $TARGET vor..."
umount $TARGET 2>/dev/null

echo "[2/2] Schreibe System-Image zurück..."
echo "      Bitte warten, das System startet danach neu."
echo "----------------------------------------------------"

# 5. Der Kern-Befehl
# -W (Wide mode) sorgt bei partclone oft für eine bessere Anzeige in der Konsole.
zcat "$IMAGE" | pv -pbt | partclone.ext4 -r -o "$TARGET"

echo "----------------------------------------------------"
echo "SYNC: Daten werden auf Festplatte geschrieben..."
sync

echo ""
echo "FERTIG! Das System ist wieder im Werkszustand."
echo "Neustart in 5 Sekunden..."
sleep 5
reboot