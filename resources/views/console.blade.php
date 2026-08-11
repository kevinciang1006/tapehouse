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
                    <button type="button" id="add-symbol-btn" class="btn btn--signal">Add symbol</button>
                </div>
            </div>
            <div class="tape-body">
                <table class="tape-table">
                    <tbody id="tape-rows"></tbody>
                </table>
            </div>
        </div>

        <div class="right-panel">
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

    </div>

</div>
@endsection
