"""Sign a PDF file using a PKCS#12 certificate."""
import argparse
import datetime
from pathlib import Path

from cryptography.hazmat.primitives.serialization import pkcs12
from endesive.pdf import cms


def load_p12(path, password):
    data = Path(path).read_bytes()
    key, cert, extra_certs = pkcs12.load_key_and_certificates(data, password.encode("utf-8"))
    if key is None or cert is None:
        raise ValueError("PKCS#12 file is missing key or certificate")
    return key, cert, extra_certs or []


def sign(
    input_pdf,
    output_pdf,
    p12_path,
    password,
    reason,
    location,
    contact,
    name,
    signature_box,
    tsa_url=None,
):
    key, cert, extra_certs = load_p12(p12_path, password)

    date = datetime.datetime.now(datetime.timezone.utc).strftime("D:%Y%m%d%H%M%S+00'00'")
    dct = {
        "sigflags": 3,
        "sigflagsft": 132,
        "sigpage": 0,
        "sigbutton": True,
        "sigfield": "Signature1",
        "auto_sigfield": True,
        "sigandcertify": True,
        "signaturebox": signature_box,
        "signature": name,
        "contact": contact,
        "location": location,
        "reason": reason,
        "signingdate": date,
        "aligned": 16384 if tsa_url else 0,
    }

    pdf_bytes = Path(input_pdf).read_bytes()
    signature = cms.sign(
        pdf_bytes,
        dct,
        key,
        cert,
        extra_certs,
        "sha256",
        None,
        tsa_url,
    )

    out_path = Path(output_pdf)
    with out_path.open("wb") as fp:
        fp.write(pdf_bytes)
        fp.write(signature)

    print(f"Signed PDF saved to {out_path.resolve()}")
    if tsa_url:
        print(f"Timestamp authority used: {tsa_url}")
    else:
        print("Note: no TSA used — signing time will be local-only and not authenticated.")


def main():
    parser = argparse.ArgumentParser(description="Sign a PDF file using PKCS#12 certificate.")
    parser.add_argument("-i", "--input", required=True, help="Input PDF path")
    parser.add_argument("-o", "--output", required=True, help="Output signed PDF path")
    parser.add_argument("-c", "--cert", default="cert.p12", help="PKCS#12 certificate path")
    parser.add_argument("-p", "--password", default="password", help="PKCS#12 password")
    parser.add_argument("--reason", default="Document approval", help="Reason for signing")
    parser.add_argument("--location", default="Indonesia", help="Signing location")
    parser.add_argument("--contact", default="signer@example.com", help="Contact info")
    parser.add_argument("--name", default="PDF Signer", help="Signer display name")
    parser.add_argument(
        "--box",
        nargs=4,
        type=float,
        default=[50, 50, 250, 120],
        metavar=("X1", "Y1", "X2", "Y2"),
        help="Signature visible box coordinates",
    )
    parser.add_argument(
        "--tsa",
        default=None,
        help=(
            "Optional TSA (Time Stamp Authority) URL to embed an authenticated timestamp. "
            "Examples: http://timestamp.digicert.com, http://timestamp.sectigo.com, "
            "http://freetsa.org/tsr"
        ),
    )
    args = parser.parse_args()

    sign(
        args.input,
        args.output,
        args.cert,
        args.password,
        args.reason,
        args.location,
        args.contact,
        args.name,
        tuple(args.box),
        args.tsa,
    )


if __name__ == "__main__":
    main()
