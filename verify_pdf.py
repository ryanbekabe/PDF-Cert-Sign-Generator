"""Verify digital signatures on a signed PDF file."""
import argparse
from pathlib import Path

from endesive.pdf import verify as pdf_verify


def verify(pdf_path, trusted_certs):
    data = Path(pdf_path).read_bytes()
    results = pdf_verify(data, trusted_certs)

    if not results:
        print("No signatures found.")
        return

    for index, (hashok, signatureok, certok) in enumerate(results, start=1):
        print(f"Signature #{index}")
        print(f"  hash ok      : {hashok}")
        print(f"  signature ok : {signatureok}")
        print(f"  cert ok      : {certok}")


def main():
    parser = argparse.ArgumentParser(description="Verify signatures on a signed PDF.")
    parser.add_argument("-i", "--input", required=True, help="Signed PDF path")
    args = parser.parse_args()
    verify(args.input, [])


if __name__ == "__main__":
    main()
