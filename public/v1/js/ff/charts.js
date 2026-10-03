/*
 * charts.js
 * Copyright (c) 2019 james@firefly-iii.org
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
var allCharts = {};


/*
 Make some colours:
 */
var colourSet = [
    [53, 124, 165],
    [0, 141, 76], // green
    [219, 139, 11],
    [202, 25, 90], // paars rood-ish #CA195A
    [85, 82, 153],
    [66, 133, 244],
    [219, 68, 55], // red #DB4437
    [244, 180, 0],
    [15, 157, 88],
    [171, 71, 188],
    [0, 172, 193],
    [255, 112, 67],
    [158, 157, 36],
    [92, 107, 192],
    [240, 98, 146],
    [0, 121, 107],
    [194, 24, 91]
];

var fillColors = [];

for (var i = 0; i < colourSet.length; i++) {
    fillColors.push("rgba(" + colourSet[i][0] + ", " + colourSet[i][1] + ", " + colourSet[i][2] + ", 0.5)");
}

Chart.defaults.global.legend.display = false;
Chart.defaults.global.animation.duration = 0;
Chart.defaults.global.responsive = true;
Chart.defaults.global.maintainAspectRatio = false;

/**
 *
 * @param data
 * @returns {{}}
 */
function colorizeData(data) {
    var newData = {};
    newData.datasets = [];

    for (var loop = 0; loop < data.count; loop++) {
        newData.labels = data.labels;
        var dataset = data.datasets[loop];
        dataset.fill = false;
        dataset.backgroundColor = dataset.borderColor = fillColors[loop];
        newData.datasets.push(dataset);
    }
    return newData;
}

/**
 * Function to draw a line chart:
 * @param URL
 * @param container
 */
function lineChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'line';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Function to draw a line chart that doesn't start at ZERO.
 * @param URL
 * @param container
 */
function lineNoStartZeroChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'line';
    options.scales.yAxes[0].ticks.beginAtZero = false;

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Overrules the currency the line chart is drawn in.
 *
 * @param URL
 * @param container
 */
function otherCurrencyLineChart(URL, container, currencySymbol) {
    "use strict";

    var colorData = true;

    var newOpts = {
        scales: {
            xAxes: [
                {
                    gridLines: {
                        display: false
                    },
                    ticks: {
                        // break ticks when too long.
                        callback: function (value, index, values) {
                            return formatLabel(value, 20);
                        }
                    }
                }
            ],
            yAxes: [{
                display: true,
                //hello: 'fresh',
                ticks: {
                    callback: function (tickValue) {
                        "use strict";
                        // use first symbol or null:
                        return accounting.formatMoney(tickValue);

                    },
                    beginAtZero: true
                }
            }]
        },
    };

    //var options = $.extend(true, newOpts, defaultChartOptions);
    var options = $.extend(true, defaultChartOptions, newOpts);

    // console.log(options);
    var chartType = 'line';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Function to draw a chart with double Y Axes and stacked columns.
 *
 * @param URL
 * @param container
 */
function doubleYChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    options.scales.yAxes = [
        // y axis 0:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "left",
            "id": "y-axis-0"
        },
        // and y axis 1:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "right",
            "id": "y-axis-1"
        }

    ];
    options.stacked = true;
    options.scales.xAxes[0].stacked = true;

    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 * Function to draw a chart with double Y Axes and non stacked columns.
 *
 * @param URL
 * @param container
 */
function doubleYNonStackedChart(URL, container) {
    "use strict";

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    options.scales.yAxes = [
        // y axis 0:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "left",
            "id": "y-axis-0"
        },
        // and y axis 1:
        {
            display: true,
            ticks: {
                callback: function (tickValue) {
                    "use strict";
                    return accounting.formatMoney(tickValue);

                },
                beginAtZero: true
            },
            position: "right",
            "id": "y-axis-1"
        }

    ];
    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}


/**
 *
 * @param URL
 * @param container
 */
function columnChart(URL, container) {
    "use strict";
    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}


/**
 *
 * @param URL
 * @param container
 */
function columnChartCustomColours(URL, container) {
    "use strict";
    var colorData = false;
    var options = $.extend(true, {}, defaultChartOptions);
    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);

}

/**
 *
 * @param URL
 * @param container
 */
function stackedColumnChart(URL, container) {
    "use strict";
    console.log('stackedColumnChart(' + URL + ', ' + container + ')');

    var colorData = true;
    var options = $.extend(true, {}, defaultChartOptions);

    options.stacked = true;
    options.scales.xAxes[0].stacked = true;
    options.scales.yAxes[0].stacked = true;

    var chartType = 'bar';

    drawAChart(URL, container, chartType, options, colorData);
}

/**
 *
 * @param URL
 * @param container
 */
function pieChart(URL, container) {
    "use strict";

    var colorData = false;
    var options = $.extend(true, {}, defaultPieOptions);
    var chartType = 'pie';

    drawAChart(URL, container, chartType, options, colorData);

}

/**
 *
 * @param URL
 * @param container
 */
function multiCurrencyPieChart(URL, container) {
    "use strict";

    var colorData = false;
    var options = $.extend(true, {}, pieOptionsWithCurrency);
    var chartType = 'pie';

    drawAChart(URL, container, chartType, options, colorData);

}

/**
 * @param URL
 * @param container
 * @param chartType
 * @param options
 * @param colorData
 * @param today
 */
function drawAChart(URL, container, chartType, options, colorData) {
    console.log('drawAChart(' + URL + ', ' + container + ')');
    var containerObj = document.getElementById(container);
    if (null === containerObj) {
        console.log('Return because NULL');
        return;
    }
    if (containerObj.length === 0) {
        console.log('Return because 0');
        return;
    }
    // charts listed in dueDateUrls get a line on each due date. A failure there leaves the chart without them.
    var dueDatesRequest = Promise.resolve([]);
    if (typeof dueDateUrls === 'object' && dueDateUrls.hasOwnProperty(container)) {
        dueDatesRequest = window.axios.get(dueDateUrls[container]).then(function (response) {
            return response.data;
        }).catch(function () {
            return [];
        });
    }
    Promise.all([window.axios.get(URL), dueDatesRequest]).then(function (responses) {
        //console.log('GET drawAChart('+URL+', '+container+')');
        containerObj.classList.remove('general-chart-error');
        var data = responses[0].data;
        var dueDates = responses[1];
        // if result is empty array, or the labels array is empty, show error.
        // console.log(URL);
        // console.log(data.length);
        // console.log(typeof data.labels);
        // console.log(data.labels.length);
        if (
            // is undefined
            typeof data === 'undefined' ||
            // is empty
            0 === data.length ||
            // isn't empty but contains no labels
            (typeof data === 'object' && typeof data.labels === 'object' && 0 === data.labels.length)
        ) {
            // console.log('NODATA drawAChart('+URL+', '+container+')');
            // console.log(data);
            // remove the chart container + parent
            var holder = document.getElementById(container).parentNode.parentNode;
            if (holder.classList.contains('card') || holder.classList.contains('card-body')) {
                // console.log('found holder');
                // find box-body:
                var boxBody;
                if (!holder.classList.contains('card-body')) {
                    // console.log('Look for card body', holder.querySelector('.card-body').length);
                    boxBody = holder.querySelector('.card-body');
                } else {
                    boxBody = holder;
                }
                // console.log('found box body', boxBody);
                // boxBody.innerHtml = '<p><em>'+noDataForChart+'</em></p>';
                boxBody.innerHTML = '<p><em>' + noDataForChart + '</em></p>';
                //boxBody.empty().append($('<p>').append($('<em>').text(noDataForChart)));
            }
            // console.log('return');
            return;
        }

        if (colorData) {
            data = colorizeData(data);
        }

        if (allCharts.hasOwnProperty(container)) {
            allCharts[container].data.datasets = data.datasets;
            allCharts[container].data.labels = data.labels;
            allCharts[container].update();
        } else {
            // new chart!
            var ctx = document.getElementById(container).getContext("2d");
            var chartOpts = {
                type: chartType,
                data: data,
                options: options,
                lineAtIndex: [],
                annotation: {},
            };
            var annotations = [];
            if (typeof drawVerticalLine !== 'undefined') {
                if (drawVerticalLine !== '') {
                    // draw line using annotation plugin.
                    annotations.push({
                            type: 'line',
                            id: 'a-line-1',
                            mode: 'vertical',
                            scaleID: 'x-axis-0',
                            value: drawVerticalLine,
                            borderColor: lineColor,
                            borderWidth: 1,
                            label: {
                                backgroundColor: 'rgba(0,0,0,0)',
                                fontFamily: "sans-serif",
                                fontSize: 12,
                                fontColor: lineTextColor,
                                position: "right",
                                xAdjust: -20,
                                yAdjust: -125,
                                enabled: true,
                                content: todayText
                            }
                        });
                }
            }
            annotations = annotations.concat(dueDateAnnotations(dueDates, data.labels));
            if (annotations.length > 0) {
                chartOpts.options.annotation = {events: ['mouseenter', 'mouseleave'], annotations: annotations};
            }
            allCharts[container] = new Chart(ctx, chartOpts);
        }

    }).catch(function (reason) {
        // console.error('Add error because error with chart.', reason);
        document.getElementById(container).classList.add('general-chart-error');
    });
}

/**
 * A dashed red vertical line per due date, as made by DueDateController, labelled when the mouse is
 * over it. The plugin only notices the mouse within a line's width, so each line comes with a wide
 * invisible twin that carries the label. Due dates that aren't on the chart's x-axis are skipped.
 *
 * @param dueDates
 * @param labels
 * @returns {Array}
 */
function dueDateAnnotations(dueDates, labels) {
    "use strict";
    var result = [];
    var toggleLabel = function (element, enabled) {
        element.options.label.enabled = enabled;
        element.chartInstance.update(0);
    };
    for (var i = 0; i < dueDates.length; i++) {
        if (-1 === labels.indexOf(dueDates[i].label)) {
            continue;
        }
        result.push({
            type: 'line',
            id: 'due-date-' + i,
            mode: 'vertical',
            scaleID: 'x-axis-0',
            value: dueDates[i].label,
            borderColor: 'rgb(220, 53, 69)',
            borderWidth: 1,
            borderDash: [4, 4]
        });
        result.push({
            type: 'line',
            id: 'due-date-hover-' + i,
            mode: 'vertical',
            scaleID: 'x-axis-0',
            value: dueDates[i].label,
            borderColor: 'rgba(0,0,0,0)',
            borderWidth: 12,
            label: {
                backgroundColor: 'rgba(220, 53, 69, 0.9)',
                fontFamily: "sans-serif",
                fontSize: 11,
                fontStyle: 'normal',
                fontColor: '#fff',
                xPadding: 4,
                yPadding: 4,
                cornerRadius: 3,
                position: 'top',
                enabled: false,
                content: dueDates[i].text
            },
            onMouseenter: function () {
                toggleLabel(this, true);
            },
            onMouseleave: function () {
                toggleLabel(this, false);
            }
        });
    }
    return result;
}
