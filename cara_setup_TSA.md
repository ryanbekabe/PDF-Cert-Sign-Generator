# Cara Setup TSA (Time Stamp Authority) Sendiri

Panduan ini menjelaskan cara membangun server TSA mirip [FreeTSA](https://freetsa.org) sendiri di Linux Ubuntu (server fisik, VPS, atau WSL). Server TSA berfungsi memberi stempel waktu kriptografi (RFC 3161) pada signature PDF, sehingga field "Ditandatangani pada" dan "Penanda waktu" pada PDF viewer terisi dengan waktu yang authenticated, bukan clock lokal.

## Daftar Isi
- [Prasyarat](#prasyarat)
- [Arsitektur Singkat](#arsitektur-singkat)
- [Langkah 1 — Install Dependencies](#langkah-1--install-dependencies)
- [Langkah 2 — Generate Root CA](#langkah-2--generate-root-ca)
- [Langkah 3 — Generate TSA Signer Certificate](#langkah-3--generate-tsa-signer-certificate)
- [Langkah 4 — Test TSA Secara Lokal](#langkah-4--test-tsa-secara-lokal)
- [Langkah 5 — Jalankan HTTP Endpoint](#langkah-5--jalankan-http-endpoint)
- [Langkah 6 — Pakai dari Klien](#langkah-6--pakai-dari-klien)
- [Production Hardening (Opsional)](#production-hardening-opsional)
- [Troubleshooting](#troubleshooting)
- [Catatan Penting](#catatan-penting)

## Prasyarat

- Ubuntu 20.04 / 22.04 / 24.04 (atau distro turunannya)
- Akses `sudo`
- Port HTTP yang bisa diakses dari klien (default script menggunakan `8080`)
- Koneksi internet untuk install paket dan sinkronisasi NTP

## Arsitektur Singkat

```
[ Klien (sign_pdf.py) ]
        │
        │  HTTP POST application/timestamp-query  (TimeStampReq, ASN.1/DER)
        ▼
[ Python HTTP server :8080 ]
        │
        │  exec: openssl ts -reply -config openssl.cnf
        ▼
[ OpenSSL TSA engine ]  ←── private key TSA + cert chain
        │
        │  TimeStampResp (CMS SignedData berisi TSTInfo)
        ▼
[ Klien — embed sebagai unsigned attribute pada CMS PDF signature ]
```

Komponen utama:
- **OpenSSL `ts`** — implementasi RFC 3161
- **Python `http.server`** — front-end HTTP minimal
- **chrony** — sinkronisasi clock ke NTP

## Langkah 1 — Install Dependencies

```bash
sudo apt update
sudo apt install -y openssl python3 chrony
sudo systemctl enable --now chrony
chronyc tracking      # cek offset < 50ms
```

## Langkah 2 — Generate Root CA

Buat struktur direktori:
```bash
mkdir -p ~/myTSA && cd ~/myTSA
mkdir -p ca/{certs,crl,newcerts,private,tsa}
chmod 700 ca/private
touch ca/index.txt
echo 1000 > ca/serial
echo 1000 > ca/tsaserial
```

Buat file `ca/openssl.cnf` — lihat [setup_tsa.sh](setup_tsa.sh) (script otomatis menulis file ini).

Generate Root CA (RSA 4096-bit, valid 10 tahun):
```bash
openssl req -new -x509 -newkey rsa:4096 -nodes \
  -keyout ca/private/cakey.pem \
  -out ca/certs/cacert.pem \
  -days 3650 -extensions v3_ca \
  -config ca/openssl.cnf
```

## Langkah 3 — Generate TSA Signer Certificate

```bash
openssl req -new -newkey rsa:2048 -nodes \
  -keyout ca/tsa/tsakey.pem \
  -out ca/tsa/tsa.csr \
  -subj "/C=ID/O=MyTSA/CN=MyTSA Time-Stamp Authority"

openssl ca -batch \
  -config ca/openssl.cnf \
  -extensions v3_tsa \
  -days 1825 \
  -in ca/tsa/tsa.csr \
  -out ca/tsa/tsacert.pem
```

TSA cert wajib punya **Extended Key Usage = `id-kp-timeStamping`** (OID `1.3.6.1.5.5.7.3.8`). Section `[ v3_tsa ]` di `openssl.cnf` mengatur ini.

## Langkah 4 — Test TSA Secara Lokal

```bash
# Bikin request hash dari file apa pun
echo "hello tsa" > /tmp/test.txt
openssl ts -query -data /tmp/test.txt -sha256 -cert -out /tmp/test.tsq

# Proses request menjadi reply
openssl ts -reply -config ca/openssl.cnf \
  -queryfile /tmp/test.tsq -out /tmp/test.tsr

# Verifikasi
openssl ts -verify -in /tmp/test.tsr -queryfile /tmp/test.tsq \
  -CAfile ca/certs/cacert.pem -untrusted ca/tsa/tsacert.pem
```

Output yang diharapkan: `Verification: OK`.

## Langkah 5 — Jalankan HTTP Endpoint

Script `setup_tsa.sh` membuat file `tsa_server.py` di `~/myTSA`. Jalankan:

```bash
cd ~/myTSA
python3 tsa_server.py
```

Atau pasang sebagai systemd service:
```bash
sudo systemctl enable --now mytsa
sudo systemctl status mytsa
```

Test dari mesin lain:
```bash
curl -H "Content-Type: application/timestamp-query" \
  --data-binary @test.tsq http://<server-ip>:8080/ \
  -o test.tsr
```

## Langkah 6 — Pakai dari Klien

Dari project [PDF-Cert-Sign-Generator](README.md):

```bash
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password \
    --tsa http://<server-ip>:8080/
```

Untuk WSL, dari host Windows pakai `http://localhost:8080/` (WSL2 otomatis port-forward), atau gunakan IP WSL dari `wsl hostname -I`.

Verifikasi PDF hasil — field "Penanda waktu" harus terisi.

## Production Hardening (Opsional)

| Concern | Solusi |
|---|---|
| HTTPS | Reverse proxy `nginx` + Let's Encrypt (`certbot --nginx`) |
| Akurasi waktu | Pastikan `chronyc tracking` offset < 50ms ke stratum 1/2 |
| Auto-restart | systemd unit (script setup sudah membuat ini) |
| Auditability | `ca/index.txt` mencatat setiap serial yang dikeluarkan |
| Rate limiting | `nginx limit_req_zone` |
| Key protection | Pindahkan private key TSA ke HSM (SoftHSM, YubiHSM, Nitrokey) via OpenSSL engine |
| Backup | Backup direktori `ca/` (private key + database) ke storage offline |

Contoh nginx reverse proxy:

```nginx
server {
    listen 443 ssl http2;
    server_name tsa.example.com;
    ssl_certificate     /etc/letsencrypt/live/tsa.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tsa.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080/;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        client_max_body_size 64k;
    }
}
```

## Troubleshooting

**`openssl ts -reply` gagal dengan "Could not find config info"**
→ Pastikan `cd ~/myTSA` sebelum menjalankan, atau pakai path absolut pada `-config`.

**`Verification: FAILED` saat test lokal**
→ Cek `ess_cert_id_alg = sha256` di `openssl.cnf`. Untuk OpenSSL 3.x, hash default sudah SHA-256. Untuk OpenSSL 1.0.x, perlu `signer_digest = sha1`.

**Klien `endesive` error `AssertionError` saat sign dengan TSA**
→ Set `aligned: 16384` (atau lebih besar) di dct signature — placeholder default tidak cukup besar untuk TSA token. Project ini sudah menanganinya di [sign_pdf.py](sign_pdf.py).

**TSA token tidak terembed di PDF**
→ Pastikan TSA URL dapat diakses dari mesin signer. Test dengan `curl -v` dulu.

**Waktu yang dikembalikan TSA salah**
→ Cek `timedatectl status` dan `chronyc sources -v`. Server TSA WAJIB sinkron ke NTP.

## Catatan Penting

1. **TSA Anda self-trusted** — root CA buatan sendiri tidak otomatis dipercaya viewer mana pun. Untuk PDF yang harus diverifikasi pihak ketiga (Adobe Reader, portal pemerintah, dsb.), Anda perlu salah satu dari:
   - Distribusikan root CA ke trust store penerima, atau
   - Pakai TSA publik tepercaya (FreeTSA, DigiCert, Sectigo).

2. **TSA self-hosted cocok untuk:** internal organisasi, testing/development, arsip internal, lab forensik, riset.

3. **Tidak cocok untuk:** dokumen kontrak antar-organisasi yang memerlukan trust chain publik, signing yang harus lulus PAdES-LTV publik, kepatuhan PSrE Indonesia (KOMDIGI), Adobe AATL, EUTL.

4. **Backup `ca/private/cakey.pem` dan `ca/tsa/tsakey.pem`** ke storage offline. Kehilangan key = kehilangan kemampuan menerbitkan signer cert baru / token TSA yang konsisten.

5. **Rotasi cert:** TSA signer cert pada panduan ini valid 5 tahun. Sebelum expired, generate signer cert baru di bawah root CA yang sama agar token lama tetap dapat diverifikasi.
