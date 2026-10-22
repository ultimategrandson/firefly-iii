<?php

/*
 * AmountLocaleInfoFromPatternTest.php
 * Copyright (c) 2026 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Tests\unit\Support;

use FireflyIII\Support\Amount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Without monetary locale data (musl, or a locale that isn't installed), the accounting.js formats
 * are read from the ICU pattern, and must come out as glibc's localeconv() has them.
 *
 * @group unit-test
 * @group support
 *
 * @internal
 *
 * @coversNothing
 */
final class AmountLocaleInfoFromPatternTest extends TestCase
{
    #[DataProvider('providePatterns')]
    public function testFormatsFollowThePattern(string $pattern, string $positive, string $negative): void
    {
        $info = Amount::getLocaleInfoFromPattern($pattern, '-');

        $this->assertSame($positive, Amount::getAmountJsConfig($info['p_sep_by_space'], $info['p_sign_posn'], $info['positive_sign'], $info['p_cs_precedes']));
        $this->assertSame($negative, Amount::getAmountJsConfig($info['n_sep_by_space'], $info['n_sign_posn'], $info['negative_sign'], $info['n_cs_precedes']));
    }

    public static function providePatterns(): iterable
    {
        yield 'en_US' => ['¤#,##0.00', '%s%v', '-%s%v'];

        yield 'de_DE' => ["#,##0.00\u{00a0}¤", '%v %s', '-%v %s'];

        yield 'pt_BR' => ["¤\u{00a0}#,##0.00", '%s %v', '-%s %v'];

        // the sign sits between the symbol and the amount, in place of the space:
        yield 'nl_NL' => ["¤\u{00a0}#,##0.00;¤\u{00a0}-#,##0.00", '%s %v', '%s-%v'];

        yield 'accounting' => ['¤#,##0.00;(¤#,##0.00)', '%s%v', '(%s%v)'];

        yield 'sign after' => ['#,##0.00 ¤;#,##0.00 ¤-', '%v %s', '%v %s-'];
    }
}
