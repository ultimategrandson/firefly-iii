<?php

/*
 * TriggerStopProcessingTest.php
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

namespace Tests\integration\Api\RuleGroup;

use FireflyIII\Models\TransactionJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Override;
use Tests\integration\TestCase;

/**
 * Applying a rule group to existing transactions must honour "stop processing" per journal, the way
 * the group behaves when a transaction is stored: a journal matched by a stopping rule is left alone
 * by the rules after it, and every other journal still gets those rules.
 *
 * @internal
 *
 * @coversNothing
 */
final class TriggerStopProcessingTest extends TestCase
{
    use RefreshDatabase;

    private string $today;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Passport::actingAs($this->createAuthenticatedUser());
        $this->today = date('Y-m-d');
    }

    public function testTriggeredGroupStopsPerJournal(): void
    {
        $card   = $this->storeAssetAccount('Card');
        $group  = $this->storeRuleGroup('Door Dash');

        // The specific rule comes first and stops; the general one after it would also match the fee.
        $this->storeRule($group, 1, 'Service fee', 'description_ends', 'Service fee', 'Service fee', true);
        $this->storeRule($group, 2, 'Dining out', 'destination_account_is', 'DoorDash', 'Dining Out', false);

        $order  = $this->storeWithdrawal($card, 'DoorDash order', [
            ['description' => 'DoorDash order', 'amount' => '80.00', 'destination_name' => 'ALDI'],
            ['description' => 'DoorDash order — Service fee', 'amount' => '8.00', 'destination_name' => 'DoorDash'],
        ]);
        $burger = $this->storeWithdrawal($card, null, [
            ['description' => 'Burger', 'amount' => '30.00', 'destination_name' => 'DoorDash'],
        ]);

        $this->postJson(
            route('api.v1.rule-groups.trigger', [$group]).sprintf('?start=%s&end=%s', $this->today, $this->today)
        )->assertNoContent();

        $this->assertSame('Service fee', $this->category($order['DoorDash order — Service fee']), 'the stopping rule wins for the fee');
        $this->assertSame('Dining Out', $this->category($burger['Burger']), 'a journal the stopping rule did not match still gets the later rule');
        $this->assertNull($this->category($order['DoorDash order']), 'a journal neither rule matched is untouched');
    }

    public function testStoredJournalStillStopsTheGroup(): void
    {
        $card  = $this->storeAssetAccount('Card');
        $group = $this->storeRuleGroup('Door Dash');
        $this->storeRule($group, 1, 'Service fee', 'description_ends', 'Service fee', 'Service fee', true);
        $this->storeRule($group, 2, 'Dining out', 'destination_account_is', 'DoorDash', 'Dining Out', false);

        // Rules fire on store here, one journal at a time, as they always have.
        $fee   = $this->storeWithdrawal($card, null, [
            ['description' => 'Order — Service fee', 'amount' => '8.00', 'destination_name' => 'DoorDash'],
        ], applyRules: true);

        $this->assertSame('Service fee', $this->category($fee['Order — Service fee']));
    }

    private function category(int $journalId): ?string
    {
        return TransactionJournal::query()->find($journalId)?->categories()->first()?->name;
    }

    private function storeAssetAccount(string $name): int
    {
        $response = $this->postJson(route('api.v1.accounts.store'), [
            'name'         => $name,
            'type'         => 'asset',
            'account_role' => 'defaultAsset',
            'currency_code' => 'EUR',
        ]);
        $response->assertOk();

        return (int) $response->json('data.id');
    }

    /**
     * @param array<int, array{description: string, amount: string, destination_name: string}> $splits
     *
     * @return array<string, int> journal id by description
     */
    private function storeWithdrawal(int $source, ?string $title, array $splits, bool $applyRules = false): array
    {
        $response = $this->postJson(route('api.v1.transactions.store'), [
            'apply_rules'   => $applyRules,
            'fire_webhooks' => false,
            'group_title'   => $title,
            'transactions'  => array_map(fn (array $split): array => [
                'type'             => 'withdrawal',
                'date'             => $this->today,
                'amount'           => $split['amount'],
                'description'      => $split['description'],
                'source_id'        => (string) $source,
                'destination_name' => $split['destination_name'],
                'currency_code'    => 'EUR',
            ], $splits),
        ]);
        $response->assertOk();

        $ids = [];
        foreach ($response->json('data.attributes.transactions') as $journal) {
            $ids[$journal['description']] = (int) $journal['transaction_journal_id'];
        }

        return $ids;
    }

    private function storeRuleGroup(string $title): int
    {
        $response = $this->postJson(route('api.v1.rule-groups.store'), ['title' => $title]);
        $response->assertOk();

        return (int) $response->json('data.id');
    }

    private function storeRule(int $group, int $order, string $title, string $trigger, string $value, string $category, bool $stop): void
    {
        $this->postJson(route('api.v1.rules.store'), [
            'title'           => $title,
            'rule_group_id'   => (string) $group,
            'order'           => $order,
            'trigger'         => 'store-journal',
            'active'          => true,
            'strict'          => false,
            'stop_processing' => $stop,
            'triggers'        => [['type' => $trigger, 'value' => $value, 'active' => true, 'stop_processing' => false]],
            'actions'         => [['type' => 'set_category', 'value' => $category, 'active' => true, 'stop_processing' => false]],
        ])->assertOk();
    }
}
