"""
Quick test: connect to mesin & dump first few attendance records.
Runs once and exits — does NOT POST to ERP.

Usage:
    python test_connection.py [config.ini]
"""

import configparser
import sys
from pathlib import Path

try:
    from zk import ZK
except ImportError:
    print("ERROR: pyzk belum terinstall. Jalankan: pip install -r requirements.txt")
    sys.exit(1)


SCRIPT_DIR = Path(__file__).resolve().parent


def main():
    config_path = SCRIPT_DIR / (sys.argv[1] if len(sys.argv) > 1 else "config.ini")
    if not config_path.exists():
        print(f"ERROR: config tidak ada: {config_path}")
        sys.exit(1)

    cfg = configparser.ConfigParser()
    cfg.read(config_path, encoding="utf-8")

    ip = cfg.get("machine", "ip")
    port = cfg.getint("machine", "port")
    timeout = cfg.getint("machine", "timeout", fallback=10)
    force_udp = cfg.getboolean("machine", "force_udp", fallback=False)
    password = cfg.getint("machine", "password", fallback=0)

    print(f"Connecting to {ip}:{port} (UDP={force_udp}, timeout={timeout}s)...")
    zk = ZK(ip, port=port, timeout=timeout, password=password, force_udp=force_udp, ommit_ping=True)

    try:
        conn = zk.connect()
    except Exception as e:
        print(f"\n❌ FAILED to connect: {e}")
        print("\nKemungkinan penyebab:")
        print("  1. IP/port salah — verifikasi mesin terjangkau (ping 192.168.1.224)")
        print("  2. Mesin tidak speak ZKTeco protocol — TH600 V9.9 mungkin Realand proprietary")
        print("  3. Coba ubah force_udp=true atau false di config.ini")
        print("  4. Coba ubah port ke 4370 (ZKTeco standard) untuk test alternatif")
        sys.exit(2)

    print("✅ Connected!")
    try:
        info = {
            "firmware": conn.get_firmware_version() if hasattr(conn, "get_firmware_version") else "?",
            "serial": conn.get_serialnumber() if hasattr(conn, "get_serialnumber") else "?",
            "device_name": conn.get_device_name() if hasattr(conn, "get_device_name") else "?",
        }
        print(f"\nDevice info: {info}")

        users = conn.get_users() or []
        print(f"\nUsers di mesin: {len(users)}")
        for u in users[:5]:
            print(f"  - PIN={u.user_id} Name={u.name}")
        if len(users) > 5:
            print(f"  ... dan {len(users) - 5} lainnya")

        atts = conn.get_attendance() or []
        print(f"\nAttendance records di mesin: {len(atts)}")
        for a in atts[-5:]:
            print(f"  - PIN={a.user_id} Time={a.timestamp} Status={getattr(a, 'status', '?')} Punch={getattr(a, 'punch', '?')}")

        print("\n✅ Test sukses. Sekarang Anda bisa jalankan: python fingerprint_sync.py")
    finally:
        try:
            conn.disconnect()
        except Exception:
            pass


if __name__ == "__main__":
    main()
