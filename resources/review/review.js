/*
 * The review page of DevTools (ADR-0028): no dependency, no build — this file, the page and its stylesheet.
 *
 * A captured page is shown in an iframe sandboxed without scripts, under a policy that forbids them: it runs
 * nothing. This page reads it — same origin, never its scripts — to outline the element under the pointer and,
 * on a click, to compute a selector that names that element and no other.
 */
(function () {
    'use strict';

    var token = new URLSearchParams(window.location.search).get('token') || '';
    var state = { scenarios: [], impacted: [], capture: null, picked: null };
    var $ = function (id) { return document.getElementById(id); };

    function withToken(path) {
        return path + (path.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(token);
    }

    function request(method, path, body) {
        return fetch(withToken(path), {
            method: method,
            headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
            body: body === undefined ? undefined : JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        });
    }

    function element(tag, text, className) {
        var created = document.createElement(tag);

        if (text !== undefined && text !== null) {
            created.textContent = text;
        }

        if (className) {
            created.className = className;
        }

        return created;
    }

    // --- The selector of an element -------------------------------------------------------------------------

    function quote(value) {
        return '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
    }

    function unique(document, selector) {
        try {
            return document.querySelectorAll(selector).length === 1;
        } catch (error) {
            return false;
        }
    }

    /**
     * A selector that names this element and no other, checked against its document: its identifier, else its
     * name, else one of its data-* attributes, else the shortest path — from an ancestor with an identifier at
     * the farthest.
     */
    function selectorOf(target) {
        var document = target.ownerDocument;
        var tag = target.localName;
        var candidate;
        var index;

        if (target.id) {
            candidate = '#' + CSS.escape(target.id);

            if (unique(document, candidate)) {
                return candidate;
            }
        }

        if (target.hasAttribute('name')) {
            candidate = tag + '[name=' + quote(target.getAttribute('name')) + ']';

            if (unique(document, candidate)) {
                return candidate;
            }
        }

        for (index = 0; index < target.attributes.length; index++) {
            var attribute = target.attributes[index];

            if (attribute.name.indexOf('data-') === 0 && attribute.name.indexOf('data-devtools') !== 0) {
                candidate = tag + '[' + attribute.name + '=' + quote(attribute.value) + ']';

                if (unique(document, candidate)) {
                    return candidate;
                }
            }
        }

        var parts = [];

        for (var current = target; current && current.nodeType === 1; current = current.parentElement) {
            if (current !== target && current.id && unique(document, '#' + CSS.escape(current.id))) {
                parts.unshift('#' + CSS.escape(current.id));

                break;
            }

            var part = current.localName;
            var parent = current.parentElement;

            if (parent) {
                var same = [];

                for (index = 0; index < parent.children.length; index++) {
                    if (parent.children[index].localName === current.localName) {
                        same.push(parent.children[index]);
                    }
                }

                if (same.length > 1) {
                    part += ':nth-of-type(' + (same.indexOf(current) + 1) + ')';
                }
            }

            parts.unshift(part);

            if (unique(document, parts.join(' > '))) {
                return parts.join(' > ');
            }
        }

        return parts.join(' > ');
    }

    // --- The run -----------------------------------------------------------------------------------------------

    function renderScenarios() {
        var nav = $('scenarios');
        var onlyImpacted = $('impacted').checked;
        nav.textContent = '';

        state.scenarios.forEach(function (scenario) {
            if (onlyImpacted && state.impacted.indexOf(scenario.workflow) === -1) {
                return;
            }

            var section = element('section');
            var title = element('h3', scenario.id + ' — ');
            title.appendChild(element('span', scenario.verdict, 'verdict-' + scenario.verdict));
            section.appendChild(title);
            var list = element('ul');

            scenario.captures.forEach(function (capture) {
                var item = element('li');
                var button = element('button', capture.label + (capture.visual ? ' · ' + capture.visual : ''), capture.visual ? 'visual-' + capture.visual : '');
                button.type = 'button';
                button.dataset.capture = capture.name;
                button.addEventListener('click', function () {
                    show(scenario, capture, button);
                });
                item.appendChild(button);
                list.appendChild(item);
            });

            if (scenario.failure && scenario.failure.screenshot && scenario.failure.dom) {
                var failure = scenario.failure;
                var item = element('li');
                var button = element('button', '✗ ' + failure.action + (failure.message ? ' — ' + failure.message : ''), 'verdict-failed');
                button.type = 'button';
                button.dataset.capture = scenario.id + '/' + failure.action;
                button.addEventListener('click', function () {
                    show(scenario, { name: scenario.id + ' — stopped at ' + failure.action, screenshot: failure.screenshot, dom: failure.dom, baseline: null, diff: null, failure: true }, button);
                });
                item.appendChild(button);
                list.appendChild(item);
            }

            if (list.children.length === 0) {
                list.appendChild(element('li', 'No screenshot.'));
            }

            section.appendChild(list);
            nav.appendChild(section);
        });

        if (!nav.firstChild) {
            nav.appendChild(element('p', onlyImpacted ? 'No scenario of a workflow the change reaches.' : 'No scenario in this run.'));
        }
    }

    function show(scenario, capture, button) {
        state.capture = capture;
        Array.prototype.forEach.call(document.querySelectorAll('nav button'), function (other) {
            other.setAttribute('aria-current', other === button ? 'true' : 'false');
        });
        $('hint').hidden = true;
        $('capture').hidden = false;
        $('feedback').hidden = false;
        $('capture-title').textContent = capture.name;
        $('screenshot').src = withToken(capture.screenshot);
        $('baseline').hidden = !capture.baseline;
        $('no-baseline').hidden = !!capture.baseline;

        if (capture.baseline) {
            $('baseline').src = withToken(capture.baseline);
        }

        $('diff-figure').hidden = !capture.diff;

        if (capture.diff) {
            $('diff').src = withToken(capture.diff);
        }

        // A failure is looked at, never made the reference.
        $('accept').hidden = !!capture.failure;
        $('workflow').value = scenario.workflow;
        $('selector').value = '';
        $('outcome').textContent = '';
        $('page').src = withToken(capture.dom);
    }

    function watch(frame) {
        var page = frame.contentDocument;

        if (!page || !page.documentElement) {
            return;
        }

        page.addEventListener('mouseover', function (event) {
            if (state.picked && state.picked !== event.target) {
                state.picked.style.outline = state.picked.dataset.devtoolsOutline || '';
            }

            state.picked = event.target;
            state.picked.dataset.devtoolsOutline = state.picked.style.outline;
            state.picked.style.outline = '2px solid #e11d48';
        });

        page.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            $('selector').value = selectorOf(event.target);
            $('summary').focus();
        }, true);
    }

    // --- Feedback and baselines --------------------------------------------------------------------------------

    function say(lines, className) {
        var outcome = $('outcome');
        outcome.textContent = '';

        lines.forEach(function (line) {
            outcome.appendChild(element('p', line, className));
        });

        return outcome;
    }

    function record(decision) {
        request('POST', '/api/feedback', {
            summary: $('summary').value,
            category: $('category').value,
            workflow: $('workflow').value,
            selector: $('selector').value,
            decision: decision
        }).then(function (answer) {
            if (answer.status === 201) {
                say(['Recorded ' + answer.data.id + ' — ' + answer.data.occurrences + ' occurrence' + (answer.data.occurrences > 1 ? 's' : '') + ', in ' + answer.data.path + '.']
                    .concat(answer.data.promote ? ['Seen twice: ' + answer.data.promote + ' makes it a rule.'] : []));
                $('summary').value = '';
                $('count').textContent = '0';
            } else if (answer.status === 409 && answer.data.recurrence) {
                var known = answer.data.recurrence;
                var outcome = say(['Already said in ' + known.id + ' (' + known.occurrences + ' times): « ' + known.summary + ' ».']);
                var again = element('button', 'Add an occurrence to it');
                var apart = element('button', 'Record this one apart');
                again.type = 'button';
                apart.type = 'button';
                again.addEventListener('click', function () { record('auto'); });
                apart.addEventListener('click', function () { record('new'); });
                outcome.appendChild(again);
                outcome.appendChild(apart);
            } else {
                say(answer.data.errors || [answer.data.error || 'The feedback was not recorded.'], 'error');
            }
        });
    }

    $('feedback').addEventListener('submit', function (event) {
        event.preventDefault();
        record(null);
    });

    $('summary').addEventListener('input', function () {
        $('count').textContent = String($('summary').value.length);
    });

    $('accept').addEventListener('click', function () {
        if (!state.capture) {
            return;
        }

        request('POST', '/api/baseline', { capture: state.capture.name }).then(function (answer) {
            say(answer.status === 201 ? ['Baseline written: ' + answer.data.baseline + ' — commit it with the change it shows.'] : (answer.data.errors || ['Not accepted.']), answer.status === 201 ? '' : 'error');
        });
    });

    $('page').addEventListener('load', function () {
        watch($('page'));
    });

    $('impacted').addEventListener('change', renderScenarios);

    request('GET', '/api/categories').then(function (answer) {
        (answer.data.categories || []).forEach(function (category) {
            var option = element('option', category.name);
            option.value = category.name;
            option.title = category.covers;
            $('category').appendChild(option);
        });
    });

    request('GET', '/api/run').then(function (answer) {
        if (answer.status !== 200) {
            $('run').textContent = answer.data.error || 'The run cannot be read.';

            return;
        }

        state.scenarios = answer.data.scenarios;
        state.impacted = answer.data.impacted;
        $('run').textContent = answer.data.run ? 'Run ' + answer.data.run + ' — ' + state.scenarios.length + ' scenarios' : (answer.data.error || 'No run yet: devtools scenarios:run writes one.');
        renderScenarios();
    });

    window.DevToolsReview = { selectorOf: selectorOf };
}());
