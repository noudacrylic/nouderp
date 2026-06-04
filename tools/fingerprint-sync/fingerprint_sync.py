"""
Fingerprint Sidecar for Noud ERP.

Polls a fingerprint machine via the ZKTeco-compatible TCP/UDP protocol
and POSTs new attendance records to the ERP's ADMS-compatible endpoint.

Usage:
    python fingerprint_sync.py [config.ini]
"""

import configparser
import json
import logging
import os
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    from zk import ZK
except ImportError:
    print("ERROR: pyzk belum terinstall. Jalankan: pip install -r requirements.txt")
    sys.exit(1)

try:
    import requests
except ImportError:
    print("ERROR: requests belum terinstall. Jalankan: pip install -r requirements.txt")
    sys.exit(1)


SCRIPT_DIR = Path(__file__).resolve().parent


def load_config(path: Path) -> configparser.ConfigParser:
    if not path.exists():
        print(f"ERROR: config file tidak ada: {path}")
        print("Tip: copy config.example.ini ke config.ini lalu edit nilai-nya.")
        sys.exit(1)
    cfg = configparser.ConfigParser()
    cfg.read(path, encoding="utf-8")
    return cfg


def setup_logging(log_file: str | None) -> logging.Logger:
    logger = logging.getLogger("fp_sync")
    logger.setLevel(logging.INFO)
    fmt = logging.Formatter("[%(asctime)s] %(levelname)s %(message)s", datefmt="%Y-%m-%d %H:%M:%S")

    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)
    logger.addHandler(sh)

    if log_file:
        fh = logging.FileHandler(SCRIPT_DIR / log_file, encoding="utf-8")
        fh.setFormatter(fmt)
        logger.addHandler(fh)

    return logger


def load_state(path: Path) -> dict:
    if path.exists():
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            return {"last_timestamp": None}
    return {"last_timestamp": None}


def save_state(path: Path, state: dict) -> None:
    path.write_text(json.dumps(state, indent=2, default=str), encoding="utf-8")


def fetch_attendance(cfg, logger):
    """Connect to fingerprint machine and pull all attendance records."""
    ip = cfg.get("machine", "ip")
    port = cfg.getint("machine", "port")
    timeout = cfg.getint("machine", "timeout", fallback=10)
    force_udp = cfg.getboolean("machine", "force_udp", fallback=False)
    password = cfg.getint("machine", "password", fallback=0)

    logger.info(f"Connecting to mesin {ip}:{port} (UDP={force_udp})...")
    zk = ZK(ip, port=port, timeout=timeout, password=password, force_udp=force_udp, ommit_ping=True)

    conn = None
    try:
        conn = zk.connect()
        logger.info("Connected. Fetching attendance...")
        conn.disable_device()
        attendances = conn.get_attendance() or []
        conn.enable_device()
        logger.info(f"Fetched {len(attendances)} total record(s) from mesin.")
        return attendances
    finally:
        if conn:
            try:
                conn.disconnect()
            except Exception:
                pass


def filter_new(records, last_timestamp_iso: str | None):
    """Return only records newer than last_timestamp."""
    if not last_timestamp_iso:
        return records
    try:
        last_dt = datetime.fromisoformat(last_timestamp_iso)
    except Exception:
        return records
    return [r for r in records if r.timestamp > last_dt]


def post_to_erp(cfg, records, logger):
    """POST new records to ERP /iclock/cdata in ATTLOG format."""
    if not records:
        return 0

    endpoint = cfg.get("erp", "endpoint")
    http_timeout = cfg.getint("erp", "http_timeout", fallback=10)
    sn = cfg.get("machine", "serial_number")

    lines = []
    for r in records:
        # ATTLOG format: PIN<TAB>YYYY-MM-DD HH:MM:SS<TAB>Status<TAB>Verify<TAB>Workcode<TAB>Reserved
        # status: 0=check_in, 1=check_out, 2=break_out, 3=break_in, 4=ot_in, 5=ot_out
        # verify: 0=password, 1=fingerprint, 2=card, 15=face
        status = getattr(r, "status", 0) or 0
        punch = getattr(r, "punch", 1) or 1
        line = f"{r.user_id}\t{r.timestamp.strftime('%Y-%m-%d %H:%M:%S')}\t{status}\t{punch}\t0\t0"
        lines.append(line)

    body = "\n".join(lines)
    url = f"{endpoint}?SN={sn}&table=ATTLOG&Stamp=9999"

    logger.info(f"POST {len(records)} record(s) to {url}")
    resp = requests.post(url, data=body.encode("utf-8"),
                         headers={"Content-Type": "text/plain"},
                         timeout=http_timeout)
    logger.info(f"  → HTTP {resp.status_code}: {resp.text[:200]}")
    resp.raise_for_status()
    return len(records)


def poll_once(cfg, state_path: Path, logger) -> None:
    state = load_state(state_path)
    last_ts = state.get("last_timestamp")

    try:
        records = fetch_attendance(cfg, logger)
    except Exception as e:
        logger.error(f"Failed to fetch from mesin: {e}")
        return

    new = filter_new(records, last_ts)
    if not new:
        logger.info("No new records since last poll.")
        return

    logger.info(f"{len(new)} new record(s) to push.")

    try:
        post_to_erp(cfg, new, logger)
    except Exception as e:
        logger.error(f"Failed to POST to ERP: {e}")
        return

    # Update state to latest record's timestamp
    latest_ts = max(r.timestamp for r in new)
    state["last_timestamp"] = latest_ts.isoformat()
    state["last_poll_at"] = datetime.now().isoformat()
    state["last_pushed_count"] = len(new)
    save_state(state_path, state)
    logger.info(f"State updated: last_timestamp = {state['last_timestamp']}")


def main():
    config_path = SCRIPT_DIR / (sys.argv[1] if len(sys.argv) > 1 else "config.ini")
    cfg = load_config(config_path)

    log_file = cfg.get("poll", "log_file", fallback="")
    logger = setup_logging(log_file or None)

    state_file = cfg.get("poll", "state_file", fallback="state.json")
    state_path = SCRIPT_DIR / state_file

    interval = cfg.getint("poll", "interval_seconds", fallback=30)

    logger.info("=" * 60)
    logger.info(f"Fingerprint Sidecar started. Interval: {interval}s")
    logger.info(f"Config: {config_path}")
    logger.info(f"State: {state_path}")
    logger.info("=" * 60)

    while True:
        try:
            poll_once(cfg, state_path, logger)
        except KeyboardInterrupt:
            logger.info("Interrupted, exiting.")
            return
        except Exception as e:
            logger.exception(f"Unexpected error in poll loop: {e}")

        time.sleep(interval)


if __name__ == "__main__":
    main()
