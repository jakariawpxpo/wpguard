<img width="1672" height="941" alt="wpguard WordPress Security Scanner" src="https://github.com/user-attachments/assets/f4b7c2a4-147e-4d13-a1fe-88094be15272" />

# wpguard

RuboCop/Brakeman-style checker for WordPress plugins, written in PHP. It combines:

1. **wpguard rules**: WordPress.org plugin guideline candidates (remote assets, tracking, trialware gates, public REST routes, unescaped output, readme and header problems, package files, and more). Run `wpguard --rules` for the list.
2. **PHPCS + WordPress Coding Standards** if `phpcs` is installed, using the plugin's `phpcs.xml[.dist]` when present.
3. **Plugin Check** (`--pcp`), as raw `wp plugin check` output.

Every finding prints `file:line:col` and the code line with one line of context. Findings are review candidates, not verdicts.

```
wpguard                      # scan current directory
wpguard path/to/plugin
wpguard plugin.zip           # scan a release ZIP
wpguard --strict             # PHPCS with --ignore-annotations
wpguard --format=json        # for CI
wpguard --fail-on=medium     # CI exit code
```

Suppress a reviewed line with `// wpguard-ignore: WG-RULE-ID` (same line or line above), or use `.wpguard.json`:
`{"ignore_rules":["WG-IFRAME"],"ignore_paths":["tests/"]}`

## Install

```
git clone https://github.com/jakariawpxpo/wpguard.git
ln -s "$PWD/wpguard/bin/wpguard" ~/.local/bin/wpguard
```

Or with Composer, once published to Packagist: `composer global require jakariawpxpo/wpguard`.
Needs PHP 7.4+ (the `zip` extension for ZIP input). Not an official WordPress.org tool; a clean run is not an approval.

Limits: line-based regex rules, so multi-line constructs are missed and some hits are false positives. Trialware, service-substance and readme-versus-code parity still need human review.

<a href="https://www.buymeacoffee.com/jakaria.jack" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me a Coffee" style="height: 60px !important;width: 217px !important;" ></a>
