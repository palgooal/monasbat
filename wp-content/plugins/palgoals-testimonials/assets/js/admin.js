jQuery(function ($) {
	'use strict';

	var i18n = window.PalgoalsTestimonialsAdmin || {};

	$('.palgoals-admin-media').each(function () {
		var container = $(this);
		var input = container.find('input[type="hidden"]');
		var preview = container.find('.palgoals-admin-media__preview');
		var removeButton = container.find('.palgoals-remove-image');
		var frame;

		container.on('click', '.palgoals-upload-image', function (event) {
			event.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: i18n.frameTitle || 'Select client photo',
				button: {
					text: i18n.buttonLabel || 'Use this photo'
				},
				library: {
					type: 'image'
				},
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				input.val(attachment.id);
				preview.html('<img class="palgoals-admin-media__image" src="' + attachment.url + '" alt="" />');
				removeButton.removeClass('hidden');
			});

			frame.open();
		});

		container.on('click', '.palgoals-remove-image', function (event) {
			event.preventDefault();
			input.val('');
			preview.html('<span class="palgoals-admin-media__placeholder">' + container.data('placeholder') + '</span>');
			removeButton.addClass('hidden');
		});
	});
});
