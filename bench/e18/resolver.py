#!/usr/bin/env python3
"""E18 fixture (H34, ADR-0020): a tiny stub DNS server, python3 stdlib only.

Answers any A query for `slow.ignis.test` with 127.0.0.1 after a 200 ms delay (anything else gets
NXDOMAIN). This is NOT wired into the runtime resolver yet: glibc reads /etc/resolv.conf for where
to send queries, and we are not allowed to edit that file on this box. H34 needs a knob the
implementation doesn't have yet -- IGNIS_RESOLVER=127.0.0.1:5353 (E18-I) -- to point the parked
getaddrinfo Op at this stub instead of the system resolver. Kept here as the fixture E18-I's
implementation, and bench/php/e18_dns.php's eventual "slow" arm, will need.

Usage: resolver.py [listen_host] [listen_port]   (default 127.0.0.1 5353)
"""
from __future__ import annotations

import socket
import struct
import sys
import time

TARGET = b"slow.ignis.test"
DELAY_S = 0.2


def parse_qname(data: bytes, offset: int) -> tuple[bytes, int]:
    labels: list[bytes] = []
    while True:
        length = data[offset]
        if length == 0:
            offset += 1
            break
        offset += 1
        labels.append(data[offset:offset + length])
        offset += length
    return b".".join(labels), offset


def build_response(query: bytes) -> bytes | None:
    if len(query) < 12:
        return None
    txid = query[0:2]
    qdcount = struct.unpack(">H", query[4:6])[0]
    if qdcount != 1:
        return None
    name, offset = parse_qname(query, 12)
    qtype, qclass = struct.unpack(">HH", query[offset:offset + 4])
    offset += 4
    question = query[12:offset]
    is_a = qtype == 1 and qclass == 1  # A / IN
    matches = name.lower() == TARGET.lower()

    rcode = 0 if (is_a and matches) else 3  # NOERROR / NXDOMAIN
    flags = 0x8180 | rcode
    ancount = 1 if (is_a and matches) else 0
    header = txid + struct.pack(">H", flags) + struct.pack(">HHHH", 1, ancount, 0, 0)

    answer = b""
    if is_a and matches:
        answer = (
            b"\xc0\x0c"                    # name: pointer to the question's name
            + struct.pack(">HH", 1, 1)      # TYPE=A, CLASS=IN
            + struct.pack(">I", 60)         # TTL
            + struct.pack(">H", 4)          # RDLENGTH
            + socket.inet_aton("127.0.0.1")
        )
    return header + question + answer


def main() -> None:
    host = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
    port = int(sys.argv[2]) if len(sys.argv) > 2 else 5353
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind((host, port))
    print(
        f"resolver.py: listening on {host}:{port} "
        f"(A {TARGET.decode()} -> 127.0.0.1, {int(DELAY_S * 1000)} ms delay)",
        flush=True,
    )
    while True:
        data, addr = sock.recvfrom(512)
        resp = build_response(data)
        if resp is None:
            continue
        time.sleep(DELAY_S)
        sock.sendto(resp, addr)


if __name__ == "__main__":
    main()
