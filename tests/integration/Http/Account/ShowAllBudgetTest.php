<?php

/*
 * ShowAllBudgetTest.php
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

namespace Tests\integration\Http\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\integration\TestCase;

/**
 * The "all transactions" view of an account must fill the budget column, as the dated view does.
 *
 * @internal
 *
 * @coversNothing
 */
final class ShowAllBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function testAllTransactionsListShowsTheBudget(): void
    {
        $user    = $this->createAuthenticatedUser();
        Passport::actingAs($user);

        $account = $this->postJson(route('api.v1.accounts.store'), [
            'name'          => 'Card',
            'type'          => 'asset',
            'account_role'  => 'defaultAsset',
            'currency_code' => 'EUR',
        ])->assertOk()->json('data.id');

        $this->postJson(route('api.v1.budgets.store'), ['name' => 'Weekly shop'])->assertOk();

        $this->postJson(route('api.v1.transactions.store'), [
            'apply_rules'  => false,
            'transactions' => [[
                'type'             => 'withdrawal',
                'date'             => date('Y-m-d'),
                'amount'           => '12.34',
                'description'      => 'Groceries run',
                'source_id'        => (string) $account,
                'destination_name' => 'Supermarket',
                'currency_code'    => 'EUR',
                'budget_name'      => 'Weekly shop',
            ]],
        ])->assertOk();

        // The page, not its compiled assets, is under test.
        $this->withoutVite();

        $this->actingAs($user)
            ->get(route('accounts.show.all', [$account]))
            ->assertOk()
            ->assertSee('Groceries run')
            ->assertSee('Weekly shop')
        ;
    }
}
