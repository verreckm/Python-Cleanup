```python
import imaplib
import ssl
import os
import datetime

# IMAP-serverinstellingen
IMAP_SERVER = "imap.ziggo.nl"
IMAP_PORT_SSL = 993

# Credentials via environment variables (VEILIG)
USERNAME = os.environ["EMAIL_USERNAME"]
PASSWORD = os.environ["EMAIL_PASSWORD"]

FOLDERS_TO_CLEAR = ["Spam"]
ENCRYPTION_METHOD = "SSL"

# Zet op True om eerst te testen zonder te verwijderen
DRY_RUN = False

LOG_FILE = "cleanup_log.txt"


def log(message):
    """Schrijft naar console én logfile."""
    timestamp = datetime.datetime.now().isoformat()
    line = f"[{timestamp}] {message}"
    print(line)
    with open(LOG_FILE, "a") as f:
        f.write(line + "\n")


def connect_imap_ssl():
    try:
        context = ssl.create_default_context()
        imap = imaplib.IMAP4_SSL(IMAP_SERVER, IMAP_PORT_SSL, ssl_context=context)
        imap.login(USERNAME, PASSWORD)
        log("Verbonden met IMAP (SSL).")
        return imap
    except Exception as e:
        log(f"Verbindingsfout: {e}")
        return None


def clear_folder(imap, folder):
    try:
        status, _ = imap.select(folder)
        if status != "OK":
            log(f"Kan map niet openen: {folder}")
            return 0

        status, msg_ids = imap.search(None, "ALL")
        if status != "OK":
            log(f"Zoekfout in map: {folder}")
            return 0

        msg_ids = msg_ids[0].split()
        count = len(msg_ids)

        if count == 0:
            log(f"{folder}: geen berichten.")
            return 0

        if DRY_RUN:
            log(f"[DRY RUN] {folder}: zou {count} berichten verwijderen.")
            return count

        for msg_id in msg_ids:
            imap.store(msg_id, "+FLAGS", "\\Deleted")

        imap.expunge()
        log(f"{folder}: {count} berichten verwijderd.")
        return count

    except Exception as e:
        log(f"Fout in map {folder}: {e}")
        return 0


def monitor_and_clear():
    log("=== Start run ===")
    imap = connect_imap_ssl()

    if not imap:
        log("Geen verbinding. Stop.")
        return

    total_deleted = 0

    for folder in FOLDERS_TO_CLEAR:
        deleted = clear_folder(imap, folder)
        total_deleted += deleted

    log(f"TOTAAL verwijderd: {total_deleted}")

    try:
        imap.logout()
    except:
        pass

    log("=== Einde run ===")


if __name__ == "__main__":
    monitor_and_clear()
```
