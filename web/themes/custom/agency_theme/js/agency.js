/**
 * @file
 * The Agency theme.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * JQuery Agency theme.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.agencyTheme = {
    attach: function (context, drupalSettings) {

    }
  };

  // Create interaction between Bottstrap and superfish menus.
  $('#navigation-trigger').click(function () {
    $('#superfish-header-navigation-toggle').trigger('click');
  });

  $(document).ready(function () {
    $('#block-mostrecentpoll form .form-item-choice label').each(function () {
      $(this).click(function () {
        var text;

        $('#block-mostrecentpoll form .form-item-choice label').each(function () {
          if (~$(this).text().indexOf(' (Selected)')) {
            var clean = $(this).text().replace(' (Selected)', '');
            $(this).text(clean);
          }
        });

        if (~$(this).text().indexOf(' (Selected)')) {
          text = $(this).text();
        }
        else {
          text = $(this).text() + ' (Selected)';
        }

        $(this).text(text);
      });
    });

    $(".view-display-id-homepage_carousel .carousel-image").each(function () {
      var $container = $(this),
          imgUrl = $container.find("img").prop("src");

      if (imgUrl) {
        $container.css(
          "backgroundImage",
          'url(' + imgUrl + ')'
        ).addClass("custom-object-fit");
      }
    });

    // Disable menu wrapper items click event.
    $('#superfish-header-navigation > li:not(:first-child) > a').click(function (e) {
      e.preventDefault();
    });

    // Set same height for the carousel info wrappers.
    var height = 0;
    $("#block-views-block-news-homepage-carousel .carousel-info").each(function () {
      if ($(this).innerHeight() >= height) {
        height = $(this).innerHeight();
      }
    });

    $("#block-views-block-news-homepage-carousel .carousel-info").each(function () {
      $(this).css('height', height + 'px');
    });

  });

  $(window).resize(function() {
    // Set same height for the carousel info wrappers.
    var height = 0;
    $("#block-views-block-news-homepage-carousel .carousel-info").each(function () {
      if ($(this).innerHeight() >= height) {
        height = $(this).innerHeight();
      }
    });

    $("#block-views-block-news-homepage-carousel .carousel-info").each(function () {
      $(this).css('height', height + 'px');
    });
  });

})(jQuery, Drupal);
