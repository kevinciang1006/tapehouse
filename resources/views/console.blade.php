@extends('layouts.app')

@section('title', 'Tapehouse — Console')

@section('body')
<div class="shell">

    <div class="statusbar">
        <div class="wordmark">TAPEHOUSE</div>

        <div class="statusbar__driver">
            <div id="driver-dot" class="driver-dot"></div>
            <div id="driver-text" class="num"></div>
        </div>

        <div class="statusbar__credits">
            <div class="label">credits</div>
            <div id="credit-bars" class="credit-bars"></div>
            <div id="credit-text" class="num"></div>
        </div>

        <div class="statusbar__right">
            <div class="statusbar__lag">
                <div class="label">lag</div>
                <div id="lag-text" class="num"></div>
            </div>
            <button type="button" id="stop-feed-btn" class="btn btn--ghost">Stop feed</button>
        </div>
    </div>

    <div id="degraded-banner" class="banner--degraded" hidden>
        Credit budget spent. Polling rotates through symbols until the next refill.
    </div>

    <div class="body-row">

        <nav class="rail">
            <button type="button" class="rail-item is-active" data-panel="tape">
                <span class="rail-item__icon rail-item__icon--square"></span>
                <span class="rail-item__label">tape</span>
            </button>
            <button type="button" class="rail-item" data-panel="ops">
                <span class="rail-item__icon rail-item__icon--circle"></span>
                <span class="rail-item__label">ops</span>
            </button>
            <button type="button" class="rail-item" data-panel="alrt">
                <span class="rail-item__icon rail-item__icon--diamond"></span>
                <span class="rail-item__label">alrt</span>
            </button>
        </nav>

        <div class="tape-panel">
            <div class="panel-header">
                <div class="label">The tape</div>
                <div class="panel-header__actions">
                    <div id="symbol-count" class="num"></div>
                    <div class="symbol-search" id="symbol-search">
                        <button type="button" id="add-symbol-btn" class="btn btn--signal">Add symbol</button>
                        <div id="symbol-search-panel" class="symbol-search__panel" hidden>
                            <input type="text" id="symbol-search-input" class="form-input" placeholder="Search ticker or name" autocomplete="off">
                            <div id="symbol-search-results" class="symbol-search__results"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tape-body">
                <div id="tape-rows"></div>
                <div id="tape-empty" class="tape-empty" hidden>No symbols yet. Add one to start the tape.</div>
            </div>
        </div>

        <div class="right-panel">

            <div id="ops-view" class="right-panel__view">
                <div class="panel-header">
                    <div class="label">Ops</div>
                </div>
                <div class="ops-stats">
                    <div class="ops-stats__row">
                        <div class="ops-stats__label">driver</div>
                        <div id="ops-driver" class="num"></div>
                    </div>
                    <div class="ops-stats__row">
                        <div class="ops-stats__label">credits</div>
                        <div id="ops-credits" class="num"></div>
                    </div>
                    <div class="ops-stats__row">
                        <div class="ops-stats__label">lag p50 / p95</div>
                        <div id="ops-lag" class="num"></div>
                    </div>
                    <div class="ops-stats__row">
                        <div class="ops-stats__label">reconnects</div>
                        <div id="ops-reconnects" class="num"></div>
                    </div>
                    <div class="ops-stats__row">
                        <div class="ops-stats__label">queue depth</div>
                        <div id="ops-queue-depth" class="num"></div>
                    </div>
                </div>
                <div class="panel-header">
                    <div class="label">Event log</div>
                </div>
                <div class="event-log-body">
                    <ul id="event-log"></ul>
                </div>
            </div>

            <div id="alerts-view" class="right-panel__view" hidden>
                <div class="panel-header">
                    <div class="label">Alert rules</div>
                    <button type="button" id="create-rule-btn" class="btn btn--signal">Create rule</button>
                </div>
                <form id="rule-form" class="rule-form" hidden>
                    <div class="rule-form__row">
                        <label for="rule-symbol-input">Symbol</label>
                        <input type="text" id="rule-symbol-input" class="form-input" placeholder="Search ticker or name" autocomplete="off">
                        <div id="rule-symbol-results" class="symbol-search__results"></div>
                    </div>
                    <div class="rule-form__row rule-form__row--split">
                        <div>
                            <label for="rule-metric">Metric</label>
                            <select id="rule-metric" class="form-input">
                                <option value="price">price</option>
                                <option value="change_pct">change%</option>
                            </select>
                        </div>
                        <div>
                            <label for="rule-condition">Condition</label>
                            <select id="rule-condition" class="form-input">
                                <option value="above">above</option>
                                <option value="below">below</option>
                            </select>
                        </div>
                    </div>
                    <div class="rule-form__row">
                        <label for="rule-threshold">Threshold</label>
                        <input type="number" id="rule-threshold" class="form-input" step="any" inputmode="decimal">
                    </div>
                    <div id="rule-form-error" class="field-error" hidden></div>
                    <div class="rule-form__actions">
                        <button type="submit" id="rule-form-submit" class="btn btn--signal">Create rule</button>
                        <button type="button" id="rule-form-cancel" class="btn btn--ghost">Cancel</button>
                    </div>
                </form>
                <div id="alert-rules" class="alert-rules"></div>
                <div id="alert-rules-empty" class="rules-empty" hidden>No rules yet. Create one to watch a threshold.</div>
                <div class="panel-header">
                    <div class="label">Fired</div>
                </div>
                <div class="event-log-body">
                    <ul id="alert-fired-log"></ul>
                    <div id="alert-fired-empty" class="rules-empty" hidden>Nothing has fired.</div>
                </div>
            </div>

        </div>

    </div>

</div>
@endsection
