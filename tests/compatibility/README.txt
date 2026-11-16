OPTIONAL OFFLINE GLPI 12 COMPATIBILITY TESTS
These checks supplement tests/ana_*_test.php. Run locally, not on production.
No extra package is needed for normal plugin operation.

1. PHP native integration
Obtain the unchanged official GLPI 12.0.1 source in a separate directory.
Using PHP >=8.3 with the standard GLPI extensions (including sodium/mysqli):
  php tests/compatibility/glpi12_native_integration.php /path/to/glpi-12.0.1
Expected result: 34 checks; no database or network connection.
The runner is CLI-only and uses a temporary random encryption key, removed
on exit. It loads real core classes, native permission/reauthentication logic,
Config encryption and DBmysql locks. Framework dependencies and database/HTTP
transport are substituted with offline fixtures. It does not boot a complete
GLPI kernel, web server, browser session or database.

2. Frontend regressions
Provide Node.js and jsdom in a separate local test dependency directory.
Make jsdom resolvable through NODE_PATH or ordinary Node module resolution:
  node tests/compatibility/frontend.cjs
Expected result: 14 checks covering GLPI11 tokens, GLPI12 omission, overlapping
requests, reauthentication recovery, unsaved values, safe links and stale
results. The runner reads the shipped JS and active template directly.
It uses a DOM simulation and mocked fetch; it is not a live-browser test.
Do not install jsdom or Node dependencies into the production plugin folder.

3. Version boundary and lifecycle
  php tests/ana_plugin_structure_test.php 11.0.8
  php tests/ana_plugin_structure_test.php 12.0.1
  php tests/ana_plugin_structure_test.php 10.0.99
  php tests/ana_plugin_structure_test.php 13.0.0
Supported versions: 19 checks each, including token branching, update data
preservation and isolation. Unsupported versions: 4 metadata/rejection checks.
