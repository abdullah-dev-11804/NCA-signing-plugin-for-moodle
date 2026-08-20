define(['jquery'], function($) {
    var defaults = {
        edgeSize: 80,
        maxSpeed: 22,
    };

    var init = function(selector, edgeSize, maxSpeed) {
        var options = $.extend({}, defaults, {
            edgeSize: edgeSize || defaults.edgeSize,
            maxSpeed: maxSpeed || defaults.maxSpeed,
        });

        $(selector).each(function() {
            var container = this;
            var frame = null;
            var active = false;
            var pointerX = null;
            var currentSpeed = 0;

            function stop() {
                active = false;
                pointerX = null;
                currentSpeed = 0;
                if (frame) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }
            }

            function getSpeed() {
                if (!active || pointerX === null) {
                    return 0;
                }

                var rect = container.getBoundingClientRect();
                var leftOffset = pointerX - rect.left;
                var rightOffset = rect.right - pointerX;

                if (container.scrollWidth <= container.clientWidth) {
                    return 0;
                }

                if (leftOffset >= 0 && leftOffset < options.edgeSize) {
                    return -Math.ceil(options.maxSpeed * (1 - (leftOffset / options.edgeSize)));
                }

                if (rightOffset >= 0 && rightOffset < options.edgeSize) {
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
                    frame = window.requestAnimationFrame(tick);
                    return;
                }

                var maxScrollLeft = container.scrollWidth - container.clientWidth;
                var nextScrollLeft = container.scrollLeft + currentSpeed;

                if (nextScrollLeft < 0) {
                    nextScrollLeft = 0;
                } else if (nextScrollLeft > maxScrollLeft) {
                    nextScrollLeft = maxScrollLeft;
                }

                container.scrollLeft = nextScrollLeft;
                frame = window.requestAnimationFrame(tick);
            }

            function start(event) {
                active = true;
                pointerX = event.clientX;
                if (!frame) {
                    frame = window.requestAnimationFrame(tick);
                }
            }

            function move(event) {
                pointerX = event.clientX;
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
