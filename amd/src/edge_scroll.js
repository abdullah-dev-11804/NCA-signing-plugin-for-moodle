define(['jquery'], function($) {
    var defaults = {
        edgeSize: 80,
        maxSpeed: 22,
    };

    var debugPrefix = 'NCASIGN_EDGE_SCROLL';

    var log = function() {
        if (window.console && window.console.log) {
            window.console.log.apply(window.console, [debugPrefix].concat(Array.prototype.slice.call(arguments)));
        }
    };

    var getScrollableTarget = function(root) {
        var current = root;
        while (current) {
            if (current.scrollWidth > current.clientWidth + 1) {
                return current;
            }
            current = current.parentElement;
        }

        return document.scrollingElement || document.documentElement;
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
            var frame = null;
            var active = false;
            var pointerX = null;
            var currentSpeed = 0;

            log('bind', {
                scrollWidth: container.scrollWidth,
                clientWidth: container.clientWidth,
                targetTag: scrollTarget && scrollTarget.tagName ? scrollTarget.tagName.toLowerCase() : 'unknown',
                targetScrollWidth: scrollTarget.scrollWidth,
                targetClientWidth: scrollTarget.clientWidth,
            });

            function stop() {
                active = false;
                pointerX = null;
                currentSpeed = 0;
                if (frame) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }
                log('stop');
            }

            function getSpeed() {
                if (!active || pointerX === null) {
                    return 0;
                }

                var rect = container.getBoundingClientRect();
                var leftOffset = pointerX - rect.left;
                var rightOffset = rect.right - pointerX;

                if (scrollTarget.scrollWidth <= scrollTarget.clientWidth) {
                    log('no-scroll-needed', {
                        scrollWidth: scrollTarget.scrollWidth,
                        clientWidth: scrollTarget.clientWidth,
                    });
                    return 0;
                }

                if (leftOffset >= 0 && leftOffset < options.edgeSize) {
                    log('edge-left', {
                        leftOffset: Math.round(leftOffset),
                        scrollLeft: container.scrollLeft,
                    });
                    return -Math.ceil(options.maxSpeed * (1 - (leftOffset / options.edgeSize)));
                }

                if (rightOffset >= 0 && rightOffset < options.edgeSize) {
                    log('edge-right', {
                        rightOffset: Math.round(rightOffset),
                        scrollLeft: container.scrollLeft,
                    });
                    return Math.ceil(options.maxSpeed * (1 - (rightOffset / options.edgeSize)));
                }

                return 0;
            }

            function tick() {
                if (!active) {
                    frame = null;
                    return;
                }

                currentSpeed = getSpeed();
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
                    scrollLeft: container.scrollLeft,
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
            }

            container.addEventListener('mouseenter', start);
            container.addEventListener('mousemove', move);
            container.addEventListener('mouseleave', stop);
            container.addEventListener('wheel', stop, {passive: true});
        });
    };

    return {
        init: init,
    };
});
