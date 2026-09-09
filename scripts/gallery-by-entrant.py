#!/usr/bin/env python3
"""
Build data/gallery/by-entrant.html -- an entrant-centric view of gallery/map.json:
every mapped image regrouped under each entrant it depicts (from the `entrant_ids`
tags), so you can eyeball "all of Mario's pics" / "every year 1998 portrait". QA
sheet for the entrant-id tagging. Companion to by-poll.html (poll-centric) and
audit.html (file-centric).

Served at  /data/gallery/by-entrant.html  (data/ is symlinked into the docroot).

  .venv/bin/python scripts/gallery-by-entrant.py
"""

from __future__ import annotations

import html
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RECORDS = ROOT / "data" / "board8wiki" / "match-records.json"
MAP = ROOT / "data" / "gallery" / "map.json"
ENTRANT_IDS = ROOT / "data" / "entrant-ids.json"
ALBUMS = ROOT / "public" / "gallery" / "albums"
WIKI_IMG_DIR = ROOT / "public" / "images" / "board8wiki"
OUT = ROOT / "data" / "gallery" / "by-entrant.html"

CONF_CLASS = {"high": "ok", "manual": "ok", "tentative": "warn"}


def thumb(im: dict) -> str:
    if im.get("source") == "wiki":
        return im["url"]
    d, fn = im["dir"], im["file"]
    if (ALBUMS / d / f"thumb_{fn}").exists():
        return f"/gallery/albums/{d}thumb_{fn}"
    alt = re.sub(r"_(\d+)\.", lambda m: f"_{int(m.group(1)):03d}.", fn)  # 3_14 -> 3_014
    if alt != fn and (ALBUMS / d / f"thumb_{alt}").exists():
        return f"/gallery/albums/{d}thumb_{alt}"
    return f"/gallery/albums/{d}{fn}"


def main() -> None:
    records = json.loads(RECORDS.read_text())
    gmap = json.loads(MAP.read_text())
    id_rows = json.loads(ENTRANT_IDS.read_text())
    name_by_id = {i: n for _t, n, i in id_rows}
    type_by_id = {i: t for t, _n, i in id_rows}

    # entrant id -> [ (contest, date, poll, image) ], newest contest last
    by_ent: dict[int, list] = defaultdict(list)
    for poll, ims in gmap["images"].items():
        rec = records.get(poll, {})
        c, dt = rec.get("contest", "?"), rec.get("date", "9999")
        for im in ims:
            for eid in im.get("entrant_ids", []):
                by_ent[eid].append((c, dt, poll, im))

    # order entrants: by type, then name
    ents = sorted(
        by_ent, key=lambda i: (type_by_id.get(i, "zz"), name_by_id.get(i, "").lower())
    )
    type_tally = Counter(type_by_id.get(i, "?") for i in ents)
    total_registry = Counter(t for t, _n, _i in id_rows)
    missing = {t: total_registry[t] - type_tally.get(t, 0) for t in total_registry}

    tallies = " &middot; ".join(
        f"{type_tally.get(t, 0)}/{total_registry[t]} {t}"
        for t in sorted(total_registry)
    )
    parts = [f"""<!doctype html><meta charset=utf-8><title>gallery by entrant</title>
<style>
 body{{font:13px/1.45 system-ui;margin:1rem;background:#fafafa}}
 h1{{margin:.2rem 0}}
 h2{{margin:1.4rem 0 .2rem;padding:.25rem 0;border-bottom:1px solid #ddd}}
 h2 .id{{color:#999;font-weight:normal;font-size:12px;font-family:ui-monospace,monospace}}
 h2 .ty{{color:#777;font-weight:normal;font-size:11px;border:1px solid #ccc;border-radius:3px;padding:0 4px;margin-left:6px}}
 .th{{display:inline-block;vertical-align:top;margin:2px 4px;text-align:center}}
 .th img{{height:78px;border:1px solid #ccc;background:#eee;display:block}}
 .th img.wiki{{border:2px solid #c0392b}}
 .th small{{display:block;font-size:10px;line-height:1.25;max-width:8.5rem;color:#555}}
 .th .poll{{font-family:ui-monospace,monospace;color:#333}}
 .ok{{color:#178a1f}} .warn{{color:#a6730a;font-weight:bold}}
 .ctx{{color:#888;font-size:11px}}
 .sum{{color:#555;margin:.2rem 0 .7rem}}
 .grp{{margin-bottom:.8rem}}
</style>
<h1>Gallery pics by entrant</h1>
<p class=sum>{len(ents)} entrants with &ge;1 mapped image ({tallies}).
Amber = tentative. Red border = Board 8 wiki image. Grouped by contest within each entrant.</p>
"""]

    for eid in ents:
        rows = by_ent[eid]
        # contest chronology = earliest date seen for that contest
        c_first = {}
        for c, dt, _p, _im in rows:
            c_first[c] = min(dt, c_first.get(c, "9999"))
        rows.sort(key=lambda r: (c_first[r[0]], r[0], int(r[2]), r[3]["file"]))

        nm = html.escape(name_by_id.get(eid, f"id {eid}"))
        ty = type_by_id.get(eid, "?")
        parts.append(
            f"<h2>{nm} <span class=id>#{eid}</span><span class=ty>{ty}</span></h2>"
        )

        cells, cur_c = [], None
        for c, _dt, poll, im in rows:
            if c != cur_c:
                if cur_c is not None:
                    cells.append("</div>")
                cells.append(
                    f"<div class=grp><span class=ctx>{html.escape(c)}</span><br>"
                )
                cur_c = c
            cls = CONF_CLASS.get(im.get("confidence", ""), "warn")
            wiki = " wiki" if im.get("source") == "wiki" else ""
            note = html.escape(im.get("note") or "")
            solo = len(im.get("entrant_ids", [])) == 1
            cells.append(
                f'<span class=th><a href="{im["url"]}" target=_blank>'
                f'<img class="{wiki.strip()}" loading=lazy src="{thumb(im)}" title="{html.escape(im["file"])}"></a>'
                f"<small><span class=poll>{poll}</span> "
                f'<span class="{cls}">{im.get("confidence", "?")}</span>'
                f"{'' if solo else ' &middot; group'}"
                + (f"<br>{note}" if note else "")
                + "</small></span>"
            )
        cells.append("</div>")
        parts.append("".join(cells))

    parts.append(
        "<p class=sum>Registry entrants with no mapped image: "
        + ", ".join(f"{missing[t]} {t}" for t in sorted(missing) if missing[t])
        + "</p>"
    )

    OUT.write_text("".join(parts))
    print(
        f"wrote {OUT}  ({len(ents)} entrants, "
        f"{sum(len(v) for v in by_ent.values())} image references)"
    )
    print("view  http://localhost:8000/data/gallery/by-entrant.html")


if __name__ == "__main__":
    main()
