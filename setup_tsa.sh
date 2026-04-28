#!/usr/bin/env bash
# setup_tsa.sh - Automated installer untuk Time Stamp Authority (RFC 3161) di Ubuntu/WSL.
# Lihat cara_setup_TSA.md untuk penjelasan lengkap.
set -euo pipefail

# ---------- Konfigurasi (boleh diubah via env var) ----------
TSA_HOME="${TSA_HOME:-$HOME/myTSA}"
TSA_PORT="${TSA_PORT:-8080}"
TSA_BIND="${TSA_BIND:-0.0.0.0}"
ROOT_CN="${ROOT_CN:-MyTSA Root CA}"
ROOT_O="${ROOT_O:-MyTSA}"
ROOT_C="${ROOT_C:-ID}"
SIGNER_CN="${SIGNER_CN:-MyTSA Time-Stamp Authority}"
ROOT_DAYS="${ROOT_DAYS:-3650}"
SIGNER_DAYS="${SIGNER_DAYS:-1825}"
INSTALL_SYSTEMD="${INSTALL_SYSTEMD:-auto}"   # auto|yes|no

# ---------- Helpers ----------
RED=$'\e[31m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; CYAN=$'\e[36m'; RESET=$'\e[0m'
log()   { echo "${CYAN}[*]${RESET} $*"; }
ok()    { echo "${GREEN}[OK]${RESET} $*"; }
warn()  { echo "${YELLOW}[!]${RESET} $*"; }
err()   { echo "${RED}[ERR]${RESET} $*" >&2; }

require_cmd() { command -v "$1" >/dev/null 2>&1 || { err "Perintah '$1' tidak ditemukan."; return 1; }; }

is_wsl() {
    grep -qiE "(microsoft|wsl)" /proc/version 2>/dev/null
}

detect_systemd() {
    if [[ "$INSTALL_SYSTEMD" == "no" ]]; then return 1; fi
    if [[ "$INSTALL_SYSTEMD" == "yes" ]]; then return 0; fi
    # auto: aktif jika systemd PID 1 dan systemctl bisa
    [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1
}

# ---------- Step 1: Install dependencies ----------
install_deps() {
    log "Memasang dependencies (openssl, python3, chrony)..."
    if [[ $EUID -ne 0 ]]; then SUDO=sudo; else SUDO=""; fi
    $SUDO apt-get update -qq
    $SUDO apt-get install -y openssl python3 ca-certificates curl >/dev/null
    if ! is_wsl; then
        $SUDO apt-get install -y chrony >/dev/null || warn "chrony gagal dipasang (lanjut tanpa)."
        $SUDO systemctl enable --now chrony 2>/dev/null || true
    else
        warn "WSL terdeteksi — clock disinkron oleh host Windows. Skip chrony."
    fi
    ok "Dependencies terpasang."
}

# ---------- Step 2: Bikin direktori + openssl.cnf ----------
bootstrap_ca_dir() {
    log "Menyiapkan struktur direktori di $TSA_HOME ..."
    mkdir -p "$TSA_HOME/ca"/{certs,crl,newcerts,private,tsa}
    chmod 700 "$TSA_HOME/ca/private"
    : > "$TSA_HOME/ca/index.txt"
    [[ -f "$TSA_HOME/ca/serial"    ]] || echo 1000 > "$TSA_HOME/ca/serial"
    [[ -f "$TSA_HOME/ca/tsaserial" ]] || echo 1000 > "$TSA_HOME/ca/tsaserial"
    write_openssl_cnf
    ok "Struktur direktori siap."
}

write_openssl_cnf() {
    cat > "$TSA_HOME/ca/openssl.cnf" <<EOF
# OpenSSL config untuk MyTSA — di-generate oleh setup_tsa.sh
[ ca ]
default_ca = CA_default

[ CA_default ]
dir              = $TSA_HOME/ca
certs            = \$dir/certs
new_certs_dir    = \$dir/newcerts
database         = \$dir/index.txt
serial           = \$dir/serial
private_key      = \$dir/private/cakey.pem
certificate      = \$dir/certs/cacert.pem
default_md       = sha256
default_days     = $ROOT_DAYS
policy           = policy_any
unique_subject   = no
copy_extensions  = none

[ policy_any ]
countryName            = optional
stateOrProvinceName    = optional
organizationName       = optional
organizationalUnitName = optional
commonName             = supplied
emailAddress           = optional

[ req ]
default_bits       = 4096
default_md         = sha256
distinguished_name = req_dn
prompt             = no

[ req_dn ]
C  = $ROOT_C
O  = $ROOT_O
CN = $ROOT_CN

[ v3_ca ]
basicConstraints     = critical, CA:TRUE
keyUsage             = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash

[ v3_tsa ]
basicConstraints     = critical, CA:FALSE
keyUsage             = critical, digitalSignature, nonRepudiation
extendedKeyUsage     = critical, timeStamping
subjectKeyIdentifier = hash

[ tsa ]
default_tsa = tsa_config1

[ tsa_config1 ]
dir                = $TSA_HOME/ca
serial             = \$dir/tsaserial
crypto_device      = builtin
signer_cert        = \$dir/tsa/tsacert.pem
certs              = \$dir/certs/cacert.pem
signer_key         = \$dir/tsa/tsakey.pem
signer_digest      = sha256
default_policy     = 1.2.3.4.1
other_policies     = 1.2.3.4.5.6, 1.2.3.4.5.7
digests            = sha256, sha384, sha512
accuracy           = secs:1, millisecs:500, microsecs:100
clock_precision_digits = 0
ordering           = yes
tsa_name           = yes
ess_cert_id_chain  = no
ess_cert_id_alg    = sha256
EOF
}

# ---------- Step 3: Generate Root CA ----------
generate_root_ca() {
    if [[ -f "$TSA_HOME/ca/certs/cacert.pem" ]]; then
        warn "Root CA sudah ada di $TSA_HOME/ca/certs/cacert.pem — skip generate."
        return
    fi
    log "Generate Root CA (RSA 4096, $ROOT_DAYS hari)..."
    openssl req -new -x509 -newkey rsa:4096 -nodes \
        -keyout "$TSA_HOME/ca/private/cakey.pem" \
        -out    "$TSA_HOME/ca/certs/cacert.pem" \
        -days   "$ROOT_DAYS" \
        -extensions v3_ca \
        -config "$TSA_HOME/ca/openssl.cnf" >/dev/null 2>&1
    chmod 600 "$TSA_HOME/ca/private/cakey.pem"
    ok "Root CA dibuat: $TSA_HOME/ca/certs/cacert.pem"
}

# ---------- Step 4: Generate TSA signer cert ----------
generate_tsa_cert() {
    if [[ -f "$TSA_HOME/ca/tsa/tsacert.pem" ]]; then
        warn "TSA signer cert sudah ada — skip generate."
        return
    fi
    log "Generate TSA signer cert (RSA 2048, $SIGNER_DAYS hari)..."
    openssl req -new -newkey rsa:2048 -nodes \
        -keyout "$TSA_HOME/ca/tsa/tsakey.pem" \
        -out    "$TSA_HOME/ca/tsa/tsa.csr" \
        -subj   "/C=$ROOT_C/O=$ROOT_O/CN=$SIGNER_CN" >/dev/null 2>&1
    chmod 600 "$TSA_HOME/ca/tsa/tsakey.pem"

    openssl ca -batch \
        -config     "$TSA_HOME/ca/openssl.cnf" \
        -extensions v3_tsa \
        -days       "$SIGNER_DAYS" \
        -in         "$TSA_HOME/ca/tsa/tsa.csr" \
        -out        "$TSA_HOME/ca/tsa/tsacert.pem" >/dev/null 2>&1
    ok "TSA signer cert dibuat: $TSA_HOME/ca/tsa/tsacert.pem"
}

# ---------- Step 5: Self-test ----------
self_test() {
    log "Self-test TSA secara lokal..."
    local tmp; tmp=$(mktemp -d)
    echo "tsa selftest $(date -u +%FT%TZ)" > "$tmp/data.txt"

    openssl ts -query -data "$tmp/data.txt" -sha256 -cert -out "$tmp/req.tsq" >/dev/null 2>&1
    openssl ts -reply -config "$TSA_HOME/ca/openssl.cnf" \
        -queryfile "$tmp/req.tsq" -out "$tmp/rep.tsr" >/dev/null 2>&1

    if openssl ts -verify -in "$tmp/rep.tsr" -queryfile "$tmp/req.tsq" \
            -CAfile     "$TSA_HOME/ca/certs/cacert.pem" \
            -untrusted  "$TSA_HOME/ca/tsa/tsacert.pem" 2>&1 | grep -q "Verification: OK"; then
        ok "Self-test sukses."
    else
        err "Self-test GAGAL. Cek $TSA_HOME/ca/openssl.cnf."
        rm -rf "$tmp"; exit 1
    fi
    rm -rf "$tmp"
}

# ---------- Step 6: HTTP server ----------
write_http_server() {
    log "Menulis tsa_server.py ..."
    cat > "$TSA_HOME/tsa_server.py" <<'PYEOF'
"""Minimal HTTP front-end untuk OpenSSL TSA (RFC 3161)."""
import os, subprocess, tempfile, sys, signal
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TSA_HOME = os.environ.get("TSA_HOME", os.path.dirname(os.path.abspath(__file__)))
CONFIG   = os.path.join(TSA_HOME, "ca", "openssl.cnf")
BIND     = os.environ.get("TSA_BIND", "0.0.0.0")
PORT     = int(os.environ.get("TSA_PORT", "8080"))
MAX_BODY = 64 * 1024  # 64 KB cukup untuk TimeStampReq normal

class TSAHandler(BaseHTTPRequestHandler):
    server_version = "MyTSA/1.0"

    def log_message(self, fmt, *args):
        sys.stderr.write("[%s] %s\n" % (self.log_date_time_string(), fmt % args))

    def do_GET(self):
        body = b"MyTSA RFC 3161 endpoint. POST application/timestamp-query.\n"
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        ctype = self.headers.get("Content-Type", "").split(";")[0].strip()
        if ctype != "application/timestamp-query":
            self.send_error(415, "Expected Content-Type: application/timestamp-query")
            return
        length = int(self.headers.get("Content-Length", "0"))
        if length <= 0 or length > MAX_BODY:
            self.send_error(413, "Invalid Content-Length")
            return
        tsq = self.rfile.read(length)

        with tempfile.TemporaryDirectory() as td:
            qpath = os.path.join(td, "req.tsq")
            rpath = os.path.join(td, "rep.tsr")
            with open(qpath, "wb") as f:
                f.write(tsq)
            proc = subprocess.run(
                ["openssl", "ts", "-reply",
                 "-config", CONFIG,
                 "-queryfile", qpath,
                 "-out", rpath],
                cwd=TSA_HOME,
                capture_output=True,
            )
            if proc.returncode != 0 or not os.path.exists(rpath):
                msg = proc.stderr.decode(errors="replace") or "openssl ts -reply failed"
                self.send_error(500, msg.strip().splitlines()[-1] if msg else "TSA error")
                return
            with open(rpath, "rb") as f:
                tsr = f.read()

        self.send_response(200)
        self.send_header("Content-Type", "application/timestamp-reply")
        self.send_header("Content-Length", str(len(tsr)))
        self.end_headers()
        self.wfile.write(tsr)


def main():
    server = ThreadingHTTPServer((BIND, PORT), TSAHandler)
    def shutdown(*_):
        sys.stderr.write("\nShutting down...\n")
        server.shutdown()
    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)
    sys.stderr.write("MyTSA listening on http://%s:%d/  (TSA_HOME=%s)\n" % (BIND, PORT, TSA_HOME))
    server.serve_forever()

if __name__ == "__main__":
    main()
PYEOF
    chmod +x "$TSA_HOME/tsa_server.py"
    ok "HTTP server: $TSA_HOME/tsa_server.py"
}

# ---------- Step 7: systemd unit ----------
install_systemd_unit() {
    if ! detect_systemd; then
        warn "systemd tidak tersedia / dimatikan — skip pemasangan service."
        warn "Jalankan manual: TSA_HOME=$TSA_HOME TSA_PORT=$TSA_PORT python3 $TSA_HOME/tsa_server.py"
        return
    fi
    log "Memasang systemd service 'mytsa' ..."
    local unit=/etc/systemd/system/mytsa.service
    sudo tee "$unit" >/dev/null <<EOF
[Unit]
Description=MyTSA Time-Stamp Authority (RFC 3161)
After=network-online.target chrony.service
Wants=network-online.target

[Service]
Type=simple
User=$USER
Environment=TSA_HOME=$TSA_HOME
Environment=TSA_BIND=$TSA_BIND
Environment=TSA_PORT=$TSA_PORT
ExecStart=/usr/bin/python3 $TSA_HOME/tsa_server.py
Restart=on-failure
RestartSec=3
NoNewPrivileges=yes
ProtectSystem=full
ProtectHome=read-only
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
EOF
    sudo systemctl daemon-reload
    sudo systemctl enable --now mytsa
    sleep 1
    if systemctl is-active --quiet mytsa; then
        ok "Service 'mytsa' aktif."
    else
        warn "Service 'mytsa' gagal start. Cek 'sudo journalctl -u mytsa -n 50'."
    fi
}

# ---------- Step 8: Smoke test via HTTP ----------
http_smoke_test() {
    if ! detect_systemd && ! pgrep -f tsa_server.py >/dev/null 2>&1; then
        warn "Server belum running — skip HTTP smoke test."
        return
    fi
    log "HTTP smoke test ke http://127.0.0.1:$TSA_PORT/ ..."
    local tmp; tmp=$(mktemp -d)
    echo "smoke" > "$tmp/d.txt"
    openssl ts -query -data "$tmp/d.txt" -sha256 -cert -out "$tmp/req.tsq" >/dev/null 2>&1
    if curl -fsS --max-time 10 \
            -H "Content-Type: application/timestamp-query" \
            --data-binary "@$tmp/req.tsq" \
            "http://127.0.0.1:$TSA_PORT/" -o "$tmp/rep.tsr"; then
        if openssl ts -verify -in "$tmp/rep.tsr" -queryfile "$tmp/req.tsq" \
                -CAfile    "$TSA_HOME/ca/certs/cacert.pem" \
                -untrusted "$TSA_HOME/ca/tsa/tsacert.pem" 2>&1 | grep -q "Verification: OK"; then
            ok "HTTP smoke test sukses."
        else
            warn "HTTP terjawab tapi verifikasi gagal."
        fi
    else
        warn "HTTP smoke test gagal — server mungkin belum siap."
    fi
    rm -rf "$tmp"
}

# ---------- Summary ----------
print_summary() {
    local ip
    ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    [[ -z "${ip:-}" ]] && ip="127.0.0.1"

    cat <<EOF

${GREEN}========================================================${RESET}
${GREEN}  MyTSA setup selesai${RESET}
${GREEN}========================================================${RESET}

  TSA_HOME    : $TSA_HOME
  Endpoint    : http://$ip:$TSA_PORT/
                (lokal: http://127.0.0.1:$TSA_PORT/)
  Root CA     : $TSA_HOME/ca/certs/cacert.pem
  TSA cert    : $TSA_HOME/ca/tsa/tsacert.pem

  Pakai dari klien (PDF-Cert-Sign-Generator):
    python sign_pdf.py -i input.pdf -o signed.pdf \\
        -c cert.p12 -p password \\
        --tsa http://$ip:$TSA_PORT/

  Manajemen service (kalau systemd terpasang):
    sudo systemctl status  mytsa
    sudo systemctl restart mytsa
    sudo journalctl -u mytsa -f

  Verifikasi manual:
    cd $TSA_HOME
    echo hi > /tmp/d.txt
    openssl ts -query -data /tmp/d.txt -sha256 -cert -out /tmp/r.tsq
    curl -H "Content-Type: application/timestamp-query" \\
         --data-binary @/tmp/r.tsq \\
         http://127.0.0.1:$TSA_PORT/ -o /tmp/r.tsr
    openssl ts -verify -in /tmp/r.tsr -queryfile /tmp/r.tsq \\
         -CAfile    $TSA_HOME/ca/certs/cacert.pem \\
         -untrusted $TSA_HOME/ca/tsa/tsacert.pem

${YELLOW}CATATAN:${RESET} Root CA ini self-signed. Viewer pihak ketiga
tidak akan trust kecuali root CA Anda di-import ke trust store
mereka. Lihat cara_setup_TSA.md untuk detail.

EOF
}

# ---------- Main ----------
main() {
    log "MyTSA setup dimulai (TSA_HOME=$TSA_HOME, port=$TSA_PORT)"
    require_cmd openssl
    require_cmd python3 || install_deps

    if ! command -v openssl >/dev/null 2>&1 || ! command -v python3 >/dev/null 2>&1; then
        install_deps
    fi

    bootstrap_ca_dir
    generate_root_ca
    generate_tsa_cert
    self_test
    write_http_server
    install_systemd_unit
    http_smoke_test
    print_summary
}

main "$@"
