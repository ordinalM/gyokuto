# Gyokuto - a PHP static site generator

## Overview

Gyokuto uses PHP and Twig to render local Markdown content to produce a static site. It can be used as is, or combined with other PHP modules.

## Installation

### Composer (recommended)

In your project, install the module with:
```
composer require ordinalm/gyokuto dev-master
```

Start a build with:

```
vendor/ordinalm/gyokuto/bin/build
```

By default, this will look for content in `./content` and output to `./www`.

## Page rendering

### Markdown page structure

Gyokuto will process flat Markdown files without metadata with no problems, but you may wish to include metadata using the Multimarkdown page header system.

```
---
title: About this site
date: 2020-10-18 13:30
header: true
---
Morbi leo risus, porta ac consectetur ac, vestibulum at eros. Curabitur
blandit tempus porttitor. Maecenas faucibus mollis interdum. Cras justo
odio, dapibus ac facilisis in, egestas eget quam.
```

Markdown headers are parsed as YAML data and are then available to Twig templates.

You may also include Twig code within page content - e.g.

```
---
foo: bar
---
The value of "foo" is "{{ current_page.meta.foo }}".
```

will render as

> The value of "foo" is "bar".

See below for the values that are automatically available to Twig.

#### Special metadata variables

- `draft` - if set to a value evaluating to `true`, this page will _not_ be copied over or included in any page lists or indexes.
- `template` - this determines the template used to process this page. If not set, the value is `default.twig`.

### Build process

#### Compile phase

All files in the content directory are marked as either parsable (`.md` or `.markdown` extension) or to simply be copied.

All parseable files are parsed, their metadata included in the overall page list, and, if appropriate, the metadata used to construct indexes.

#### Build phase

Non-parsable files are simply copied unchanged to the same filename in the output directory. These can be of any type - images, CSS, PHP code etc.

Markdown files are parsed with the following steps:

1. All Twig code in the page body is evaluated.
2. The page body is converted into HTML from Markdown.
3. The chosen Twig template for that page, as defined in the `template` metadata value, is run.

### Twig variables available during rendering

- `current_page` - the page being rendered now
    - `meta`
        - `title` - an unescaped string, taken either from the `title` Markdown header field or from the filename
        - `date` - a UNIX timestamp taken either from the `date` field in the Markdown header
        - any other variables set in the page's Markdown header
    - `path` - from site root, beginning with `/`
    - `content` - processed HTML from page Markdown body (not available within pages)
- `pages` - all pages in the build, keyed by path, in descending order of `date`, do not include `content`. Can be used to look up page metadata referred to in indexes.
    - `meta`
        - `title`
        - `date`
    - `path`
- `options`
    - all values declared in the options file

If any metadata fields have been specified for indexing using the `index` value in the options file, they will appear as:

- `index` - keyed by the meta variable being indexed, sorted by the value of that variable
    - key 1
        - path 1
        - path 2
        - ...
    - key 2
        - path 3
        - ...
    ...

The paths can be used to look up page metadata in the `pages` list.

#### Example: list of all pages by tag

In `gyokuto.yml`:
```
index: [ "tags" ]
```

In a page or template:
```
{% for tag, pagelist in index.tags %}
<h3>{{ tag|escape }}</h3>
<ul>
{% for path in pagelist %}
<li><a href="{{ path }}">{{ pages[path].meta.title|escape }}</a></li>
{% endfor %}
</ul>
{% endfor %}
```
