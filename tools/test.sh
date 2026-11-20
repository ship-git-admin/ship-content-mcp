#!/bin/bash
# 手元の試験をまとめて動かす（WordPress は使わない。実機の試験は wp ship-content-mcp selftest）。
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-php}"

echo "== PHP の構文"
BAD=$(find ship-content-mcp tests -name '*.php' -print0 | xargs -0 -n1 "$PHP" -l 2>&1 | grep -v '^No syntax errors' || true)
if [ -n "$BAD" ]; then echo "$BAD"; exit 1; fi
echo "OK"

echo "== PHP：署名の形・IP"
"$PHP" tests/php/run.php | tail -1

echo "== PHP：ACF の構造を読む（架空の ACF）"
"$PHP" tests/php/schema_reader_test.php tests/fixtures/acf_schema.synthetic.json | tail -1

echo "== PHP：ACF の値の検証・差分（架空の入力欄）"
"$PHP" tests/php/validator_test.php | tail -1

echo "== PHP：写真（形式・分割・base64・位置情報の削除）"
"$PHP" tests/php/media_test.php | tail -1

echo "== 制作方針の出力（contracts/policy_output.schema.json）"
"$PHP" tests/php/policy_output_test.php | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
try:
    import jsonschema
    schema = json.load(open("contracts/policy_output.schema.json"))
    for k in ("empty", "saved"):
        jsonschema.validate(d[k], schema)
except ImportError:
    print("（jsonschema がないため、形の検査は飛ばしました）")
assert d["empty"]["version"] == 1 and d["saved"]["version"] == 2 and d["revision"] == 2
assert d["saved"]["type_policy"] == "施工後の写真は基本PC2カラム。\n写真1枚なら1カラムでもよい。"
assert d["history_len"] == 3
print("OK")
'

echo "== 中継プログラム"
node --test tests/node/bridge.test.mjs 2>&1 | grep -E '^ℹ (pass|fail)'
