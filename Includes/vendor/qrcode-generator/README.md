# Vendored QR Code Generator

Source: [Kazuhiko Arase's qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator), `php/qrcode.php`.

Pinned upstream revision: `83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8`.

Original source: https://raw.githubusercontent.com/kazuhikoarase/qrcode-generator/83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8/php/qrcode.php

Original source SHA-256: `839337a00e1ab8be1916ab20702d724aa41cdd7991dd7fc8187ffbc602998b4e`.

Copyright (c) 2009 Kazuhiko Arase. Licensed under the MIT license; the complete license is preserved in `LICENSE` and the source copyright header is retained.

Local adaptations are limited to adding the `MBUS\Vendor\QRCodeGenerator` namespace, replacing global `define()` calls with namespace-local `const` declarations to avoid collisions, and omitting the closing PHP tag to prevent accidental PDF output. Encoding algorithms and tables are unchanged.

`Includes/qr_pdf.php` uses only the module-matrix API, with explicit byte mode and medium error correction (matching the on-screen ticket). It bounds automatic sizing to versions 1–10, the upstream capacity table's supported range. Current signed ticket tokens fit within version 5 even for the largest 64-bit IDs. Rendering uses FPDF vector rectangles; upstream's optional GD image methods are never called. No Composer, GD, JavaScript, external QR service, or runtime download is required.
