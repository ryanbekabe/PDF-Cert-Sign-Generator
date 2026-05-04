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
