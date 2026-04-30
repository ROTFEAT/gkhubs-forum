#!/usr/bin/env bash
# cms/tests/static-check.sh —— 在 Docker test 阶段内运行，无网络/无 DB 依赖。
set -euo pipefail

echo "==> PHP 语法检查"
find mu-plugins healthz.php -type f -name "*.php" -print0 \
  | xargs -0 -n1 php -l

echo "==> Bash 语法检查"
find tests -type f -name "*.sh" -print0 \
  | xargs -0 -n1 bash -n

echo "==> 至少要有一个 mu-plugin"
test -n "$(find mu-plugins -maxdepth 1 -name '*.php' -print -quit)" \
  || { echo "FAIL: mu-plugins/ 没有 PHP 文件"; exit 1; }

echo "==> JSON fixture 验证"
for f in tests/fixtures/*.json; do
    [ -f "$f" ] || continue
    python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$f"
done

echo "==> 静态检查全部通过"
