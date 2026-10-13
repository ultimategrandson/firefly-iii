<?php

/**
 * BulkController.php
 * Copyright (c) 2019 james@firefly-iii.org
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

namespace FireflyIII\Http\Controllers\Transaction;

use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Http\Requests\BulkEditJournalRequest;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Class BulkController
 */
final class BulkController extends Controller
{
    private JournalRepositoryInterface $repository;

    /**
     * BulkController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            $this->repository = app(JournalRepositoryInterface::class);
            app('view')->share('title', (string) trans('firefly.transactions'));
            app('view')->share('mainTitleIcon', 'bi-shuffle');

            return $next($request);
        });
    }

    /**
     * Edit a set of journals in bulk.
     *
     * TODO user wont be able to tell if the journal is part of a split.
     *
     * @return Factory|View
     */
    public function edit(array $journals): Factory|\Illuminate\Contracts\View\View
    {
        $subTitle    = (string) trans('firefly.mass_bulk_journals');

        $this->rememberPreviousUrl('transactions.bulk-edit.url');

        // make amounts positive.

        // get list of budgets:
        /** @var BudgetRepositoryInterface $budgetRepos */
        $budgetRepos = app(BudgetRepositoryInterface::class);
        $budgetList  = app('expandedform')->makeSelectListWithEmpty($budgetRepos->getActiveBudgets());

        return view('transactions.bulk.edit', ['journals' => $journals, 'subTitle' => $subTitle, 'budgetList' => $budgetList]);
    }

    /**
     * Update all journals.
     */
    public function update(BulkEditJournalRequest $request): RedirectResponse
    {
        $journalIds     = $request->input('journals');
        $journalIds     = is_array($journalIds) ? $journalIds : [];
        $ignoreCategory = 1 === (int) $request->input('ignore_category');
        $ignoreBudget   = 1 === (int) $request->input('ignore_budget');
        $tagsAction     = $request->input('tags_action');
        $ignoreSource   = 1 === (int) $request->input('ignore_source_account');
        $ignoreDest     = 1 === (int) $request->input('ignore_destination_account');
        $sourceName     = $request->convertString('source_name');
        $destName       = $request->convertString('destination_name');
        $collection     = new Collection();
        $count          = 0;
        $tags           = null === $request->input('tags') ? [] : $request->input('tags');

        foreach ($journalIds as $journalId) {
            $journalId = (int) $journalId;
            $journal   = $this->repository->find($journalId);
            if (null !== $journal) {
                // Accounts first: they can change the journal's type, and only a withdrawal takes a budget.
                $resultD = $this->updateJournalAccounts($journal, $ignoreSource ? '' : $sourceName, $ignoreDest ? '' : $destName);
                $resultA = $this->updateJournalBudget($journal, $ignoreBudget, $request->integer('budget_id'));
                $resultB = $this->updateJournalTags($journal, $tagsAction, $tags);
                $resultC = $this->updateJournalCategory($journal, $ignoreCategory, $request->convertString('category'));
                if ($resultA || $resultB || $resultC || $resultD) {
                    ++$count;
                    $collection->push($journal);
                }
            }
        }

        $flags          = new TransactionGroupEventFlags();
        $objects        = new TransactionGroupEventObjects();

        // run rules on changed journals:
        /** @var TransactionJournal $journal */
        foreach ($collection as $journal) {
            $objects->appendFromTransactionGroup($journal->transactionGroup);
        }
        event(new UpdatedSingleTransactionGroup($flags, $objects));
        event(new WebhookMessagesRequestSending());

        Preferences::mark();
        $request->session()->flash('success', trans_choice('firefly.mass_edited_transactions_success', $count));

        // redirect to previous URL:
        return redirect($this->getPreviousUrl('transactions.bulk-edit.url'));
    }

    /**
     * Moves the journal to the named source and/or destination account (an empty name leaves that side alone).
     *
     * A name is looked for among asset and liability accounts first, then among expense (destination) or revenue
     * (source) accounts, and only an unknown name makes a new expense or revenue account. The journal's type then
     * follows from the pair, as on the convert page: naming an asset account as a withdrawal's destination makes it a
     * transfer. Passing only a name, the update service looked it up by the journal's current type, so an asset
     * account's name made a new expense account of that name and the withdrawal pointed at it.
     *
     * Left as it was: a reconciled journal, a pair that makes no transaction (an expense account as a deposit's
     * destination), the same account on both sides, and two own accounts in different currencies, which need the
     * amounts the convert page asks for.
     */
    private function updateJournalAccounts(TransactionJournal $journal, string $sourceName, string $destName): bool
    {
        if ('' === $sourceName && '' === $destName) {
            return false;
        }
        if ($journal->transactions()->where('reconciled', true)->exists()) {
            Log::debug(sprintf('Journal #%d is reconciled, will not change its accounts.', $journal->id));

            return false;
        }
        $sourceTransaction = $journal->transactions()->where('amount', '<', 0)->first();
        $destTransaction   = $journal->transactions()->where('amount', '>', 0)->first();
        if (null === $sourceTransaction?->account || null === $destTransaction?->account) {
            return false;
        }

        [$source, $sourceType] = $this->resolveAccount($sourceName, AccountTypeEnum::REVENUE->value, $sourceTransaction->account);
        [$dest, $destType]     = $this->resolveAccount($destName, AccountTypeEnum::EXPENSE->value, $destTransaction->account);

        $type              = config('firefly.account_to_transaction')[$sourceType][$destType] ?? null;
        $movable           = [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value];
        if (!in_array($type, $movable, true)) {
            Log::debug(sprintf('Journal #%d: a %s account cannot pay a %s account, left alone.', $journal->id, $sourceType, $destType));

            return false;
        }
        if ($source instanceof Account && $dest instanceof Account && $source->id === $dest->id) {
            Log::debug(sprintf('Journal #%d would have #%d on both sides, left alone.', $journal->id, $source->id));

            return false;
        }
        if ($source instanceof Account && $dest instanceof Account && !$this->sameCurrency($source, $dest)) {
            Log::debug(sprintf('Journal #%d would move between currencies, left alone.', $journal->id));

            return false;
        }

        // Only now, with the change known to be valid, is a new expense or revenue account made.
        $source ??= $this->newAccount($sourceName, $sourceType);
        $dest   ??= $this->newAccount($destName, $destType);

        $before            = [(int) $sourceTransaction->account_id, (int) $destTransaction->account_id, $journal->transactionType->type];

        /** @var JournalUpdateService $service */
        $service           = app(JournalUpdateService::class);
        $service->setTransactionJournal($journal);
        $service->setData(['source_id' => $source->id, 'destination_id' => $dest->id, 'type' => $type]);
        $service->update();
        $journal->refresh();

        // Budgets and subscriptions belong to withdrawals only; the update service clears a transfer's budget but
        // keeps a subscription, and keeps both on a deposit.
        if (TransactionTypeEnum::WITHDRAWAL->value !== $journal->transactionType->type) {
            $journal->budgets()->sync([]);
            if (null !== $journal->bill_id) {
                $journal->bill_id = null;
                $journal->save();
            }
        }

        $after             = [
            (int) $journal->transactions()->where('amount', '<', 0)->value('account_id'),
            (int) $journal->transactions()->where('amount', '>', 0)->value('account_id'),
            $journal->transactionType->type,
        ];
        Log::debug(sprintf('Bulk changed journal #%d from %s to %s', $journal->id, implode(',', $before), implode(',', $after)));

        return $before !== $after;
    }

    /**
     * The account a name means, with its type. An empty name keeps the current account. An unknown name returns no
     * account and the creatable type, so the caller can check the change before anything is made.
     *
     * @return array{0: null|Account, 1: string}
     */
    private function resolveAccount(string $name, string $creatableType, Account $current): array
    {
        if ('' === $name) {
            return [$current, $current->accountType->type];
        }

        /** @var AccountRepositoryInterface $accounts */
        $accounts = app(AccountRepositoryInterface::class);
        $accounts->setUser(auth()->user());
        $own      = [AccountTypeEnum::ASSET->value, AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value];
        $account  = $accounts->findByName($name, $own) ?? $accounts->findByName($name, [$creatableType]);

        return null === $account ? [null, $creatableType] : [$account, $account->accountType->type];
    }

    private function newAccount(string $name, string $type): Account
    {
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser(auth()->user());

        return $factory->findOrCreate($name, $type);
    }

    /**
     * Expense and revenue accounts carry no currency of their own, so only a pair of own accounts can disagree.
     */
    private function sameCurrency(Account $source, Account $dest): bool
    {
        /** @var AccountRepositoryInterface $accounts */
        $accounts = app(AccountRepositoryInterface::class);
        $accounts->setUser(auth()->user());
        $from     = $accounts->getAccountCurrency($source);
        $to       = $accounts->getAccountCurrency($dest);

        return null === $from || null === $to || $from->id === $to->id;
    }

    private function updateJournalBudget(TransactionJournal $journal, bool $ignoreUpdate, int $budgetId): bool
    {
        if ($ignoreUpdate || TransactionTypeEnum::WITHDRAWAL->value !== $journal->transactionType->type) {
            return false;
        }
        Log::debug(sprintf('Set budget to %d', $budgetId));
        $this->repository->updateBudget($journal, $budgetId);

        return true;
    }

    private function updateJournalCategory(TransactionJournal $journal, bool $ignoreUpdate, string $category): bool
    {
        if ($ignoreUpdate) {
            return false;
        }
        Log::debug(sprintf('Set budget to %s', $category));
        $this->repository->updateCategory($journal, $category);

        return true;
    }

    private function updateJournalTags(TransactionJournal $journal, string $action, array $tags): bool
    {
        if ('do_replace' === $action) {
            Log::debug(sprintf('Set tags to %s', implode(',', $tags)));
            $this->repository->updateTags($journal, $tags);
        }
        if ('do_append' === $action) {
            $existing = $journal->tags->pluck('tag')->toArray();
            $new      = array_unique(array_merge($tags, $existing));
            $this->repository->updateTags($journal, $new);
        }

        return true;
    }
}
