# Competition Card Design QA

- Source visual: `codex-clipboard-85b15c01-69c0-484e-aefe-d86771f0b369.png`
- Source dimensions: 881 x 1280 pixels
- Preview: `tests/visual/competition-card-preview.php?preview=1`
- Browser: Codex in-app browser

## Checks

- The original supplied artwork is used as the full card background without stretching or cropping.
- The rendered card uses the exact 881:1280 source aspect ratio.
- Dynamic name, insurance date, measured weight, age category, and city fields fully cover the sample values.
- The participant photo and verification area fully cover the sample portrait and QR code.
- Text remains legible and centered at the desktop preview width.
- Print CSS uses A4 portrait, zero page margin, full viewport height, centered placement, and print color adjustment.
- The print toolbar is excluded from printed output.

No P0, P1, P2, or P3 visual issues remain.

final result: passed
