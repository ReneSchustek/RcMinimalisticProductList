<?php

declare(strict_types=1);

namespace Ruhrcoder\RcMinimalisticProductList\Service;

/**
 * Die drei Werte der Plugin-Einstellung „Geltungsbereich“.
 *
 * Die Fallwerte sind die Optionskennungen aus `config.xml` und stehen so in `system_config`; wer einen
 * davon umbenennt, setzt jeden gespeicherten Shop auf den Rückfall in `LayoutDecision`.
 */
enum LayoutScope: string
{
    case Off = 'off';
    case All = 'all';
    case Categories = 'categories';
}
