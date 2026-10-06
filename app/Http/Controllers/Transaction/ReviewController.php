<?php

/**
 * ReviewController.php
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

namespace FireflyIII\Http\Controllers\Transaction;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Models\RecurrenceTransaction;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Lists what was created after a cut-off moment (transactions, expense and revenue accounts), so
 * a batch of imported transactions can be reviewed, bulk edited and cleaned up in one place.
 */
final class ReviewController extends Controller
{
    private const string PREFERENCE = 'lastTransactionReview';

    public function __construct()
    {
        parent::__construct();

        $this->middleware(static function ($request, $next) {
            app('view')->share('title', (string) trans('firefly.transactions'));
            app('view')->share('mainTitleIcon', 'bi-shuffle');

            return $next($request);
        });
    }

    public function index(Request $request): Factory|\Illuminate\Contracts\View\View
    {
        /** @var User $user */
        $user       = auth()->user();
        $cutoff     = $this->getCutoff($request);
        $page       = clamp((int) $request->input('page'), 1, 2 ** 16);
        $pageSize   = (int) Preferences::get('listPageSize', 50)->data;
        $journalIds = $user->transactionJournals()->where('transaction_journals.created_at', '>=', $cutoff)->pluck('transaction_journals.id')->all();

        if (0 === count($journalIds)) {
            // the collector ignores an empty ID list, so it would list everything.
            $groups = new LengthAwarePaginator([], 0, $pageSize, $page);
        }
        if (0 !== count($journalIds)) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector
                ->setJournalIds($journalIds)
                ->setLimit($pageSize)
                ->setPage($page)
                ->withAccountInformation()
                ->withBudgetInformation()
                ->withCategoryInformation()
                ->withAttachmentInformation()
            ;
            $groups    = $collector->getPaginatedGroups();
        }
        $groups->setPath(route('transactions.review.index'));
        $groups->appends(['cutoff' => $cutoff->format('Y-m-d\TH:i')]);

        $accounts   = $this->getNewAccounts($user, $cutoff);
        $empty      = $this->getEmptyAccounts($accounts);

        return view('transactions.review', [
            'subTitle'     => (string) trans('firefly.review_new'),
            'subTitleIcon' => 'bi-check2-square',
            'cutoff'       => $cutoff,
            'journalCount' => count($journalIds),
            'groups'       => $groups,
            'accounts'     => $accounts,
            'empty'        => $empty,
            'lastReview'   => Preferences::get(self::PREFERENCE)?->data,
        ]);
    }

    /**
     * Remember now as the moment everything was reviewed, so the next review starts from here.
     */
    public function markReviewed(Request $request): RedirectResponse
    {
        $now = now(config('app.timezone'));
        Preferences::set(self::PREFERENCE, $now->format('Y-m-d H:i:s'));
        $request->session()->flash('success', (string) trans('firefly.review_marked', ['date' => $now->isoFormat((string) trans('config.date_time_js'))]));

        return redirect(route('transactions.review.index'));
    }

    /**
     * Delete the expense and revenue accounts created after the cut-off that have no transactions left, such as ones
     * whose transactions were all moved to an existing account. Accounts a recurring transaction uses are kept,
     * because deleting them would delete the recurring transaction too.
     */
    public function prune(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user       = auth()->user();
        $cutoff     = $this->getCutoff($request);
        $repository = app(AccountRepositoryInterface::class);
        $count      = 0;

        /** @var Account $account */
        foreach ($this->getEmptyAccounts($this->getNewAccounts($user, $cutoff)) as $account) {
            $repository->destroy($account, null);
            ++$count;
        }
        Preferences::mark();
        $request->session()->flash('success', trans_choice('firefly.review_pruned', $count));

        return redirect(route('transactions.review.index', ['cutoff' => $cutoff->format('Y-m-d\TH:i')]));
    }

    private function getCutoff(Request $request): Carbon
    {
        $timezone = config('app.timezone');
        $value    = (string) $request->input('cutoff');
        if ('' === $value) {
            $value = (string) Preferences::get(self::PREFERENCE)?->data;
        }
        if ('' !== $value) {
            try {
                return Carbon::parse($value, $timezone)->startOfMinute();
            } catch (InvalidArgumentException) {
                // fall through to the default.
            }
        }

        return today($timezone)->subDays(7);
    }

    /**
     * Expense and revenue accounts created after the cut-off, with their number of transactions.
     */
    private function getNewAccounts(User $user, Carbon $cutoff): Collection
    {
        $types = [AccountTypeEnum::EXPENSE->value, AccountTypeEnum::REVENUE->value];

        return $user->accounts()
            ->with(['accountType'])
            ->withCount('transactions')
            ->whereHas('accountType', static function ($query) use ($types): void {
                $query->whereIn('type', $types);
            })
            ->where('accounts.created_at', '>=', $cutoff)
            ->get()
            ->sortBy([
                static fn (Account $a, Account $b): int => $a->accountType->type <=> $b->accountType->type,
                static fn (Account $a, Account $b): int => strcasecmp($a->name, $b->name),
            ])
            ->values()
        ;
    }

    /**
     * Of these accounts, the IDs of the ones a recurring transaction uses.
     */
    private function getRecurringAccountIds(Collection $accounts): array
    {
        $ids       = $accounts->pluck('id')->all();
        $recurring = RecurrenceTransaction::query()
            ->where(static function ($query) use ($ids): void {
                $query->whereIn('source_id', $ids)->orWhereIn('destination_id', $ids);
            })
            ->get(['source_id', 'destination_id'])
        ;

        return array_map(intval(...), array_merge($recurring->pluck('source_id')->all(), $recurring->pluck('destination_id')->all()));
    }

    /**
     * The accounts that have no transactions and that no recurring transaction uses, so they can go.
     */
    private function getEmptyAccounts(Collection $accounts): Collection
    {
        $recurring = $this->getRecurringAccountIds($accounts);

        return $accounts->filter(static fn (Account $account): bool => 0 === (int) $account->transactions_count && !in_array($account->id, $recurring, true));
    }
}
