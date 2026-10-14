<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext;

use ItpContext\Contract\RuleIdentifier;
use ItpContext\Model\RuleDef;

enum Rules implements RuleIdentifier
{
    case Database;
    case Mail;

    public function getDefinition(): RuleDef
    {
        return match ($this) {
            self::Database => new RuleDef(
                statement: 'Use the database abstraction.',
                refs: ['docs/adr/database.md'],
                changeGuidance: 'Read docs/adr/database.md first.',
            ),
            self::Mail => new RuleDef(
                statement: 'Send mail through the mailer abstraction.',
                refs: ['docs/adr/mail.md'],
            ),
        };
    }
}
