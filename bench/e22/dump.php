<?php
// Answers with what this SAPI made of the request body. Used by BOTH arms of E22: under `php -S`
// it reports PHP's own rfc1867 (the oracle), under ignis it reports our parser.
echo json_encode(['post' => $_POST], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
