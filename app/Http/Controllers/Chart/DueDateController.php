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
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Account;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Navigation;
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

    /**
     * Due dates on every account. Bills are often charged to a liability rather than to the asset
     * accounts on the dashboard chart, and still fall due.
     */
    public function frontpage(Carbon $start, Carbon $end): JsonResponse
    {
        return response()->json($this->dueDates(null, $start, $end, false));
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
     * Transactions on a liability are one item per liability, named after it, so a bill split into
     * charges and credits shows as what it adds to the debt: withdrawals less deposits. Others are one
     * item per transaction group, named by its title. Without $accounts, all accounts count.
     */
    private function dueDates(?Collection $accounts, Carbon $start, Carbon $end, bool $bucketed): array
    {
        $start     = $start->copy()->startOfDay();
        $end       = $end->copy()->endOfDay();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        if ($accounts instanceof Collection) {
            $collector->setAccounts($accounts);
        }
        $journals  = $collector->setMetaDateRange($start, $end, 'due_date')->withAccountInformation()->getExtractedJournals();
        $liability = config('firefly.valid_liabilities');

        $buckets   = $bucketed ? $this->bucketEnds($start, $end) : [];
        $format    = (string) trans('config.month_and_day_js', [], Steam::getLocale());
        $days      = [];
        foreach ($journals as $journal) {
            /** @var null|Carbon $due */
            $due     = $journal['due_date'] ?? null;
            if (!$due instanceof Carbon) {
                continue;
            }
            $bucket  = $this->bucketFor($buckets, $due);
            $key     = $bucket->format('Y-m-d');
            if (!array_key_exists($key, $days)) {
                $days[$key] = ['date' => $key, 'label' => $bucket->isoFormat($format), 'items' => []];
            }
            // the side of the journal that isn't the expense or revenue account.
            $own     = TransactionTypeEnum::WITHDRAWAL->value === $journal['transaction_type_type'] ? 'source' : 'destination';
            $itemKey = sprintf('group-%d', $journal['transaction_group_id']);
            $name    = '' !== (string) $journal['transaction_group_title'] ? $journal['transaction_group_title'] : $journal['description'];
            $url     = route('transactions.show', [$journal['transaction_group_id']]);
            if (in_array($journal[$own.'_account_type'], $liability, true)) {
                $itemKey = sprintf('account-%d', $journal[$own.'_account_id']);
                $name    = $journal[$own.'_account_name'];
                $url     = route('accounts.show', [$journal[$own.'_account_id']]);
            }
            if (!array_key_exists($itemKey, $days[$key]['items'])) {
                $days[$key]['items'][$itemKey] = [
                    'description'    => $name,
                    'amount'         => '0',
                    'currency_id'    => (int) $journal['currency_id'],
                    'currency'       => [$journal['currency_symbol'], (int) $journal['currency_decimal_places']],
                    'mixed_currency' => false,
                    'url'            => $url,
                ];
            }
            $item    = &$days[$key]['items'][$itemKey];
            $amount  = Steam::positive($journal['amount']);
            if ((int) $journal['currency_id'] !== $item['currency_id']) {
                $item['mixed_currency'] = true;
            }
            $item['amount'] = TransactionTypeEnum::DEPOSIT->value === $journal['transaction_type_type'] ? bcsub($item['amount'], $amount) : bcadd($item['amount'], $amount);
            unset($item);
        }
        ksort($days);

        foreach ($days as $key => $day) {
            $items = [];
            foreach ($day['items'] as $item) {
                $items[] = [
                    'description' => $item['description'],
                    'amount'      => $item['mixed_currency'] ? null : Amount::formatFlat($item['currency'][0], $item['currency'][1], $item['amount'], false),
                    'url'         => $item['url'],
                ];
            }
            $days[$key]['items'] = $items;
            if (1 === count($items)) {
                $days[$key]['text'] = null === $items[0]['amount']
                    ? (string) trans('firefly.due_date_line_no_amount', ['description' => $items[0]['description']])
                    : (string) trans('firefly.due_date_line', ['description' => $items[0]['description'], 'amount' => $items[0]['amount']]);
            }
            if (1 !== count($items)) {
                $days[$key]['text'] = (string) trans('firefly.due_date_line_multiple', ['count' => count($items)]);
            }
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
