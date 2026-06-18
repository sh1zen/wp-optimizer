(function ($) {
    'use strict';

    function closeFaq($item) {
        const $question = $item.find('> .wps-faq-question-wrapper > .wpopt-faq-toggle');
        const $answer = $question.next('.wps-faq-answer');
        const answer = $answer.get(0);

        if (!answer || !$item.hasClass('is-open')) {
            return;
        }

        $item.removeClass('is-open');
        $question.attr('aria-expanded', 'false');
        $question.children('.wps-collapse-icon').removeClass('wps-collapse-icon-close');

        answer.style.height = answer.scrollHeight + 'px';
        answer.style.opacity = '1';

        requestAnimationFrame(function () {
            answer.style.height = '0px';
            answer.style.opacity = '0';
        });
    }

    function openFaq($item) {
        const $question = $item.find('> .wps-faq-question-wrapper > .wpopt-faq-toggle');
        const $answer = $question.next('.wps-faq-answer');
        const answer = $answer.get(0);

        if (!answer || $item.hasClass('is-open')) {
            return;
        }

        $item.addClass('is-open');
        $question.attr('aria-expanded', 'true');
        $question.children('.wps-collapse-icon').addClass('wps-collapse-icon-close');

        answer.style.display = 'block';
        answer.style.height = '0px';
        answer.style.opacity = '0';

        requestAnimationFrame(function () {
            answer.style.height = answer.scrollHeight + 'px';
            answer.style.opacity = '1';
        });
    }

    function initFaq() {
        $('.wpopt-faq-shell .wps-faq-item').each(function (index) {
            const $item = $(this);
            const $question = $item.find('> .wps-faq-question-wrapper > .wpopt-faq-toggle');
            const $answer = $question.next('.wps-faq-answer');
            const answerId = 'wpopt-faq-answer-' + index;

            $question.attr({
                role: 'button',
                tabindex: '0',
                'aria-expanded': 'false',
                'aria-controls': answerId
            });

            $answer.attr('id', answerId).css({
                display: 'none',
                height: '0px',
                opacity: 0
            });
        });
    }

    $(function () {
        initFaq();

        $('body')
            .on('click', '.wpopt-faq-shell .wpopt-faq-toggle', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const $item = $(this).closest('.wps-faq-item');

                if ($item.hasClass('is-open')) {
                    closeFaq($item);
                }
                else {
                    openFaq($item);
                }
            })
            .on('keydown', '.wpopt-faq-shell .wpopt-faq-toggle', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                $(this).trigger('click');
            })
            .on('transitionend', '.wpopt-faq-shell .wps-faq-answer', function (event) {
                if (event.originalEvent.propertyName !== 'height') {
                    return;
                }

                const $answer = $(this);
                const $item = $answer.closest('.wps-faq-item');

                if ($item.hasClass('is-open')) {
                    this.style.height = 'auto';
                }
                else {
                    this.style.display = 'none';
                }
            });
    });
})(jQuery);
