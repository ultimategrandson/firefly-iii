<?php

/**
 * DueDateController.php
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
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Http\Controllers\DateCalculation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Lists the due dates of transactions in a period, so charts can draw them as vertical lines.
 */
class DueDateController extends Controller
{
    use DateCalculation;

    private AccountRepositoryInterface $accountRepository;

    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            $this->accountRepository = app(AccountRepositoryInterface::class);

            return $next($request);
        });
    }

    /**
     * Due dates on the accounts shown on the dashboard.
     */
    public function frontpage(Carbon $start, Carbon $end): JsonResponse
    {
        $defaultSet = $this->accountRepository->getAccountsByType([AccountTypeEnum::DEFAULT->value, AccountTypeEnum::ASSET->value])->pluck('id')->toArray();
        $frontpage  = Preferences::get('frontpageAccounts', $defaultSet);
        $ids        = is_array($frontpage->data) && count($frontpage->data) > 0 ? $frontpage->data : $defaultSet;

        return response()->json($this->dueDates($this->accountRepository->getAccountsById($ids), $start, $end, false));
    }

    /**
     * Due dates on a single account, labelled to match the account's period chart.
     */
    public function account(Account $account, Carbon $start, Carbon $end): JsonResponse
    {
        return response()->json($this->dueDates(new Collection()->push($account), $start, $end, true));
    }

    /**
     * One entry per day with something due, or with $bucketed, per point of the chart.account.period
     * chart (a week or longer for long periods). "label" is the x-axis label of that day or point.
     */
    private function dueDates(Collection $accounts, Carbon $start, Carbon $end, bool $bucketed): array
    {
        $start    = $start->copy()->startOfDay();
        $end      = $end->copy()->endOfDay();
        if (0 === $accounts->count()) {
            return [];
        }

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $journals  = $collector->setAccounts($accounts)->setMetaDateRange($start, $end, 'due_date')->getExtractedJournals();

        $buckets  = $bucketed ? $this->bucketEnds($start, $end) : [];
        $format   = (string) trans('config.month_and_day_js', [], Steam::getLocale());
        $days     = [];
        foreach ($journals as $journal) {
            /** @var null|Carbon $due */
            $due = $journal['due_date'] ?? null;
            if (!$due instanceof Carbon) {
                continue;
            }
            $bucket = $this->bucketFor($buckets, $due);
            $key    = $bucket->format('Y-m-d');
            if (!array_key_exists($key, $days)) {
                $days[$key] = [
                    'date'  => $key,
                    'label' => $bucket->isoFormat($format),
                    'items' => [],
                ];
            }
            $days[$key]['items'][] = [
                'description' => $journal['description'],
                'amount'      => Amount::formatFlat($journal['currency_symbol'], (int) $journal['currency_decimal_places'], Steam::positive($journal['amount']), false),
                'url'         => route('transactions.show', [$journal['transaction_group_id']]),
            ];
        }
        ksort($days);

        foreach ($days as $key => $day) {
            $days[$key]['text'] = 1 === count($day['items'])
                ? (string) trans('firefly.due_date_line', ['description' => $day['items'][0]['description'], 'amount' => $day['items'][0]['amount']])
                : (string) trans('firefly.due_date_line_multiple', ['count' => count($day['items'])]);
        }

        return array_values($days);
    }

    /**
     * The end of each bucket in AccountController::period(), which labels each point by its bucket end.
     */
    private function bucketEnds(Carbon $start, Carbon $end): array
    {
        $step    = $this->calculateStep($start, $end);
        $current = Navigation::endOfX(clone $start, $step, null);
        $ends    = [];
        while ($end->gte($current)) {
            $ends[]  = clone $current;
            $current = Navigation::endOfX(Navigation::addPeriod($current, $step), $step, null);
        }

        return $ends;
    }

    /**
     * The first bucket ending on or after the date. The chart has no point past its last bucket, so
     * later dates go on the last one.
     */
    private function bucketFor(array $buckets, Carbon $date): Carbon
    {
        /** @var Carbon $bucket */
        foreach ($buckets as $bucket) {
            if ($bucket->gte($date)) {
                return $bucket;
            }
        }

        return [] === $buckets ? $date : end($buckets);
    }
}
