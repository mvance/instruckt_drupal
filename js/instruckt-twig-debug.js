/**
 * @file
 * Drupal behavior: reads Twig debug HTML comments and enriches annotation
 * POST bodies with the template that rendered the annotated element.
 *
 * When Twig debug mode is enabled ($settings['twig_debug'] = TRUE), Drupal
 * emits HTML comments of the form:
 *   <!-- BEGIN OUTPUT from 'themes/mytheme/templates/node--article.html.twig' -->
 * immediately before the element they wrap.
 *
 * This behavior:
 *   1. Scans those comments and tags each element with data-drupal-twig /
 *      data-drupal-twig-component attributes.
 *   2. Installs a fetch interceptor that injects framework context into
 *      annotation POST bodies before they are sent.
 *
 * When no debug comments are found (debug mode off), the behavior exits
 * immediately with zero overhead.
 */

(function (Drupal) {
  /**
   * Extracts a template name from a full Twig template path.
   *
   * @param {string} path
   *   E.g. 'themes/mytheme/templates/node--article.html.twig'
   * @return {string}
   *   E.g. 'node--article'
   */
  function componentFromPath(path) {
    // Grab the basename, strip the .html.twig (or .twig) extension.
    const base = path.split('/').pop() || path;
    return base.replace(/\.html\.twig$/, '').replace(/\.twig$/, '');
  }

  /**
   * Walks the document body for Twig debug BEGIN OUTPUT comment nodes and
   * annotates the following element sibling with data attributes.
   *
   * @return {number}
   *   Count of elements that were annotated (0 means debug mode is off).
   */
  function scanTwigDebugComments() {
    let count = 0;
    const walker = document.createTreeWalker(
      document.body,
      NodeFilter.SHOW_COMMENT,
    );

    for (
      let node = walker.nextNode();
      node !== null;
      node = walker.nextNode()
    ) {
      const text = node.nodeValue || '';
      const match = text.match(/BEGIN OUTPUT from '([^']+)'/);
      if (!match) {
        continue;
      }
      const templatePath = match[1];

      // Walk forward through siblings to find the next element node.
      let sibling = node.nextSibling;
      while (sibling && sibling.nodeType !== Node.ELEMENT_NODE) {
        sibling = sibling.nextSibling;
      }
      if (!sibling) {
        continue;
      }

      sibling.setAttribute('data-drupal-twig', templatePath);
      sibling.setAttribute(
        'data-drupal-twig-component',
        componentFromPath(templatePath),
      );
      count++;
    }

    return count;
  }

  /**
   * Walks el's ancestor chain looking for a data-drupal-twig attribute.
   *
   * @param {Element} el
   * @return {{framework: string, component: string, source_file: string}|null}
   */
  function getTwigContext(el) {
    let current = el;
    while (current) {
      const path =
        current.getAttribute && current.getAttribute('data-drupal-twig');
      if (path) {
        return {
          framework: 'twig',
          component:
            current.getAttribute('data-drupal-twig-component') ||
            componentFromPath(path),
          source_file: path,
        };
      }
      current = current.parentElement;
    }
    return null;
  }

  /**
   * Wraps window.fetch to inject Twig framework context into annotation POSTs.
   *
   * @param {string} endpoint
   *   The instruckt base endpoint, e.g. '/instruckt'.
   */
  function installFetchInterceptor(endpoint) {
    const annotationsUrl = endpoint + '/annotations';
    const originalFetch = window.fetch;

    window.fetch = function (input, init) {
      const url =
        typeof input === 'string' ? input : (input && input.url) || '';

      // Only intercept POST requests to the annotations endpoint.
      if (
        url.indexOf(annotationsUrl) === -1 ||
        !init ||
        (init.method || '').toUpperCase() !== 'POST'
      ) {
        return originalFetch.call(this, input, init);
      }

      try {
        const body = JSON.parse(init.body);

        // Only enrich if framework context is absent and an element selector is present.
        if (!body.framework && body.element) {
          const el = document.querySelector(body.element);
          if (el) {
            const context = getTwigContext(el);
            if (context) {
              body.framework = context;
              init = { ...init, body: JSON.stringify(body) };
            }
          }
        }
      } catch (e) {
        // JSON parse error or selector failure — pass through unmodified.
      }

      return originalFetch.call(this, input, init);
    };
  }

  /**
   * Drupal behavior: entry point.
   */
  Drupal.behaviors.instruckt_twig_debug = {
    attach(context, settings) {
      if (context !== document) {
        return;
      }
      if (!settings.instruckt_drupal) {
        return;
      }
      if (scanTwigDebugComments() === 0) {
        return;
      }
      installFetchInterceptor(settings.instruckt_drupal.endpoint);
    },
  };
})(Drupal);
