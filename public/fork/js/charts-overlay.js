/*
 * charts-overlay.js
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
 * FORK: restyles every Chart.js 2.7 chart of the v1 layout without touching upstream's charts.js.
 * Loaded from layout/default.twig AFTER the page's own scripts (which define the charts) and BEFORE
 * DOM-ready (when upstream draws them), so a global Chart.js plugin can reshape options and
 * datasets on creation and on every data refresh:
 *   - one palette + typography + grid/tooltip styling taken from the overlay's CSS tokens (--fk-*),
 *     so charts follow the same light/dark theme as the rest of the page;
 *   - lines: no point-per-datum, 2px stroke, soft fill under single-series lines, points on hover;
 *   - bars: solid, capped width; upstream's hard-coded red/green become the theme's danger/success;
 *   - pies become doughnuts with a legend, and everything past the top 8 slices is folded into "Other"
 *     (only when all slices share a currency, so the tooltip amounts stay honest);
 *   - the "today" marker on the dashboard/account pages follows the theme instead of prefers-color-scheme.
 * Switch: FORK_UI_OVERLAY (same as the CSS overlay).
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined' || !Chart.plugins || !Chart.defaults || !Chart.defaults.global || Chart.__forkOverlay) {
        return;
    }
    Chart.__forkOverlay = true;

    var TOP_SLICES_WIDE = 8;   // legend beside the doughnut
    var TOP_SLICES_NARROW = 6; // legend under it
    var LEGEND_MAX_CHARS = 30;
    var reducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var FALLBACK_PALETTE = ['#2f6fb5', '#2e9e6b', '#d9891c', '#c4407a', '#6f5bc7', '#1f9aa8', '#d0443f', '#6b7a8f'];
    // upstream's hard-coded dataset colours (Chart/ReportController, Chart/CategoryController, WholePeriodChartGenerator)
    var UPSTREAM_SEMANTIC = {'rgba(219, 68, 55, 0.5)': 'danger', 'rgba(0, 141, 76, 0.5)': 'success'};

    function cssVar(name, fallback) {
        var value = getComputedStyle(document.body).getPropertyValue(name).trim();
        return value || fallback;
    }

    function readTokens() {
        var palette = [];
        for (var i = 1; i <= 12; i++) {
            var c = cssVar('--fk-chart-' + i, '');
            if (c) {
                palette.push(c);
            }
        }
        return {
            text: cssVar('--fk-text', '#1f2933'),
            muted: cssVar('--fk-muted', '#6b7280'),
            border: cssVar('--fk-border', '#e4e7eb'),
            borderStrong: cssVar('--fk-border-strong', '#d0d5db'),
            surface: cssVar('--fk-surface', '#ffffff'),
            success: cssVar('--fk-success', '#16a34a'),
            danger: cssVar('--fk-danger', '#dc2626'),
            font: getComputedStyle(document.body).fontFamily || 'sans-serif',
            palette: palette.length ? palette : FALLBACK_PALETTE
        };
    }

    // "#rgb", "#rrggbb", "rgb(...)" or "rgba(...)" -> "rgba(r,g,b,a)"
    function withAlpha(color, a) {
        var hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(color).trim());
        if (hex) {
            var h = hex[1];
            if (h.length === 3) {
                h = h.replace(/./g, function (ch) { return ch + ch; });
            }
            return 'rgba(' + parseInt(h.substr(0, 2), 16) + ',' + parseInt(h.substr(2, 2), 16) + ',' + parseInt(h.substr(4, 2), 16) + ',' + a + ')';
        }
        var rgb = /^rgba?\(([^)]+)\)/.exec(String(color).trim());
        if (rgb) {
            var parts = rgb[1].split(',').slice(0, 3).map(function (p) { return p.trim(); });
            return 'rgba(' + parts.join(',') + ',' + a + ')';
        }
        return color;
    }

    function semanticFor(color) {
        var key = typeof color === 'string' ? UPSTREAM_SEMANTIC[color.replace(/\s+/g, ' ').trim()] : undefined;
        return key ? T[key] : null;
    }

    function assign(target, props) {
        target = target || {};
        Object.keys(props).forEach(function (k) { target[k] = props[k]; });
        return target;
    }

    var T = readTokens();

    function applyGlobals() {
        var g = Chart.defaults.global;
        g.defaultFontFamily = T.font;
        g.defaultFontColor = T.muted;
        g.defaultFontSize = 12;
        g.animation.duration = reducedMotion ? 0 : 350;
        assign(g.tooltips, {
            backgroundColor: T.surface, titleFontColor: T.text, bodyFontColor: T.text, footerFontColor: T.muted,
            borderColor: T.borderStrong, borderWidth: 1, cornerRadius: 8, xPadding: 10, yPadding: 8,
            titleMarginBottom: 6, caretSize: 6, displayColors: true, titleFontStyle: '600'
        });
        assign(g.legend.labels, {usePointStyle: true, boxWidth: 8, padding: 14, fontColor: T.text});
        g.elements.arc.borderColor = T.surface;
        // the dashboard / account "today" marker (page-level globals read by charts.js at draw time)
        if (typeof window.lineColor !== 'undefined') {
            window.lineColor = withAlpha(T.danger, 0.7);
        }
        if (typeof window.lineTextColor !== 'undefined') {
            window.lineTextColor = T.muted;
        }
    }

    function isCircular(chart) {
        return chart.config.type === 'pie' || chart.config.type === 'doughnut';
    }

    function chartData(chart) {
        return chart.data || chart.config.data;
    }

    function styleOptions(chart) {
        var o = chart.options;
        var datasets = (chartData(chart) || {}).datasets || [];
        var holderWidth = chart.canvas && chart.canvas.parentNode ? chart.canvas.parentNode.clientWidth : 0;

        if (isCircular(chart)) {
            var wide = holderWidth >= 520;
            chart.config.type = 'doughnut';
            chart._fkTopSlices = wide ? TOP_SLICES_WIDE : TOP_SLICES_NARROW;
            o.cutoutPercentage = 58;
            o.legend = assign(o.legend, {display: true, position: wide ? 'right' : 'bottom'});
            o.legend.labels = assign(o.legend.labels, {usePointStyle: true, boxWidth: 8, padding: 10, fontColor: T.text});
            truncateLegend(o.legend.labels);
            o.elements = o.elements || {};
            o.elements.arc = assign(o.elements.arc, {borderWidth: 2, borderColor: T.surface});
            o.layout = assign(o.layout, {padding: 4});
            // a legend under the ring needs a taller canvas, or the ring shrinks to a coin (aspect = width / height)
            if (!wide && o.maintainAspectRatio !== false) {
                chart.aspectRatio = 0.8;
            }
            return;
        }
        // upstream's "today" marker (chartjs-plugin-annotation) sits 125px above the line's centre, which
        // is off-canvas once a legend takes part of the height: pin it to the top of the line instead
        // chartjs-plugin-annotation registers before us and has already copied the config into
        // chart.annotation.options in its own beforeInit, so restyle that copy (and the raw options too)
        var lists = [o.annotation && o.annotation.annotations, chart.annotation && chart.annotation.options && chart.annotation.options.annotations];
        lists.forEach(function (annotations) {
            (annotations || []).forEach(function (a) {
                if (a && a.label) {
                    assign(a.label, {
                        position: 'top', xAdjust: 30, yAdjust: 4, fontFamily: T.font, fontStyle: '600', fontSize: 11, fontColor: T.muted,
                        backgroundColor: withAlpha(T.surface, 0.9), cornerRadius: 4, xPadding: 6, yPadding: 3
                    });
                }
                if (a && a.borderColor) {
                    a.borderColor = withAlpha(T.danger, 0.8);
                }
            });
        });

        var scales = o.scales || {};
        (scales.xAxes || []).forEach(function (axis) {
            axis.gridLines = assign(axis.gridLines, {display: false, drawBorder: false});
            axis.ticks = assign(axis.ticks, {fontColor: T.muted, maxRotation: 0, autoSkip: true, autoSkipPadding: 18, padding: 6});
            axis.maxBarThickness = 36;
            axis.barPercentage = 0.75;
            axis.categoryPercentage = 0.8;
        });
        (scales.yAxes || []).forEach(function (axis) {
            axis.gridLines = assign(axis.gridLines, {
                color: T.border, zeroLineColor: T.borderStrong, drawBorder: false, borderDash: [3, 4], zeroLineBorderDash: [], lineWidth: 1
            });
            axis.ticks = assign(axis.ticks, {fontColor: T.muted, padding: 8});
        });
        o.tooltips = assign(o.tooltips, {mode: 'index', intersect: false, position: 'nearest'});
        o.legend = assign(o.legend, {display: datasets.length > 1, position: 'bottom'});
        o.legend.labels = assign(o.legend.labels, {usePointStyle: true, boxWidth: 8, padding: 14, fontColor: T.text});
        o.layout = assign(o.layout, {padding: {top: 8, right: 8, bottom: 0, left: 0}});
    }

    // Chart.js 2 never wraps legend text; long labels are cut off, so shorten them (tooltips keep the full label)
    function truncateLegend(labels) {
        if (labels._fkTruncates) {
            return;
        }
        var generate = labels.generateLabels || Chart.defaults.global.legend.labels.generateLabels;
        labels.generateLabels = function (chart) {
            var items = generate.call(this, chart) || [];
            items.forEach(function (item) {
                if (typeof item.text === 'string' && item.text.length > LEGEND_MAX_CHARS) {
                    item.text = item.text.slice(0, LEGEND_MAX_CHARS - 1).replace(/\s+$/, '') + '\u2026';
                }
            });
            return items;
        };
        labels._fkTruncates = true;
    }

    function otherLabel(count) {
        return 'Other (' + count + ')';
    }

    // fold everything past the top slices into one "Other" slice, keeping labels/data/currency arrays aligned
    function groupSlices(data, top) {
        var ds = data.datasets && data.datasets[0];
        if (!ds || !Array.isArray(ds.data) || ds._fkGrouped || ds.data.length <= top + 1) {
            return;
        }
        var symbols = ds.currency_symbol;
        if (Array.isArray(symbols) && symbols.some(function (s) { return s !== symbols[0]; })) {
            return; // mixed currencies in one pie: summing them would lie
        }
        var order = ds.data.map(function (_, i) { return i; }).sort(function (a, b) {
            return parseFloat(ds.data[b]) - parseFloat(ds.data[a]);
        });
        var keep = order.slice(0, top);
        var rest = order.slice(top);
        var other = rest.reduce(function (sum, i) { return sum + parseFloat(ds.data[i]); }, 0);
        ds.data = keep.map(function (i) { return ds.data[i]; }).concat([other.toFixed(2)]);
        data.labels = keep.map(function (i) { return data.labels[i]; }).concat([otherLabel(rest.length)]);
        if (Array.isArray(symbols)) {
            ds.currency_symbol = keep.map(function (i) { return symbols[i]; }).concat([symbols[0]]);
        }
        ds._fkGrouped = true;
    }

    function sliceColours(n, labels) {
        var out = [];
        for (var i = 0; i < n; i++) {
            var isOther = i === n - 1 && /^Other \(\d+\)$/.test(String(labels[i]));
            out.push(isOther ? withAlpha(T.muted, 0.55) : T.palette[i % T.palette.length]);
        }
        return out;
    }

    function styleDatasets(chart) {
        var data = chartData(chart);
        if (!data || !Array.isArray(data.datasets)) {
            return;
        }
        if (isCircular(chart)) {
            groupSlices(data, chart._fkTopSlices || TOP_SLICES_WIDE);
            data.datasets.forEach(function (ds) {
                var n = Array.isArray(ds.data) ? ds.data.length : 0;
                ds.backgroundColor = sliceColours(n, data.labels || []);
                ds.hoverBackgroundColor = ds.backgroundColor;
                ds.borderColor = T.surface;
                ds.borderWidth = 2;
            });
            return;
        }
        var single = data.datasets.length === 1;
        data.datasets.forEach(function (ds, i) {
            var type = ds.type || chart.config.type;
            var base = semanticFor(ds.backgroundColor) || semanticFor(ds.borderColor) || T.palette[i % T.palette.length];
            if (type === 'line') {
                assign(ds, {
                    borderColor: base, backgroundColor: single ? withAlpha(base, 0.12) : base, fill: single ? 'start' : false,
                    borderWidth: 2, lineTension: 0.3, pointRadius: 0, pointHoverRadius: 4, pointHitRadius: 14,
                    pointBackgroundColor: base, pointBorderColor: T.surface, pointBorderWidth: 2, pointHoverBorderWidth: 2
                });
                return;
            }
            if (Array.isArray(ds.backgroundColor)) {
                ds.backgroundColor = ds.backgroundColor.map(function (c, j) {
                    return withAlpha(semanticFor(c) || T.palette[j % T.palette.length], 0.85);
                });
                ds.hoverBackgroundColor = ds.backgroundColor.map(function (c) { return withAlpha(c, 1); });
            } else {
                ds.backgroundColor = withAlpha(base, 0.85);
                ds.hoverBackgroundColor = base;
            }
            ds.borderWidth = 0;
            ds.borderColor = base;
        });
    }

    Chart.plugins.register({
        id: 'forkOverlay',
        beforeInit: function (chart) { styleOptions(chart); },
        beforeUpdate: function (chart) { styleDatasets(chart); }
    });

    applyGlobals();

    // re-theme live charts when the colour scheme flips (darkMode preference "browser")
    function retheme() {
        T = readTokens();
        applyGlobals();
        var charts = window.allCharts || {};
        Object.keys(charts).forEach(function (key) {
            var chart = charts[key];
            if (!chart || !chart.options) {
                return;
            }
            styleOptions(chart);
            if (chart.tooltip) {
                chart.tooltip._options = chart.options.tooltips;
            }
            chart.update();
        });
    }
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        if (mq.addEventListener) {
            mq.addEventListener('change', retheme);
        } else if (mq.addListener) {
            mq.addListener(retheme);
        }
    }
})();
