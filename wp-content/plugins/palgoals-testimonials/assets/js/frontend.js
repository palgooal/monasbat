(function () {
	'use strict';

	var elementorHookBound = false;

	function getWrapperGap(wrapper) {
		if (!wrapper || !window.getComputedStyle) {
			return 24;
		}

		var styles = window.getComputedStyle(wrapper);
		var gapVar = wrapper.classList.contains('pg-testimonials') ? '--pg-gap' : '--palgoals-card-gap';
		var gap = parseFloat(styles.getPropertyValue(gapVar));

		if (!Number.isFinite(gap) && '--pg-gap' === gapVar) {
			gap = parseFloat(styles.getPropertyValue('--palgoals-card-gap'));
		}

		return Number.isFinite(gap) ? gap : 24;
	}

	function syncSwiperGap(slider, swiper) {
		var wrapper = slider.closest('.palgoals-testimonials, .palgoals-chat-shots, .pg-testimonials');
		var gap = getWrapperGap(wrapper);

		if (!swiper || swiper.destroyed) {
			return;
		}

		if (slider.dataset.palgoalsGap === String(gap)) {
			return;
		}

		swiper.params.spaceBetween = gap;
		swiper.originalParams.spaceBetween = gap;

		if (swiper.params.breakpoints) {
			Object.keys(swiper.params.breakpoints).forEach(function (breakpoint) {
				swiper.params.breakpoints[breakpoint].spaceBetween = gap;
			});
		}

		if (swiper.originalParams.breakpoints) {
			Object.keys(swiper.originalParams.breakpoints).forEach(function (breakpoint) {
				swiper.originalParams.breakpoints[breakpoint].spaceBetween = gap;
			});
		}

		slider.dataset.palgoalsGap = String(gap);
		swiper.update();
	}

	function watchSwiperGap(slider, swiper) {
		if (slider.palgoalsGapWatcher) {
			return;
		}

		slider.palgoalsGapWatcher = window.setInterval(function () {
			if (!slider.isConnected || !swiper || swiper.destroyed) {
				window.clearInterval(slider.palgoalsGapWatcher);
				delete slider.palgoalsGapWatcher;
				return;
			}

			syncSwiperGap(slider, swiper);
		}, 250);
	}

	function initTestimonials(root) {
		var scope = root || document;

		scope.querySelectorAll('.js-palgoals-swiper').forEach(function (slider) {
			if (slider.dataset.palgoalsReady === '1' || slider.dataset.pgReady === '1') {
				return;
			}

			if (typeof window.Swiper !== 'function') {
				return;
			}

			var wrapper = slider.closest('.palgoals-testimonials, .palgoals-chat-shots, .pg-testimonials');
			if (!wrapper) {
				return;
			}
			var options = {};
			var pagination = wrapper.querySelector('.palgoals-testimonials__pagination, .palgoals-chat-shots__pagination, .pg-testimonials__pagination');
			var nextButton = wrapper.querySelector('.palgoals-testimonials__button--next, .palgoals-chat-shots__button--next, .pg-testimonials__button--next');
			var prevButton = wrapper.querySelector('.palgoals-testimonials__button--prev, .palgoals-chat-shots__button--prev, .pg-testimonials__button--prev');

			try {
				options = JSON.parse(slider.getAttribute('data-swiper-options') || '{}');
			} catch (error) {
				options = {};
			}

			options.spaceBetween = getWrapperGap(wrapper);

			options.pagination = pagination ? {
				el: pagination,
				clickable: true
			} : false;
			options.navigation = nextButton && prevButton ? {
				nextEl: nextButton,
				prevEl: prevButton
			} : false;

			var swiper = new window.Swiper(slider, options);

			slider.dataset.palgoalsGap = '';
			syncSwiperGap(slider, swiper);
			watchSwiperGap(slider, swiper);

			window.addEventListener('resize', function () {
				syncSwiperGap(slider, swiper);
			});

			if (window.matchMedia) {
				['(max-width: 767px)', '(min-width: 768px) and (max-width: 1023px)', '(min-width: 1024px)'].forEach(function (query) {
					var mediaQuery = window.matchMedia(query);
					var listener = function () {
						syncSwiperGap(slider, swiper);
					};

					if (typeof mediaQuery.addEventListener === 'function') {
						mediaQuery.addEventListener('change', listener);
					} else if (typeof mediaQuery.addListener === 'function') {
						mediaQuery.addListener(listener);
					}
				});
			}

			slider.dataset.palgoalsReady = '1';
			slider.dataset.pgReady = '1';
			slider.classList.add('pg-is-initialized');
			slider.classList.add('palgoals-is-initialized');
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initTestimonials(document);
		});
	} else {
		initTestimonials(document);
	}

	function bindElementorHook() {
		if (elementorHookBound || !window.elementorFrontend || !window.elementorFrontend.hooks) {
			return;
		}

		window.elementorFrontend.hooks.addAction('frontend/element_ready/palgoals-testimonials.default', function ($scope) {
			initTestimonials($scope[0]);
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/palgoals-testimonials-slider.default', function ($scope) {
			initTestimonials($scope[0]);
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/palgoals-chat-screenshots.default', function ($scope) {
			initTestimonials($scope[0]);
		});
		elementorHookBound = true;
	}

	bindElementorHook();
	window.addEventListener('elementor/frontend/init', bindElementorHook);

	if (window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', bindElementorHook);
	}
})();
