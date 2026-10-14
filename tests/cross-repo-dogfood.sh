#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
upstream="$root/_integration/itp-context"
test -f "$upstream/vendor/autoload.php"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/host/src"

cat > "$tmp/host/src/Rules.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Fx;

use ItpContext\Contract\RuleIdentifier;
use ItpContext\Model\RuleDef;

enum Rules implements RuleIdentifier
{
    case Mailer;

    public function getDefinition(): RuleDef
    {
        return match ($this) {
            self::Mailer => new RuleDef(
                statement: 'Use the mailer abstraction.',
                refs: ['docs/adr/mailer.md'],
                changeGuidance: 'Read docs/adr/mailer.md before modifying this method.',
            ),
        };
    }
}
PHP

cat > "$tmp/host/src/Mailer.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Fx;

use ItpContext\Attribute\Rule;

final class Mailer
{
    #[Rule(Rules::Mailer)]
    public function send(string $to): string
    {
        return strtoupper($to);
    }
}
PHP

git -C "$tmp/host" init -q -b main
git -C "$tmp/host" config user.email 'dogfood@example.test'
git -C "$tmp/host" config user.name 'Dogfood'
git -C "$tmp/host" config commit.gpgsign false
git -C "$tmp/host" add -A
git -C "$tmp/host" commit -q -m baseline

sed -i 's/strtoupper/strtolower/' "$tmp/host/src/Mailer.php"

# Real notice producer, using the pinned itp-context checkout.
php "$upstream/bin/itp-context-changes" --root="$tmp/host" --base=HEAD > "$tmp/notices.json"
php -r '
$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (count($d["notices"]) !== 1
    || $d["notices"][0]["subject"] !== "Fx\\Mailer::send"
    || $d["notices"][0]["refs"] !== ["docs/adr/mailer.md"]
    || !str_contains($d["notices"][0]["message"], "Read docs/adr/mailer.md")
) {
    fwrite(STDERR, "Producer did not identify the protected method and its ADR guidance.\n");
    exit(1);
}
' "$tmp/notices.json"

# A local agent sees each specific change once. CI ignores acknowledgement.
env -u CI php "$upstream/bin/itp-context-changes" --root="$tmp/host" --base=HEAD --once --state-dir="$tmp/state" > "$tmp/local-1.json"
env -u CI php "$upstream/bin/itp-context-changes" --root="$tmp/host" --base=HEAD --once --state-dir="$tmp/state" > "$tmp/local-2.json"
php -r '
$a = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$b = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
if (count($a["notices"]) !== 1 || count($b["notices"]) !== 0) {
    fwrite(STDERR, "Local acknowledgement did not suppress an unchanged notice.\n");
    exit(1);
}
' "$tmp/local-1.json" "$tmp/local-2.json"
CI=true php "$upstream/bin/itp-context-changes" --root="$tmp/host" --base=HEAD --once --state-dir="$tmp/state" > "$tmp/ci.json"
cmp "$tmp/notices.json" "$tmp/ci.json"

sed -i 's/strtolower/ucfirst/' "$tmp/host/src/Mailer.php"
env -u CI php "$upstream/bin/itp-context-changes" --root="$tmp/host" --base=HEAD --once --state-dir="$tmp/state" > "$tmp/local-3.json"
php -r '
$a = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$b = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
if (count($b["notices"]) !== 1 || $a["notices"][0]["id"] === $b["notices"][0]["id"]) {
    fwrite(STDERR, "Changed implementation did not produce a fresh advisory notice.\n");
    exit(1);
}
' "$tmp/local-1.json" "$tmp/local-3.json"

# Pass the real producer output to PHPStan's actual agent formatter, not a mocked DTO.
php "$upstream/bin/itp-context-changes" --root="$tmp/host" --base=HEAD > "$tmp/notices.json"
cat > "$tmp/phpstan.neon" <<NEON
includes:
    - $root/extension.neon
parameters:
    level: max
    tmpDir: $tmp/phpstan-cache
    paths:
        - $root/tests/Fixture/CleanRun.php
    agentFormat:
        outputMode: json
        noticesFile: $tmp/notices.json
NEON

"$root/vendor/bin/phpstan" analyse --configuration="$tmp/phpstan.neon" --error-format=agent --no-progress > "$tmp/clean.json"
"$root/vendor/bin/phpstan" analyse --configuration="$tmp/phpstan.neon" --error-format=agent --no-progress > "$tmp/warm.json"

php -r '
foreach (array_slice($argv, 1) as $path) {
    $d = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($d["summary"]["totalIssues"] !== 0
        || count($d["notices"]) !== 1
        || $d["notices"][0]["subject"] !== "Fx\\Mailer::send"
        || $d["notices"][0]["refs"] !== ["docs/adr/mailer.md"]
    ) {
        fwrite(STDERR, "Formatter lost the real notice or introduced a fake PHPStan error.\n");
        exit(1);
    }
}
' "$tmp/clean.json" "$tmp/warm.json"

sed "s|$root/tests/Fixture/CleanRun.php|$root/tests/Fixture/MethodOnString.php|" "$tmp/phpstan.neon" > "$tmp/phpstan-error.neon"
set +e
"$root/vendor/bin/phpstan" analyse --configuration="$tmp/phpstan-error.neon" --error-format=agent --no-progress > "$tmp/error.json"
status=$?
set -e
test "$status" -eq 1
php -r '
$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if ($d["summary"]["totalIssues"] < 1 || count($d["notices"]) !== 1) {
    fwrite(STDERR, "Real PHPStan errors must remain errors alongside advisory notices.\n");
    exit(1);
}
' "$tmp/error.json"

printf '%s\n' 'Cross-repository dogfood passed: generated notice, local once/CI behavior, warm cache, and real-error exit code.'
