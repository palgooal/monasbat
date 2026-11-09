# Font sources and licences

All local font files in `fonts/` are Arabic-script WOFF2 subsets fetched on 2026-10-26 from the official Google Fonts CSS API using a current Chrome user agent. They are intentionally limited to the three weights used in the comparison: 400, 500 and 700. The prototype uses `font-display: swap`.

| Family | Official source | Licence | Local files |
| --- | --- | --- | --- |
| IBM Plex Sans Arabic | [IBM Plex package](https://github.com/IBM/plex/tree/master/packages/plex-sans-arabic) and the [package licence](https://github.com/IBM/plex/blob/master/packages/plex-sans-arabic/LICENSE.txt) | SIL Open Font License 1.1; the package declares IBM's reserved font name “Plex”. | `ibm-plex-sans-arabic-*-arabic.woff2` |
| Tajawal | [Google Fonts family source](https://github.com/google/fonts/tree/main/ofl/tajawal) and [OFL.txt](https://github.com/google/fonts/blob/main/ofl/tajawal/OFL.txt) | SIL Open Font License 1.1 | `tajawal-*-arabic.woff2` |
| Cairo | [Google Fonts family source](https://github.com/google/fonts/tree/main/ofl/cairo) and [OFL.txt](https://github.com/google/fonts/blob/main/ofl/cairo/OFL.txt) | SIL Open Font License 1.1 | `cairo-*-arabic.woff2` |

The WOFF2 delivery URLs were resolved through the official endpoint `https://fonts.googleapis.com/css2` and downloaded from its official `fonts.gstatic.com` URLs. This comparison does not alter or rename the font binaries.
