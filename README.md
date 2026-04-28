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
```

### 2. Sign a PDF

```
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --reason "Approval" --location "Jakarta" --name "Nama Anda"
```

Optional, atur posisi visible signature box (koordinat PDF):

```
python sign_pdf.py -i input.pdf -o signed.pdf -c cert.p12 -p password --box 50 50 250 120
```

### 3. Verify signed PDF

```
python verify_pdf.py -i signed.pdf
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
```
