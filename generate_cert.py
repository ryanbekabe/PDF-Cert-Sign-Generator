"""Generate a self-signed PKCS#12 certificate for PDF signing."""
import argparse
import datetime
from pathlib import Path

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import NameOID


def build_subject(common_name, organization, country, email):
    return x509.Name([
        x509.NameAttribute(NameOID.COUNTRY_NAME, country),
        x509.NameAttribute(NameOID.ORGANIZATION_NAME, organization),
        x509.NameAttribute(NameOID.COMMON_NAME, common_name),
        x509.NameAttribute(NameOID.EMAIL_ADDRESS, email),
    ])


def generate(output, password, common_name, organization, country, email, days):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    subject = issuer = build_subject(common_name, organization, country, email)
    now = datetime.datetime.now(datetime.timezone.utc)

    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(issuer)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + datetime.timedelta(days=days))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=True,
                key_encipherment=True,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(
            x509.ExtendedKeyUsage([x509.ObjectIdentifier("1.3.6.1.5.5.7.3.4")]),
            critical=False,
        )
        .sign(key, hashes.SHA256())
    )

    p12 = pkcs12.serialize_key_and_certificates(
        name=common_name.encode("utf-8"),
        key=key,
        cert=cert,
        cas=None,
        encryption_algorithm=serialization.BestAvailableEncryption(password.encode("utf-8")),
    )

    out_path = Path(output)
    out_path.write_bytes(p12)
    print(f"Certificate saved to {out_path.resolve()}")
    print(f"Subject: CN={common_name}, O={organization}, C={country}, Email={email}")
    print(f"Valid for: {days} days")


def main():
    parser = argparse.ArgumentParser(description="Generate self-signed PKCS#12 certificate.")
    parser.add_argument("-o", "--output", default="cert.p12", help="Output .p12 file path")
    parser.add_argument("-p", "--password", default="password", help="PKCS#12 password")
    parser.add_argument("--cn", default="PDF Signer", help="Common Name")
    parser.add_argument("--org", default="Example Org", help="Organization")
    parser.add_argument("--country", default="ID", help="Country code (2 letters)")
    parser.add_argument("--email", default="signer@example.com", help="Email address")
    parser.add_argument("--days", type=int, default=365, help="Validity in days")
    args = parser.parse_args()

    generate(args.output, args.password, args.cn, args.org, args.country, args.email, args.days)


if __name__ == "__main__":
    main()
