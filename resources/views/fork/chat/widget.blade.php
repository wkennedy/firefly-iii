{{-- FORK: the in-app chat assistant (FORK_CHAT), included from layout/v3/session.blade.php just before
     </body> through a one-line hook. Everything the script needs travels on data attributes — no inline
     JavaScript, so the page's strict CSP (script-src 'strict-dynamic' + nonce) needs no exception. The
     stylesheet is linked here too (a body <link rel="stylesheet"> is valid and keeps the hook to one line);
     the panel starts hidden, so there is nothing to flash before it loads.

     The posted date range is the one the page itself is showing (the Range middleware's session
     values), so "this month" in a question means the same thing it means on screen. --}}
@if(config('fork.chat'))
    <link href="fork/css/chat.css?v={{ $FF_BUILD_TIME }}" rel="stylesheet" type="text/css" nonce="{{ $JS_NONCE }}">
    <div id="fork-chat"
         class="fk-chat"
         data-dark="{{ 'dark' === ($darkMode ?? '') ? 'always' : ('browser' === ($darkMode ?? '') ? 'browser' : 'never') }}"
         data-stream-url="{{ route('fork.chat.stream') }}"
         data-send-url="{{ route('fork.chat.send') }}"
         data-apply-url="{{ route('fork.chat.apply') }}"
         data-stream="{{ config('fork.chat_stream') ? '1' : '0' }}"
         data-csrf="{{ csrf_token() }}"
         data-start="{{ session()->has('start') ? \Carbon\Carbon::parse(session('start'))->format('Y-m-d') : '' }}"
         data-end="{{ session()->has('end') ? \Carbon\Carbon::parse(session('end'))->format('Y-m-d') : '' }}">

        <button type="button" class="fk-chat__launcher" aria-expanded="false" aria-controls="fork-chat-panel"
                title="Ask about your finances">
            <span class="bi bi-chat-dots" aria-hidden="true"></span>
            <span class="visually-hidden">Ask about your finances</span>
        </button>

        <section id="fork-chat-panel" class="fk-chat__panel" role="dialog" aria-label="Ask about your finances" hidden>
            <header class="fk-chat__head">
                <span class="fk-chat__title">Ask about your finances</span>
                <span class="fk-chat__head-actions">
                    <button type="button" class="fk-chat__icon-button fk-chat__clear">Clear</button>
                    <button type="button" class="fk-chat__icon-button fk-chat__close" aria-label="Close">&times;</button>
                </span>
            </header>

            <div class="fk-chat__log" aria-live="polite">
                <div class="fk-chat__hint">
                    Ask about transactions, categories and dates. Every number comes from your own ledger.
                    <ul>
                        <li>How much did I spend on groceries last month?</li>
                        <li>What did I buy at Amazon in June?</li>
                        <li>Which categories did I spend the most on this year?</li>
                    </ul>
                </div>
            </div>

            <div class="fk-chat__status" hidden></div>

            <form class="fk-chat__form">
                <textarea class="fk-chat__input" rows="1" placeholder="Ask a question…" aria-label="Your question"></textarea>
                <button type="submit" class="fk-chat__send">Ask</button>
            </form>
        </section>
    </div>

    <script type="text/javascript" src="fork/js/chat.js?v={{ $FF_BUILD_TIME }}" nonce="{{ $JS_NONCE }}"></script>
@endif
