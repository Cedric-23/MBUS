<?php
// Used by Render health checks (no database required)
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
