#!/usr/bin/env python3
"""
Build data/gallery/audit.html -- a standalone contact sheet of the Coppermine
gallery, grouped by the key encoded in each filename, with:
  * the resolved match entrants (from match-records.json)
  * the Phase-1 poll resolution + confidence (from gallery/map.json)
  * Board 8 wiki images that share a filename stem (a "collision" to eyeball)

Served at  /data/gallery/audit.html  (data/ is symlinked into the docroot).
Not routed through the CMS -- plain static file.

  .venv/bin/python scripts/gallery-audit.py
"""

from __future__ import annotations

import html
import json
import re
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INVENTORY = ROOT / "data" / "gallery" / "inventory.json"
RECORDS = ROOT / "data" / "board8wiki" / "match-records.json"
MAP = ROOT / "data" / "gallery" / "map.json"
ENTRANTS = ROOT / "data" / "gallery" / "entrants.json"
ALBUMS = ROOT / "public" / "gallery" / "albums"
OUT = ROOT / "data" / "gallery" / "audit.html"

AID_CONTEST = {
    1: "SpC2K5",
    2: "SC2K4",
    3: "SpC2K4",
    4: "SC2K3",
    5: "SC2K2",
    6: "SC2K5",
    7: "BSE2K6",
    8: "CB2K6",
    9: "CB VI",
    10: "CB VII",
    11: "BGE 2K9",
    12: "CB VIII",
    13: "GOTD",
    14: "Rivalry",
    15: "CB IX",
    16: "BGE 2K15",
    17: "Best Year",
    18: "CB X",
    19: "GOTD 2",
}
PREFIX = {
    1: "b",
    2: "sum04b",
    3: "spr04b",
    4: "sum03b",
    5: "sum02b",
    7: "bse",
    8: "cb5",
    9: "cb6-",
    10: "cb7-",
    11: "bge09-",
    12: "cb8-",
    13: "gotd-",
    14: "rivals-",
}


def parse_key(aid: int, fn: str):
    """-> (key, variant, kind)."""
    s = fn.lower().rsplit(".", 1)[0]
    if aid == 15:
        m = re.match(r"^(\d{3,4})(?:[-_](\w+))?$", s)
        if m:
            return m.group(1), m.group(2) or "", "pollid"
    if aid == 19:
        if s.startswith("gotd"):
            return s, "", "skip"
        m = re.match(r"^(\d{4})-([12])(.*)$", s)
        if m:
            return m.group(1), "s" + m.group(2) + m.group(3), "pollid"
    if aid == 17:
        m = re.match(r"^(?:r(\d+)_)?(\d{4})(_[lr])?$", s)
        if m:
            return (
                m.group(2),
                (("r" + m.group(1)) if m.group(1) else "") + (m.group(3) or ""),
                "entrant",
            )
    if aid == 18:
        if re.match(r"^\d+$", s):
            return s, "", "entrant"
        m = re.match(r"^r(\d+)-(\d+)$", s)
        if m:
            return f"r{m.group(1)}-{m.group(2)}", "", "roundlocal"
        if s.startswith("logo-"):
            return s, "", "skip"
        m = re.match(r"^([a-z]+)-(.*)$", s)
        return (m.group(1), m.group(2), "named") if m else (s, "", "named")
    if aid == 16:
        m = re.match(r"^(\d+)_(\d+)(?:_(\d+))?$", s)
        if m:
            return (
                f"r{int(m.group(1))}m{int(m.group(2)):03d}",
                m.group(3) or "",
                "roundlocal",
            )
        m = re.match(r"^(\d+)$", s)
        if m:
            return f"{int(m.group(1)):03d}", "", "entrant"
    if aid == 6:
        m = re.match(r"^(br?)(\d+)(.*)$", s)
        if m:
            pre = "BR" if m.group(1) == "br" else ""
            return f"{pre}{int(m.group(2)):03d}", m.group(3).lstrip("-_"), "match"
    if aid in PREFIX:
        if "int" in s:
            return s, "", "skip"
        pre = PREFIX[aid]
        if s.startswith(pre):
            m = re.match(r"^(\d+)(.*)$", s[len(pre) :])
            if m:
                return f"{int(m.group(1)):03d}", m.group(2).lstrip("-_"), "match"
    return s, "", "unparsed"


def main() -> None:
    pics = json.loads(INVENTORY.read_text())["pics"]
    records = json.loads(RECORDS.read_text())
    gmap = (
        json.loads(MAP.read_text())
        if MAP.exists()
        else {"images": {}, "unresolved": []}
    )

    # file -> (poll, confidence)   and   file -> reason
    resolved_by_file: dict[str, list] = defaultdict(
        list
    )  # file -> [(poll, confidence), ...]
    for poll, imgs in gmap.get("images", {}).items():
        for im in imgs:
            resolved_by_file[im["file"]].append((poll, im.get("confidence", "?")))
    reason_by_file = {u["file"]: u["reason"] for u in gmap.get("unresolved", [])}

    wiki_by_stem = {}
    for poll, r in records.items():
        for fn, url in zip(r.get("wiki_images", []), r.get("wiki_image_urls", [])):
            wiki_by_stem[fn.lower().rsplit(".", 1)[0]] = (poll, url)

    per_contest = defaultdict(list)
    for poll, r in records.items():
        if r.get("official", True):
            per_contest[r["contest"]].append((r["date"], int(poll), poll))
    ordinal = {}
    for c, lst in per_contest.items():
        for i, (_d, _p, poll) in enumerate(sorted(lst), 1):
            ordinal[(c, i)] = poll

    def ents_html(poll: str) -> str:
        r = records.get(poll)
        if not r:
            return ""
        segs = []
        for e in r["entrants"]:
            nm = (f"({e['seed']})" if e.get("seed") else "") + html.escape(e["name"])
            segs.append(f"<b>{nm}</b>" if e["finish"] == 1 else nm)
        return (
            " &rsaquo; ".join(segs)
            + f' <span class="pmeta">#{poll} &middot; {r["date"]}</span>'
        )

    ejson = json.loads(ENTRANTS.read_text()) if ENTRANTS.exists() else {}
    bge15_idx = {
        d["index"]: nm
        for nm, d in ejson.get("BGE 2K15", {}).get("entrants", {}).items()
    }
    byear_set = set(ejson.get("Best Year", {}).get("entrants", {}))
    cbx_idx = {
        d["index"]: nm for nm, d in ejson.get("CB X", {}).get("entrants", {}).items()
    }

    def resolve_ents(aid: int, key: str, kind: str) -> str:
        if kind == "pollid":
            return ents_html(key) or f'<span class="hint">poll {key}?</span>'
        if kind == "match":
            d = re.sub(r"\D", "", key)
            poll = ordinal.get((AID_CONTEST[aid], int(d))) if d else None
            return (
                ents_html(poll)
                if poll
                else '<span class="hint">consolation / out of range</span>'
            )
        if aid == 16 and kind == "entrant":  # BGE 2K15 NNN.jpg -> entrant
            nm = bge15_idx.get(int(key)) if key.isdigit() else None
            return (
                f'{html.escape(nm)} <span class="pmeta">BGE 2K15 entrant #{int(key)}</span>'
                if nm
                else '<span class="hint">joke-poll entrant</span>'
            )
        if aid == 16 and kind == "roundlocal":  # BGE 2K15 R_MMM.jpg
            mm = re.match(r"r(\d+)m(\d+)", key)
            nm = bge15_idx.get(int(mm.group(2))) if mm else None
            return (
                f'{html.escape(nm)} <span class="pmeta">round {int(mm.group(1))} portrait</span>'
                if nm
                else '<span class="hint">round-local &mdash; unmapped</span>'
            )
        if aid == 17 and kind == "entrant":  # Best Year: key = year
            return (
                f'year {html.escape(key)} <span class="pmeta">Best Year entrant</span>'
                if key in byear_set
                else f'<span class="hint">{html.escape(key)} &mdash; non-bracket candidate year</span>'
            )
        if aid == 18 and kind == "entrant":  # CB X: key = N.png index
            nm = cbx_idx.get(int(key)) if key.isdigit() else None
            if nm:
                return f'{html.escape(nm)} <span class="pmeta">CB X entrant #{int(key)}</span>'
            return '<span class="hint">Legends bracket extra portrait (unmapped)</span>'
        if aid == 18 and kind == "roundlocal":  # CB X: rN-IDX.png
            mm = re.match(r"r(\d+)-(\d+)", key)
            nm = cbx_idx.get(int(mm.group(2))) if mm else None
            return (
                f'{html.escape(nm)} <span class="pmeta">round {int(mm.group(1))} portrait</span>'
                if nm
                else '<span class="hint">round-local &mdash; unmapped</span>'
            )
        if aid == 18 and kind == "named":  # CB X alt-art submission
            return f'<span class="hint">{html.escape(key)} &mdash; alt-art submission (crowdsource)</span>'
        if kind == "roundlocal":
            return '<span class="hint">round-local &mdash; unmapped (phase 2/3)</span>'
        if kind == "entrant":
            return f'<span class="hint">{"year " if aid == 17 else "entrant #"}{html.escape(key)} &mdash; phase 2</span>'
        if kind == "named":
            return f'<span class="hint">{html.escape(key)} (alt art) &mdash; phase 2/3</span>'
        return ""

    groups: dict[int, dict[str, list]] = {}
    titles: dict[int, str] = {}
    for pic in pics:
        aid, d, fn, w, h = pic["aid"], pic["dir"], pic["file"], pic["w"], pic["h"]
        titles[aid] = pic["album"]
        key, variant, kind = parse_key(aid, fn)
        ctime = (pic.get("ctime") or "")[:10]
        groups.setdefault(aid, {}).setdefault(key, []).append(
            (fn, d, w, h, variant, kind, ctime)
        )

    def thumb(d: str, fn: str) -> str:
        if (ALBUMS / d / f"thumb_{fn}").exists():
            return f"/gallery/albums/{d}thumb_{fn}"
        alt = re.sub(
            r"_(\d+)\.", lambda m: f"_{int(m.group(1)):03d}.", fn
        )  # 3_14 -> 3_014
        if alt != fn and (ALBUMS / d / f"thumb_{alt}").exists():
            return f"/gallery/albums/{d}thumb_{alt}"
        return f"/gallery/albums/{d}{fn}"

    st = gmap.get("stats", {})
    parts = [f"""<!doctype html><meta charset=utf-8><title>gallery audit</title>
<style>
 body{{font:13px/1.4 system-ui;margin:1rem;background:#fafafa}}
 h2{{margin:1.6rem 0 .3rem;position:sticky;top:0;background:#fafafa;padding:.3rem 0}}
 table{{border-collapse:collapse;width:100%;background:#fff}}
 td,th{{border:1px solid #ddd;padding:3px 6px;vertical-align:top;text-align:left}}
 th{{background:#f0f0f0}}
 td.k{{font-family:ui-monospace,monospace;white-space:nowrap}}
 img{{height:54px;border:1px solid #ccc;background:#eee;display:block}}
 img.wiki{{border:2px solid #c0392b}}
 .th{{display:inline-block;vertical-align:top;margin:1px 2px;text-align:center}}
 .th small{{display:block;color:#444;font-size:10px;line-height:1.2}}
 tr.collision td.k{{background:#fff3f0}}
 tr.unres td.k{{background:#f4f4f4;color:#999}}
 .badge{{display:inline-block;font-size:11px;background:#eee;border-radius:3px;padding:0 4px;margin-left:4px}}
 .ok{{color:#178a1f;font-size:11px}}          /* high / manual */
 .warn{{color:#a6730a;font-size:11px;font-weight:bold}}  /* tentative */
 .no{{color:#b00;font-size:11px}}             /* unresolved */
 .skip{{color:#888;font-size:11px}}           /* skipped (logo/intro) */
 .sum{{color:#555;margin:.2rem 0 .6rem}}
 td.ents{{max-width:22rem;font-size:12px}}
 .pmeta{{color:#999;font-size:11px;white-space:nowrap}}
 .hint{{color:#b00;font-size:11px;font-style:italic}}
</style>
<h1>Coppermine gallery audit</h1>
<p class=sum>Grouped by filename key. <b>map.json:</b> {st.get("resolved", "?")} resolved,
{st.get("unresolved", "?")} unresolved, {st.get("polls_with_images", "?")} polls with images.
Red-bordered thumb + tinted key = a Board 8 wiki image with the same filename stem.
Grey row = not resolved to a poll (Phase 2/3).</p>
"""]

    for aid in sorted(groups):
        g = groups[aid]
        n_img = sum(len(v) for v in g.values())
        n_res = sum(1 for v in g.values() for (fn, *_) in v if fn in resolved_by_file)
        n_coll = sum(
            1
            for v in g.values()
            for (fn, *_) in v
            if fn.lower().rsplit(".", 1)[0] in wiki_by_stem
        )
        parts.append(f"<h2>aid {aid} &mdash; {titles[aid]}</h2>")
        parts.append(
            f"<p class=sum>{len(g)} keys &middot; {n_img} images &middot; "
            f"{n_res} resolved to a poll &middot; {n_coll} wiki-stem collisions</p>"
        )
        if aid == 17:
            parts.append(
                "<p class=sum><b>Best Year notes:</b> entrant = year. <code>YYYY.jpg</code> "
                "(wide ~200&times;120) = <b>Wildcard round</b> art &mdash; only 1978/79/81/83 (the "
                "old years that played a Wildcard match, polls 6686&ndash;6689) used it; the other "
                "9 bare years never played. <code>r1_YYYY.jpg</code> (slim 232&times;600) = round-1 "
                "portrait; it is reused (tentatively) for round 2, since there is no <code>r2_</code> "
                "set. <code>r3/r4/r5_YYYY_{l,r}.jpg</code> = per-round portrait, l/r = poll side. "
                "Green = <code>r1_</code> on an actual round-1 match; amber = everything else.</p>"
            )
        if aid == 18:
            parts.append(
                "<p class=sum><b>CB X notes:</b> <code>N.png</code> (1&ndash;128) = entrant "
                "portrait, used tentatively for rounds 1&ndash;3; <code>r4-N.png</code> = round-4 "
                "portrait. <code>129&ndash;136.png</code> = the 8 all-time legends (Link, Mega Man, "
                "Cloud, Crono, Snake, Sonic, Samus, Mario) &mdash; <b>skipped</b>: the Legends / "
                "Losers / Grand Final matches (polls 7358&ndash;7387) have purpose-made banners on "
                "the Board 8 wiki that the AMR uses instead. Rounds 5+ are left for crowdsourcing. "
                "<code>&lt;char&gt;-b-&lt;artist&gt;.png</code> / <code>-f-</code> = user-submitted "
                "<b>b</b>ackground / <b>f</b>oreground layers that get composited together "
                "(assumption from the wiki); which match each was for is unknown &mdash; crowdsource.</p>"
            )
        parts.append(
            "<table><tr><th>key</th><th>n</th><th>map</th>"
            "<th>entrants (finish order, winner bold)</th><th>gallery</th><th>wiki (same stem)</th></tr>"
        )

        def sortkey(k: str):
            return [int(t) if t.isdigit() else t for t in re.split(r"(\d+)", k)]

        for key in sorted(g, key=sortkey):
            items = g[key]
            kind = items[0][5]
            gcells = wcells = ""
            seen_wiki = set()
            polls_hit, confs = set(), set()
            for fn, d, w, h, variant, _k, ctime in sorted(items, key=lambda x: x[0]):
                stem = fn.lower().rsplit(".", 1)[0]
                for p, c in resolved_by_file.get(fn, []):
                    polls_hit.add(p)
                    confs.add(c)
                gcells += (
                    f'<span class=th><a href="/gallery/albums/{d}{fn}" target=_blank>'
                    f'<img loading=lazy src="{thumb(d, fn)}" title="{fn}  {w}x{h}  var={variant or "-"}"></a>'
                    f"<small>{ctime}</small></span>"
                )
                if stem in wiki_by_stem and stem not in seen_wiki:
                    seen_wiki.add(stem)
                    wp, wu = wiki_by_stem[stem]
                    wcells += f'<a href="{wu}" target=_blank><img class=wiki loading=lazy src="{wu}" title="wiki poll {wp}  {fn}"></a>'
            any_res = bool(polls_hit)
            plist = ", ".join(
                sorted(polls_hit, key=lambda p: int(p) if p.isdigit() else 0)
            )
            if "tentative" in confs:
                mapcell = f"<span class=warn>&#8776; poll {plist} (tentative)</span>"
            elif "manual" in confs:
                mapcell = f"<span class=ok>&#10003; poll {plist} (manual)</span>"
            elif any_res:
                mapcell = f"<span class=ok>&#10003; poll {plist}</span>"
            elif kind == "skip":
                mapcell = "<span class=skip>skipped (logo/intro)</span>"
            else:
                mapcell = f"<span class=no>&#8213; {html.escape(reason_by_file.get(items[0][0], kind))}</span>"
            cls = (
                " class=collision"
                if seen_wiki
                else (" class=unres" if not any_res and kind != "skip" else "")
            )
            parts.append(
                f'<tr{cls}><td class="k">{key}<span class="badge">{kind}</span></td>'
                f"<td>{len(items)}</td><td>{mapcell}</td>"
                f'<td class="ents">{resolve_ents(aid, key, kind)}</td>'
                f"<td>{gcells}</td><td>{wcells}</td></tr>"
            )
        parts.append("</table>")

    OUT.write_text("".join(parts))
    print(
        f"wrote {OUT}  ({sum(len(g) for g in groups.values())} key-groups, {len(pics)} images)"
    )
    print(
        "view  http://localhost:8000/data/gallery/audit.html   (php -S localhost:8000 -t public serve.php)"
    )


if __name__ == "__main__":
    main()
