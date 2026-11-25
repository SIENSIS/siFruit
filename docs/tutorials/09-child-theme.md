# Tutorial 9 — A child theme

A theme changes how the site looks without touching a module. You will create **Tutorial Demo**, a
child of the `default` theme that changes the primary colour and the logo, adds its own CSS, and
redraws the public page of the to-do tasks (tutorial 5) as coloured columns. Everything it does
not override comes from its parent.

The finished theme ships in the repository as tutorial material: `themes/tutorial-demo/` (themes
live in `themes/`, never inside a module). Reference: [`themes.md`](../themes.md), sections
"View overrides" and "Child themes".

## 1. Generate it

```bash
php spark make:theme TutorialDemo          # child of "default" (--parent=<slug>, --parent=none)
```

`TutorialDemo` becomes the slug `tutorial-demo`. `make:theme` writes `themes/tutorial-demo/`: the manifest
`Theme.php` (namespace `Themes\TutorialDemo`), `Assets/css/theme.css`, empty `Views/overrides`,
`Views/components` and `Views/partials`, and ca/es/en language files; it validates the theme and
publishes its assets. (The repository already has this theme: to follow along, generate another
name, such as `TasksDemo2`.)

## 2. Colours and logo through the parent's options

A child theme changes a colour by **redeclaring the default of a parent option**, not by copying
CSS. The parent turns `primaryColor` into the Bootstrap variables (`--bs-primary` and its
variants, light and dark mode):

```php
final class Theme extends ThemeManifest
{
    public string $name    = 'Tutorial Demo';
    public string $version = '1.0.0';
    public string $author  = 'kpskeletor';
    public ?string $parent = 'default';

    /** Layouts this theme provides itself (the others come from the parent). */
    public array $layouts = [];

    /** Loaded after the parent's stylesheets. */
    public array $styles = ['css/theme.css'];

    public array $options = [
        'primaryColor' => ['default' => '#6f42c1'],
        'logo'         => ['default' => 'themes/tutorial-demo/img/logo.svg'],
    ];
}
```

The logo is `Assets/img/logo.svg`, published to `public/themes/tutorial-demo/img/logo.svg`.
An SVG of the theme itself is fine: it is code you ship, not a user upload (uploads never accept
SVG by default). Administrators can still change both values in Settings › Theme.

## 3. The theme's own CSS

`Assets/css/theme.css` is loaded after the parent's stylesheets (`$styles`). Use CSS custom
properties built on Bootstrap's, so light and dark mode both keep working:

```css
:root {
    --tutorial-demo-accent: #6f42c1;
    --tutorial-demo-column-todo: var(--bs-secondary-bg);
    --tutorial-demo-column-doing: var(--bs-primary-bg-subtle);
    --tutorial-demo-column-done: var(--bs-success-bg-subtle);
}

.tutorial-demo-hero {
    border-left: 0.35rem solid var(--tutorial-demo-accent);
    padding-left: 1rem;
}
```

Never `style=""` in a view: the Content-Security-Policy refuses inline styles.

## 4. Override a module view

A theme overrides a module's view by putting a file at the same path under
`Views/overrides/<Module>/`. The Tutorial module's controller renders `Modules\Tutorial\Views\public\index`, so
the override is:

```
themes/tutorial-demo/Views/overrides/Tutorial/public/index.php
```

It receives exactly the same variables as the module's view (copy its docblock) and extends the
same `$layout`:

```php
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<header class="tutorial-demo-hero mb-4">
    <?php if ($breadcrumbs === []): ?><h1 class="h2 mb-1"><?= esc($title) ?></h1><?php endif ?>
    …
</header>
<div class="row g-3 tutorial-demo-board">
    …
</div>
<?= $this->endSection() ?>
```

Rules for an override: escape everything, keep the data contract (an override must not query
the database or check permissions: the controller already did), and override as little as
possible: `tutorial/request` keeps the module's own view because the theme does not override it.
The view resolution order is the active theme, then its parent chain, then the module
([`themes.md`, "View resolution"](../themes.md#view-resolution)).

## 5. Activate it

```bash
php spark assets:publish      # copies the theme's Assets/ to public/themes/tutorial-demo/
```

Choose it in Settings › Theme for the public site (or set `theme.public = tutorial-demo` in `.env`)
and open `/tutorial`: purple buttons and links, the theme's logo, and the to-do tasks in coloured
columns. Switch the colour mode: the columns follow it. The back office keeps its own theme.

## Tests

`modules/Tutorial/Tests/ChildThemeTest.php` (activates the theme with
`setting()->set('Theme.public', 'tutorial-demo')` and resets the theme service):

- `testTheThemeIsAValidChildOfTheDefaultTheme`: the manifest, the override file, no layouts of its
  own, the chain `['tutorial-demo', 'default']`;
- `testItOverridesTheTutorialPublicPageAndItsColour`: `/tutorial` uses the override inside the parent's
  public layout, `--bs-primary: #6f42c1;`, the logo, both stylesheets; `tutorial/request` keeps the
  module's view; no inline script, handler or style.

`php spark csp:check` scans `themes/*/Views` too.

```bash
vendor/bin/phpunit modules/Tutorial/Tests/ChildThemeTest.php
```

Next: [tutorial 10](10-file-uploads.md), file uploads.
