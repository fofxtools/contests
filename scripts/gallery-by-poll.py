#!/usr/bin/env python3
"""
Build data/gallery/by-poll.html -- a poll-centric view of gallery/map.json: one
row per poll that has >=1 gallery image, showing the matchup (finish order, winner
bold) next to every mapped gallery pic and the Board 8 wiki image(s) for the same
match. This is the QA sheet for how match pics will look once wired into the AMR
page -- audit.html is the file-centric companion (every file, is it placed?).

Served at  /data/gallery/by-poll.html  (data/ is symlinked into the docroot).
Not routed through the CMS -- plain static file.

  .venv/bin/python scripts/gallery-by-poll.py
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
ALBUMS = ROOT / "public" / "gallery" / "albums"
WIKI_IMG_DIR = ROOT / "public" / "images" / "board8wiki"        # local copies of wiki images
OUT = ROOT / "data" / "gallery" / "by-poll.html"

CONF_CLASS = {"high": "ok", "manual": "ok", "tentative": "warn"}


def wiki_src(name: str, cdn_url: str) -> str:
    """Local copy if downloaded, else the CDN url."""
    n = name.strip().replace(" ", "_")
    n = n[:1].upper() + n[1:]
    return f"/images/board8wiki/{n}" if (WIKI_IMG_DIR / n).exists() else cdn_url


def thumb(d: str, fn: str) -> str:
    if (ALBUMS / d / f"thumb_{fn}").exists():
        return f"/gallery/albums/{d}thumb_{fn}"
    alt = re.sub(r"_(\d+)\.", lambda m: f"_{int(m.group(1)):03d}.", fn)   # 3_14 -> 3_014
    if alt != fn and (ALBUMS / d / f"thumb_{alt}").exists():
        return f"/gallery/albums/{d}thumb_{alt}"
    return f"/gallery/albums/{d}{fn}"


def matchup_html(rec: dict) -> str:
    if not rec:
        return '<span class="hint">no match record</span>'
    out = []
    for i, e in enumerate(rec.get("entrants", [])):
        nm = html.escape(e["name"])
        tag = f'{nm} <span class=pmeta>{e["votes"]:,} &middot; {e["pct"]}%</span>'
        out.append(f"<b>{tag}</b>" if i == 0 else tag)
    return "<br>".join(out)


def main() -> None:
    records = json.loads(RECORDS.read_text())
    gmap = json.loads(MAP.read_text())
    images = gmap["images"]                       # {poll: [ {file,dir,url,w,h,variant,confidence,note?,source?} ]}

    # skipped gallery pics that still belong to a poll (CB X legend portraits) --
    # shown greyed on the row, but not part of the mapping
    skipped_by_poll: dict[str, list] = defaultdict(list)
    for s in gmap.get("skipped", []):
        for p in s.get("polls", []):
            skipped_by_poll[p].append(s)

    # contest -> [(date, pollint, poll)]  for chronological ordering
    contest_rows: dict[str, list] = defaultdict(list)
    for poll in set(images) | set(skipped_by_poll):
        rec = records.get(poll, {})
        contest_rows[rec.get("contest", "?")].append(
            (rec.get("date", "9999"), int(poll), poll))
    contest_order = sorted(contest_rows, key=lambda c: min(contest_rows[c]))

    # per-contest coverage (matches with >=1 pic / official matches)
    official = defaultdict(int)
    for r in records.values():
        if r.get("official", True):
            official[r["contest"]] += 1

    st = gmap.get("stats", {})
    conf_tally = Counter(im.get("confidence", "?") for ims in images.values() for im in ims)
    src_tally = Counter(im.get("source", "gallery") for ims in images.values() for im in ims)
    multi = sum(1 for ims in images.values() if len(ims) > 1)
    parts = [f"""<!doctype html><meta charset=utf-8><title>gallery by poll</title>
<style>
 body{{font:13px/1.45 system-ui;margin:1rem;background:#fafafa}}
 h1{{margin:.2rem 0}}
 h2{{margin:1.6rem 0 .3rem;position:sticky;top:0;background:#fafafa;padding:.3rem 0}}
 table{{border-collapse:collapse;width:100%;background:#fff}}
 td,th{{border:1px solid #ddd;padding:4px 7px;vertical-align:top;text-align:left}}
 th{{background:#f0f0f0}}
 td.p{{font-family:ui-monospace,monospace;white-space:nowrap}}
 td.d{{white-space:nowrap;color:#555}}
 td.m{{max-width:20rem;font-size:12px}}
 img{{height:70px;border:1px solid #ccc;background:#eee;display:block}}
 img.wiki{{border:2px solid #c0392b}}
 .th{{display:inline-block;vertical-align:top;margin:1px 3px;text-align:center}}
 .th small{{display:block;font-size:10px;line-height:1.25;max-width:8rem}}
 .th .fn{{color:#444;font-family:ui-monospace,monospace}}
 .ok{{color:#178a1f}}
 .warn{{color:#a6730a;font-weight:bold}}
 .pmeta{{color:#999;font-size:11px;white-space:nowrap}}
 .hint{{color:#b00;font-size:11px;font-style:italic}}
 .sum{{color:#555;margin:.2rem 0 .6rem}}
 .skipnote{{color:#888;font-size:10px}}
 .wcap{{color:#c0392b;font-size:10px}}
 .th.sk img{{opacity:.55;border-style:dashed}}
 tr.tent td.p{{background:#fff8e8}}
 tr.wiki td.p{{background:#fdeceb}}
</style>
<h1>Gallery pics by poll</h1>
<p class=sum>{len(images)} polls mapped &middot; {sum(len(v) for v in images.values())} images
({conf_tally.get('high',0)+conf_tally.get('manual',0)} <span class=ok>confirmed</span>,
{conf_tally.get('tentative',0)} <span class=warn>tentative</span>;
{src_tally.get('gallery',0)} gallery, {src_tally.get('wiki',0)} <span class=wcap>Board&nbsp;8 wiki</span>)
&middot; {multi} polls with &gt;1 image.
Red-bordered = Board 8 wiki image (row tinted). Dashed/faded = a gallery pic that
exists for this match but is <b>not</b> mapped. Amber = tentative.
Companion to <a href="/data/gallery/audit.html">audit.html</a> (file-centric).</p>
"""]

    for contest in contest_order:
        rows = sorted(contest_rows[contest])
        cov = official.get(contest, 0)
        parts.append(f"<h2>{html.escape(contest)}</h2>")
        parts.append(f'<p class=sum>{len(rows)} of {cov} official matches have a mapped image</p>'
                     if cov else "")
        parts.append("<table><tr><th>poll</th><th>date</th><th>matchup "
                     "(finish order, winner bold)</th><th>mapped images</th>"
                     "<th>wiki (reference)</th></tr>")
        for _d, _pi, poll in rows:
            rec = records.get(poll, {})
            ims = images.get(poll, [])
            has_wiki = any(im.get("source") == "wiki" for im in ims)
            tentative = any(im.get("confidence") == "tentative" for im in ims)

            gcells = ""
            for im in sorted(ims, key=lambda x: (x.get("source", "gallery") == "wiki", x["file"])):
                cls = CONF_CLASS.get(im.get("confidence", ""), "warn")
                note = html.escape(im.get("note") or "")
                is_wiki = im.get("source") == "wiki"
                src = im["url"] if is_wiki else thumb(im["dir"], im["file"])
                dim = "" if im.get("w") is None else f'  {im["w"]}x{im["h"]}'
                gcells += (
                    f'<span class=th><a href="{im["url"]}" target=_blank>'
                    f'<img class="{"wiki" if is_wiki else ""}" loading=lazy src="{src}" '
                    f'title="{html.escape(im["file"])}{dim}"></a>'
                    f'<small><span class="fn">{html.escape(im["file"])}</span>'
                    f'<span class="{cls}"> {im.get("confidence","?")}</span>'
                    + (f' <span class=wcap>wiki</span>' if is_wiki else '')
                    + (f'<br>{note}' if note else "") + '</small></span>')
            for s in skipped_by_poll.get(poll, []):           # unmapped gallery pics
                gcells += (
                    f'<span class="th sk"><a href="{s["url"]}" target=_blank>'
                    f'<img loading=lazy src="{thumb(s["dir"], s["file"])}" title="{html.escape(s["file"])}"></a>'
                    f'<small><span class="fn">{html.escape(s["file"])}</span>'
                    f'<br><span class=skipnote>not mapped</span></small></span>')

            wcells = ""
            for wn, wu in zip(rec.get("wiki_images", []), rec.get("wiki_image_urls", [])):
                if has_wiki:                                  # already shown as a mapped image
                    continue
                src = wiki_src(wn, wu)
                wcells += (f'<a href="{src}" target=_blank>'
                           f'<img class=wiki loading=lazy src="{src}" title="{html.escape(wn)}"></a>')

            row_cls = " class=wiki" if has_wiki else " class=tent" if tentative else ""
            parts.append(
                f'<tr{row_cls}>'
                f'<td class="p">{poll}</td><td class="d">{rec.get("date","")}</td>'
                f'<td class="m">{matchup_html(rec)}</td>'
                f'<td>{gcells}</td><td>{wcells}</td></tr>')
        parts.append("</table>")

    OUT.write_text("".join(p for p in parts if p))
    print(f"wrote {OUT}  ({len(images)} polls, {sum(len(v) for v in images.values())} placements)")
    print("view  http://localhost:8000/data/gallery/by-poll.html")


if __name__ == "__main__":
    main()
