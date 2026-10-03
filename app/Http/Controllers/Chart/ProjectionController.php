<?php

/**
 * ProjectionController.php
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

namespace FireflyIII\Http\Controllers\Chart;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\RecurrenceRepetition;
use FireflyIII\Models\RecurrenceTransaction;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Recurring\RecurringRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Http\Api\ExchangeRateConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Projects the total of the dashboard's accounts forward from today: the money that recurring
 * transactions, subscriptions and debts with due dates are expected to move into or out of them.
 * The dashboard adds this to the sum of the account lines.
 */
class ProjectionController extends Controller
{
    private AccountRepositoryInterface   $accountRepository;
    private BillRepositoryInterface      $billRepository;
    private ExchangeRateConverter        $converter;
    private TransactionCurrency          $primary;
    private RecurringRepositoryInterface $recurringRepository;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            $this->accountRepository   = app(AccountRepositoryInterface::class);
            $this->billRepository      = app(BillRepositoryInterface::class);
            $this->recurringRepository = app(RecurringRepositoryInterface::class);
            $this->converter           = new ExchangeRateConverter();
            $this->primary             = Amount::getPrimaryCurrency();

            return $next($request);
        });
    }

    /**
     * "entries" holds, for every day of the period, how far the expected events up to and including
     * that day move the total, in the primary currency. "events" lists them per day.
     */
    public function frontpage(Carbon $start, Carbon $end): JsonResponse
    {
        $start    = $start->copy()->startOfDay();
        $end      = $end->copy()->endOfDay();
        $today    = today(config('app.timezone'));
        $accounts = $this->frontpageAccounts();
        $ids      = $accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $events   = [];
        if ($end->gte($today)) {
            $events = array_merge(
                $this->recurringEvents($ids, $today, $end),
                $this->subscriptionEvents($ids, $today, $end),
                $this->debtEvents($ids, $today, $end),
            );
        }
        usort($events, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $entries  = [];
        $perDay   = [];
        $sum      = '0';
        $index    = 0;
        $count    = count($events);
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key           = $day->format('Y-m-d');
            while ($index < $count && $events[$index]['date'] <= $key) {
                $sum                                 = bcadd($sum, $events[$index]['amount']);
                $perDay[$events[$index]['date']][] = [
                    'description' => $events[$index]['description'],
                    'amount'      => Amount::formatAnything($this->primary, $events[$index]['amount'], false),
                ];
                ++$index;
            }
            $entries[$key] = $sum;
        }

        return response()->json([
            'label'         => (string) trans('firefly.projected_total'),
            'currency_code' => $this->primary->code,
            'today'         => $today->format('Y-m-d'),
            'entries'       => $entries,
            'events'        => $perDay,
        ]);
    }

    /**
     * Occurrences of active recurring transactions from today on, for those not yet created today.
     * Only the part that crosses into or out of the dashboard accounts counts.
     */
    private function recurringEvents(array $ids, Carbon $today, Carbon $end): array
    {
        $events = [];

        /** @var Recurrence $recurrence */
        foreach ($this->recurringRepository->get() as $recurrence) {
            if (!$recurrence->active) {
                continue;
            }
            $firedToday = null !== $recurrence->latest_date && $recurrence->latest_date->isSameDay($today);
            $dates      = [];

            /** @var RecurrenceRepetition $repetition */
            foreach ($recurrence->recurrenceRepetitions as $repetition) {
                // like CreateRecurringTransactions, count from the first date so skips line up.
                $dates = array_merge($dates, $this->recurringRepository->getOccurrencesInRange($repetition, clone $recurrence->first_date, clone $end));
            }
            usort($dates, static fn (Carbon $a, Carbon $b): int => $a <=> $b);
            if ($recurrence->repetitions > 0) {
                $dates = array_slice($dates, 0, $recurrence->repetitions);
            }

            /** @var Carbon $date */
            foreach ($dates as $date) {
                if ($date->lt($today) || $date->lt($recurrence->first_date) || ($firedToday && $date->isSameDay($today))) {
                    continue;
                }
                if (null !== $recurrence->repeat_until && $date->gt($recurrence->repeat_until)) {
                    continue;
                }

                /** @var RecurrenceTransaction $transaction */
                foreach ($recurrence->recurrenceTransactions as $transaction) {
                    $in  = in_array((int) $transaction->destination_id, $ids, true);
                    $out = in_array((int) $transaction->source_id, $ids, true);
                    if ($in === $out) {
                        continue;
                    }
                    $amount   = $this->toPrimary($transaction->transactionCurrency, $date, Steam::positive($transaction->amount));
                    $events[] = [
                        'date'        => $date->format('Y-m-d'),
                        'description' => $recurrence->title,
                        'amount'      => $in ? $amount : Steam::negative($amount),
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * Expected dates of active subscriptions after today that have no payment linked around them,
     * at the middle of their amount range. Subscriptions charged to a liability are left to
     * debtEvents(), and those last paid from an account not on the dashboard are left out.
     */
    private function subscriptionEvents(array $ids, Carbon $today, Carbon $end): array
    {
        $events = [];
        $from   = $today->copy()->addDay();

        /** @var Bill $bill */
        foreach ($this->billRepository->getActiveBills() as $bill) {
            $last = TransactionJournal::where('bill_id', $bill->id)->orderBy('date', 'desc')->first();
            if (null !== $last) {
                $source = $this->sourceAccount($last);
                if (null === $source || in_array($source->accountType->type, config('firefly.valid_liabilities'), true) || !in_array((int) $source->id, $ids, true)) {
                    continue;
                }
            }
            $amount = bcdiv(bcadd((string) $bill->amount_min, (string) $bill->amount_max), '2');

            /** @var Carbon $date */
            foreach ($this->billRepository->getPayDatesInRange($bill, $from, $end) as $date) {
                if (null !== $bill->end_date && $date->gt($bill->end_date)) {
                    continue;
                }
                // paid if something is linked within half a period either side of the date.
                $previous = Navigation::subtractPeriod(clone $date, $bill->repeat_freq, $bill->skip + 1);
                $half     = (int) floor($previous->diffInDays($date, true) / 2);
                $paid     = $this->billRepository->getPaidDatesInRange($bill, $date->copy()->subDays($half)->startOfDay(), $date->copy()->addDays($half)->endOfDay());
                if ($paid->count() > 0) {
                    continue;
                }
                $events[] = [
                    'date'        => $date->format('Y-m-d'),
                    'description' => $bill->name,
                    'amount'      => Steam::negative($this->toPrimary($bill->transactionCurrency, $date, $amount)),
                ];
            }
        }

        return $events;
    }

    /**
     * What is still owed on each liability, set against its charges with due dates, newest first,
     * and expected to be paid on those due dates (today, when overdue). Liabilities last paid from
     * an account not on the dashboard are left out.
     */
    private function debtEvents(array $ids, Carbon $today, Carbon $end): array
    {
        $liabilities = config('firefly.valid_liabilities');

        /** @var GroupCollectorInterface $collector */
        $collector   = app(GroupCollectorInterface::class);
        $journals    = $collector->withMetaDate('due_date')->withAccountInformation()->getExtractedJournals();

        // due-dated charges per liability: what they add to the debt, per due date.
        $charges     = [];
        $dueDated    = [];
        foreach ($journals as $journal) {
            if (!($journal['due_date'] ?? null) instanceof Carbon) {
                continue;
            }
            $dueDated[] = (int) $journal['transaction_journal_id'];
            $own        = TransactionTypeEnum::WITHDRAWAL->value === $journal['transaction_type_type'] ? 'source' : 'destination';
            if (!in_array($journal[$own.'_account_type'], $liabilities, true)) {
                continue;
            }
            $accountId                    = (int) $journal[$own.'_account_id'];
            $date                         = $journal['due_date']->format('Y-m-d');
            $amount                       = Steam::positive($journal['amount']);
            $charges[$accountId][$date] ??= '0';
            $charges[$accountId][$date]   = 'source' === $own ? bcadd($charges[$accountId][$date], $amount) : bcsub($charges[$accountId][$date], $amount);
        }

        $events      = [];
        foreach ($charges as $accountId => $perDate) {
            $account = $this->accountRepository->find($accountId);
            if (!$account instanceof Account) {
                continue;
            }
            $payer   = $this->lastPayer($account, $dueDated);
            if ($payer instanceof Account && !in_array((int) $payer->id, $ids, true)) {
                continue;
            }
            $currency = $this->accountRepository->getAccountCurrency($account) ?? $this->primary;
            // a debt's balance is negative while something is owed on it.
            $balance  = (string) Transaction::where('account_id', $account->id)
                ->whereHas('transactionJournal', static fn ($query) => $query->whereNull('deleted_at'))
                ->sum('amount');
            $owed     = bcmul($balance, '-1');
            if (1 !== bccomp($owed, '0')) {
                continue;
            }
            krsort($perDate);
            foreach ($perDate as $date => $charged) {
                if (1 !== bccomp($owed, '0')) {
                    break;
                }
                if (1 !== bccomp($charged, '0')) {
                    continue;
                }
                $part = -1 === bccomp($charged, $owed) ? $charged : $owed;
                $owed = bcsub($owed, $part);
                $due  = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
                $when = $due->lt($today) ? $today : $due;
                if ($when->gt($end)) {
                    continue;
                }
                $events[] = [
                    'date'        => $when->format('Y-m-d'),
                    'description' => $due->lt($today) ? (string) trans('firefly.projected_overdue', ['name' => $account->name]) : $account->name,
                    'amount'      => Steam::negative($this->toPrimary($currency, $when, $part)),
                ];
            }
        }

        return $events;
    }

    /**
     * The account the most recent payment into the liability came from: the newest transaction into it
     * that is not itself a due-dated charge or credit.
     */
    private function lastPayer(Account $liability, array $dueDated): ?Account
    {
        /** @var null|Transaction $transaction */
        $transaction = Transaction::where('transactions.account_id', $liability->id)
            ->where('transactions.amount', '>', 0)
            ->whereNotIn('transactions.transaction_journal_id', $dueDated)
            ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->whereNull('transaction_journals.deleted_at')
            ->orderBy('transaction_journals.date', 'desc')
            ->first(['transactions.*']);

        return null === $transaction ? null : $this->sourceAccount($transaction->transactionJournal);
    }

    private function sourceAccount(TransactionJournal $journal): ?Account
    {
        return $journal->transactions()->where('amount', '<', 0)->first()?->account;
    }

    private function toPrimary(?TransactionCurrency $currency, Carbon $date, string $amount): string
    {
        if (null === $currency || $currency->id === $this->primary->id) {
            return $amount;
        }

        return $this->converter->convert($currency, $this->primary, $date, $amount);
    }

    /**
     * The accounts on the dashboard's accounts chart, as the chart's API picks them.
     */
    private function frontpageAccounts(): Collection
    {
        $defaultSet = $this->accountRepository->getAccountsByType([AccountTypeEnum::DEFAULT->value, AccountTypeEnum::ASSET->value])->pluck('id')->toArray();
        $frontpage  = Preferences::get('frontpageAccounts', $defaultSet);
        $ids        = is_array($frontpage->data) && count($frontpage->data) > 0 ? $frontpage->data : $defaultSet;

        return $this->accountRepository->getAccountsById($ids);
    }
}
