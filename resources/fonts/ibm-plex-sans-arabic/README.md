# IBM Plex Sans Arabic — the eight files this product serves

The interface is set in IBM Plex Sans Arabic. Until now the panel asked
`fonts.bunny.net` for it on every page load, which cost a reader in Riyadh a DNS
lookup, a TLS handshake and a round trip to a third party before any Arabic text
could be drawn — and put the appearance of an internal attendance system in the
hands of a host nobody here controls. The faces are served from this application
instead. The declarations that use them are in
`resources/css/filament/theme.css`.

## Licence

**SIL Open Font License 1.1.** `OFL.txt` beside these files is the licence as IBM
publishes it, copied verbatim from
`https://raw.githubusercontent.com/IBM/plex/master/packages/plex-sans-arabic/LICENSE.txt`
(sha256 `7e6b2818edbd8f6a01ae80641cc8f16a51080d08fb4e532be3a0b6f74adb07da`, which
is also the sha256 of the licence at the root of that repository — they are the
same file). Copyright © 2017 IBM Corp., with Reserved Font Name "Plex".

The OFL permits redistribution, bundled with other software, provided the licence
travels with the font and the reserved name is not taken for a modified version.
Both conditions are met here: `OFL.txt` sits in this directory and is committed
with the fonts, and nothing in this repository modifies or renames a face — the
`@font-face` rules declare the family under the name IBM ships it with.

## Where the bytes came from

Downloaded from `https://fonts.bunny.net/ibm-plex-sans-arabic/files/`, which
mirrors the Fontsource build of the family (`license: OFL-1.1`, `source:
https://github.com/google/fonts`, npm package version 5.3.0). Bunny is where this
application was already fetching them from, so these are the same bytes the
interface has been drawn with since it launched, now served from our own origin.
Each file begins `wOF2`.

## Which files, and why only these

Four weights, two subsets, woff2 only:

| Weight | Where it is used |
|---|---|
| 400 | body text, table cells, every label |
| 500 | `FontWeight::Medium` — the emphasised column in the attendance and rejection tables |
| 600 | `FontWeight::SemiBold` — the leading column of every table, headings |
| 700 | Filament's own `font-bold`, and two places in this product's views |

That is exactly the set Filament's `BunnyFontProvider` was requesting
(`:400,500,600,700`), so nothing on any screen changes weight. Nothing else is
here: no italics, because no screen sets one, and none of the four light weights
or the black, because no class in this application or in Filament's compiled
theme asks for them.

`latin` and `arabic` only. The `latin-ext` and `cyrillic-ext` subsets Bunny also
publishes were left out: the two languages this product speaks are Arabic and
English, and a character outside the two loaded ranges falls through to the
Arabic-capable fallback stack the theme names after the family, which is the
correct outcome rather than a broken one.

woff2 only. Every browser that can be asked for a location — the entire premise
of this application — has supported woff2 for years, so a woff or ttf beside each
file would be weight nobody downloads.

## Replacing them

Fetch the same eight names from the URL above, keep `OFL.txt` beside them, and run
`npm run build`. Vite fingerprints each file into `public/build/assets/`, which is
committed because the server has no Node.
