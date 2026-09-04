#!/usr/bin/env python3
"""Dependency-free barcode sheet generator for the staging test kit.

Reads ``products.json`` (same directory) and emits, next to it:

* ``<date>-dhouha-barcodes.html`` — printable A4 sheet, 3 columns, one card
  per product (name, SKU, barcode as inline SVG, human-readable digits,
  location code, stock on hand).
* ``<date>-dhouha-barcodes.csv``  — ``name,sku,ean,location`` reference.

Symbologies are implemented here from the specifications — no third-party
library, no network access:

* **EAN-13** — check digit (mod-10, weights 1/3), L/G/R digit patterns and the
  first-digit parity table, guard bars 101 / 01010 / 101 (95 modules).
* **Code 128 B** — the 107-entry width pattern table, start code B (104),
  weighted mod-103 checksum, stop pattern (13 modules).

``python3 gen-barcodes.py --self-test`` runs the round-trip suite only.
Every run runs the suite first and aborts if anything fails, so a sheet is
never produced from an encoder that cannot decode its own output.
"""

from __future__ import annotations

import argparse
import csv
import html
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent

# --------------------------------------------------------------------------
# EAN-13
# --------------------------------------------------------------------------

EAN_L = (
    "0001101", "0011001", "0010011", "0111101", "0100011",
    "0110001", "0101111", "0111011", "0110111", "0001011",
)
EAN_G = (
    "0100111", "0110011", "0011011", "0100001", "0011101",
    "0111001", "0000101", "0010001", "0001001", "0010111",
)
EAN_R = (
    "1110010", "1100110", "1101100", "1000010", "1011100",
    "1001110", "1010000", "1000100", "1001000", "1110100",
)
# Parity of the six digits in the left half, selected by the first digit.
EAN_PARITY = (
    "LLLLLL", "LLGLGG", "LLGGLG", "LLGGGL", "LGLLGG",
    "LGGLLG", "LGGGLL", "LGLGLG", "LGLGGL", "LGGLGL",
)


def ean13_check_digit(first12: str) -> str:
    """Return the mod-10 check digit for the first 12 digits of an EAN-13."""
    if len(first12) != 12 or not first12.isdigit():
        raise ValueError(f"expected 12 digits, got {first12!r}")
    total = sum(int(d) * (1 if i % 2 == 0 else 3) for i, d in enumerate(first12))
    return str((10 - (total % 10)) % 10)


def ean13_is_valid(code: str) -> bool:
    return (
        len(code) == 13
        and code.isdigit()
        and ean13_check_digit(code[:12]) == code[12]
    )


def ean13_encode(code: str) -> str:
    """Encode an EAN-13 to its 95-module bit string (1 = bar, 0 = space)."""
    if not ean13_is_valid(code):
        raise ValueError(f"invalid EAN-13 (check digit): {code!r}")
    parity = EAN_PARITY[int(code[0])]
    bits = ["101"]
    for digit, side in zip(code[1:7], parity):
        table = EAN_L if side == "L" else EAN_G
        bits.append(table[int(digit)])
    bits.append("01010")
    for digit in code[7:]:
        bits.append(EAN_R[int(digit)])
    bits.append("101")
    out = "".join(bits)
    assert len(out) == 95, len(out)
    return out


def ean13_decode(bits: str) -> str:
    """Decode a 95-module EAN-13 bit string back to its 13 digits.

    Raises ValueError on any structural problem, so it doubles as a verifier.
    """
    if len(bits) != 95:
        raise ValueError(f"expected 95 modules, got {len(bits)}")
    if bits[:3] != "101" or bits[45:50] != "01010" or bits[92:] != "101":
        raise ValueError("guard patterns do not match")

    parity_seen = []
    left_digits = []
    for i in range(6):
        chunk = bits[3 + 7 * i: 10 + 7 * i]
        if chunk in EAN_L:
            left_digits.append(EAN_L.index(chunk))
            parity_seen.append("L")
        elif chunk in EAN_G:
            left_digits.append(EAN_G.index(chunk))
            parity_seen.append("G")
        else:
            raise ValueError(f"left chunk {i} not an L/G pattern: {chunk}")

    pattern = "".join(parity_seen)
    if pattern not in EAN_PARITY:
        raise ValueError(f"parity pattern {pattern} has no first digit")
    first = EAN_PARITY.index(pattern)

    right_digits = []
    for i in range(6):
        chunk = bits[50 + 7 * i: 57 + 7 * i]
        if chunk not in EAN_R:
            raise ValueError(f"right chunk {i} not an R pattern: {chunk}")
        right_digits.append(EAN_R.index(chunk))

    code = str(first) + "".join(map(str, left_digits + right_digits))
    if not ean13_is_valid(code):
        raise ValueError(f"decoded {code} fails its own check digit")
    return code


# --------------------------------------------------------------------------
# Code 128 B
# --------------------------------------------------------------------------

# Element widths for values 0..106; each entry is bar,space,bar,space,bar,space
# (the stop pattern, value 106, carries a seventh element).
C128_PATTERNS = (
    "212222", "222122", "222221", "121223", "121322", "131222", "122213",
    "122312", "132212", "221213", "221312", "231212", "112232", "122132",
    "122231", "113222", "123122", "123221", "223211", "221132", "221231",
    "213212", "223112", "312131", "311222", "321122", "321221", "312212",
    "322112", "322211", "212123", "212321", "232121", "111323", "131123",
    "131321", "112313", "132113", "132311", "211313", "231113", "231311",
    "112133", "112331", "132131", "113123", "113321", "133121", "313121",
    "211331", "231131", "213113", "213311", "213131", "311123", "311321",
    "331121", "312113", "312311", "332111", "314111", "221411", "431111",
    "111224", "111422", "121124", "121421", "141122", "141221", "112214",
    "112412", "122114", "122411", "142112", "142211", "241211", "221114",
    "413111", "241112", "134111", "111242", "121142", "121241", "114212",
    "124112", "124211", "411212", "421112", "421211", "212141", "214121",
    "412121", "111143", "111341", "131141", "114113", "114311", "411113",
    "411311", "113141", "114131", "311141", "411131", "211412", "211214",
    "211232", "2331112",
)
C128_START_B = 104
C128_STOP = 106


def code128b_values(text: str) -> list[int]:
    """Return the full symbol value sequence (start, data, checksum, stop)."""
    for ch in text:
        if not 32 <= ord(ch) <= 126:
            raise ValueError(f"character {ch!r} is outside Code 128 set B")
    data = [ord(ch) - 32 for ch in text]
    checksum = C128_START_B
    for position, value in enumerate(data, start=1):
        checksum += position * value
    checksum %= 103
    return [C128_START_B, *data, checksum, C128_STOP]


def code128b_encode(text: str) -> str:
    """Encode text to a Code 128 B bit string (1 = bar, 0 = space)."""
    bits: list[str] = []
    for value in code128b_values(text):
        widths = C128_PATTERNS[value]
        for index, width in enumerate(widths):
            bits.append(("1" if index % 2 == 0 else "0") * int(width))
    return "".join(bits)


def _bits_to_widths(bits: str) -> list[int]:
    widths: list[int] = []
    run = 1
    for previous, current in zip(bits, bits[1:]):
        if current == previous:
            run += 1
        else:
            widths.append(run)
            run = 1
    widths.append(run)
    return widths


def code128b_decode(bits: str) -> str:
    """Decode a Code 128 B bit string back to its text (verifier)."""
    widths = _bits_to_widths(bits)
    if len(widths) < 7 + 6 + 6 or (len(widths) - 7) % 6 != 0:
        raise ValueError(f"element count {len(widths)} is not a Code 128 frame")

    symbols: list[int] = []
    cursor = 0
    while cursor < len(widths) - 7:
        pattern = "".join(str(w) for w in widths[cursor:cursor + 6])
        if pattern not in C128_PATTERNS:
            raise ValueError(f"unknown pattern {pattern} at element {cursor}")
        symbols.append(C128_PATTERNS.index(pattern))
        cursor += 6
    stop = "".join(str(w) for w in widths[cursor:])
    if stop != C128_PATTERNS[C128_STOP]:
        raise ValueError(f"bad stop pattern {stop}")

    if not symbols or symbols[0] != C128_START_B:
        raise ValueError("missing start code B")
    *data, checksum = symbols[1:]
    expected = C128_START_B
    for position, value in enumerate(data, start=1):
        expected += position * value
    if checksum != expected % 103:
        raise ValueError(f"checksum {checksum} != {expected % 103}")
    return "".join(chr(value + 32) for value in data)


# --------------------------------------------------------------------------
# SVG rendering
# --------------------------------------------------------------------------

def bits_to_svg(bits: str, *, module: float = 1.6, height: float = 58.0,
                quiet: int = 12) -> str:
    """Render a bit string as an inline SVG of black bars on transparent."""
    width = (len(bits) + 2 * quiet) * module
    rects: list[str] = []
    index = 0
    while index < len(bits):
        if bits[index] == "1":
            run = 1
            while index + run < len(bits) and bits[index + run] == "1":
                run += 1
            x = (quiet + index) * module
            rects.append(
                f'<rect x="{x:.2f}" y="0" width="{run * module:.2f}" '
                f'height="{height:.2f}"/>'
            )
            index += run
        else:
            index += 1
    return (
        f'<svg class="bars" viewBox="0 0 {width:.2f} {height:.2f}" '
        f'width="100%" height="{height:.0f}" preserveAspectRatio="none" '
        f'role="img" shape-rendering="crispEdges">'
        f'<g fill="#000">{"".join(rects)}</g></svg>'
    )


# --------------------------------------------------------------------------
# Self-tests — encode, decode, compare
# --------------------------------------------------------------------------

def self_test(codes: list[str], texts: list[str]) -> list[str]:
    """Run the round-trip suite. Returns the list of failure messages."""
    failures: list[str] = []

    # Reference vectors from the GS1 / Code 128 specifications.
    if ean13_check_digit("590123412345") != "7":
        failures.append("EAN-13 check digit: 5901234123457 reference failed")
    if ean13_check_digit("400638133393") != "1":
        failures.append("EAN-13 check digit: 4006381333931 reference failed")
    if ean13_encode("5901234123457")[:3] != "101":
        failures.append("EAN-13 encode: missing leading guard")
    if ean13_decode(ean13_encode("5901234123457")) != "5901234123457":
        failures.append("EAN-13 round trip: 5901234123457 failed")
    # A single flipped module must be rejected, not silently decoded.
    broken = list(ean13_encode("5901234123457"))
    broken[4] = "0" if broken[4] == "1" else "1"
    try:
        ean13_decode("".join(broken))
    except ValueError:
        pass
    else:
        failures.append("EAN-13 decode: a corrupted symbol decoded cleanly")

    for code in codes:
        if not ean13_is_valid(code):
            failures.append(f"EAN-13 {code}: invalid check digit")
            continue
        try:
            if ean13_decode(ean13_encode(code)) != code:
                failures.append(f"EAN-13 {code}: round trip mismatch")
        except ValueError as exc:
            failures.append(f"EAN-13 {code}: {exc}")

    # Code 128 B: the classic "CHECK DIGIT" style vector plus every SKU.
    if code128b_values("HI345678")[-2] != (
        (104 + sum(i * (ord(c) - 32) for i, c in enumerate("HI345678", 1))) % 103
    ):
        failures.append("Code 128 B: checksum formula disagrees with itself")
    for text in ["PB-BAB-0003", *texts]:
        try:
            if code128b_decode(code128b_encode(text)) != text:
                failures.append(f"Code 128 B {text}: round trip mismatch")
        except ValueError as exc:
            failures.append(f"Code 128 B {text}: {exc}")

    return failures


# --------------------------------------------------------------------------
# Sheet
# --------------------------------------------------------------------------

CSS = """
:root { color-scheme: light; }
@page { size: A4 portrait; margin: 10mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #fff; color: #111;
       font: 11px/1.35 "Helvetica Neue", Arial, sans-serif; }
header { padding: 0 0 8px; border-bottom: 2px solid #111; margin-bottom: 10px; }
h1 { margin: 0 0 3px; font-size: 16px; letter-spacing: .2px; }
.meta { font-size: 10px; color: #444; }
.meta b { color: #111; }
.legend { margin: 8px 0 12px; font-size: 10px; color: #333;
          border: 1px solid #bbb; border-radius: 4px; padding: 6px 8px;
          background: #fafafa; }
h2 { font-size: 12px; margin: 14px 0 6px; padding: 3px 6px; background: #111;
     color: #fff; border-radius: 3px; page-break-after: avoid; }
.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.card { border: 1px solid #999; border-radius: 4px; padding: 6px 7px 5px;
        page-break-inside: avoid; break-inside: avoid; background: #fff; }
.card .nm { font-weight: 700; font-size: 10px; line-height: 1.25;
            height: 26px; overflow: hidden; }
.card .sku { font-family: "SFMono-Regular", Menlo, Consolas, monospace;
             font-size: 9px; color: #555; margin: 1px 0 4px; }
.card .bars { display: block; }
.card .hr { text-align: center; font-family: "SFMono-Regular", Menlo, Consolas,
            monospace; font-size: 11px; letter-spacing: 1.5px; margin-top: 2px; }
.card .foot { display: flex; justify-content: space-between; gap: 4px;
              margin-top: 3px; font-size: 8.5px; color: #444;
              border-top: 1px dotted #bbb; padding-top: 3px; }
.tag { display: inline-block; padding: 0 3px; border-radius: 2px;
       border: 1px solid #888; font-size: 8px; }
.tag.lot { border-color: #a4620a; color: #a4620a; }
.tag.kg  { border-color: #0a5ca4; color: #0a5ca4; }
.tag.zero{ border-color: #a40a0a; color: #a40a0a; }
footer { margin-top: 12px; font-size: 9px; color: #666;
         border-top: 1px solid #ccc; padding-top: 5px; }
@media print { .noprint { display: none; } }
"""


def build_html(kit: dict) -> str:
    loc = kit["locations"]["main"]
    loc2 = kit["locations"]["second"]
    groups = {g["key"]: g["label"] for g in kit["groups"]}
    by_group: dict[str, list[dict]] = {}
    for product in kit["products"]:
        by_group.setdefault(product["group"], []).append(product)

    parts = [
        "<!doctype html>",
        '<html lang="fr">',
        "<head>",
        '<meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        "<title>Planche de codes-barres — kit de test Dhouha</title>",
        f"<style>{CSS}</style>",
        "</head>",
        "<body>",
        "<header>",
        "<h1>Planche de codes-barres — kit de test staging</h1>",
        '<div class="meta">'
        f'<b>Tenant</b> {html.escape(kit["company"]["name"])} · '
        f'<b>Emplacement principal</b> {html.escape(loc["code"])} '
        f'({html.escape(loc["name"])}) · '
        f'<b>2<sup>e</sup> emplacement</b> {html.escape(loc2["code"])} '
        f'({html.escape(loc2["name"])}) · '
        f'<b>Généré le</b> {html.escape(kit["date"])}'
        "</div>",
        "</header>",
        '<div class="legend">'
        "Imprimer <b>à 100 % (taille réelle, sans « ajuster à la page »)</b> sur "
        "A4 blanc — une réduction casse la lecture au scanner. "
        "Étiquettes : <span class=\"tag lot\">LOT</span> produit suivi par lot "
        "(numéro + péremption demandés à la réception) · "
        "<span class=\"tag kg\">kg</span> quantité décimale (3 décimales) · "
        "<span class=\"tag zero\">0</span> stock nul à l'emplacement principal. "
        "Les quantités affichées sont celles relevées le "
        f"{html.escape(kit['date'])} — elles bougeront dès votre premier test."
        "</div>",
    ]

    for key in sorted(by_group):
        parts.append(f"<h2>{html.escape(groups.get(key, key))}</h2>")
        parts.append('<div class="grid">')
        for p in by_group[key]:
            if p["symbology"] == "ean13":
                bits = ean13_encode(p["barcode"])
                human = f'{p["barcode"][0]} {p["barcode"][1:7]} {p["barcode"][7:]}'
                scanned = p["barcode"]
            else:
                bits = code128b_encode(p["sku"])
                human = p["sku"]
                scanned = p["sku"]
            tags = []
            if p["batch_tracked"]:
                tags.append('<span class="tag lot">LOT</span>')
            if int(p["decimals"]) > 0:
                tags.append(f'<span class="tag kg">{html.escape(p["unit"])}</span>')
            if float(p["qty_store_tun1"]) == 0:
                tags.append('<span class="tag zero">0</span>')
            parts.append(
                '<div class="card">'
                f'<div class="nm">{html.escape(p["name"])}</div>'
                f'<div class="sku">{html.escape(p["sku"])} · '
                f'{html.escape(p["symbology"].upper())} · '
                f'{html.escape(scanned)}</div>'
                f'{bits_to_svg(bits)}'
                f'<div class="hr">{html.escape(human)}</div>'
                '<div class="foot">'
                f'<span>{html.escape(loc["code"])} · '
                f'{html.escape(p["qty_store_tun1"])} {html.escape(p["unit"])}</span>'
                f'<span>{"".join(tags)}</span>'
                "</div></div>"
            )
        parts.append("</div>")

    parts.append(
        "<footer>Généré par <code>docs/qa/testkit/gen-barcodes.py</code> depuis "
        "<code>products.json</code> — encodage EAN-13 et Code 128 B implémentés "
        "dans le script, chaque symbole est décodé puis recomparé avant "
        "impression. Aucune dépendance externe.</footer>"
    )
    parts.append("</body>")
    parts.append("</html>")
    return "\n".join(parts)


def build_csv(kit: dict, path: Path) -> None:
    loc = kit["locations"]["main"]["code"]
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["name", "sku", "ean", "location"])
        for p in kit["products"]:
            writer.writerow([p["name"], p["sku"], p["barcode"], loc])


# --------------------------------------------------------------------------

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", default=str(HERE / "products.json"))
    parser.add_argument("--out-html", default=None)
    parser.add_argument("--out-csv", default=None)
    parser.add_argument("--self-test", action="store_true",
                        help="run the round-trip suite and exit")
    args = parser.parse_args()

    kit = json.loads(Path(args.data).read_text(encoding="utf-8"))
    eans = [p["barcode"] for p in kit["products"]]
    skus = [p["sku"] for p in kit["products"] if p["symbology"] == "code128"]

    failures = self_test(eans, skus)
    for message in failures:
        print(f"FAIL  {message}", file=sys.stderr)
    print(f"self-test: {len(eans)} EAN-13 + {len(skus) + 1} Code 128 B "
          f"round trips, {len(failures)} failure(s)")
    if failures:
        return 1
    if args.self_test:
        return 0

    stem = f"{kit['date']}-dhouha-barcodes"
    out_html = Path(args.out_html or HERE / f"{stem}.html")
    out_csv = Path(args.out_csv or HERE / f"{stem}.csv")
    out_html.write_text(build_html(kit), encoding="utf-8")
    build_csv(kit, out_csv)
    print(f"wrote {out_html}")
    print(f"wrote {out_csv}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
