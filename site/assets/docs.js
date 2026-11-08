/* docs.js: progressive enhancement for the shared docs site, owned by Squire (kit/docs/).
 *
 * The pages are complete without this file. It adds three conveniences: a copy button on every code block,
 * an accessible tablist on every code tab set (with the choice remembered per set key), and a mobile menu
 * that closes when you pick a page. No dependencies, no globals, nothing that breaks the reading of a single
 * page if it fails to load. Escape closes the open mobile menu.
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
  // (WCAG 2.1.1), so a block that overflows joins the tab order and one that fits leaves it. A block in a
  // hidden tab panel has no box to measure (both widths read 0), so it keeps what it had until its tab shows.
  function markScrollable(pre) {
    if (pre.closest('[hidden]')) {
      return;
    }
    if (pre.scrollWidth > pre.clientWidth) {
      pre.setAttribute('tabindex', '0');
    } else {
      pre.removeAttribute('tabindex');
    }
  }

  // True when the button stays clear of the code: beside the block (a theme's gutter; a long line scrolls right up
  // to the block's edge, so the edge counts, within a pixel) or in a band above or below its lines (the padding).
  function clearsCode(button, pre) {
    var b = button.getBoundingClientRect();
    var p = pre.getBoundingClientRect();
    var cs = window.getComputedStyle(pre);
    var top = p.top + (parseFloat(cs.paddingTop) || 0);
    var bottom = p.bottom - (parseFloat(cs.paddingBottom) || 0);
    return b.right <= p.left + 1 || b.left >= p.right - 1 || b.bottom <= top || b.top >= bottom;
  }

  // Each theme sizes the button's place for its resting "Copy". A longer state word may grow it only while it
  // stays clear of the code; otherwise the button keeps its resting width, and a word that does not fit that
  // width gives way to a mark (the live region still says it in words), so the button never covers code.
  function showState(button, pre, word, mark) {
    var rest = button.getBoundingClientRect().width;
    button.style.inlineSize = '';
    button.textContent = word;
    if (clearsCode(button, pre)) {
      return;
    }
    if (rest > 0) {
      button.style.inlineSize = rest + 'px';
    }
    if (button.scrollWidth > button.clientWidth + 1) {
      button.textContent = mark;
    }
  }

  function addCopyButtons() {
    var blocks = Array.prototype.slice.call(document.querySelectorAll('.docs-article pre'));
    if (blocks.length > 0) {
      window.addEventListener('resize', function () {
        blocks.forEach(markScrollable);
      });
      // Web fonts often settle after the first measure, and they change block widths: measure once more
      // when they do, so a block that overflows only with the final font still joins the tab order.
      if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
        document.fonts.ready.then(function () {
          blocks.forEach(markScrollable);
        });
      }
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
          showState(button, pre, ok ? 'Copied' : 'Failed', ok ? '✓' : '✕');
          status.textContent = ok ? 'Code copied.' : 'Could not copy the code.';
          if (timer !== null) {
            clearTimeout(timer);
          }
          timer = setTimeout(function () {
            button.textContent = 'Copy';
            button.style.inlineSize = '';
            status.textContent = '';
            timer = null;
          }, 2000);
        });
      });
    });
  }

  function wireTabs() {
    var sets = Array.prototype.slice.call(document.querySelectorAll('.docs-tabs'));
    if (sets.length === 0) {
      return;
    }

    // Storage can throw in private mode or embedded contexts; treat it as absent, not fatal.
    function readStored(key) {
      try {
        return window.localStorage.getItem(key);
      } catch (err) {
        return null;
      }
    }
    function writeStored(key, value) {
      try {
        window.localStorage.setItem(key, value);
      } catch (err) {
        // No storage: the choice simply does not outlive this visit.
      }
    }

    var groups = {}; // data-tabs key -> the wired sets that share it
    var seq = 0;

    sets.forEach(function (set) {
      var key = set.getAttribute('data-tabs') || '';
      var panels = [];
      for (var i = 0; i < set.children.length; i++) {
        if (set.children[i].className === 'docs-tabs__panel') {
          panels.push(set.children[i]);
        }
      }
      if (panels.length === 0) {
        return;
      }

      var tablist = document.createElement('div');
      tablist.className = 'docs-tabs__tablist';
      tablist.setAttribute('role', 'tablist');
      tablist.setAttribute('aria-label', 'Code examples');
      set.insertBefore(tablist, set.firstChild);

      var state = {
        key: key,
        tablist: tablist,
        buttons: [],
        panels: panels,
        labels: [],
        selected: -1,
        select: null,
        selectByLabel: null
      };

      panels.forEach(function (panel, index) {
        var label = panel.getAttribute('data-tab-label') || 'Example ' + (index + 1);
        state.labels.push(label);

        var heading = panel.querySelector('.docs-tabs__label');
        if (heading) {
          heading.hidden = true; // the tab carries the name now
        }

        var tabId = 'docs-tab-' + seq;
        var panelId = 'docs-tab-panel-' + seq;
        seq += 1;

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'docs-tabs__tab';
        button.setAttribute('role', 'tab');
        button.id = tabId;
        button.textContent = label;
        button.setAttribute('aria-controls', panelId);

        panel.id = panelId;
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('tabindex', '0');
        panel.setAttribute('aria-labelledby', tabId);

        tablist.appendChild(button);
        state.buttons.push(button);
      });

      state.select = function (index, persist) {
        if (index === state.selected) {
          return;
        }
        state.selected = index;
        state.buttons.forEach(function (button, i) {
          button.setAttribute('aria-selected', i === index ? 'true' : 'false');
          button.tabIndex = i === index ? 0 : -1;
        });
        state.panels.forEach(function (panel, i) {
          panel.hidden = i !== index;
        });
        // The shown panel's code now has a box: measure it, so a block that scrolls is keyboard reachable.
        Array.prototype.forEach.call(state.panels[index].querySelectorAll('pre'), markScrollable);
        if (persist) {
          writeStored('squire-docs-tab:' + key, state.labels[index]);
          // Keep every other set that shares this key in step.
          (groups[key] || []).forEach(function (other) {
            if (other !== state) {
              other.selectByLabel(state.labels[index], false);
            }
          });
        }
      };

      state.selectByLabel = function (label, persist) {
        var index = state.labels.indexOf(label);
        if (index !== -1) {
          state.select(index, persist);
        }
      };

      state.buttons.forEach(function (button, index) {
        button.addEventListener('click', function () {
          state.select(index, true);
        });
      });

      state.tablist.addEventListener('keydown', function (event) {
        var count = state.buttons.length;
        var next = null;
        if (event.key === 'ArrowRight') {
          next = (state.selected + 1) % count;
        } else if (event.key === 'ArrowLeft') {
          next = (state.selected - 1 + count) % count;
        } else if (event.key === 'Home') {
          next = 0;
        } else if (event.key === 'End') {
          next = count - 1;
        }
        if (next === null) {
          return;
        }
        event.preventDefault();
        state.select(next, true);
        state.buttons[next].focus();
      });

      // The stored choice for this key wins when it names one of the tabs; otherwise the first tab.
      var start = state.labels.indexOf(readStored('squire-docs-tab:' + key));
      state.select(start === -1 ? 0 : start, false);

      (groups[key] || (groups[key] = [])).push(state);
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
      // Escape closes the open menu and hands focus back to its summary, so a keyboard user is not left inside a
      // list they wanted to dismiss. Enter and Space already open and close it: that part is the browser's.
      menu.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !menu.open) {
          return;
        }
        menu.open = false;
        var summary = menu.querySelector('summary');
        if (summary) {
          summary.focus();
        }
        event.preventDefault();
      });
    });
  }

  ready(function () {
    addCopyButtons();
    wireTabs();
    closeMenuOnNavigate();
  });
})();
