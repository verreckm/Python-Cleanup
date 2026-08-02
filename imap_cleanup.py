import imaplib
import ssl
import os
import json
import datetime
import email
import requests
from collections import defaultdict
from email.header import decode_header
from email.utils import parseaddr

IMAP_SERVER = "imap.ziggo.nl"
IMAP_PORT_SSL = 993

USERNAME = os.environ["EMAIL_USERNAME"]
PASSWORD = os.environ["EMAIL_PASSWORD"]

# WordPress-koppeling
WP_REPORT_URL = os.environ.get("WP_REPORT_URL")   # bv. https://jouwsite.nl/wp-json/spamcleanup/v1/report
WP_API_KEY = os.environ.get("WP_API_KEY")

FOLDERS_TO_CLEAR = ["Spam"]
DRY_RUN = False

REPORT_DIR = "reports"
os.makedirs(REPORT_DIR, exist_ok=True)

LOG_FILE = os.path.join(REPORT_DIR, "cleanup_log.txt")
JSON_FILE = os.path.join(REPORT_DIR, "daily_cleanup.json")
REPORT_FILE = os.path.join(REPORT_DIR, "cleanup_report.txt")


def log(message):
    timestamp = datetime.datetime.now().isoformat(timespec="seconds")
    line = f"[{timestamp}] {message}"
    print(line)
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def decode_mime_header(value):
    if not value:
        return ""
    result = ""
    for txt, enc in decode_header(value):
        if isinstance(txt, bytes):
            result += txt.decode(enc or "utf-8", errors="replace")
        else:
            result += txt
    return result


def load_history():
    """Laadt de historie en gooit automatisch entries van vorige dagen weg,
    zodat het bestand niet blijft groeien zonder aparte opschoon-job."""
    if not os.path.exists(JSON_FILE):
        return []

    with open(JSON_FILE, "r", encoding="utf-8") as f:
        history = json.load(f)

    today = datetime.date.today().isoformat()
    # Oude entries zonder "date"-veld (van vóór deze wijziging) worden ook opgeruimd.
    pruned = [item for item in history if item.get("date") == today]

    removed = len(history) - len(pruned)
    if removed:
        log(f"{removed} oude entry/entries opgeruimd uit daily_cleanup.json (niet van vandaag).")

    return pruned


def save_history(history):
    with open(JSON_FILE, "w", encoding="utf-8") as f:
        json.dump(history, f, ensure_ascii=False, indent=2)


def build_report(history):
    grouped = defaultdict(list)
    for item in history:
        grouped[item["folder"]].append(item)

    with open(REPORT_FILE, "w", encoding="utf-8") as f:
        f.write("Spam Cleanup Rapport\n")
        f.write("=" * 40 + "\n\n")
        f.write(f"Laatste update: {datetime.datetime.now():%d-%m-%Y %H:%M}\n\n")
        if not history:
            f.write("Er zijn vandaag geen berichten verwijderd.\n")
            return
        total = 0
        for folder, msgs in grouped.items():
            f.write(f"Map: {folder}\nAantal verwijderd: {len(msgs)}\n\n")
            total += len(msgs)
            for m in msgs:
                f.write(f"• {m['timestamp']} | {m['sender']}\n")
                f.write(f"  {m['subject']}\n\n")
        f.write("=" * 40 + "\n")
        f.write(f"Totaal verwijderd: {total}\n")


def connect_imap_ssl():
    try:
        imap = imaplib.IMAP4_SSL(IMAP_SERVER, IMAP_PORT_SSL, ssl_context=ssl.create_default_context())
        imap.login(USERNAME, PASSWORD)
        log("Verbonden met IMAP.")
        return imap
    except Exception as e:
        log(f"Verbindingsfout: {e}")
        return None


def clear_folder(imap, folder, history):
    status, _ = imap.select(folder)
    if status != "OK":
        return 0

    status, ids = imap.search(None, "ALL")
    if status != "OK":
        return 0

    ids = ids[0].split()
    count = 0

    for msg_id in ids:
        sender = "Onbekend"
        subject = "Geen onderwerp"
        try:
            status, data = imap.fetch(msg_id, "(RFC822.HEADER)")
            if status == "OK" and data and data[0]:
                msg = email.message_from_bytes(data[0][1])
                # parseaddr splitst "Naam <adres>" -> ("Naam", "adres"); we bewaren alleen het adres.
                _, sender = parseaddr(msg.get("From"))
                sender = sender or "Onbekend"
                subject = decode_mime_header(msg.get("Subject"))
        except Exception:
            pass

        history.append({
            "date": datetime.date.today().isoformat(),
            "timestamp": datetime.datetime.now().strftime("%H:%M"),
            "folder": folder,
            "sender": sender,
            "subject": subject
        })

        log(f"Verwijderen | {sender} | {subject}")

        if not DRY_RUN:
            imap.store(msg_id, "+FLAGS", "\\Deleted")
        count += 1

    if not DRY_RUN and count:
        imap.expunge()

    return count


def push_to_wordpress(new_items):
    """Stuur alleen de items van déze run naar het WordPress dashboard."""
    if not WP_REPORT_URL or not WP_API_KEY:
        log("WP_REPORT_URL of WP_API_KEY niet ingesteld — WordPress-push overgeslagen.")
        return

    if not new_items:
        log("Geen nieuwe items deze run, WordPress-push overgeslagen.")
        return

    payload = {
        "generated_at": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        "history": new_items,
    }

    try:
        response = requests.post(
            WP_REPORT_URL,
            json=payload,
            headers={"X-API-Key": WP_API_KEY},
            timeout=15,
        )
        response.raise_for_status()
        log(f"WordPress-push gelukt: {response.json()}")
    except requests.exceptions.RequestException as e:
        log(f"WordPress-push mislukt: {e}")


def monitor_and_clear():
    open(LOG_FILE, "w", encoding="utf-8").close()
    history = load_history()
    start_index = len(history)  # alles vanaf hier is nieuw voor deze run

    imap = connect_imap_ssl()
    if not imap:
        return

    total = 0
    try:
        for folder in FOLDERS_TO_CLEAR:
            total += clear_folder(imap, folder, history)
    finally:
        try:
            imap.logout()
        except Exception:
            pass

    new_items = history[start_index:]

    save_history(history)
    build_report(history)
    push_to_wordpress(new_items)
    log(f"Deze run verwijderd: {total}")


if __name__ == "__main__":
    monitor_and_clear()
