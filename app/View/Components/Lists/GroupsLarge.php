<?php

/*
 * GroupsLarge.php
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

namespace FireflyIII\View\Components\Lists;

use Closure;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class GroupsLarge extends Component
{
    public Collection|LengthAwarePaginator $groups;
    public ?Account $account;
    public bool $showCategory;
    public bool $showBudget;
    public bool $showSubscription;
    public array $subscriptions;

    /**
     * Create a new component instance.
     */
    public function __construct(Collection|LengthAwarePaginator $groups, ?bool $showCategory, ?bool $showBudget, ?Account $account = null)
    {
        $this->groups       = $groups;
        $this->account      = $account;
        $this->showCategory = $showCategory ?? false;
        $this->showBudget   = $showBudget ?? false;
        // lists that show a category or budget also show the subscription.
        $this->showSubscription = $this->showCategory || $this->showBudget;
        $this->subscriptions    = $this->showSubscription ? $this->getSubscriptions() : [];
    }

    /**
     * The subscription linked to each listed journal, keyed by journal ID, looked up here because not every list's
     * collector includes them.
     */
    private function getSubscriptions(): array
    {
        $journalIds = [];
        foreach ($this->groups as $group) {
            foreach ($group['transactions'] as $transaction) {
                $journalIds[] = (int) $transaction['transaction_journal_id'];
            }
        }
        if (0 === count($journalIds)) {
            return [];
        }
        $rows       = TransactionJournal::query()
            ->join('bills', 'bills.id', '=', 'transaction_journals.bill_id')
            ->whereIn('transaction_journals.id', $journalIds)
            ->whereNull('bills.deleted_at')
            ->get(['transaction_journals.id as journal_id', 'bills.id as bill_id', 'bills.name as bill_name'])
        ;
        $result     = [];
        foreach ($rows as $row) {
            $result[(int) $row->journal_id] = ['id' => (int) $row->bill_id, 'name' => (string) $row->bill_name];
        }

        return $result;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): Closure|string|View
    {
        return view('components.lists.groups-large');
    }
}
