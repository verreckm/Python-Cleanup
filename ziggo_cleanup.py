import imaplib
import ssl
import os
import json
import datetime
import email
from collections import defaultdict
from email.header import decode_header

IMAP_SERVER = "imap.ziggo.nl"
IMAP_PORT_SSL = 993

USERNAME = os.environ["EMAIL_USERNAME"]
PASSWORD = os.environ["EMAIL_PASSWORD"]

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
    if os.path.exists(JSON_FILE):
        with open(JSON_FILE, "r", encoding="utf-8") as f:
            return json.load(f)
    return []


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
                sender = decode_mime_header(msg.get("From"))
                subject = decode_mime_header(msg.get("Subject"))
        except Exception:
            pass

        history.append({
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


def monitor_and_clear():
    open(LOG_FILE, "w", encoding="utf-8").close()
    history = load_history()

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

    save_history(history)
    build_report(history)
    log(f"Deze run verwijderd: {total}")


if __name__ == "__main__":
    monitor_and_clear()
