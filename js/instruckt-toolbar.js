(function (Drupal, drupalSettings) {
  Drupal.behaviors.instruckt_drupal = {
    attach: function (context, settings) {
      if (context !== document) return;
      if (!window.Instruckt || !settings.instruckt_drupal) return;
      Instruckt.init({
        endpoint: settings.instruckt_drupal.endpoint,
        theme: settings.instruckt_drupal.theme || 'auto',
        position: settings.instruckt_drupal.position || 'bottom-right',
      });
    }
  };
})(Drupal, drupalSettings);
