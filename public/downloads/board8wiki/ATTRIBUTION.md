# Attribution

The Markdown in this bundle is derived from the **Board 8 wiki**
(<https://board8.fandom.com/>), which publishes its content under the
**Creative Commons Attribution-ShareAlike 3.0** licence
(<https://creativecommons.org/licenses/by-sa/3.0/>).

## Contents

- 19 contest overview pages
- 1528 individual match writeups

grouped one file per contest (`packs/`), plus `board8wiki-all.md` with every
contest concatenated.

## Changes made to the original

- MediaWiki markup converted to GitHub-flavoured Markdown (via pandoc).
- Navigation boxes, character-icon templates, image embeds, `<gallery>` blocks
  and `<ref>` footnotes removed.
- `[[wikilinks]]` flattened to plain text.
- The writeup infobox (division, match number, date, predictions) flattened to a
  bullet list.
- Pages concatenated per contest; each page's top heading demoted one level.

Every section keeps a `_source: <page title> (revid N)_` line identifying the
exact revision it was taken from. Page URLs follow the pattern
`https://board8.fandom.com/wiki/<page title>`.

## Not affiliated

This is an independent archive. It is not affiliated with, endorsed by, or
maintained by the Board 8 wiki, Fandom, or GameFAQs.

Generated 2026-09-10 by `scripts/board8wiki-bundle.py`.
