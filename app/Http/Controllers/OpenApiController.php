<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Serves the OpenAPI 3.1 contract for the enforcement + management API and a lightweight,
 * fully self-contained HTML reference at `/api/docs`.
 *
 *  - `GET /api/openapi.yaml` — the hand-authored spec (source of truth), `application/yaml`.
 *  - `GET /api/openapi.json` — the generated JSON projection, `application/json`.
 *  - `GET /api/docs`         — an inline, CDN-free reference page that renders the spec.
 *
 * The docs page embeds the spec JSON and renders it with vanilla inline JS/CSS — no
 * external hosts, so it is safe under a strict CSP. The spec files are read from
 * `docs/openapi/` (the YAML is the source of truth; `composer openapi:json` regenerates
 * the JSON, and a test fails the build on drift).
 */
class OpenApiController extends Controller
{
    private const string YAML_PATH = 'docs/openapi/cbox-billing.yaml';

    private const string JSON_PATH = 'docs/openapi/cbox-billing.json';

    public function yaml(): Response
    {
        return new Response(
            $this->read(self::YAML_PATH),
            SymfonyResponse::HTTP_OK,
            [
                'Content-Type' => 'application/yaml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }

    public function json(): JsonResponse
    {
        /** @var array<string, mixed> $spec */
        $spec = json_decode($this->read(self::JSON_PATH), true) ?: [];

        return (new JsonResponse($spec))
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function docs(): Response
    {
        $spec = $this->read(self::JSON_PATH);

        return new Response(
            $this->page($spec),
            SymfonyResponse::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $contents === false ? '' : $contents;
    }

    /**
     * The self-contained docs page: the spec JSON embedded verbatim and rendered by an
     * inline script. No external stylesheet, font, or script — CSP-safe by construction.
     */
    private function page(string $specJson): string
    {
        // Embedded in a <script type="application/json"> block so no escaping games are
        // needed; only the closing-tag sequence must be neutralised. A nowdoc keeps the
        // page's own `$`-tokens (JS `$ref`) literal; the spec is spliced in at __SPEC_JSON__.
        $safe = str_replace('</', '<\/', $specJson);

        $template = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cbox Billing API reference</title>
<style>
  :root {
    --bg: #ffffff; --fg: #1a1a2e; --muted: #5b6472; --line: #e4e7ec;
    --card: #f7f8fa; --accent: #3b5bdb; --code: #f0f2f5;
    --get: #1a7f37; --post: #3b5bdb; --put: #9a6700; --delete: #cf222e;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0f1117; --fg: #e6e8eb; --muted: #9aa4b2; --line: #262b36;
      --card: #171a21; --accent: #7c93f5; --code: #1c2029;
      --get: #4ac26b; --post: #7c93f5; --put: #d4a72c; --delete: #f47067;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--fg);
    font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  header { padding: 28px 24px; border-bottom: 1px solid var(--line); }
  header h1 { margin: 0 0 4px; font-size: 22px; }
  header p { margin: 0; color: var(--muted); max-width: 70ch; }
  .layout { display: flex; align-items: flex-start; }
  nav { position: sticky; top: 0; max-height: 100vh; overflow-y: auto;
    width: 240px; flex: 0 0 240px; padding: 20px 12px; border-right: 1px solid var(--line); }
  nav a { display: block; padding: 4px 10px; color: var(--muted); text-decoration: none;
    border-radius: 6px; font-size: 13.5px; }
  nav a:hover { background: var(--card); color: var(--fg); }
  nav .tag { margin-top: 14px; font-weight: 600; color: var(--fg); font-size: 12px;
    text-transform: uppercase; letter-spacing: .04em; padding: 4px 10px; }
  main { flex: 1 1 auto; padding: 20px 28px 120px; min-width: 0; max-width: 900px; }
  section.tag > h2 { font-size: 18px; margin: 34px 0 4px; padding-top: 10px; }
  section.tag > p.desc { margin: 0 0 12px; color: var(--muted); }
  .op { border: 1px solid var(--line); border-radius: 10px; margin: 12px 0; overflow: hidden; }
  .op > summary { cursor: pointer; list-style: none; padding: 12px 14px; display: flex;
    gap: 10px; align-items: center; background: var(--card); }
  .op > summary::-webkit-details-marker { display: none; }
  .method { font-weight: 700; font-size: 11.5px; letter-spacing: .04em; padding: 3px 8px;
    border-radius: 6px; color: #fff; flex: 0 0 auto; }
  .method.get { background: var(--get); } .method.post { background: var(--post); }
  .method.put { background: var(--put); } .method.delete { background: var(--delete); }
  .path { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13.5px; }
  .op .summary-text { color: var(--muted); font-size: 13px; margin-left: auto; text-align: right; }
  .op .body { padding: 14px; border-top: 1px solid var(--line); }
  .op .body h4 { margin: 16px 0 6px; font-size: 12px; text-transform: uppercase;
    letter-spacing: .04em; color: var(--muted); }
  .op .body h4:first-child { margin-top: 0; }
  .badge { display: inline-block; font-size: 11px; padding: 2px 7px; border-radius: 5px;
    background: var(--code); color: var(--muted); margin-right: 6px; }
  .badge.auth { color: var(--accent); }
  pre { background: var(--code); border-radius: 8px; padding: 12px; overflow-x: auto;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; margin: 0 0 8px; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; }
  th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { color: var(--muted); font-weight: 600; }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: var(--code);
    padding: 1px 5px; border-radius: 5px; font-size: 12.5px; }
  .resp { display: flex; gap: 8px; align-items: baseline; padding: 3px 0; }
  .status { font-family: ui-monospace, monospace; font-weight: 700; flex: 0 0 44px; }
  .s2 { color: var(--get); } .s4 { color: var(--delete); } .s5 { color: var(--delete); }
  .links a { color: var(--accent); }
  @media (max-width: 720px) { nav { display: none; } main { padding: 16px; } }
</style>
</head>
<body>
<header>
  <h1>Cbox Billing API</h1>
  <p id="subtitle"></p>
  <p class="links" style="margin-top:10px">
    <a href="/api/openapi.yaml">openapi.yaml</a> &nbsp;·&nbsp;
    <a href="/api/openapi.json">openapi.json</a>
  </p>
</header>
<div class="layout">
  <nav id="nav"></nav>
  <main id="main"></main>
</div>
<script type="application/json" id="spec">__SPEC_JSON__</script>
<script>
(function () {
  var spec = JSON.parse(document.getElementById('spec').textContent);
  document.getElementById('subtitle').textContent =
    (spec.info && spec.info.summary) || 'API reference';
  document.title = (spec.info && spec.info.title || 'API') + ' reference';

  var tagOrder = (spec.tags || []).map(function (t) { return t.name; });
  var tagDesc = {};
  (spec.tags || []).forEach(function (t) { tagDesc[t.name] = t.description || ''; });
  var byTag = {};
  var methods = ['get', 'post', 'put', 'delete', 'patch'];

  Object.keys(spec.paths).forEach(function (path) {
    methods.forEach(function (m) {
      var op = spec.paths[path][m];
      if (!op) return;
      var tag = (op.tags && op.tags[0]) || 'Other';
      (byTag[tag] = byTag[tag] || []).push({ method: m, path: path, op: op });
    });
  });

  var tags = tagOrder.filter(function (t) { return byTag[t]; });
  Object.keys(byTag).forEach(function (t) { if (tags.indexOf(t) < 0) tags.push(t); });

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }
  function slug(s) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '-'); }

  var nav = document.getElementById('nav');
  var main = document.getElementById('main');

  tags.forEach(function (tag) {
    nav.appendChild(el('div', 'tag', tag));
    var section = el('section', 'tag');
    section.id = slug(tag);
    section.appendChild(el('h2', null, tag));
    if (tagDesc[tag]) section.appendChild(el('p', 'desc', tagDesc[tag]));

    byTag[tag].forEach(function (entry) {
      var op = entry.op, m = entry.method;
      var opId = op.operationId || (m + entry.path);

      var a = el('a', null, op.summary || opId);
      a.href = '#' + slug(opId);
      nav.appendChild(a);

      var det = el('details', 'op');
      det.id = slug(opId);
      var sum = el('summary');
      sum.appendChild(el('span', 'method ' + m, m.toUpperCase()));
      sum.appendChild(el('span', 'path', entry.path));
      sum.appendChild(el('span', 'summary-text', op.summary || ''));
      det.appendChild(sum);

      var body = el('div', 'body');

      var meta = el('p');
      var noAuth = op.security && op.security.length === 0;
      var authBadge = el('span', 'badge auth', noAuth ? 'no auth' : 'bearer token');
      meta.appendChild(authBadge);
      var hasIdem = (op.parameters || []).some(function (p) { return p.name === 'Idempotency-Key'; });
      if (hasIdem) meta.appendChild(el('span', 'badge', 'Idempotency-Key'));
      meta.appendChild(el('code', null, opId));
      body.appendChild(meta);

      if (op.description) {
        var d = el('div');
        d.style.color = 'var(--muted)';
        d.style.whiteSpace = 'pre-wrap';
        d.textContent = op.description;
        body.appendChild(d);
      }

      var params = (op.parameters || []).map(resolve);
      if (params.length) {
        body.appendChild(el('h4', null, 'Parameters'));
        var tbl = el('table');
        var thead = el('tr');
        ['Name', 'In', 'Required', 'Description'].forEach(function (h) { thead.appendChild(el('th', null, h)); });
        tbl.appendChild(thead);
        params.forEach(function (p) {
          var tr = el('tr');
          tr.appendChild(cellCode(p.name));
          tr.appendChild(el('td', null, p['in']));
          tr.appendChild(el('td', null, p.required ? 'yes' : 'no'));
          tr.appendChild(el('td', null, p.description || ''));
          tbl.appendChild(tr);
        });
        body.appendChild(tbl);
      }

      if (op.requestBody) {
        body.appendChild(el('h4', null, 'Request body'));
        var ex = firstExample(op.requestBody.content);
        if (ex !== undefined) body.appendChild(pre(ex));
      }

      body.appendChild(el('h4', null, 'Responses'));
      Object.keys(op.responses).forEach(function (code) {
        var r = op.responses[code];
        var row = el('div', 'resp');
        row.appendChild(el('span', 'status s' + code[0], code));
        row.appendChild(el('span', null, r.description || ''));
        body.appendChild(row);
      });
      var okCode = Object.keys(op.responses).filter(function (c) { return c[0] === '2'; })[0];
      if (okCode && op.responses[okCode].content) {
        var rex = firstExample(op.responses[okCode].content);
        if (rex !== undefined) {
          body.appendChild(el('h4', null, 'Example response (' + okCode + ')'));
          body.appendChild(pre(rex));
        }
      }

      det.appendChild(body);
      section.appendChild(det);
    });

    main.appendChild(section);
  });

  function resolve(ref) {
    if (ref && ref['$ref']) {
      var parts = ref['$ref'].replace('#/', '').split('/');
      var node = spec;
      parts.forEach(function (p) { node = node[p]; });
      return node;
    }
    return ref;
  }
  function firstExample(content) {
    if (!content) return undefined;
    var mt = content['application/json'] || content[Object.keys(content)[0]];
    if (!mt) return undefined;
    if (mt.examples) {
      var k = Object.keys(mt.examples)[0];
      return mt.examples[k].value;
    }
    if (mt.example !== undefined) return mt.example;
    return undefined;
  }
  function pre(obj) {
    return el('pre', null, typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2));
  }
  function cellCode(text) {
    var td = el('td'); td.appendChild(el('code', null, text)); return td;
  }
})();
</script>
</body>
</html>
HTML;

        return str_replace('__SPEC_JSON__', $safe, $template);
    }
}
