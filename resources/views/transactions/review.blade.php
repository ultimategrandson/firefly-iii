@extends('layout.v3.session')
@section('content')
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="card mb-2">
                <div class="card-header">
                    <h3 class="card-title">{{ __('firefly.review_new') }}</h3>
                </div>
                <div class="card-body">
                    <p>{{ __('firefly.review_explain') }}</p>
                    <form method="GET" action="{{ route('transactions.review.index') }}" class="row g-2 align-items-center mb-2">
                        <div class="col-auto">
                            <label for="cutoff" class="col-form-label">{{ __('firefly.review_cutoff') }}</label>
                        </div>
                        <div class="col-auto">
                            <input type="datetime-local" id="cutoff" name="cutoff" class="form-control" value="{{ $cutoff->format('Y-m-d\TH:i') }}">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">{{ __('firefly.review_show') }}</button>
                        </div>
                    </form>
                    @if(null !== $lastReview)
                        <p class="text-muted mb-0">{{ __('firefly.review_last_reviewed', ['date' => \Carbon\Carbon::parse($lastReview, config('app.timezone'))->isoFormat((string) trans('config.date_time_js'))]) }}</p>
                    @endif
                </div>
                <div class="card-footer text-end">
                    <form method="POST" action="{{ route('transactions.review.mark') }}" class="d-inline">
                        <input name="_token" type="hidden" value="{{ csrf_token() }}">
                        <span class="text-muted small me-2">{{ __('firefly.review_mark_explain') }}</span>
                        <button type="submit" class="btn btn-outline-success"><span class="bi bi-check2-all"></span> {{ __('firefly.review_mark') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="card mb-2">
                <div class="card-header">
                    <h3 class="card-title">{{ __('firefly.review_accounts', ['count' => count($accounts)]) }}</h3>
                </div>
                @if(0 === count($accounts))
                    <div class="card-body">
                        <p class="mb-0">{{ __('firefly.review_no_accounts') }}</p>
                    </div>
                @else
                    <div class="card-body p-0">
                        @php($emptyIds = $empty->pluck('id')->all())
                        <table class="table table-valign-middle table-sm table-hover mb-0">
                            <thead>
                            <tr>
                                <th>{{ trans('list.name') }}</th>
                                <th>{{ trans('list.type') }}</th>
                                <th>{{ trans('list.created_at') }}</th>
                                <th class="text-end">{{ __('firefly.review_account_transactions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($accounts as $account)
                                <tr>
                                    <td><a href="{{ route('accounts.show', [$account->id]) }}">{{ $account->name }}</a></td>
                                    <td>{{ __('firefly.account_type_'.$account->accountType->type) }}</td>
                                    <td>{{ $account->created_at->isoFormat((string) trans('config.date_time_js')) }}</td>
                                    <td class="text-end">
                                        @if(0 === (int) $account->transactions_count)
                                            @if(in_array($account->id, $emptyIds, true))
                                                <span class="badge text-bg-warning">{{ __('firefly.review_account_empty') }}</span>
                                            @else
                                                <span class="badge text-bg-secondary">{{ __('firefly.review_account_recurring') }}</span>
                                            @endif
                                        @else
                                            {{ $account->transactions_count }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(count($empty) > 0)
                        <div class="card-footer text-end">
                            <form method="POST" action="{{ route('transactions.review.prune') }}" class="d-inline" id="prune-form">
                                <input name="_token" type="hidden" value="{{ csrf_token() }}">
                                <input name="cutoff" type="hidden" value="{{ $cutoff->format('Y-m-d\TH:i') }}">
                                <button type="submit" class="btn btn-outline-danger"><span class="bi bi-trash"></span> {{ trans_choice('firefly.review_prune', count($empty)) }}</button>
                            </form>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="card mb-2">
                <div class="card-header">
                    <h3 class="card-title">{{ __('firefly.review_transactions', ['count' => $journalCount]) }}</h3>
                </div>
                @if(0 === $journalCount)
                    <div class="card-body">
                        <p class="mb-0">{{ __('firefly.review_no_transactions') }}</p>
                    </div>
                @else
                    <div class="card-body p-0">
                        <x-lists.groups-large :groups="$groups" :account="null" :show-category="true" :show-budget="true" />
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
@section('scripts')
    @vite(['js/pages/generic-nodates.js'])
    <script nonce="{{ $JS_NONCE }}">
        var pruneForm = document.getElementById('prune-form');
        if (null !== pruneForm) {
            pruneForm.addEventListener('submit', function (e) {
                if (!confirm(@json(__('firefly.review_prune_confirm')))) {
                    e.preventDefault();
                }
            });
        }
    </script>
    <script src="v1/js/ff/list/groups.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
@endsection
