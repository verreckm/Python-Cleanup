import imaplib
import ssl
import os
import datetime
import email
from email.header import decode_header

# IMAP-serverinstellingen
IMAP_SERVER = "imap.ziggo.nl"
IMAP_PORT_SSL = 993

# Credentials via GitHub Secrets / Environment Variables
USERNAME = os.environ["EMAIL_USERNAME"]
PASSWORD = os.environ["EMAIL_PASSWORD"]

# Mappen die geleegd moeten worden
FOLDERS_TO_CLEAR = ["Spam"]

# Zet op True om eerst te testen zonder te verwijderen
DRY_RUN = False

LOG_FILE = "cleanup_log.txt"


def log(message):
    """Schrijft naar console én logfile."""
    timestamp = datetime.datetime.now().isoformat()
    line = f"[{timestamp}] {message}"

    print(line)

    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def decode_mime_header(value):
    """Decodeert e-mail headers zoals Subject en From."""
    if not value:
        return ""

    decoded_parts = decode_header(value)
    result = ""

    for text, encoding in decoded_parts:
        if isinstance(text, bytes):
            result += text.decode(encoding or "utf-8", errors="replace")
        else:
            result += text

    return result


def connect_imap_ssl():
    """Maak verbinding met Ziggo IMAP via SSL."""
    try:
        context = ssl.create_default_context()

        imap = imaplib.IMAP4_SSL(
            IMAP_SERVER,
            IMAP_PORT_SSL,
            ssl_context=context
        )

        imap.login(USERNAME, PASSWORD)

        log("Verbonden met IMAP (SSL).")

        return imap

    except Exception as e:
        log(f"Verbindingsfout: {e}")
        return None


def clear_folder(imap, folder):
    """Leeg een map en log afzender + onderwerp van verwijderde berichten."""
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

        if not msg_ids:
            log(f"{folder}: geen berichten gevonden.")
            return 0

        log(f"{folder}: {len(msg_ids)} berichten gevonden.")

        deleted_count = 0

        for msg_id in msg_ids:

            sender = "Onbekend"
            subject = "Geen onderwerp"

            try:
                status, msg_data = imap.fetch(
                    msg_id,
                    "(RFC822.HEADER)"
                )

                if status == "OK" and msg_data and msg_data[0]:

                    msg = email.message_from_bytes(
                        msg_data[0][1]
                    )

                    sender = decode_mime_header(
                        msg.get("From")
                    )

                    subject = decode_mime_header(
                        msg.get("Subject")
                    )

            except Exception as e:
                log(
                    f"Fout bij ophalen headers van bericht "
                    f"{msg_id.decode()}: {e}"
                )

            log(
                f"Verwijderen | Afzender: {sender} | "
                f"Onderwerp: {subject}"
            )

            if not DRY_RUN:
                imap.store(
                    msg_id,
                    "+FLAGS",
                    "\\Deleted"
                )

            deleted_count += 1

        if not DRY_RUN:
            imap.expunge()

        if DRY_RUN:
            log(
                f"[DRY RUN] {folder}: zou "
                f"{deleted_count} berichten verwijderen."
            )
        else:
            log(
                f"{folder}: {deleted_count} berichten verwijderd."
            )

        return deleted_count

    except Exception as e:
        log(f"Fout in map {folder}: {e}")
        return 0


def monitor_and_clear():
    """Hoofdfunctie."""

    # Maak bij iedere run een nieuw logbestand
    with open(LOG_FILE, "w", encoding="utf-8") as f:
        f.write(
            f"=== Nieuwe run gestart op "
            f"{datetime.datetime.now().isoformat()} ===\n"
        )

    log("=== Start run ===")

    imap = connect_imap_ssl()

    if not imap:
        log("Geen verbinding. Stop.")
        return

    total_deleted = 0

    try:
        for folder in FOLDERS_TO_CLEAR:
            deleted = clear_folder(imap, folder)
            total_deleted += deleted

        log(f"TOTAAL verwijderd: {total_deleted}")

    finally:
        try:
            imap.logout()
            log("Uitgelogd van IMAP-server.")
        except Exception:
            pass

    log("=== Einde run ===")


if __name__ == "__main__":
    monitor_and_clear()
