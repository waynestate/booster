# Booster front page

A static page: plain HTML, CSS and a little vanilla JS, no build step. Serve `site/` from anywhere (any static host,
`python -m http.server`, or open `index.html` directly).

- type: Barlow Condensed 600 and 800 (SIL OFL, self-hosted in `assets/fonts/`) for display; Lato from WayneUI for body
- world: a vaccination record card meets a launch sequence. Clinical white and pale mint paper, green ink, a gold flame,
  coral only as a signal (countdown, a held dose, EOL)
- base: WayneUI 2.0.8, copied into `assets/wayne-ui/` from its static path; `assets/booster.css` overrides the look
- magic: the record card. Pick an example app, press Run the plan: a countdown, then each hop is stamped as its gate
  passes while the version climbs. "A capped hop" shows a dose held at the review threshold. The plans are real
  `booster plan` output for the apps in `examples/`
- reduced motion: no countdowns or lift-offs; the page loads with every dose already stamped
- after hours: a reader whose system is set to dark gets the record room after hours (section 12 of
  `assets/booster.css`, the same night shift as the docs): a graphite desk, faint mint graph paper, cream type, gold
  still the flame. Green keeps its own places (the never list, the install slab, the footer). The only new light is
  the burn under the rocket, which breathes while it lifts, and simply glows when motion is reduced
- marks: `brand/` is Booster's ChatGPT mark (never redrawn); `assets/marks/` holds the other stack parts' marks and
  WayneUI's own mark

Screenshots are in `screenshots/`.

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
folder that holds all the projects side by side. Dev is picked automatically on `localhost` or `127.0.0.1`;
`?env=dev` or `?env=production` forces one (remembered until `?env=auto`), `<meta name="stack-env">` sets the
page default, and `?devBase=http://127.0.0.1:<port>` points at a server on another port. On a host ending in
`.appstest.wayne.edu` the links point at `https://<project>.appstest.wayne.edu/` instead, the pre-production home
of every part (`?env=test` forces it). WayneUI is the exception: in development its links go to
`http://127.0.0.1:4180/`, since it does not run from a subfolder, and in test they stay on production, since
there is no pre-production WayneUI.
