# Drop 01 — developer notes

The spec is `CLAUDE.md`. The owner's guide is `DEPLOY-GODADDY.md`.

```
src/                  everything that ships (= public_html)
  app/                PHP code (lib/, controllers/, views/, migrations/) — denied to the web
  assets/             waitlist, admin, fonts, icons
prototypes/           approved designs (never shipped)
tools/
  build.sh            → dist/drop01-deploy.zip
  test-all.sh         build + fresh Apache container from the zip + install + all tests + email check
  tests/run.php       PHP unit tests
  e2e/run.mjs         Playwright mobile tests (npm install in tools/e2e first)
  docker/Dockerfile   php:8.2-apache with gd/zip (`docker build -t drop01-php tools/docker`)
  php                 runs PHP from that image when the machine has none
  fetch-fonts.sh, make-icons.mjs
```

Quick loop: `./tools/test-all.sh` (needs Docker; uses ports 8092 and 8025). Admin of the test install: `owner@example.com` / `correct-horse-1`.

Without Docker: `php -S localhost:8000 -t src src/index.php`, then open `/install.php`.
