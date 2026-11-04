/* docs.js: progressive enhancement for the shared docs site, owned by Squire (kit/docs/).
 *
 * The pages are complete without this file. It adds two conveniences: a copy button on every code block
 * and a mobile menu that closes when you pick a page. No dependencies, no globals, nothing that breaks
 * the reading of a single page if it fails to load.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  function copyText(text) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      // writeText resolves with undefined on success and rejects on failure: map both to the true or false below.
      return navigator.clipboard.writeText(text).then(function () {
        return true;
      }, function () {
        return false;
      });
    }
    // Fallback for non-secure contexts: select into a throwaway field and use the legacy command.
    var field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'absolute';
    field.style.left = '-9999px';
    document.body.appendChild(field);
    field.select();
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (err) {
      ok = false;
    }
    document.body.removeChild(field);
    return Promise.resolve(ok);
  }

  // A code block wider than its box scrolls sideways; keyboard users can only scroll what they can focus
  // (WCAG 2.1.1), so a block that overflows joins the tab order and one that fits leaves it.
  function markScrollable(pre) {
    if (pre.scrollWidth > pre.clientWidth) {
      pre.setAttribute('tabindex', '0');
    } else {
      pre.removeAttribute('tabindex');
    }
  }

  function addCopyButtons() {
    var blocks = Array.prototype.slice.call(document.querySelectorAll('.docs-article pre'));
    if (blocks.length > 0) {
      window.addEventListener('resize', function () {
        blocks.forEach(markScrollable);
      });
    }
    blocks.forEach(function (pre) {
      var wrap = document.createElement('div');
      wrap.className = 'docs-copy';
      pre.parentNode.insertBefore(wrap, pre);
      wrap.appendChild(pre);

      var code = pre.querySelector('code');
      var text = code ? code.textContent : pre.textContent;

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'docs-copy__button';
      button.textContent = 'Copy';
      button.setAttribute('aria-label', 'Copy code to clipboard');

      var status = document.createElement('span');
      status.className = 'docs-copy__status';
      status.setAttribute('aria-live', 'polite');

      wrap.appendChild(button);
      wrap.appendChild(status);
      markScrollable(pre);

      var timer = null;
      button.addEventListener('click', function () {
        copyText(text).then(function (ok) {
          button.textContent = ok ? 'Copied' : 'Copy failed';
          status.textContent = ok ? 'Code copied.' : 'Could not copy the code.';
          if (timer !== null) {
            clearTimeout(timer);
          }
          timer = setTimeout(function () {
            button.textContent = 'Copy';
            status.textContent = '';
            timer = null;
          }, 2000);
        });
      });
    });
  }

  function closeMenuOnNavigate() {
    var menus = Array.prototype.slice.call(document.querySelectorAll('.docs-menu'));
    menus.forEach(function (menu) {
      menu.addEventListener('click', function (event) {
        var target = event.target;
        while (target && target !== menu) {
          if (target.tagName === 'A') {
            menu.open = false;
            break;
          }
          target = target.parentNode;
        }
      });
    });
  }

  ready(function () {
    addCopyButtons();
    closeMenuOnNavigate();
  });
})();
