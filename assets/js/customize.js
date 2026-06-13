(function ($) {
  // Typography live preview — updates CSS variables on the :root element
  // so all theme elements react immediately without a page reload.

  var slugs = [
    'base-font', 'small-font', 'medium-font', 'normal-font', 'large-font',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button'
  ];

  var props = [
    'font-family', 'font-size', 'line-height',
    'letter-spacing', 'font-weight', 'font-color'
  ];

  // Inject a live-preview <style> block into the preview iframe head
  function getPreviewStyle() {
    var style = document.getElementById('siteglow-typo-live');
    if (!style) {
      style = document.createElement('style');
      style.id = 'siteglow-typo-live';
      document.head.appendChild(style);
    }
    return style;
  }

  var vars = {};

  function flushVars() {
    var rules = Object.keys(vars).map(function (k) {
      return '  --' + k + ': ' + vars[k] + ';';
    }).join('\n');
    getPreviewStyle().textContent = ':root {\n' + rules + '\n}';
  }

  slugs.forEach(function (slug) {
    props.forEach(function (prop) {
      // setting ID: theme_base-font_font_family
      var settingId = 'theme_' + slug + '_' + prop.replace(/-/g, '_');
      // CSS variable: --base-font-font-family
      var varName = slug + '-' + prop;

      wp.customize(settingId, function (value) {
        value.bind(function (to) {
          vars[varName] = to;
          flushVars();
        });
      });
    });
  });

  // Header template live preview
  wp.customize('header_template_css', function (value) {
    value.bind(function (to) {
      var style = document.getElementById('siteglow-header-live-css');
      if (!style) {
        style = document.createElement('style');
        style.id = 'siteglow-header-live-css';
        document.head.appendChild(style);
      }
      style.textContent = to || '';
    });
  });

})(jQuery);
