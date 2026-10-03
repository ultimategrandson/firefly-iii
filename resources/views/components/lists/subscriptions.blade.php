@foreach($bills as $objectGroupOrder => $objectGroup)
    @if(count($objectGroup['bills']) > 0)
    <div class="row">
        <div class="col-lg-12 col-sm-12 col-md-12">
            <div class="card mb-2">
                <x-elements.card-header-with-menu :cardTitle="$objectGroup['object_group_title']"
                                                  :route="route('subscriptions.create')"
                                                  :linkTitle="__('firefly.create_new_bill')"/>
                <div class="card-body p-0">
                    <table class="table table-valign-middle table-hover table-sm" id="bill-sortable">
                        <thead>
                        <tr>
                            <th class="w-5">&nbsp;</th>
                            <th class="w-25">{{ __('list.name') }}</th>
                            <th class="w-20 d-xl-table-cell d-none"  {{-- hide on LG and smaller --}}>{{ __('list.linked_to_rules') }}</th>
                            <th class="text-end text-nowrap">{{ trans('list.matchingAmount') }}</th>
                            <th class="w-15">{{ trans('list.paid_current_period') }}</th>
                            <th class="w-15">{{ trans('list.next_expected_match') }}</th>
                            <th class="d-xl-table-cell d-none">{{ trans('list.repeat_freq') }}</th>
                            <th>&nbsp;</th>
                        </tr>
                        </thead>
                        @if(count($objectGroup['bills']) > 0)
                            <tbody class="bill-connected-list" data-title="{{ $objectGroup['object_group_title'] }}">
                            @foreach($objectGroup['bills'] as $entry)
                                <tr class="bill-sortable" data-id="{{ $entry['id'] }}" data-name="{{ $entry['name'] }}"
                                    data-order="{{ $entry['order'] }}" data-position="{{ $loop->index }}">
                                    <td>
                                        <a class="btn btn-sm btn-outline-secondary bi bi-list bill-handle"></a>
                                    </td>
                                    <td>
                                        @if(!$entry['active'])
                                            <span class="bi bi-ban"></span>
                                        @endif
                                        <a href="{{ route('subscriptions.show',$entry['id']) }}"
                                           title="{{ $entry['name'] }}">{{ $entry['name'] }}</a>
                                        {{-- count attachments --}}
                                        @if(count($entry['attachments']) > 0)
                                            <span class="bi bi-paperclip"></span>
                                        @endif
                                            @if(null !== $entry['end_date'])
                                                <br>
                                                @if(carbonize($entry['end_date'])->lte($today))
                                                    <span
                                                        class="text-danger">{{ trans('firefly.bill_end_index_line', ['date' => carbonize($entry['end_date'])->isoFormat($monthAndDayFormat)])  }}</span>
                                                @else
                                                    {{ trans('firefly.bill_end_index_line', ['date' => carbonize($entry['end_date'])->isoFormat($monthAndDayFormat)])  }}
                                                @endif
                                            @endif
                                            @if($entry['extension_date'])
                                                <br>
                                                @if(carbonize($entry['extension_date'])->lte($today))
                                                    <span
                                                        class="text-danger">{{ trans('firefly.bill_extension_index_line', ['date' => carbonize($entry['extension_date'])->isoFormat($monthAndDayFormat)])  }}</span>
                                                @else
                                                    {{ trans('firefly.bill_extension_index_line', ['date' => carbonize($entry['extension_date'])->isoFormat($monthAndDayFormat)])  }}
                                                @endif
                                            @endif

                                    </td>
                                    <td class="rules d-xl-table-cell d-none"  {{-- hide on LG and smaller --}}>
                                        @if(count($entry['rules']) > 0)
                                            <ul class="list-unstyled">
                                                @foreach($entry['rules'] as $rule)
                                                    <li>
                                                        <a href="{{ route('rules.edit', [$rule['id']]) }}?_from={{ urlencode($FF3_FROM) }}">
                                                            {{ $rule['title'] }}
                                                        </a>
                                                        @if(!$rule['active'])
                                                            ({{ strtolower(__('firefly.list_inactive_rule')) }})
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                @php
                    // "~" marks the average of a range; a fixed amount is shown as it is. The
                    // primary-currency figure only adds something when the subscription is in
                    // another currency.
                    $isRange   = 0 !== bccomp((string) $entry['amount_min'], (string) $entry['amount_max']);
                    $average   = bcdiv(bcadd((string) $entry['amount_min'], (string) $entry['amount_max']), '2');
                    $pcAverage = null;
                    if ((int) $entry['currency_id'] !== (int) $primaryCurrency->id && null !== $entry['pc_amount_max'] && '0' !== $entry['pc_amount_max']) {
                        $pcAverage = bcdiv(bcadd((string) $entry['pc_amount_min'], (string) $entry['pc_amount_max']), '2');
                    }
                @endphp
                <span class="mr-2"
                      @if($isRange) title="{{ e(format_amount_by_symbol($entry['amount_min'], $entry['currency_symbol'], $entry['currency_decimal_places'], false)) }} -- {{ e(format_amount_by_symbol($entry['amount_max'], $entry['currency_symbol'], $entry['currency_decimal_places'], false)) }}" @endif>
                    @if($isRange)~ @endif{!! format_amount_by_symbol($average, $entry['currency_symbol'], $entry['currency_decimal_places'])  !!}
                    @if(null !== $pcAverage)
                        (~ {!! format_amount_by_symbol($pcAverage, $primaryCurrency->symbol, $primaryCurrency->decimal_places) !!})
                    @endif
                </span>
                                    </td>

                                    {{--
                                    paidDates = 0 (bill not paid in period)
                                    pay_dates  = 0 (bill not expected to be paid in this period)
                                    bill is active.
                                    --}}
                                    @if(0 === count($entry['paid_dates']) && 0 === count($entry['pay_dates']) && $entry['active'])
                                        <td class="paid_in_period text-muted">
                                            {{ trans('firefly.not_expected_period') }}
                                        </td>
                                        <td class="expected_in_period">
                                            @if($entry['next_expected_match'])
                                                {{ carbonize($entry['next_expected_match'])->isoFormat($monthAndDayFormat) }}
                                            @endif
                                        </td>
                                    @endif

                                    {{--
                                    paid_dates = 0 (bill not paid in period)
                                    pay_dates  > 0 (bill IS expected to be paid in this period)
                                    bill is active
                                    first pay date is in the past.
                                    --}}
                                    @if(0 === count($entry['paid_dates']) && count($entry['pay_dates']) > 0 && $entry['active'])
                                        @if($entry['next_expected_match_diff'] === __('firefly.not_expected_period'))
                                            {{-- terrible code, you should sue me for this. --}}
                                            <td class="paid_in_period text-muted">
                                                {{ $entry['next_expected_match_diff'] }}
                                            </td>
                                        @else
                                            <td class="paid_in_period text-warning">
                                                {{ $entry['next_expected_match_diff'] }}
                                                <!-- {{ __('firefly.bill_expected_date', ['date' => $entry['next_expected_match_diff']]) }} -->
                                            </td>
                                        @endif
                                        <td class="expected_in_period">
                                            @foreach($entry['pay_dates'] as $date)
                                                {{ carbonize($date)->isoFormat($monthAndDayFormat) }}<br>
                                            @endforeach

                                        </td>
                                    @endif

                                    {{--
                                    paid_dates >= 0 (bill is paid X times).
                                    Don't care about pay_dates.
                                    --}}

                                    @if(count($entry['paid_dates']) > 0 && $entry['active'])
                                        <td class="paid_in_period text-success">
                                            @foreach($entry['paid_dates'] as $currentPaid)
                                                <a href="{{ route('transactions.show', $currentPaid['transaction_group_id']) }}">{{ carbonize($currentPaid['date'])->isoFormat($monthAndDayFormat) }}</a>
                                                <br/>
                                            @endforeach
                                        </td>
                                        <td class="expected_in_period">
                                            <!-- not just show next expected match, loop all pay_dates. -->
                                            @if($entry['next_expected_match'] && 1 === count($entry['pay_dates']))
                                                {{ carbonize($entry['next_expected_match'])->isoFormat($monthAndDayFormat) }}
                                            @elseif($entry['next_expected_match'] && count($entry['pay_dates']) > 0)
                                                @foreach($entry['pay_dates'] as $date)
                                                    {{ carbonize($date)->isoFormat($monthAndDayFormat) }}<br>
                                                @endforeach
                                            @else
                                                <span class="text-muted">{{ $entry['next_expected_match_diff'] }}</span>
                                            @endif
                                        </td>
                                    @endif
                                    {{-- bill is not active --}}
                                    @if(!$entry['active'])
                                        <td class="paid_in_period text-muted">
                                            ~
                                        </td>
                                        <td class="expected_in_period text-muted">~</td>
                                    @endif
                                    <td class="d-xl-table-cell d-none">
                                        {{ __('firefly.repeat_freq_' . $entry['repeat_freq']) }}
                                        @if($entry['skip'] > 0)
                                            {{ __('firefly.skips_over') }} {{ $entry['skip'] }}
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                {{ __('firefly.actions') }}
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="{{ route('subscriptions.edit',$entry['id']) }}?_from={{ urlencode($FF3_FROM) }}"><span class="bi bi-pencil"></span> {{ __('firefly.edit') }}</a></li>
                                                <li><a class="dropdown-item text-danger" href="{{ route('subscriptions.delete',$entry['id']) }}?_from={{ urlencode($FF3_FROM) }}"><span class="bi bi-trash"></span> {{ __('firefly.delete') }}</a></li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            @if(array_key_exists($objectGroupOrder, $sums))
                                {{-- name the currency of a sum only when there is more than one --}}
                                @php $manyCurrencies = count($sums[$objectGroupOrder]) > 1; @endphp
                                @foreach($sums[$objectGroupOrder] as $sum)
                                    @if('0' !== $sum['avg'])
                                        <tr {{-- hide on MD and smaller --}} class="d-lg-table-row d-none">
                                            <td class="d-xl-table-cell d-none">&nbsp;</td> <!-- handle -->
                                            <td colspan="2" class="text-end"> <!-- title -->
                                                <small>{{ __('firefly.sum') }} @if($manyCurrencies)({{ $sum['currency_code'] }})@endif
                                                    ({{ __('firefly.active_exp_bills_only') }})</small>
                                            </td>
                                            <td class="text-end text-nowrap"> <!-- amount -->
                                                {!! format_amount_by_symbol($sum['avg'], $sum['currency_symbol'], $sum['currency_decimal_places']) !!}
                                            </td>
                                            <td colspan="4">&nbsp;</td> <!-- handle -->
                                        </tr>
                                    @endif
                                    @if('0' !== $sum['total_left_to_pay'])
                                        <tr {{-- hide on MD and smaller --}} class="d-lg-table-row d-none">
                                            <td class="d-xl-table-cell d-none">&nbsp;</td> <!-- handle -->
                                            <td colspan="2" class="text-end"> <!-- title -->
                                                <small>{{ __('firefly.sum') }} @if($manyCurrencies)({{ $sum['currency_code'] }})@endif
                                                    ({{ __('firefly.left_to_pay_active_bills') }})</small>
                                            </td>
                                            <td class="text-end text-nowrap"> <!-- amount -->
                                                {!! format_amount_by_symbol($sum['total_left_to_pay'], $sum['currency_symbol'], $sum['currency_decimal_places']) !!}
                                            </td>
                                            <td colspan="4">&nbsp;</td> <!-- handle -->
                                        </tr>
                                    @endif
                                    @if('0' !== $sum['per_period'])
                                        <tr {{-- hide on MD and smaller --}} class="d-lg-table-row d-none">
                                            <td class="d-xl-table-cell d-none">&nbsp;</td> <!-- handle -->
                                            <td colspan="2" class="text-end"> <!-- title -->
                                                <small>{{ __('firefly.per_period_sum_' . $sum['period']) }}
                                                    @if($manyCurrencies)({{ $sum['currency_code'] }})@endif
                                                    ({{ __('firefly.active_bills_only') }})</small>
                                            </td>
                                            <td class="text-end text-nowrap"> <!-- amount -->
                                                {!!  format_amount_by_symbol($sum['per_period'], $sum['currency_symbol'], $sum['currency_decimal_places']) !!}
                                            </td>
                                            <td colspan="4">&nbsp;</td> <!-- handle -->
                                        </tr>
                                    @endif
                                @endforeach
                            @endif
                            </tbody>
                        @endif

                    </table>
                </div>
                <x-elements.card-footer-with-menu :route="route('subscriptions.create')"
                                                  :linkTitle="__('firefly.create_new_bill')"/>
            </div>
        </div>
    </div>
@endif
    @endforeach

@if(count($totals) > 0)

    <h5 class="m-2">{{ __('firefly.totals') }}</h5>

    <table class="table table-bordered table-sm table-hover mb-2">
        <tbody>
        @php $manyCurrencies = count($totals) > 1; @endphp
        @foreach($totals as $sum)
            @if('0' !== $sum['avg'])
                <tr>
                    <td class="text-end"> <!-- title -->
                        <small>{{ __('firefly.sum') }} @if($manyCurrencies)({{ $sum['currency_code'] }})@endif
                            ({{ __('firefly.active_exp_bills_only_total') }})</small>
                    </td>
                    <td class="text-end text-nowrap"> <!-- amount -->
                        {!! format_amount_by_symbol($sum['avg'], $sum['currency_symbol'], $sum['currency_decimal_places']) !!}
                    </td>
                </tr>
            @endif
            @if('0' !== $sum['per_period'])
                <tr>
                    <td class="text-end"> <!-- title -->
                        <small>{{ __('firefly.per_period_sum_' . $sum['period']) }} @if($manyCurrencies)({{ $sum['currency_code'] }})@endif
                            ({{ __('firefly.active_bills_only_total') }})</small>
                    </td>
                    <td class="text-end text-nowrap"> <!-- amount -->
                        {!! format_amount_by_symbol($sum['per_period'], $sum['currency_symbol'], $sum['currency_decimal_places']) !!}
                    </td>
                </tr>
            @endif
        @endforeach
        </tbody>

    </table>
@endif
