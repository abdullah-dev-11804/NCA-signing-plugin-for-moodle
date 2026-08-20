define(['jquery'], function($) {
    var defaults = {
        edgeSize: 80,
        maxSpeed: 22,
    };

    var debugPrefix = 'NCASIGN_EDGE_SCROLL';

    var log = function() {
        if (window.NCASIGN_EDGE_SCROLL_DEBUG && window.console && window.console.log) {
            window.console.log.apply(window.console, [debugPrefix].concat(Array.prototype.slice.call(arguments)));
        }
    };

    var getScrollableTarget = function(root) {
        var responsive = root.querySelector('.table-responsive');
        if (responsive) {
            return responsive;
        }

        var table = root.querySelector('table.generaltable, table.table, table');
        if (table && table.parentElement) {
            return table.parentElement;
        }

        return root;
    };

    var init = function(selector, edgeSize, maxSpeed) {
        var options = $.extend({}, defaults, {
            edgeSize: edgeSize || defaults.edgeSize,
            maxSpeed: maxSpeed || defaults.maxSpeed,
        });

        log('init', {
            selector: selector,
            edgeSize: options.edgeSize,
            maxSpeed: options.maxSpeed,
            matchCount: $(selector).length,
        });

        $(selector).each(function() {
            var container = this;
            var scrollTarget = getScrollableTarget(container);
            var table = container.querySelector('table.generaltable, table.table, table');
            var frame = null;
            var active = false;
            var pointerX = null;
            var currentSpeed = 0;
            var pauseUntil = 0;

            log('bind', {
                scrollWidth: container.scrollWidth,
                clientWidth: container.clientWidth,
                targetTag: scrollTarget && scrollTarget.tagName ? scrollTarget.tagName.toLowerCase() : 'unknown',
                targetClass: scrollTarget && scrollTarget.className ? scrollTarget.className : '',
                targetScrollWidth: scrollTarget.scrollWidth,
                targetClientWidth: scrollTarget.clientWidth,
                tableScrollWidth: table ? table.scrollWidth : null,
                tableClientWidth: table ? table.clientWidth : null,
            });

            function stop() {
                active = false;
                pointerX = null;
                currentSpeed = 0;
                pauseUntil = 0;
                if (frame) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }
                container.classList.remove('local-ncasign-edge-scroll-left', 'local-ncasign-edge-scroll-right');
                log('stop');
            }

            function updateCursorClass(speed) {
                container.classList.remove('local-ncasign-edge-scroll-left', 'local-ncasign-edge-scroll-right');
                if (speed < 0) {
                    container.classList.add('local-ncasign-edge-scroll-left');
                } else if (speed > 0) {
                    container.classList.add('local-ncasign-edge-scroll-right');
                }
            }

            function getSpeed() {
                if (!active || pointerX === null) {
                    return 0;
                }

                var rect = scrollTarget.getBoundingClientRect();
                var leftOffset = pointerX - rect.left;
                var rightOffset = rect.right - pointerX;

                if (scrollTarget.scrollWidth <= scrollTarget.clientWidth + 1) {
                    log('no-scroll-needed', {
                        scrollWidth: scrollTarget.scrollWidth,
                        clientWidth: scrollTarget.clientWidth,
                        tableScrollWidth: table ? table.scrollWidth : null,
                        tableClientWidth: table ? table.clientWidth : null,
                    });
                    updateCursorClass(0);
                    return 0;
                }

                if (leftOffset >= 0 && leftOffset < options.edgeSize) {
                    log('edge-left', {
                        leftOffset: Math.round(leftOffset),
                        scrollLeft: scrollTarget.scrollLeft,
                    });
                    return -Math.ceil(options.maxSpeed * (1 - (leftOffset / options.edgeSize)));
                }

                if (rightOffset >= 0 && rightOffset < options.edgeSize) {
                    log('edge-right', {
                        rightOffset: Math.round(rightOffset),
                        scrollLeft: scrollTarget.scrollLeft,
                    });
                    return Math.ceil(options.maxSpeed * (1 - (rightOffset / options.edgeSize)));
                }

                updateCursorClass(0);
                return 0;
            }

            function tick() {
                if (!active) {
                    frame = null;
                    return;
                }

                if (pauseUntil > Date.now()) {
                    updateCursorClass(0);
                    frame = window.requestAnimationFrame(tick);
                    return;
                }

                currentSpeed = getSpeed();
                updateCursorClass(currentSpeed);
                if (currentSpeed === 0) {
                    log('tick-zero-speed', {
                        scrollLeft: scrollTarget.scrollLeft,
                    });
                    frame = window.requestAnimationFrame(tick);
                    return;
                }

                var maxScrollLeft = scrollTarget.scrollWidth - scrollTarget.clientWidth;
                var nextScrollLeft = scrollTarget.scrollLeft + currentSpeed;

                if (nextScrollLeft < 0) {
                    nextScrollLeft = 0;
                } else if (nextScrollLeft > maxScrollLeft) {
                    nextScrollLeft = maxScrollLeft;
                }

                scrollTarget.scrollLeft = nextScrollLeft;
                log('tick-scroll', {
                    speed: currentSpeed,
                    scrollLeft: nextScrollLeft,
                    maxScrollLeft: maxScrollLeft,
                });
                frame = window.requestAnimationFrame(tick);
            }

            function start(event) {
                active = true;
                pointerX = event.clientX;
                log('mouseenter', {
                    clientX: pointerX,
                    scrollLeft: scrollTarget.scrollLeft,
                });
                if (!frame) {
                    frame = window.requestAnimationFrame(tick);
                }
            }

            function move(event) {
                pointerX = event.clientX;
                log('mousemove', {
                    clientX: pointerX,
                });
                if (active && !frame) {
                    frame = window.requestAnimationFrame(tick);
                }
            }

            function wheel(event) {
                if (Math.abs(event.deltaY) >= Math.abs(event.deltaX)) {
                    pauseUntil = Date.now() + 260;
                    updateCursorClass(0);
                }
                if (active && !frame) {
                    frame = window.requestAnimationFrame(tick);
                }
            }

            container.addEventListener('mouseenter', start);
            container.addEventListener('mousemove', move);
            container.addEventListener('mouseleave', stop);
            container.addEventListener('wheel', wheel, {passive: true});
        });
    };

    return {
        init: init,
    };
});
