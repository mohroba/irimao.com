import ftplib
import hashlib
import os
import pathlib
import sys

HOST = "ftp.irimao.com"
USER = "dev@irimao.com"
PASSWORD = os.environ["IRIMAO_FTP_PASSWORD"]
LOCAL_ROOT = pathlib.Path(__file__).resolve().parents[1]
REMOTE_CANDIDATES = [
    "/wp-content/plugins/irimao-plugin",
    "/public_html/wp-content/plugins/imao-custom-plugin",
    "/wp-content/plugins/imao-custom-plugin",
    "/domains/irimao.com/public_html/wp-content/plugins/imao-custom-plugin",
]
BACKUP_ROOT = LOCAL_ROOT / "output" / "production-backups" / "card-alignment-and-qr-fix-14050626"

FILES = [
    "includes/Services/CompetitionCards.php",
]


def ensure_remote_dir(ftp, remote_dir):
    current = ""
    for part in remote_dir.strip("/").split("/"):
        current += "/" + part
        try:
            ftp.mkd(current)
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise


def sha256(data):
    return hashlib.sha256(data).hexdigest()


ftp = ftplib.FTP_TLS(HOST, timeout=30)
ftp.login(USER, PASSWORD)
ftp.prot_p()

REMOTE_ROOT = ""
for candidate in REMOTE_CANDIDATES:
    try:
        ftp.cwd(candidate)
        REMOTE_ROOT = candidate
        break
    except ftplib.error_perm:
        continue
if not REMOTE_ROOT:
    print("Remote plugin path not found", file=sys.stderr)
    sys.exit(2)

verified = []
for relative in FILES:
    local_path = LOCAL_ROOT / relative
    remote_path = REMOTE_ROOT + "/" + relative
    backup_path = BACKUP_ROOT / relative
    remote_data = bytearray()
    try:
        ftp.retrbinary("RETR " + remote_path, remote_data.extend)
        backup_path.parent.mkdir(parents=True, exist_ok=True)
        backup_path.write_bytes(remote_data)
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"):
            raise

    ensure_remote_dir(ftp, remote_path.rsplit("/", 1)[0])
    with local_path.open("rb") as stream:
        ftp.storbinary("STOR " + remote_path, stream)
    check = bytearray()
    ftp.retrbinary("RETR " + remote_path, check.extend)
    local_hash = sha256(local_path.read_bytes())
    if sha256(check) != local_hash:
        raise RuntimeError("Hash mismatch: " + relative)
    verified.append(relative)

ftp.quit()
print("Remote root:", REMOTE_ROOT)
print("Verified files:", len(verified))
print("Backup:", BACKUP_ROOT)
