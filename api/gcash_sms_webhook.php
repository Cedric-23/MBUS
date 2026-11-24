<?php
// SMS auto-confirm has been retired. Conductors check and approve every GCash payment by hand.
http_response_code(410);
header('Content-Type: application/json');
echo '{"ok":false,"error":"SMS auto-confirm is turned off."}';
