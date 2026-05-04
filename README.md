# PDF-Cert-Sign-Generator

Project ini berisi program untuk menggenerate signature file PDF menggunakan bahasa pemrograman Python.

Program terdiri dari tiga script utama:

- `generate_cert.py` — membuat self-signed certificate berformat PKCS#12 (`.p12`).
- `sign_pdf.py` — menandatangani file PDF menggunakan certificate `.p12`.
- `verify_pdf.py` — memverifikasi signature pada file PDF yang telah ditandatangani.

## Requirements

- Python 3.8+
- Dependencies: lihat `requirements.txt`

## Installation

```
pip install -r requirements.txt
```

## Usage

### 1. Generate self-signed certificate

```
python generate_cert.py -o cert.p12 -p password --cn "Nama Anda" --org "Organisasi" --country ID --email email@example.com --days 365

python generate_cert.py -o cert.p12 -p password --cn "Riyan Hidayat Samosir" --org "HanyaJasa.Com Org" --country ID --email hanyajasa@gmail.com --days 365

```

### 2. Sign a PDF

```
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --reason "Approval" --location "Jakarta" --name "Nama Anda"

python sign_pdf.py -i Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf -o 202604071034-MTsN-4-Bener-Meriah-Jln-Desa-Bener-Mulie-Bener_signed.pdf -c cert.p12 -p password --reason "Approval" --location "Palangka Raya" --name "Riyan Hidayat Samosir"


python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --tsa http://freetsa.org/tsr --reason "Approval" --location "Palangka Raya" --name "Riyan Hidayat Samosir"

python sign_pdf.py -i Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf -o 202604071034-MTsN-4-Bener-Meriah-Jln-Desa-Bener-Mulie-Bener.signed3tsr.pdf -c cert.p12 -p password --tsa http://freetsa.org/tsr --reason "Approval" --location "Palangka Raya" --name "Riyan Hidayat Samosir"

python sign_pdf.py -i Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf -o Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf.signed.pdf -c cert.p12 -p password --tsa http://freetsa.org/tsr --reason "Brosur layanan HanyaJasa.Com" --location "Palangka Raya" --name "Riyan Hidayat Samosir"

```

Optional, atur posisi visible signature box (koordinat PDF):

```
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --box 50 50 250 120

python sign_pdf.py -i Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf -o Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf2_signed.pdf -c cert.p12 -p password --box 50 50 250 120

```

Optional, sertakan **TSA timestamp** agar field "Ditandatangani pada" dan "Penanda waktu" terisi dengan waktu yang authenticated (bukan dari clock lokal). Tanpa TSA, viewer biasanya menampilkan "false (local)" karena waktu hanya berasal dari `/M` lokal:

```
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --tsa http://freetsa.org/tsr

python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --tsa http://timestamp.digicert.com
```

TSA URL umum yang gratis/publik:
- `http://freetsa.org/tsr`
- `http://timestamp.digicert.com`
- `http://timestamp.sectigo.com`

### Bangun TSA Sendiri

Anda juga bisa menjalankan TSA lokal/internal sendiri di Linux atau WSL. Lihat panduan [cara_setup_TSA.md](cara_setup_TSA.md) dan script otomatis [setup_tsa.sh](setup_tsa.sh):

```
chmod +x setup_tsa.sh
./setup_tsa.sh
```

Setelah selesai, gunakan endpoint TSA lokal:

```
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --tsa http://localhost:8080/
```

### 3. Verify signed PDF

```
python verify_pdf.py -i signed.pdf

python verify_pdf.py -i Brosur_Server_Non_ID_HanyaJasa.Com_2026.pdf2_signed.pdf

```

## Setup Repository

```
echo "# PDF-Cert-Sign-Generator" >> README.md
git init
git add README.md
git commit -m "first commit"
git branch -M main
git remote add origin https://github.com/ryanbekabe/PDF-Cert-Sign-Generator.git
git push -u origin main

curl -F "pdf=@Brosur_Server_Non_ID_HanyaJasa.Com_2026_mei.pdf" -o Brosur_Server_Non_ID_HanyaJasa.Com_1447.pdfoutput_signed.pdf  https://server203.rsipalangkaraya.co.id/php_a
pi/index.php/sign
```
