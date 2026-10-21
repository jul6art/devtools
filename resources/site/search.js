// DevTools — search over the exported pages (ADR-0034), from the index beside this script: no server.
(function () {
  'use strict';

  var root = document.currentScript.getAttribute('data-root') || '.';
  var input = document.getElementById('search');
  var results = document.getElementById('results');
  var pages = window.DEVTOOLS_SEARCH || [];

  if (window.mermaid) {
    window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'default' });
    window.mermaid.run({ querySelector: 'pre.mermaid' }).catch(function (error) {
      document.documentElement.setAttribute('data-mermaid-error', String(error && error.message ? error.message : error));
    });
  }

  function search(query) {
    var words = query.toLowerCase().split(/\s+/).filter(Boolean);

    return pages
      .map(function (page) {
        var haystack = (page.title + ' ' + page.url + ' ' + page.text).toLowerCase();
        var score = 0;

        for (var i = 0; i < words.length; i++) {
          if (haystack.indexOf(words[i]) === -1) {
            return null;
          }

          score += page.title.toLowerCase().indexOf(words[i]) === -1 ? 1 : 3;
        }

        return { page: page, score: score };
      })
      .filter(Boolean)
      .sort(function (a, b) { return b.score - a.score || a.page.title.localeCompare(b.page.title); })
      .slice(0, 20);
  }

  input.addEventListener('input', function () {
    results.textContent = '';
    var found = input.value.trim() === '' ? [] : search(input.value);

    found.forEach(function (hit) {
      var item = document.createElement('li');
      var link = document.createElement('a');
      var excerpt = document.createElement('small');
      link.href = root + '/' + hit.page.url;
      link.textContent = hit.page.title;
      excerpt.textContent = hit.page.text;
      link.appendChild(excerpt);
      item.appendChild(link);
      results.appendChild(item);
    });

    results.hidden = found.length === 0;
  });
})();
