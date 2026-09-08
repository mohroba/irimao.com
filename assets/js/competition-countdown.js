(function () {
    'use strict';

    const selectors = {
        root: '.imao-competition-countdown',
        timer: '[data-imao-countdown]',
        number: '[data-unit]'
    };

    function render(timer) {
        const target = Number(timer.dataset.imaoCountdown) * 1000;
        const remaining = Math.max(0, target - Date.now());
        const totalSeconds = Math.floor(remaining / 1000);
        const values = {
            days: Math.floor(totalSeconds / 86400),
            hours: Math.floor((totalSeconds % 86400) / 3600),
            minutes: Math.floor((totalSeconds % 3600) / 60),
            seconds: totalSeconds % 60
        };

        timer.querySelectorAll(selectors.number).forEach((element) => {
            const value = values[element.dataset.unit] ?? 0;
            element.textContent = String(value).padStart(2, '0');
        });

        if (remaining === 0) {
            timer.setAttribute('aria-label', timer.dataset.expiredLabel || 'مهلت به پایان رسیده است');
        }
    }

    function initialize(root) {
        const timers = Array.from(root.querySelectorAll(selectors.timer));
        timers.forEach(render);

        if (!timers.length) {
            return;
        }

        const interval = window.setInterval(() => {
            timers.forEach(render);
            if (timers.every((timer) => Number(timer.dataset.imaoCountdown) * 1000 <= Date.now())) {
                window.clearInterval(interval);
            }
        }, 1000);
    }

    function initializeAll(scope) {
        const context = scope && scope.querySelectorAll ? scope : document;
        context.querySelectorAll(selectors.root).forEach(initialize);
    }

    document.addEventListener('DOMContentLoaded', () => initializeAll(document));
    window.addEventListener('elementor/frontend/init', () => {
        if (window.elementorFrontend?.hooks) {
            window.elementorFrontend.hooks.addAction(
                'frontend/element_ready/imao_competition_countdown.default',
                ($scope) => initializeAll($scope?.[0] || $scope)
            );
        }
    });
}());
