{{-- RUNNING BALANCE --}}
@php
    // Decide which side's balance belongs to the account being viewed, and which of the
    // transaction's currencies that balance is kept in. A side with a foreign amount is held in
    // the foreign currency; without one, both sides share the transaction currency.
    $side      = null;
    $inForeign = false;
    $title     = '';

    if ('Deposit' === $type) {
        if ($source['id'] === $account?->id) {
            [$side, $title] = ['source', 'Deposit, source'];
        } else {
            // A deposit from a revenue or cash account is booked in the destination's currency.
            // From a liability it may be booked in the liability's, with the destination's
            // currency as the foreign amount (#12043, #12169) — but only if there is one; without
            // it the symbol was null and the balance was shown in the primary currency.
            $fromRevenue = in_array($source['type'], ['Revenue account', 'Cash account'], true);
            [$side, $title] = ['destination', $fromRevenue ? 'Deposit from revenue' : 'Deposit from liab'];
            $inForeign = !$fromRevenue && null !== $foreign['id'];
        }
    } elseif ('Withdrawal' === $type || 'Opening balance' === $type) {
        if ($account?->id == $source['id']) {
            [$side, $title] = ['source', $type . ', source'];
        } elseif ($account?->id == $destination['id']) {
            [$side, $title] = ['destination', $type . ', dest'];
        }
    } elseif ('Reconciliation' === $type) {
        if ($account?->id == $source['id']) {
            [$side, $title] = ['zero', 'Reconciliation, src'];
        } elseif ($account?->id == $destination['id']) {
            [$side, $title] = ['destination', 'Reconciliation, dest'];
        }
    } elseif ('Transfer' === $type) {
        if ($account?->id == $source['id']) {
            [$side, $title] = ['source', 'Transfer, source'];
        } else {
            $inForeign = null !== $foreign['id'];
            [$side, $title] = ['destination', $inForeign ? 'Transfer, dest, foreign currency' : 'Transfer, dest, normal currency'];
        }
    }

    $balance = match ($side) {
        'source'      => $source['balance_after'],
        'destination' => $destination['balance_after'],
        'zero'        => '0',
        default       => null,
    };

    $shown = $inForeign
        ? ['id' => $foreign['id'], 'symbol' => $foreign['symbol'], 'decimal_places' => $foreign['decimal_places']]
        : $currency;

    // The same balance in the primary currency, as the amount column shows it. Converted at this
    // transaction's own rate, so it is marked approximate.
    $pcBalance = null;
    if (null !== $balance && $convertToPrimary && null !== ($shown['id'] ?? null) && (int) $primaryCurrency->id !== (int) $shown['id']) {
        $base = $inForeign ? $amounts['foreign'] : $amounts['amount'];
        $pc   = $inForeign ? $amounts['pc_foreign'] : $amounts['pc_amount'];
        if (is_numeric($base) && is_numeric($pc) && 0 !== bccomp((string) $base, '0', 12)) {
            $rate      = bcdiv(\FireflyIII\Support\Facades\Steam::positive((string) $pc), \FireflyIII\Support\Facades\Steam::positive((string) $base), 12);
            $pcBalance = bcmul((string) $balance, $rate, 12);
        }
    }
@endphp
@if(false === $balanceDirty && '' !== $destination['balance_after'] && '' !== $source['balance_after'])
    @if(null !== $balance)
        <span title="{{ $title }}">{!! format_amount_by_symbol($balance, $shown['symbol'], $shown['decimal_places']) !!}</span>
        @if(null !== $pcBalance)
            (~ {!! format_amount_by_symbol($pcBalance, $primaryCurrency->symbol, $primaryCurrency->decimal_places) !!})
        @endif
    @elseif(in_array($type, ['Withdrawal', 'Opening balance', 'Reconciliation'], true))
        -
    @else
        &nbsp;
    @endif
@endif
