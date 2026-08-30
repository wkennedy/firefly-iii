/*
 * chat.js
 * Copyright (c) 2026 the fork authors.
 *
 * This file is part of a fork of Firefly III (https://github.com/firefly-iii).
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
 * along with this program.  If not, see https://www.gnu.org/licenses/.
 */

/*
 * FORK: the in-app chat widget (FORK_CHAT). No dependencies — jQuery and AdminLTE are on the page
 * but nothing here needs them, and staying independent means an upstream asset change cannot break
 * it. Everything the page needs is on the root element's data attributes.
 *
 * The one security-relevant rule in this file: model output is NEVER assigned to innerHTML. Answers
 * are built as DOM nodes from a small markdown subset, so a shop name that happens to contain HTML
 * is text, not markup.
 */
(function () {
    'use strict';

    var root = document.getElementById('fork-chat');
    if (null === root) {
        return;
    }

    var config = {
        streamUrl: root.getAttribute('data-stream-url'),
        sendUrl: root.getAttribute('data-send-url'),
        applyUrl: root.getAttribute('data-apply-url'),
        streaming: '1' === root.getAttribute('data-stream'),
        csrf: root.getAttribute('data-csrf'),
        start: root.getAttribute('data-start') || '',
        end: root.getAttribute('data-end') || ''
    };

    var launcher = root.querySelector('.fk-chat__launcher');
    var panel = root.querySelector('.fk-chat__panel');
    var log = root.querySelector('.fk-chat__log');
    var form = root.querySelector('.fk-chat__form');
    var input = root.querySelector('.fk-chat__input');
    var send = root.querySelector('.fk-chat__send');
    var status = root.querySelector('.fk-chat__status');
    var hint = root.querySelector('.fk-chat__hint');
    var clear = root.querySelector('.fk-chat__clear');
    var close = root.querySelector('.fk-chat__close');

    var STORAGE_KEY = 'fork-chat-transcript';
    var history = [];
    var busy = false;

    /* ---------------------------------------------------------------- markdown */

    var INLINE = /(`[^`]+`|\*\*[^*]+\*\*|__[^_]+__|\*[^*\n]+\*|_[^_\n]+_)/g;

    function inline(target, text) {
        var last = 0;
        var match;
        INLINE.lastIndex = 0;
        while (null !== (match = INLINE.exec(text))) {
            if (match.index > last) {
                target.appendChild(document.createTextNode(text.slice(last, match.index)));
            }
            var token = match[0];
            var node;
            if ('`' === token.charAt(0)) {
                node = document.createElement('code');
                node.textContent = token.slice(1, -1);
            } else if (0 === token.indexOf('**') || 0 === token.indexOf('__')) {
                node = document.createElement('strong');
                node.textContent = token.slice(2, -2);
            } else {
                node = document.createElement('em');
                node.textContent = token.slice(1, -1);
            }
            target.appendChild(node);
            last = match.index + token.length;
        }
        if (last < text.length) {
            target.appendChild(document.createTextNode(text.slice(last)));
        }
    }

    function cells(line) {
        var trimmed = line.trim().replace(/^\|/, '').replace(/\|$/, '');
        return trimmed.split('|').map(function (cell) {
            return cell.trim();
        });
    }

    function isDivider(line) {
        return undefined !== line && /^\s*\|?[\s:|-]+\|[\s:|-]*$/.test(line) && -1 !== line.indexOf('-');
    }

    function table(lines, index, parent) {
        var header = cells(lines[index]);
        var wrap = document.createElement('div');
        var element = document.createElement('table');
        var head = document.createElement('thead');
        var headRow = document.createElement('tr');
        var body = document.createElement('tbody');
        wrap.className = 'fk-chat__table-wrap';
        header.forEach(function (text) {
            var cell = document.createElement('th');
            inline(cell, text);
            headRow.appendChild(cell);
        });
        head.appendChild(headRow);
        element.appendChild(head);

        var cursor = index + 2;
        while (cursor < lines.length && -1 !== lines[cursor].indexOf('|')) {
            var row = document.createElement('tr');
            cells(lines[cursor]).forEach(function (text) {
                var cell = document.createElement('td');
                inline(cell, text);
                row.appendChild(cell);
            });
            body.appendChild(row);
            cursor += 1;
        }
        element.appendChild(body);
        wrap.appendChild(element);
        parent.appendChild(wrap);

        return cursor;
    }

    function list(lines, index, parent, ordered) {
        var pattern = ordered ? /^\s*\d+[.)]\s+/ : /^\s*[-*+]\s+/;
        var element = document.createElement(ordered ? 'ol' : 'ul');
        var cursor = index;
        while (cursor < lines.length && pattern.test(lines[cursor])) {
            var item = document.createElement('li');
            inline(item, lines[cursor].replace(pattern, ''));
            element.appendChild(item);
            cursor += 1;
        }
        parent.appendChild(element);

        return cursor;
    }

    function fence(lines, index, parent) {
        var pre = document.createElement('pre');
        var code = document.createElement('code');
        var collected = [];
        var cursor = index + 1;
        while (cursor < lines.length && !/^```/.test(lines[cursor])) {
            collected.push(lines[cursor]);
            cursor += 1;
        }
        code.textContent = collected.join('\n');
        pre.appendChild(code);
        parent.appendChild(pre);

        return cursor + 1;
    }

    /**
     * Render the markdown the model actually produces — headings, bold, lists, tables, code — as
     * DOM nodes. Anything unrecognised stays literal text, which is the safe direction to fail in.
     */
    function markdown(text) {
        var fragment = document.createDocumentFragment();
        var lines = String(text).split('\n');
        var index = 0;

        while (index < lines.length) {
            var line = lines[index];
            if (/^```/.test(line)) {
                index = fence(lines, index, fragment);
            } else if (/^\s*$/.test(line)) {
                index += 1;
            } else if (/^#{1,6}\s+/.test(line)) {
                var heading = document.createElement('h3');
                inline(heading, line.replace(/^#{1,6}\s+/, ''));
                fragment.appendChild(heading);
                index += 1;
            } else if (-1 !== line.indexOf('|') && isDivider(lines[index + 1])) {
                index = table(lines, index, fragment);
            } else if (/^\s*[-*+]\s+/.test(line)) {
                index = list(lines, index, fragment, false);
            } else if (/^\s*\d+[.)]\s+/.test(line)) {
                index = list(lines, index, fragment, true);
            } else {
                var collected = [];
                while (index < lines.length && !/^\s*$/.test(lines[index]) && !/^```/.test(lines[index])
                    && !/^#{1,6}\s+/.test(lines[index]) && !/^\s*[-*+]\s+/.test(lines[index])
                    && !/^\s*\d+[.)]\s+/.test(lines[index])) {
                    collected.push(lines[index]);
                    index += 1;
                }
                var paragraph = document.createElement('p');
                inline(paragraph, collected.join(' '));
                fragment.appendChild(paragraph);
            }
        }

        return fragment;
    }

    /* ------------------------------------------------------------------- view */

    function bubble(kind) {
        var element = document.createElement('div');
        element.className = 'fk-chat__msg fk-chat__msg--' + kind;
        log.appendChild(element);
        if (null !== hint) {
            hint.hidden = true;
        }

        return element;
    }

    function scroll() {
        log.scrollTop = log.scrollHeight;
    }

    function say(kind, text) {
        var element = bubble(kind);
        element.appendChild(markdown(text));
        scroll();

        return element;
    }

    function working(text) {
        status.hidden = '' === text;
        status.textContent = text;
        scroll();
    }

    function sources(element, tools) {
        if (!tools || 0 === tools.length) {
            return;
        }
        var line = document.createElement('div');
        line.className = 'fk-chat__sources';
        line.textContent = 'From: ' + tools.map(function (tool) {
            return tool.name;
        }).join(', ');
        element.appendChild(line);
    }

    function thinking(element) {
        var details = document.createElement('details');
        var summary = document.createElement('summary');
        var body = document.createElement('pre');
        details.className = 'fk-chat__thinking';
        summary.textContent = 'Reasoning';
        details.appendChild(summary);
        details.appendChild(body);
        element.appendChild(details);

        return body;
    }

    /* ---------------------------------------------------------------- storage */

    function remember() {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(history));
        } catch (error) {
            /* private mode, quota, storage disabled: the conversation just does not survive a reload. */
        }
    }

    function restore() {
        var stored = null;
        try {
            stored = window.sessionStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return;
        }
        if (null === stored) {
            return;
        }
        try {
            var parsed = JSON.parse(stored);
            if (!Array.isArray(parsed)) {
                return;
            }
            parsed.forEach(function (turn) {
                if ('user' === turn.role || 'assistant' === turn.role) {
                    history.push(turn);
                    say('user' === turn.role ? 'user' : 'bot', turn.content);
                }
            });
        } catch (error) {
            /* unreadable transcript: start fresh rather than argue with it. */
        }
    }

    /* ------------------------------------------------------------------- send */

    function payload(message) {
        var body = {message: message, history: history.slice(0, -1)};
        if ('' !== config.start && '' !== config.end) {
            body.context = {start: config.start, end: config.end};
        }

        return JSON.stringify(body);
    }

    function headers(accept) {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': config.csrf,
            'X-Requested-With': 'XMLHttpRequest',
            Accept: accept
        };
    }

    function failureText(response) {
        if (409 === response.status || 410 === response.status) {
            return 'That confirmation is no longer valid. Ask again and confirm the new card.';
        }
        if (419 === response.status) {
            return 'Your session expired. Reload the page and ask again.';
        }
        if (429 === response.status) {
            return 'That is a lot of questions at once — wait a moment and try again.';
        }
        if (502 === response.status) {
            return 'The language model did not answer. Is LM Studio running?';
        }

        return 'The request failed (HTTP ' + response.status + ').';
    }

    /**
     * A change the model proposed, rendered as something only a person can complete. The card holds
     * the token; the model never saw it, and clicking is a different request to a different route.
     */
    function proposalCard(proposal) {
        var card = document.createElement('div');
        var head = document.createElement('div');
        var what = document.createElement('div');
        var move = document.createElement('div');
        var actions = document.createElement('div');
        var cancel = document.createElement('button');
        var confirm = document.createElement('button');
        var amount = proposal.amount + (proposal.currency ? ' ' + proposal.currency : '');

        card.className = 'fk-chat__card';
        head.className = 'fk-chat__card-title';
        head.textContent = 'Confirm change';
        what.className = 'fk-chat__card-what';
        what.textContent = proposal.description + ' · ' + proposal.date + ' · ' + amount;
        move.className = 'fk-chat__card-move';
        move.textContent = (proposal.from || 'no category') + '  →  ' + proposal.to;

        cancel.type = 'button';
        cancel.className = 'fk-chat__card-button';
        cancel.textContent = 'Cancel';
        confirm.type = 'button';
        confirm.className = 'fk-chat__card-button fk-chat__card-button--go';
        confirm.textContent = 'Change it';

        actions.className = 'fk-chat__card-actions';
        actions.appendChild(cancel);
        actions.appendChild(confirm);
        card.appendChild(head);
        card.appendChild(what);
        card.appendChild(move);
        card.appendChild(actions);

        var settle = function (text, failed) {
            while (card.firstChild) {
                card.removeChild(card.firstChild);
            }
            card.className = 'fk-chat__card fk-chat__card--' + (failed ? 'failed' : 'done');
            card.textContent = text;
        };

        cancel.addEventListener('click', function () {
            settle('Left as ' + (proposal.from || 'no category') + '.', false);
        });

        confirm.addEventListener('click', function () {
            cancel.disabled = true;
            confirm.disabled = true;
            confirm.textContent = 'Changing…';
            fetch(config.applyUrl, {
                method: 'POST',
                headers: headers('application/json'),
                body: JSON.stringify({token: proposal.token}),
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json().then(function (json) {
                    return {ok: response.ok, status: response.status, json: json};
                });
            }).then(function (result) {
                if (!result.ok) {
                    // 409 and 410 carry a message worth reading: the data moved, or the card is stale.
                    settle(result.json.message || failureText({status: result.status}), true);

                    return;
                }
                settle(result.json.data.message, false);
            }).catch(function () {
                settle('The change could not be sent.', true);
            });
        });

        return card;
    }

    function proposals(element, list) {
        if (!list || 0 === list.length) {
            return;
        }
        list.forEach(function (proposal) {
            element.appendChild(proposalCard(proposal));
        });
        scroll();
    }

    function finish(element, answer, tools, reasoning, cards) {
        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
        element.appendChild(markdown(answer));
        sources(element, tools);
        proposals(element, cards);
        if (reasoning) {
            // Kept, collapsed, after the answer: it is the only way to see why the model asked for
            // what it asked for, and throwing it away at the end of the turn hides exactly that.
            element.appendChild(reasoning);
        }
        history.push({role: 'assistant', content: answer});
        remember();
        scroll();
    }

    function events(chunk, state, handlers) {
        state.buffer += chunk;
        var boundary = state.buffer.indexOf('\n\n');
        while (-1 !== boundary) {
            var block = state.buffer.slice(0, boundary);
            state.buffer = state.buffer.slice(boundary + 2);
            var name = '';
            var data = '';
            block.split('\n').forEach(function (line) {
                if (0 === line.indexOf('event: ')) {
                    name = line.slice(7);
                }
                if (0 === line.indexOf('data: ')) {
                    data = line.slice(6);
                }
            });
            if ('' !== name) {
                var parsed = null;
                try {
                    parsed = JSON.parse(data);
                } catch (error) {
                    parsed = null; /* a half-written event is not worth killing the answer over. */
                }
                if (null !== parsed) {
                    // Deliberately NOT inside the try: a rendering bug must surface in the console,
                    // not leave a silently empty answer bubble behind.
                    handlers(name, parsed);
                }
            }
            boundary = state.buffer.indexOf('\n\n');
        }
    }

    function streamAnswer(message) {
        var element = bubble('bot');
        var answer = '';
        var reasoning = null;
        var tools = [];

        return fetch(config.streamUrl, {
            method: 'POST',
            headers: headers('text/event-stream'),
            body: payload(message),
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok || null === response.body) {
                throw new Error(failureText(response));
            }
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var state = {buffer: ''};

            var handle = function (name, data) {
                if ('thinking' === name) {
                    working('Thinking…');
                    if (null === reasoning) {
                        reasoning = thinking(element);
                    }
                    reasoning.textContent += data.text;
                } else if ('tool' === name) {
                    tools.push(data);
                    working('Looking up ' + data.name.replace(/_/g, ' ') + '…');
                } else if ('delta' === name) {
                    working('Writing…');
                    answer += data.text;
                    var kept = null === reasoning ? null : reasoning.parentNode;
                    while (element.firstChild) {
                        element.removeChild(element.firstChild);
                    }
                    element.appendChild(markdown(answer));
                    if (null !== kept) {
                        element.appendChild(kept);
                    }
                    scroll();
                } else if ('done' === name) {
                    finish(element, data.answer, data.tools, null === reasoning ? null : reasoning.parentNode, data.proposals);
                } else if ('error' === name) {
                    element.className = 'fk-chat__msg fk-chat__msg--error';
                    element.textContent = data.message;
                }
            };

            var pump = function () {
                return reader.read().then(function (result) {
                    if (result.done) {
                        events('\n\n', state, handle);
                        return;
                    }
                    events(decoder.decode(result.value, {stream: true}), state, handle);

                    return pump();
                });
            };

            return pump();
        }).catch(function (error) {
            element.className = 'fk-chat__msg fk-chat__msg--error';
            element.textContent = error.message || 'The request failed.';
        });
    }

    function postAnswer(message) {
        var element = bubble('bot');
        element.textContent = '…';
        working('Thinking…');

        return fetch(config.sendUrl, {
            method: 'POST',
            headers: headers('application/json'),
            body: payload(message),
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(failureText(response));
            }

            return response.json();
        }).then(function (json) {
            finish(element, json.data.answer, json.data.tools, null, json.data.proposals);
        }).catch(function (error) {
            element.className = 'fk-chat__msg fk-chat__msg--error';
            element.textContent = error.message || 'The request failed.';
        });
    }

    function ask(message) {
        busy = true;
        send.disabled = true;
        history.push({role: 'user', content: message});
        say('user', message);
        remember();

        var request = config.streaming ? streamAnswer(message) : postAnswer(message);
        request.then(function () {
            busy = false;
            send.disabled = false;
            working('');
            input.focus();
        });
    }

    /* ------------------------------------------------------------------ wiring */

    function open(show) {
        panel.hidden = !show;
        launcher.setAttribute('aria-expanded', show ? 'true' : 'false');
        if (show) {
            input.focus();
            scroll();
        }
    }

    launcher.addEventListener('click', function () {
        open(panel.hidden);
    });

    close.addEventListener('click', function () {
        open(false);
        launcher.focus();
    });

    clear.addEventListener('click', function () {
        history = [];
        remember();
        while (log.firstChild) {
            log.removeChild(log.firstChild);
        }
        if (null !== hint) {
            hint.hidden = false;
            log.appendChild(hint);
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var message = input.value.trim();
        if ('' === message || busy) {
            return;
        }
        input.value = '';
        input.style.height = 'auto';
        ask(message);
    });

    input.addEventListener('keydown', function (event) {
        if ('Enter' === event.key && !event.shiftKey) {
            event.preventDefault();
            form.dispatchEvent(new Event('submit', {cancelable: true}));
        }
    });

    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    });

    document.addEventListener('keydown', function (event) {
        if ('Escape' === event.key && !panel.hidden) {
            open(false);
            launcher.focus();
        }
    });

    restore();
}());
