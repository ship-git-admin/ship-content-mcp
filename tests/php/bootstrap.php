<?php
/**
 * WordPress なしで試験できる部品だけを読む（署名の形・IP の判定）。
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../ship-content-mcp/src/Security/SignatureCodec.php';
require __DIR__ . '/../../ship-content-mcp/src/Security/IpRules.php';
